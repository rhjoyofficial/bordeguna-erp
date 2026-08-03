<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\PaymentEventType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DisbursementBatch;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what happened when someone tried to pay a payslip (DIS-01/DIS-02).
 *
 * One write path for all three outcomes, for the same reason `RecordPunch` is
 * the single door for every attendance source: the rules that follow a payment
 * — reconciling the loan and PAY-11 ledgers under Correction C9c — must run
 * whichever screen recorded it, and a second entry point is where that
 * eventually stops being true.
 *
 * Nothing may be paid before the run is Disbursed. That is not a formality: the
 * repayment and recovery postings this reconciles against do not exist until
 * the Disbursed transition writes them.
 */
class RecordPayslipPayment
{
    public function __construct(
        private readonly ReconcilePayslipLedgers $reconcile,
        private readonly PostPayrollPayment $posting,
    ) {}

    /**
     * @throws ValidationException
     */
    public function paid(
        Payslip $payslip,
        User $user,
        float $amount,
        ?SalaryPaymentMethod $method = null,
        ?Carbon $paidOn = null,
        ?string $reference = null,
        ?DisbursementBatch $batch = null,
        ?MoneyAccount $moneyAccount = null,
    ): PayslipPayment {
        $this->guardDisbursed($payslip);

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'A payment must be for more than zero.',
            ]);
        }

        $outstanding = round((float) $payslip->net - $payslip->paidTotal(), 2);

        if ($amount > $outstanding + 0.005) {
            // DIS-02 allows a part payment, never an over-payment. Paying more
            // than the payslip is worth is a data-entry error, and accepting it
            // would make "paid" a figure that no longer means what it says.
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'That is more than the %s still outstanding on this payslip.',
                    number_format(max($outstanding, 0), 2),
                ),
            ]);
        }

        return $this->record($payslip, $user, PaymentEventType::Paid, $amount, $method, $paidOn, $reference, null, $batch, null, $moneyAccount);
    }

    /**
     * DIS-02's "unpaid status and retry": the attempt is on record with its
     * reason, so a bounced transfer is something HR can see and act on rather
     * than an absence they have to notice.
     *
     * @throws ValidationException
     */
    public function failed(
        Payslip $payslip,
        User $user,
        string $reason,
        ?SalaryPaymentMethod $method = null,
        ?Carbon $paidOn = null,
        ?DisbursementBatch $batch = null,
    ): PayslipPayment {
        $this->guardDisbursed($payslip);

        return $this->record(
            $payslip,
            $user,
            PaymentEventType::Failed,
            // What the attempt was worth. It moves no money — the event type
            // decides that — but recording zero would lose what was tried.
            round((float) $payslip->net - $payslip->paidTotal(), 2),
            $method,
            $paidOn,
            null,
            $reason,
            $batch,
        );
    }

    /**
     * A payment that came back — a returned transfer, a bounced cheque.
     *
     * The original row is never edited: this is a new event pointing at it, so
     * the pair reads as the two things that actually happened.
     *
     * @throws ValidationException
     */
    public function reverse(PayslipPayment $payment, User $user, string $reason, ?Carbon $paidOn = null): PayslipPayment
    {
        if ($payment->event_type !== PaymentEventType::Paid) {
            throw ValidationException::withMessages([
                'payment' => 'Only a recorded payment can be reversed.',
            ]);
        }

        $already = PayslipPayment::withoutGlobalScopes()
            ->where('reverses_id', $payment->id)
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'payment' => 'That payment has already been reversed.',
            ]);
        }

        $payslip = $payment->payslip;

        return $this->record(
            $payslip,
            $user,
            PaymentEventType::Reversed,
            (float) $payment->amount,
            $payment->method,
            $paidOn,
            $payment->reference,
            $reason,
            $payment->batch,
            $payment,
        );
    }

    private function record(
        Payslip $payslip,
        User $user,
        PaymentEventType $type,
        float $amount,
        ?SalaryPaymentMethod $method,
        ?Carbon $paidOn,
        ?string $reference,
        ?string $failureReason,
        ?DisbursementBatch $batch,
        ?PayslipPayment $reverses = null,
        ?MoneyAccount $moneyAccount = null,
    ): PayslipPayment {
        return DB::transaction(function () use ($payslip, $user, $type, $amount, $method, $paidOn, $reference, $failureReason, $batch, $reverses, $moneyAccount) {
            $event = PayslipPayment::create([
                'business_id' => $payslip->business_id,
                'payslip_id' => $payslip->id,
                'employee_id' => $payslip->employee_id,
                'disbursement_batch_id' => $batch?->id,
                'event_type' => $type,
                'method' => $method
                    ?? $batch?->method
                    ?? $payslip->employee?->salary_payment_method
                    ?? SalaryPaymentMethod::Cash,
                'money_account_id' => $moneyAccount?->id,
                'amount' => max($amount, 0),
                'paid_on' => ($paidOn ?? now())->toDateString(),
                'reference' => $reference,
                'failure_reason' => $failureReason,
                'reverses_id' => $reverses?->id,
                'created_by' => $user->id,
            ]);

            // Correction C9c. Declarative, so it is safe here after every event
            // — it makes the ledgers agree with whether the payslip is settled,
            // rather than reacting to what this particular event was.
            $this->reconcile->handle($payslip->fresh(), $event);

            // ⚠⚠ THE JOURNAL IS POSTED HERE, at the single payment, rather than
            // at the batch. This action is already the one path both routes
            // take - ConfirmDisbursementBatch walks it - so posting here is what
            // finally makes "whole batch" and "employee-wise" the same
            // mechanism rather than two that can disagree. Before this, paying
            // one person by hand moved money and told the ledger nothing.
            //
            // ⚠ Only a real payment posts. A FAILED attempt moved no money, and
            // a REVERSED one takes the original journal back out rather than
            // writing a second entry of its own.
            if ($type === PaymentEventType::Paid) {
                $this->posting->handle($event->fresh(['employee', 'moneyAccount']), $moneyAccount, $user);
            }

            if ($type === PaymentEventType::Reversed && $reverses !== null) {
                $this->posting->reverse($reverses, $user);
            }

            return $event;
        });
    }

    /**
     * @throws ValidationException
     */
    private function guardDisbursed(Payslip $payslip): void
    {
        $status = $payslip->run?->status;

        if (! in_array($status, [PayrollStatus::Disbursed, PayrollStatus::Locked], true)) {
            throw ValidationException::withMessages([
                'payslip' => 'This payroll has not been disbursed yet, so nothing can be recorded as paid.',
            ]);
        }
    }
}
