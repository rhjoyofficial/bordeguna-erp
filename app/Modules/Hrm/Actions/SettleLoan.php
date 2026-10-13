<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Early settlement (ADJ-07's third ticked item).
 *
 * The employee pays off what is left in one go, outside payroll — cash, a bank
 * transfer, a deduction from a final settlement. Three things follow, and the
 * order matters:
 *
 *  1. A settlement entry closes the balance on the ledger.
 *  2. Every remaining scheduled installment is **cancelled, not deleted**, so
 *     the original plan stays readable next to what actually happened.
 *  3. The loan becomes Settled, which stops payroll deducting against it.
 *
 * Note what this deliberately is **not**: a write-off. The amount received is
 * required and must clear the balance. ADJ-07 says "early settlement", meaning
 * the debt is paid early — forgiving a debt is a different decision that nobody
 * has authorised, and letting it in through this door would make an unpaid loan
 * indistinguishable from a paid one.
 */
class SettleLoan
{
    public function __construct(private readonly PostLoanSettlement $posting) {}

    /**
     * `$receivedInto` and `$receivedOn` are what the ledger needs: which account
     * the money came into and on what day. Once accounting is on, a settlement
     * that cannot say where the money went is refused by the posting, and the
     * whole settlement rolls back with it.
     *
     * @throws ValidationException
     */
    public function handle(Loan $loan, float $amountReceived, string $note, ?MoneyAccount $receivedInto = null, ?string $receivedOn = null, ?User $settledBy = null): Loan
    {
        if (! $loan->isApplied() || $loan->status !== LoanStatus::Active) {
            throw ValidationException::withMessages([
                'loan' => 'Only an active, disbursed loan can be settled.',
            ]);
        }

        $outstanding = $loan->outstanding();

        if ($outstanding <= 0.005) {
            throw ValidationException::withMessages([
                'loan' => 'This loan is already fully repaid.',
            ]);
        }

        if (round($amountReceived, 2) < round($outstanding, 2)) {
            // A part payment is not a settlement. Accepting one here would leave
            // a loan marked Settled with money still owed on it.
            throw ValidationException::withMessages([
                'amount_received' => sprintf(
                    'An early settlement must clear the full outstanding balance of %s.',
                    number_format($outstanding, 2),
                ),
            ]);
        }

        $this->guardNoPendingDeduction($loan);

        return DB::transaction(function () use ($loan, $outstanding, $note, $receivedInto, $receivedOn, $settledBy) {
            $entry = LoanLedgerEntry::once("loan_settlement:{$loan->id}", [
                'loan_id' => $loan->id,
                'employee_id' => $loan->employee_id,
                'entry_type' => LoanLedgerEntryType::EarlySettlement,
                // Negative: the debt is cleared. Exactly the outstanding amount,
                // never the amount handed over — an overpayment is change, not a
                // negative loan balance.
                'amount' => -$outstanding,
                'occurred_on' => $receivedOn ?? now()->toDateString(),
                'note' => $note,
                'reference_type' => $loan->getMorphClass(),
                'reference_id' => $loan->id,
                'created_by' => Auth::id(),
            ]);

            if ($entry !== null) {
                $this->posting->handle($loan, $entry, $receivedInto, $settledBy ?? Auth::user());
            }

            // Kept as history rather than removed: "what was the plan, and what
            // actually happened" is a question a settled loan still has to
            // answer.
            $loan->installments()
                ->where('status', LoanInstallmentStatus::Scheduled)
                ->update(['status' => LoanInstallmentStatus::Cancelled]);

            $loan->update([
                'status' => LoanStatus::Settled,
                'settled_at' => now(),
                'settlement_note' => $note,
            ]);

            return $loan->fresh(['installments', 'ledgerEntries']);
        });
    }

    /**
     * ⚠⚠ Refuse while a payroll that is not yet paid already deducts one of
     * this loan's installments. The settlement clears the whole balance, and
     * that payroll would then collect the installment AGAIN when it is
     * disbursed: the employee pays twice for the same month. Settle after
     * that payroll is disbursed, or re-run (or reopen) it first so the
     * deduction drops off.
     *
     * @throws ValidationException
     */
    private function guardNoPendingDeduction(Loan $loan): void
    {
        $sources = $loan->installments()->pluck('id')
            ->map(fn ($id) => "loan_installment:{$id}")
            ->all();

        if ($sources === []) {
            return;
        }

        $line = PayslipLine::query()
            ->whereIn('source', $sources)
            ->whereHas('payslip.run', fn ($q) => $q->whereIn('status', [
                PayrollStatus::Draft, PayrollStatus::Calculated, PayrollStatus::Reviewed, PayrollStatus::Approved,
            ]))
            ->with('payslip.run.period')
            ->first();

        if ($line === null) {
            return;
        }

        $run = $line->payslip->run;

        throw ValidationException::withMessages([
            'loan' => sprintf(
                'The %s payroll (version %d, %s) already deducts an installment of this loan and has not been paid yet. Settle after it is disbursed, or re-run or reopen it first, or the installment is collected twice.',
                $run->period?->label() ?? 'open',
                $run->version,
                $run->status->label(),
            ),
        ]);
    }

    /**
     * Closes a loan that reached the end of its schedule.
     *
     * Separate from early settlement and posting no ledger entry, because
     * nothing moved — the final repayment already brought the balance to zero.
     * This only records that the agreement is finished.
     */
    public function closeIfRepaid(Loan $loan): Loan
    {
        if ($loan->status !== LoanStatus::Active || ! $loan->isFullyRepaid()) {
            return $loan;
        }

        $loan->update([
            'status' => LoanStatus::Settled,
            'settled_at' => now(),
            'settlement_note' => 'Repaid in full through payroll.',
        ]);

        return $loan->fresh();
    }
}
