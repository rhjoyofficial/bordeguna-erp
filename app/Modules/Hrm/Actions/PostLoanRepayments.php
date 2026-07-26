<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records the loan repayments a **disbursed** payroll actually collected.
 *
 * Timing is the whole design, and it is what makes loan recovery safe:
 *
 * **Not at calculation.** A run may be recalculated freely while it is Draft or
 * Calculated (WFL-05). If a repayment posted then, the second calculation would
 * read a balance the first had already reduced, the installment would shift, and
 * the figures would move every time HR pressed a button. Posting at disbursement
 * means every recalculation of an open run sees an unchanged balance and
 * produces an identical deduction — the non-circularity property this slice
 * depends on.
 *
 * **Read off the payslip, not recomputed.** What was collected is whatever line
 * the payslip carries. The ledger must record what was paid, not what the rules
 * would say today.
 *
 * **Keyed per installment.** The installment is the thing that must be collected
 * exactly once; a payslip is not, because a recalculation replaces it.
 */
class PostLoanRepayments
{
    public function __construct(private readonly SettleLoan $settle) {}

    public function handle(PayrollRun $run): int
    {
        $period = $run->period;

        if ($period === null) {
            return 0;
        }

        return DB::transaction(function () use ($run, $period) {
            $posted = 0;

            foreach ($run->payslips()->with('lines')->get() as $payslip) {
                foreach ($this->loanLines($payslip) as $line) {
                    $installment = $this->installmentFor($line);

                    if ($installment === null || ! $installment->isDue()) {
                        continue;
                    }

                    $loan = $installment->loan;

                    if ($loan === null) {
                        continue;
                    }

                    $entry = LoanLedgerEntry::once(LoanLedgerEntry::repaymentKey($installment->id), [
                        'loan_id' => $loan->id,
                        'employee_id' => $loan->employee_id,
                        'entry_type' => LoanLedgerEntryType::Repayment,
                        // Negative: the debt shrinks by exactly what the payslip
                        // took, not by what the schedule planned.
                        'amount' => -1 * round((float) $line->amount, 2),
                        'occurred_on' => $period->end_date->toDateString(),
                        'payroll_period_id' => $period->id,
                        'note' => sprintf(
                            'Installment %d of %d recovered from %s payroll.',
                            $installment->sequence,
                            $loan->installment_count,
                            $period->label(),
                        ),
                        'reference_type' => $payslip->getMorphClass(),
                        'reference_id' => $payslip->id,
                        'created_by' => Auth::id(),
                    ]);

                    if ($entry === null) {
                        continue;
                    }

                    $installment->update([
                        'status' => LoanInstallmentStatus::Paid,
                        'paid_at' => now(),
                        'payslip_id' => $payslip->id,
                    ]);

                    // A loan that has reached zero closes itself rather than
                    // sitting Active with nothing left to take.
                    $this->settle->closeIfRepaid($loan->fresh());

                    $posted++;
                }
            }

            return $posted;
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, PayslipLine>
     */
    private function loanLines(Payslip $payslip)
    {
        return $payslip->lines->where('code', 'loan_repayment');
    }

    /**
     * The installment a payslip line came from, resolved through the `source`
     * the builder stamped on it. That stamp is the provenance PAY-13 asks for,
     * and it doubles as the join back to what must be marked paid.
     */
    private function installmentFor(PayslipLine $line): ?LoanInstallment
    {
        if (! str_starts_with((string) $line->source, 'loan_installment:')) {
            return null;
        }

        $id = (int) substr((string) $line->source, strlen('loan_installment:'));

        return LoanInstallment::with('loan')->find($id);
    }
}
