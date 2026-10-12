<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\PayslipPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a salary payment to the day the money actually left.
 *
 * ⚠⚠ THE INCIDENT THIS EXISTS FOR (12 Oct 2026). September 2026's two batches
 * were confirmed on 12 October, the bank had paid on 30 September, and the
 * batch screen sent "today" as the paid date without asking. Nine "Salary paid"
 * journals landed on 12 October: the bank book disagreed with the statement
 * for twelve days and September's closing payable was overstated.
 *
 * A posted journal is never edited, so the correction is the ledger's own:
 *
 *  1. the wrongly dated journal is REVERSED ON ITS OWN DATE, so that day nets
 *     to nothing and the memo says why;
 *  2. a fresh journal is posted on the right date through the SAME path every
 *     salary payment takes (`PostPayrollPayment::handle()`), so the lines are
 *     the ones a correct confirmation would have written;
 *  3. the payment row takes the new date, and the activity log keeps the old
 *     date and all three journal numbers.
 *
 * Idempotent: a payment already on the new date is skipped, so pressing the
 * button twice changes nothing the second time.
 */
class CorrectPayslipPaidDate
{
    public function __construct(private readonly PostPayrollPayment $posting) {}

    /**
     * @return int how many payments were moved
     *
     * @throws ValidationException
     */
    public function forBatch(DisbursementBatch $batch, Carbon $paidOn, string $reason, User $user): int
    {
        $this->guardBusiness((int) $batch->business_id);

        if (! $batch->isConfirmed()) {
            throw ValidationException::withMessages([
                'paid_on' => 'This batch has not been confirmed yet. Confirm it with the right date instead.',
            ]);
        }

        $payments = PayslipPayment::query()
            ->where('disbursement_batch_id', $batch->id)
            ->where('event_type', PaymentEventType::Paid)
            ->get();

        return $this->correct($payments, $paidOn, $reason, $user);
    }

    /**
     * @throws ValidationException
     */
    public function forPayment(PayslipPayment $payment, Carbon $paidOn, string $reason, User $user): int
    {
        $this->guardBusiness((int) $payment->business_id);

        if ($payment->event_type !== PaymentEventType::Paid) {
            throw ValidationException::withMessages([
                'paid_on' => 'Only a recorded payment has a paid date to correct.',
            ]);
        }

        return $this->correct(collect([$payment]), $paidOn, $reason, $user);
    }

    /**
     * @param  Collection<int, PayslipPayment>  $payments
     *
     * @throws ValidationException
     */
    private function correct(Collection $payments, Carbon $paidOn, string $reason, User $user): int
    {
        $paidOn = $paidOn->copy()->startOfDay();
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the paid date is being corrected.']);
        }

        if ($paidOn->isAfter(Carbon::today())) {
            throw ValidationException::withMessages([
                'paid_on' => 'The paid date cannot be in the future. Use the date on the bank statement.',
            ]);
        }

        // ⚠ Both ends of the move have to be open: the new date receives an
        // entry, and the old date receives its reversal. Checked here for a
        // clear answer before anything is written; PostJournal checks again.
        AccountingPeriod::assertOpenFor($paidOn);

        return DB::transaction(function () use ($payments, $paidOn, $reason, $user) {
            $moved = 0;

            foreach ($payments as $payment) {
                $payment = PayslipPayment::query()->lockForUpdate()->findOrFail($payment->id);
                $payment->loadMissing(['payslip.run', 'employee', 'moneyAccount']);

                // A payment that already came back (bounced, returned) has had
                // its journal taken out; there is nothing left to move.
                $returned = PayslipPayment::withoutGlobalScopes()->where('reverses_id', $payment->id)->exists();

                if ($returned) {
                    continue;
                }

                $status = $payment->payslip?->run?->status;

                if (! in_array($status, [PayrollStatus::Disbursed, PayrollStatus::Locked], true)) {
                    throw ValidationException::withMessages([
                        'paid_on' => 'This payroll is not disbursed, so it has no payment to correct.',
                    ]);
                }

                $oldDate = $payment->paid_on->copy()->startOfDay();
                $journal = $this->posting->existingJournal($payment);
                $journalDate = $journal !== null ? Carbon::parse($journal->journal_date)->startOfDay() : null;

                $dateRight = $oldDate->equalTo($paidOn);
                $journalRight = $journal === null || $journalDate->equalTo($paidOn);

                if ($dateRight && $journalRight) {
                    continue;
                }

                $reversal = null;
                $replacement = null;

                if ($journal !== null && ! $journalRight) {
                    AccountingPeriod::assertOpenFor($journalDate);

                    // ⚠⚠ Dated on the WRONG date it removes, not today: the
                    // point is that 12 October nets to nothing, and a reversal
                    // dated today would leave the wrong day carrying the payment
                    // and move it to today as well.
                    $reversal = $this->posting->reverse(
                        $payment,
                        $user,
                        $journalDate,
                        sprintf(
                            'Reversal of %s: paid date corrected from %s to %s. %s',
                            $journal->journal_number,
                            $journalDate->toDateString(),
                            $paidOn->toDateString(),
                            $reason,
                        ),
                    );
                }

                $payment->forceFill(['paid_on' => $paidOn->toDateString()])->save();

                if ($journal !== null && ! $journalRight) {
                    $replacement = $this->posting->handle(
                        $payment->fresh(['employee', 'moneyAccount']),
                        $payment->moneyAccount,
                        $user,
                        'paid date corrected from '.$journalDate->toDateString().': '.$reason,
                    );
                }

                activity()
                    ->performedOn($payment)
                    ->causedBy($user)
                    ->withProperties([
                        'reason' => $reason,
                        'paid_on' => [$oldDate->toDateString(), $paidOn->toDateString()],
                        'old_journal' => $journal?->journal_number,
                        'reversal_journal' => $reversal?->journal_number,
                        'new_journal' => $replacement?->journal_number,
                    ])
                    ->log('salary paid date corrected');

                $moved++;
            }

            return $moved;
        });
    }

    /**
     * ⚠ Route binding already scopes to the current business; this is the
     * second lock on the door for a caller that did not come through a route.
     *
     * @throws ValidationException
     */
    private function guardBusiness(int $businessId): void
    {
        if ($businessId !== app(BusinessContext::class)->id()) {
            throw ValidationException::withMessages([
                'paid_on' => 'That payment belongs to a different business.',
            ]);
        }
    }
}
