<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Enums\PayrollBalanceEntryType;
use App\Modules\Hrm\Enums\PayslipPaymentStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollBalanceEntry;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Models\PayslipPayment;
use App\Modules\Hrm\Support\PayrollBalanceLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the loan and PAY-11 ledgers honest about whether a payslip was actually
 * paid (Correction C9c).
 *
 * Phase 3e posts a loan repayment, and 3d a carry-forward recovery, the moment
 * a run is marked **Disbursed**. That timing is load-bearing and is not touched
 * here: it is what stops a recalculation reading a balance an earlier
 * calculation had already reduced. But "disbursed" means the payroll was
 * released for payment, and DIS-02 is explicit that a payment can then fail.
 * Without this action a bounced bank transfer would leave an installment
 * recorded as collected from a salary the employee never received.
 *
 * The design is **declarative, not sequential**. It does not track what happened
 * last; it asks one question — *is this payslip settled?* — and makes the
 * ledgers agree with the answer. That is what makes it safe to call after every
 * payment event, in any order, however many times:
 *
 *  - settled, but the posting has been reversed  → post it again;
 *  - not settled, but the posting still stands   → reverse it;
 *  - already in agreement                        → do nothing.
 *
 * Every write is a **compensating entry, never a deletion** — both ledgers stay
 * append-only, so "we collected it, then the transfer bounced, then it went
 * through" reads as the three events it was. Keys carry the payment event id,
 * which is what stops a repost and a reversal from ever colliding.
 *
 * ⚠ A **partial** payment deliberately reverses nothing. Apportioning a
 * shortfall across loan recovery, carry-forward recovery and net pay needs a
 * rule nobody has stated; DIS-02 answers that case with "manual handling", and
 * inventing a split here would be worse than leaving it to a human.
 */
class ReconcilePayslipLedgers
{
    public function __construct(private readonly SettleLoan $settle) {}

    /**
     * @param  PayslipPayment  $event  the payment event that prompted this, whose
     *                                 id keys any entry written
     */
    public function handle(Payslip $payslip, PayslipPayment $event): int
    {
        $payslip->load('lines', 'payments', 'run.period');
        $status = $payslip->paymentStatus();

        // ⚠ Three states, not two. **Partial is not "not settled"** — it is the
        // case C9c deliberately leaves alone, because apportioning a shortfall
        // across loan recovery, carry-forward recovery and net pay needs a rule
        // nobody has stated. Treating it as unpaid would silently reverse a
        // deduction against a payslip most of which *was* paid.
        if ($status === PayslipPaymentStatus::Partial) {
            return 0;
        }

        $settled = $status === PayslipPaymentStatus::Paid;

        return DB::transaction(function () use ($payslip, $event, $settled) {
            return $this->reconcileLoans($payslip, $event, $settled)
                + $this->reconcileBalance($payslip, $event, $settled);
        });
    }

