<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use App\Modules\Hrm\Support\LoanSchedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved loan into effect: the money goes out, and the plan to get it
 * back comes into existence.
 *
 * Two things happen, both idempotent.
 *
 * **The disbursement is posted to the ledger** as a single positive entry — the
 * moment the employee starts owing anything. Before this, a loan has no ledger
 * entries at all and therefore an outstanding balance of exactly zero, which is
 * the honest answer for money that has not been handed over.
 *
 * **The repayment schedule is generated** from ADJ-07's three terms. It is a
 * plan, not a set of payments: every row starts `Scheduled`, and only a
 * disbursed payroll run turns one into `Paid`.
 */
class DisburseLoan
{
    public function handle(Loan $loan): Loan
    {
        if ($loan->isApplied()) {
            return $loan;
        }

        return DB::transaction(function () use ($loan) {
            $loan->update([
                'status' => LoanStatus::Active,
                'disbursed_on' => now()->toDateString(),
                'applied_at' => now(),
            ]);

            $this->postDisbursement($loan);
            $this->generateSchedule($loan);

            return $loan->fresh(['installments', 'ledgerEntries']);
        });
    }

    /**
     * The one entry that creates the debt. Keyed on the loan, so re-applying an
     * already-disbursed loan cannot lend the same money twice.
     */
    private function postDisbursement(Loan $loan): void
    {
        LoanLedgerEntry::once("loan_disbursement:{$loan->id}", [
            'loan_id' => $loan->id,
            'employee_id' => $loan->employee_id,
            'entry_type' => LoanLedgerEntryType::Disbursement,
            // Positive: this is what the employee now owes.
            'amount' => round((float) $loan->principal, 2),
            'occurred_on' => now()->toDateString(),
            'note' => sprintf(
                '%s %s of %s disbursed.',
                $loan->type->label(),
                $loan->reference,
                number_format((float) $loan->principal, 2),
            ),
            'reference_type' => $loan->getMorphClass(),
            'reference_id' => $loan->id,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * ADJ-07's principal, installment and start month, turned into rows.
     *
     * Skipped entirely if a schedule already exists, so this action stays safe
     * to repeat — the same reason every ledger posting in this codebase is
     * keyed.
     */
    private function generateSchedule(Loan $loan): void
    {
        if ($loan->installments()->exists()) {
            return;
        }

        $rows = LoanSchedule::build(
            (float) $loan->principal,
            (float) $loan->installment_amount,
            (int) $loan->start_year,
            (int) $loan->start_month,
        );

        foreach ($rows as $row) {
            $loan->installments()->create([
                ...$row,
                'status' => LoanInstallmentStatus::Scheduled,
            ]);
        }

        // The count is stored for display, but the schedule is the truth — a
        // remainder-carrying final installment can make them differ from a naive
        // principal ÷ installment.
        $loan->update(['installment_count' => count($rows)]);
    }
}
