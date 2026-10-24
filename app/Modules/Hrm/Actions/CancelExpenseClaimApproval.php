<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Corrections\ExpenseClaimActionPolicy;
use App\Modules\Hrm\Models\ExpenseClaim;
use App\Modules\Hrm\Models\ExpenseClaimLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): an approved, unpaid expense claim
 * had a typo (HRM plan S1: "only a database edit fixes it").
 *
 *   1. the approval journal (Dr the expense heads / Cr Employee
 *      Reimbursements Payable) is mirrored through ReverseJournal, on its own
 *      date while that month is open, else on the first open day (Owner);
 *   2. the approval request goes back to Returned, at stage 1, with a
 *      decision row that says it was an approval cancelled and why;
 *   3. the claim is the employee's again (`submitted_at` cleared, exactly as
 *      Send back does) and forgets its approval journal, so the next approval
 *      posts a fresh one.
 *
 * The claim keeps its number and every line. One transaction, the claim
 * re-read under a lock: a cancel and a payment at the same moment cannot both
 * win (SettleExpenseClaim locks the same row).
 */
class CancelExpenseClaimApproval
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly ExpenseClaimActionPolicy $policy,
    ) {}

    public function handle(ExpenseClaim $claim, User $by, ActionReason $why): ExpenseClaim
    {
        return DB::transaction(function () use ($claim, $by, $why) {
            $claim = ExpenseClaim::query()->withoutGlobalScope(BusinessScope::class)->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();
            $request = ApprovalRequest::query()->withoutGlobalScopes()
                ->where('approvable_type', $claim->getMorphClass())->where('approvable_id', $claim->getKey())
                ->lockForUpdate()->first();
            $claim->setRelation('approvalRequest', $request);

            $availability = $this->policy->availability($claim, $by, DocumentAction::Cancel);

            if (! $availability->allowed) {
                throw ValidationException::withMessages(['reason_text' => $availability->blockers[0]->message]);
            }

            $date = $this->policy->cancelDate($claim, $by);
            $reversal = null;

            if ($claim->approval_journal_id !== null) {
                $journal = Journal::query()->findOrFail($claim->approval_journal_id);

                try {
                    $reversal = $this->reverseJournal->handle($journal->load('lines'), $by, $date->date, "Approval of {$claim->claim_number} cancelled: {$why->display()}");
                } catch (PeriodClosedException $e) {
                    throw ValidationException::withMessages(['reason_text' => $e->getMessage()]);
                }
            }

            $request->decisions()->create([
                'stage_sequence' => $request->current_stage,
                'stage_name' => 'Approval cancelled',
                'decided_by' => $by->id,
                'decision' => ApprovalStatus::Returned,
                'note' => "Approval cancelled: {$why->display()}",
                'self_approved' => false,
                'decided_at' => now(),
            ]);

            $request->forceFill([
                'status' => ApprovalStatus::Returned,
                'current_stage' => 1,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_note' => "Approval cancelled: {$why->display()}",
            ])->save();

            $claim->forceFill(['submitted_at' => null, 'approval_journal_id' => null])->save();
            $this->foldScratchDrafts($claim);

            CorrectionLog::event('cancelled', $by, $claim, array_filter([
                'reason' => $why->display(),
                'what' => 'approval',
                'reversal_date' => $date?->date?->toDateString(),
                'reversal_journal' => $reversal?->journal_number,
            ], fn ($v) => $v !== null));

            return $claim->fresh();
        });
    }

    /**
     * ⚠⚠ ONE OPEN CLAIM PER EMPLOYEE (10 Sep 2026), as Send back keeps it
     * (ExpenseClaimController::consolidateOpenDrafts): spends added while this
     * claim was approved started a scratch draft, and now both would be open.
     * The scratch's lines (their receipts hang off the line) move onto THIS
     * claim, which carries the number and the decisions, and the emptied,
     * never-submitted shell goes.
     */
    private function foldScratchDrafts(ExpenseClaim $claim): void
    {
        $extras = ExpenseClaim::query()->withoutGlobalScope(BusinessScope::class)
            ->where('business_id', $claim->business_id)
            ->where('employee_id', $claim->employee_id)
            ->whereKeyNot($claim->getKey())
            ->whereNull('submitted_at')
            ->whereNull('settled_at')
            ->get();

        if ($extras->isEmpty()) {
            return;
        }

        foreach ($extras as $extra) {
            ExpenseClaimLine::query()->where('expense_claim_id', $extra->getKey())->update(['expense_claim_id' => $claim->getKey()]);
            $extra->approvalRequest?->delete();
            $extra->delete();
        }

        $claim->load('lines');
        $claim->syncFromLines();
    }
}