    /**
     * ADJ-07 loan installments collected by this payslip.
     */
    private function reconcileLoans(Payslip $payslip, PayslipPayment $event, bool $settled): int
    {
        $written = 0;

        foreach ($payslip->lines->where('code', 'loan_repayment') as $line) {
            $installment = $this->installmentFor($line);
            $loan = $installment?->loan;

            if ($installment === null || $loan === null) {
                continue;
            }

            $base = LoanLedgerEntry::repaymentKey($installment->id);
            $standing = $this->netUnder(LoanLedgerEntry::query(), $base);

            // A negative net means the repayment is currently in force: the debt
            // has been reduced. Zero means it was never posted, or has since
            // been reversed back out.
            $isPosted = abs($standing) > 0.005;

            if ($settled === $isPosted) {
                continue;
            }

            $amount = round((float) $line->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            if ($settled) {
                LoanLedgerEntry::once("{$base}:repost:{$event->id}", [
                    'loan_id' => $loan->id,
                    'employee_id' => $loan->employee_id,
                    'entry_type' => LoanLedgerEntryType::Repayment,
                    'amount' => -$amount,
                    'occurred_on' => $event->paid_on->toDateString(),
                    'payroll_period_id' => $payslip->run?->payroll_period_id,
                    'note' => sprintf(
                        'Installment %d of %d collected again — the salary payment succeeded on retry.',
                        $installment->sequence,
                        $loan->installment_count,
                    ),
                    'reference_type' => $payslip->getMorphClass(),
                    'reference_id' => $payslip->id,
                    'created_by' => Auth::id(),
                ]);

                $installment->update([
                    'status' => LoanInstallmentStatus::Paid,
                    'paid_at' => now(),
                    'payslip_id' => $payslip->id,
                ]);

                $this->settle->closeIfRepaid($loan->fresh());
            } else {
                LoanLedgerEntry::once("{$base}:reverse:{$event->id}", [
                    'loan_id' => $loan->id,
                    'employee_id' => $loan->employee_id,
                    'entry_type' => LoanLedgerEntryType::RepaymentReversed,
                    // Positive by exactly what was taken off: the debt returns.
                    // Read from the ledger rather than the payslip line, so a
                    // reversal can never undo more than was actually posted.
                    'amount' => abs($standing),
                    'occurred_on' => $event->paid_on->toDateString(),
                    'payroll_period_id' => $payslip->run?->payroll_period_id,
                    'note' => sprintf(
                        'Installment %d of %d reversed — the salary payment failed (%s).',
                        $installment->sequence,
                        $loan->installment_count,
                        $event->failure_reason ?: 'reason not stated',
                    ),
                    'reference_type' => $payslip->getMorphClass(),
                    'reference_id' => $payslip->id,
                    'created_by' => Auth::id(),
                ]);

                // Back to a plan: the money was never collected, so the
                // installment is due again rather than quietly forgiven.
                $installment->update([
                    'status' => LoanInstallmentStatus::Scheduled,
                    'paid_at' => null,
                    'payslip_id' => null,
                ]);

                $this->reopenIfOwing($loan->fresh());
            }

            $written++;
        }

        return $written;
    }

    /**
     * The PAY-11 carry-forward recovery this payslip took (Correction C8c).
     */
    private function reconcileBalance(Payslip $payslip, PayslipPayment $event, bool $settled): int
    {
        $period = $payslip->run?->period;

        if ($period === null) {
            return 0;
        }

        $recovered = round((float) $payslip->lines
            ->where('code', 'balance_recovery')
            ->sum(fn (PayslipLine $line) => (float) $line->amount), 2);

        if ($recovered <= 0) {
            return 0;
        }

        $base = PayrollBalanceLedger::recoveryKey($payslip->employee_id, $period->id);
        $standing = $this->netUnder(PayrollBalanceEntry::query(), $base);
        $isPosted = abs($standing) > 0.005;

        if ($settled === $isPosted) {
            return 0;
        }

        if ($settled) {
            PayrollBalanceEntry::once("{$base}:repost:{$event->id}", [
                'employee_id' => $payslip->employee_id,
                'entry_type' => PayrollBalanceEntryType::Recovered,
                'amount' => -$recovered,
                'payroll_period_id' => $period->id,
                'occurred_on' => $event->paid_on->toDateString(),
                'note' => sprintf(
                    'Recovered %s again — the %s salary payment succeeded on retry.',
                    number_format($recovered, 2),
                    $period->label(),
                ),
                'reference_type' => $payslip->getMorphClass(),
                'reference_id' => $payslip->id,
                'created_by' => Auth::id(),
            ]);
        } else {
            PayrollBalanceEntry::once("{$base}:reverse:{$event->id}", [
                'employee_id' => $payslip->employee_id,
                'entry_type' => PayrollBalanceEntryType::RecoveryReversed,
                // The shortfall comes back onto the balance, dated to the period
                // it belonged to, so the next payroll recovers it under C8c's
                // ordinary rule rather than a special case.
                'amount' => abs($standing),
                'payroll_period_id' => $period->id,
                'occurred_on' => $event->paid_on->toDateString(),
                'note' => sprintf(
                    'Recovery of %s reversed — the %s salary payment failed (%s).',
                    number_format($recovered, 2),
                    $period->label(),
                    $event->failure_reason ?: 'reason not stated',
                ),
                'reference_type' => $payslip->getMorphClass(),
                'reference_id' => $payslip->id,
                'created_by' => Auth::id(),
            ]);
        }

        return 1;
    }

    /**
     * The net of every entry posted under a base key and its derivatives.
     *
     * Matched as "the key itself, or the key followed by a colon" rather than a
     * bare `LIKE base%`, which would let `loan_installment:1` pick up
     * `loan_installment:11` and silently reconcile the wrong loan.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function netUnder($query, string $base): float
    {
        return round((float) $query
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('source_key', $base)->orWhere('source_key', 'like', $base.':%'))
            ->sum('amount'), 2);
    }

    /**
     * A loan closed by a repayment that has now been reversed still has money
     * owing on it, so it goes back to Active — otherwise payroll would stop
     * deducting against a debt that was never actually paid.
     *
     * Only a loan closed by repayment reopens. An early settlement is a
     * separate, deliberate act by a human and is not undone by a failed payroll
     * payment.
     */
    private function reopenIfOwing(Loan $loan): void
    {
        if ($loan->status !== LoanStatus::Settled || $loan->isFullyRepaid()) {
            return;
        }

        if ($loan->settlement_note !== 'Repaid in full through payroll.') {
            return;
        }

        $loan->update([
            'status' => LoanStatus::Active,
            'settled_at' => null,
            'settlement_note' => null,
        ]);
    }

    private function installmentFor(PayslipLine $line): ?LoanInstallment
    {
        if (! str_starts_with((string) $line->source, 'loan_installment:')) {
            return null;
        }

        $id = (int) substr((string) $line->source, strlen('loan_installment:'));

        return LoanInstallment::with('loan')->find($id);
    }
}
