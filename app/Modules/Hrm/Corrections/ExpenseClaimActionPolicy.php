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
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an employee expense claim after it
 * was approved (FINAL-PLAN 3.4 and 4.2; HRM plan S1 and S2).
 *
 *   Approved, not paid   Cancel approval: the approval journal (Dr expense /
 *                        Cr Employee Reimbursements Payable) is mirrored and
 *                        the claim goes back to "Sent back for correction",
 *                        so the employee fixes it and sends it again. The
 *                        claim's approver.
 *   Paid                 Reverse payment, kept apart from fixing the claim:
 *                        the payment is undone and the claim is "Approved,
 *                        awaiting payment" again. The Owner.
 *                          paid now        its settlement journal is mirrored
 *                          legacy voucher  the expense voucher is reversed
 *                          with salary     the generated salary line is
 *                                          cancelled, while that month still
 *                                          takes inputs
 *
 * Both are actions the module runs itself (CancelExpenseClaimApproval,
 * ReverseExpenseClaimPayment), not engine corrections; the policy is
 * registered so the buttons, their blockers and the History tab come from one
 * place. Dates: HrmCorrectionDate.
 *
 * `effectProfile()` is EMPTY on purpose: the engine never runs this type (no
 * corrector is registered), and the guards a profile would name are the
 * policy's own blockers below, asked again by the actions under their lock
 * (a payment line on a bank statement, a salary month that no longer takes
 * inputs, a closed accounting month).
 */
class ExpenseClaimActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.expense_claim';

    public const PERMISSION = 'hrm.payroll.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return ExpenseClaim::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || Owners::is($user);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var ExpenseClaim $document */
        if ($document->isSettled()) {
            return DocumentStage::Done;
        }

        return match (true) {
            $document->isDraft() => DocumentStage::Draft,
            $document->isApproved() => DocumentStage::ApprovedNoEffect,
            default => DocumentStage::Waiting,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var ExpenseClaim $document */
        $actions = [];

        if ($this->canView($document, $user)) {
            if ($document->isSettled()) {
                $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
            } elseif ($document->isApproved()) {
                $actions[] = $this->availability($document, $user, DocumentAction::Cancel);
            }
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var ExpenseClaim $document */
        return match ($action) {
            DocumentAction::Cancel => $this->cancelApproval($document, $user),
            DocumentAction::Reverse => $this->reversePayment($document, $user),
            default => $this->make($action, [new Blocker('not_offered', "{$action->label()} is not offered on an expense claim.")], null),
        };
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->claim_number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('hrm.expense-claims.index');
    }

    /** The claim's approver: whoever the LAST stage of its workflow lets act. */
    public function isApproverOf(ExpenseClaim $claim, User $user): bool
    {
        $request = $claim->approvalRequest;
        $stage = $request?->workflow?->stageAt($request->totalStages());

        return $stage !== null ? $stage->allows($user) : ($user->can(self::PERMISSION) || Owners::is($user));
    }

    /** The live journal the "pay now" route posted for a booked claim, if any. */
    public static function settlementJournalOf(ExpenseClaim $claim): ?Journal
    {
        return Journal::query()
            ->where('source_type', $claim->getMorphClass())
            ->where('source_id', $claim->getKey())
            ->when($claim->approval_journal_id !== null, fn ($q) => $q->where('id', '!=', $claim->approval_journal_id))
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->orderByDesc('id')
            ->first();
    }

    /** Lands on: the approval journal's own date (open) or the first open day. */
    public function cancelDate(ExpenseClaim $claim, User $user): ?HrmCorrectionDate
    {
        $journal = $claim->approval_journal_id === null ? null : Journal::query()->find($claim->approval_journal_id);

        return $journal === null ? null : HrmCorrectionDate::for(Carbon::parse($journal->journal_date), $user, 'cancel an approval in');
    }

    /** Lands on: the payment's own date (open) or the first open day; null for a salary line. */
    public function reverseDate(ExpenseClaim $claim, User $user): ?HrmCorrectionDate
    {
        if ($claim->settlement_method === ExpenseClaim::SETTLE_PAYROLL) {
            return null;
        }

        $date = match (true) {
            $claim->voucher !== null => $claim->voucher->spent_on,
            default => ($j = self::settlementJournalOf($claim)) === null ? null : $j->journal_date,
        };

        return $date === null ? null : HrmCorrectionDate::for(Carbon::parse($date), $user, 'reverse a payment in');
    }

    private function cancelApproval(ExpenseClaim $claim, User $user): ActionAvailability
    {
        $action = DocumentAction::Cancel;
        $blockers = [];

        if (! $this->isApproverOf($claim, $user)) {
            $blockers[] = new Blocker('not_approver', 'Only the claim\'s approver can cancel its approval.');
        }

        if ($claim->isSettled()) {
            $blockers[] = new Blocker('already_paid', "{$claim->claim_number} is already paid, so reverse the payment first.");
        } elseif (! $claim->isApproved()) {
            $blockers[] = new Blocker('not_approved', "{$claim->claim_number} is not approved, so there is no approval to cancel.");
        }

        $date = $claim->isApproved() && ! $claim->isSettled() ? $this->cancelDate($claim, $user) : null;

        if ($claim->approval_journal_id !== null && $claim->isApproved() && ! $claim->isSettled()) {
            $journal = Journal::query()->find($claim->approval_journal_id);

            if ($journal === null || $journal->hasBeenReversed()) {
                $blockers[] = new Blocker('no_ledger_entry', 'This claim\'s approval entry is missing or already reversed, so ask the administrator to check it.');
            }
        }

        if ($date?->blocker !== null) {
            $blockers[] = $date->blocker;
        }

        return $this->make($action, $blockers, $date);
    }

    private function reversePayment(ExpenseClaim $claim, User $user): ActionAvailability
    {
        $action = DocumentAction::Reverse;
        $blockers = [];

        if (! Owners::is($user)) {
            $blockers[] = new Blocker('owner_only', 'Only the Owner can reverse a claim\'s payment.', resolvableBy: 'owner');
        }

        if (! $claim->isSettled()) {
            return $this->make($action, [...$blockers, new Blocker('not_paid', "{$claim->claim_number} is not paid, so there is no payment to reverse.")], null);
        }

        $date = null;

        if ($claim->settlement_method === ExpenseClaim::SETTLE_PAYROLL) {
            $adjustment = $claim->adjustment;
            $period = $adjustment?->period;

            if ($adjustment === null || $adjustment->isCancelled()) {
                $blockers[] = new Blocker('no_salary_line', 'This claim\'s salary line is missing or already cancelled, so ask the administrator to check it.');
            } elseif (! PayrollInputWindow::accepts($period)) {
                $blockers[] = new Blocker('salary_month_closed', "The {$period?->label()} payroll already took this claim, so it reached the employee with the salary and cannot be reversed here.");
            }
        } else {
            $date = $this->reverseDate($claim, $user);
            $journalIds = $this->paymentJournalIds($claim);

            if ($date === null || $journalIds === []) {
                $blockers[] = new Blocker('no_ledger_entry', 'This claim\'s payment entry is missing or already reversed, so ask the administrator to check it.');
            } elseif (DB::table('bank_statement_lines')->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $journalIds)->select('id'))->exists()) {
                $blockers[] = new Blocker('bank_matched', 'Reverse: matched on the bank statement. Unmatch it first.', resolvableBy: 'accountant');
            }

            if ($date?->blocker !== null) {
                $blockers[] = $date->blocker;
            }
        }

        return $this->make($action, $blockers, $date);
    }

    /** @return list<int> the live payment journal: the settlement journal, or the legacy voucher's */
    private function paymentJournalIds(ExpenseClaim $claim): array
    {
        if ($claim->voucher !== null) {
            if ($claim->voucher->isReversed()) {
                return [];
            }

            return Journal::query()
                ->where('source_type', $claim->voucher->getMorphClass())
                ->where('source_id', $claim->voucher->getKey())
                ->whereNull('reverses_journal_id')
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $journal = self::settlementJournalOf($claim);

        return $journal === null ? [] : [(int) $journal->id];
    }

    /** @param list<Blocker> $blockers */
    private function make(DocumentAction $action, array $blockers, ?HrmCorrectionDate $date): ActionAvailability
    {
        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: in_array($action, [DocumentAction::Cancel, DocumentAction::Reverse], true),
            requiresReason: true,
            reasonContext: $action === DocumentAction::Cancel ? 'cancellation' : 'reversal',
            approval: ApprovalRoute::None,
            date: $date?->date === null ? null : new DateRuleSummary('hrm', $date->sentence),
        );
    }
}
