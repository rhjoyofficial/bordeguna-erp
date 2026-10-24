<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Corrections\StaffLoanActionPolicy;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\LoanInstallment;
use App\Modules\Hrm\Models\LoanLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a staff loan or advance cancelled
 * before its first instalment (FINAL-PLAN 3.4: "Cancel loan: payout
 * reversed"). The Owner.
 *
 *   the ledger        the payout journal (Dr Employee Advances / Cr the money
 *                     account) mirrored through ReverseJournal, on the payout's
 *                     own date while open, else on the first open day
 *   the loan ledger   one compensating entry, the payout negated, keyed on the
 *                     loan: what the employee owes is zero again, and both
 *                     records of the debt move together
 *   the schedule      every scheduled instalment Cancelled, so payroll never
 *                     takes one
 *   the loan          Cancelled (the status existed and was never set), with
 *                     who, when, why and the reversal journal
 *
 * A wrong amount is "cancel and copy": a new loan request with the right one.
 * One transaction, the loan re-read under a lock.
 */
class CancelStaffLoan
{
    public function __construct(
        private readonly StaffLoanActionPolicy $policy,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    public function handle(Loan $loan, User $by, ActionReason $why): Loan
    {
        return DB::transaction(function () use ($loan, $by, $why) {
            $loan = Loan::query()->withoutGlobalScope(BusinessScope::class)->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($loan, $by, DocumentAction::Cancel);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $date = $this->policy->cancelDate($loan, $by);
            $journal = $this->policy->payoutJournal($loan);
            $reversal = null;

            if ($journal !== null) {
                try {
                    $reversal = $this->reverseJournal->handle($journal->load('lines'), $by, $date->date, "{$loan->type->label()} {$loan->reference} cancelled: {$why->display()}");
                } catch (PeriodClosedException $e) {
                    throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
                }
            }

            $owed = round($loan->outstanding(), 2);

            if ($owed > 0) {
                LoanLedgerEntry::once("loan_disbursement_reversed:{$loan->id}", [
                    'business_id' => $loan->business_id,
                    'loan_id' => $loan->id,
                    'employee_id' => $loan->employee_id,
                    'entry_type' => LoanLedgerEntryType::DisbursementReversed,
                    'amount' => -1 * $owed,
                    'occurred_on' => $date->date->toDateString(),
                    'note' => "{$loan->type->label()} {$loan->reference} cancelled before the first instalment: {$why->display()}",
                    'reference_type' => $loan->getMorphClass(),
                    'reference_id' => $loan->id,
                    'created_by' => $by->id,
                ]);
            }

            LoanInstallment::query()->where('loan_id', $loan->id)->where('status', LoanInstallmentStatus::Scheduled)->update(['status' => LoanInstallmentStatus::Cancelled]);

            $loan->forceFill([
                'status' => LoanStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $why->display(),
                'cancel_journal_id' => $reversal?->id,
            ])->save();

            CorrectionLog::event('cancelled', $by, $loan, array_filter([
                'reason' => $why->display(),
                'reversal_date' => $date->date->toDateString(),
                'reversal_journal' => $reversal?->journal_number,
                'principal' => (string) $loan->principal,
            ], fn ($v) => $v !== null));

            return $loan->fresh();
        });
    }
}
