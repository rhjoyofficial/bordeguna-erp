<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DateRuleSummary;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Hrm\Actions\PostLoanDisbursement;
use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\LoanLedgerEntryType;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a staff loan or advance paid out by
 * mistake, with the wrong amount, or the employee changed their mind (FINAL-PLAN
 * 3.4 "Loan or advance, before the first instalment"; HRM plan S5).
 *
 *   Cancel   before ANY instalment is recovered: the payout journal (Dr
 *            Employee Advances / Cr the money account) is mirrored, a
 *            compensating entry zeroes the loan ledger, every scheduled
 *            instalment is Cancelled, and the loan reads Cancelled. The Owner.
 *
 * Refused when the payout is matched on a bank statement (unmatch first), when
 * any instalment was recovered or settled, or when an instalment sits on a
 * payroll that is already reviewed, approved or paid (that payslip would still
 * take it). An instalment on a payroll that can still be recalculated is
 * allowed: the screen says to recalculate it.
 *
 * Reschedule (after recovery started) waits for the 2026 labour-law check
 * (decision 14); it is not built here.
 *
 * The module's own action (CancelStaffLoan); dates: HrmCorrectionDate.
 */
class StaffLoanActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.staff_loan';

    public const PERMISSION = 'hrm.payroll.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Loan::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || Owners::is($user);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var Loan $document */
        return match ($document->status) {
            LoanStatus::Pending => DocumentStage::Waiting,
            default => DocumentStage::Done,
        };
    }

    public function effectProfile(): EffectProfile
    {
        // Engine never runs this type; the guards are the blockers below.
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var Loan $document */
        $actions = [];

        if ($this->canView($document, $user) && $document->status === LoanStatus::Active) {
            $actions[] = $this->availability($document, $user, DocumentAction::Cancel);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var Loan $document */
        $blockers = [];
        $date = null;

        if ($action !== DocumentAction::Cancel) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a staff loan.");
        } else {
            if (! Owners::is($user)) {
                $blockers[] = new Blocker('owner_only', 'Only the Owner can cancel a loan that was paid out.', resolvableBy: 'owner');
            }

            $blockers = [...$blockers, ...$this->cancelBlockers($document)];

            $date = $this->cancelDate($document, $user);

            // Somebody who is not the Owner already reads "Only the Owner".
            if ($date->blocker !== null && Owners::is($user)) {
                $blockers[] = $date->blocker;
            }
        }

        $open = $date?->date === null ? null : $this->onOpenPayroll($document);
        $sentence = $date?->date === null ? null : $date->sentence.($open !== null ? " Recalculate the {$open} payroll so it drops the instalment." : '');

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $action === DocumentAction::Cancel,
            requiresReason: true,
            reasonContext: 'cancellation',
            approval: ApprovalRoute::None,
            date: $sentence === null ? null : new DateRuleSummary('hrm', $sentence),
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->reference;
    }

    public function urlOf(Model $document): ?string
    {
        return route('hrm.loans.index');
    }

    /** Lands on: the payout's own date (open) or the first open day (the Owner). */
    public function cancelDate(Loan $loan, User $user): HrmCorrectionDate
    {
        return HrmCorrectionDate::for(Carbon::parse($loan->disbursed_on ?? now()), $user, 'cancel a payout in');
    }

    /** The payout journal, while it stands. */
    public function payoutJournal(Loan $loan): ?Journal
    {
        $journal = app(PostLoanDisbursement::class)->existingJournal($loan);

        return $journal === null || $journal->hasBeenReversed() ? null : $journal;
    }

    /** @return list<Blocker> */
    private function cancelBlockers(Loan $loan): array
    {
        if ($loan->status === LoanStatus::Cancelled) {
            return [new Blocker('already_cancelled', "{$loan->reference} was already cancelled on ".($loan->cancelled_at?->format('j M') ?? 'an earlier day').'.')];
        }

        if ($loan->status !== LoanStatus::Active || ! $loan->isApplied()) {
            return [new Blocker('not_paid_out', "{$loan->reference} was not paid out, so there is no payout to cancel.")];
        }

        $recovered = $loan->installments()->where('status', '!=', LoanInstallmentStatus::Scheduled)->exists()
            || $loan->ledgerEntries()->where('entry_type', '!=', LoanLedgerEntryType::Disbursement)->exists();

        if ($recovered) {
            return [new Blocker('recovery_started', "Recovery of {$loan->reference} has started, so settle it instead.")];
        }

        $blockers = [];

        if (($month = $this->onClosedPayroll($loan)) !== null) {
            $blockers[] = new Blocker('on_closed_payroll', "An instalment of {$loan->reference} is on the {$month} payroll, which can no longer be recalculated, so settle it instead.");
        }

        $journal = app(PostLoanDisbursement::class)->existingJournal($loan);

        if ($journal !== null && DB::table('bank_statement_lines')->whereIn('matched_journal_line_id', $journal->lines()->select('id'))->exists()) {
            $blockers[] = new Blocker('bank_matched', 'Cancel: matched on the bank statement. Unmatch it first.', resolvableBy: 'accountant');
        }

        return $blockers;
    }

    /** The month of a payroll that is not voided, not recalculable, and takes an instalment. */
    private function onClosedPayroll(Loan $loan): ?string
    {
        return $this->payrollsTaking($loan)->first(fn (PayrollRun $run) => ! $run->status->isRerunnable() && ! $run->status->isVoided())?->period?->label();
    }

    /** The month of a payroll still being recalculated that takes an instalment. */
    private function onOpenPayroll(Loan $loan): ?string
    {
        return $this->payrollsTaking($loan)->first(fn (PayrollRun $run) => $run->status->isRerunnable())?->period?->label();
    }

    /** @return Collection<int, PayrollRun> */
    private function payrollsTaking(Loan $loan)
    {
        $sources = $loan->installments()->pluck('id')->map(fn ($id) => "loan_installment:{$id}")->all();

        if ($sources === []) {
            return collect();
        }

        return PayrollRun::query()
            ->with('period')
            ->whereIn('id', DB::table('payslips')
                ->join('payslip_lines', 'payslip_lines.payslip_id', '=', 'payslips.id')
                ->whereIn('payslip_lines.source', $sources)
                ->select('payslips.payroll_run_id'))
            ->get();
    }
}
