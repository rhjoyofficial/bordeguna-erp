<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Foundation\Actions\WithdrawApprovalRequest;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Foundation\Models\ApprovalWorkflowStage;
use App\Modules\Purchasing\Support\CorrectionScreen;
use App\Support\ApprovalCards;
use App\Support\ApprovalInbox;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The common approval inbox: one page listing every pending request the current
 * user may act on, across modules. Actionability (and the stage-aware "who")
 * comes from ApprovalInbox so this screen and the sidebar badge never disagree;
 * the decision-grade payload of each row comes from ApprovalCards, where a new
 * consumer adds its case (and the guard test makes sure it does).
 */
class ApprovalInboxController extends Controller
{
    public function index(Request $request, ApprovalInbox $inbox, ApprovalCards $cards): Response
    {
        $user = $request->user();
        $actionable = $inbox->pendingActionableFor($user);

        // Legacy approvers may always open the inbox; anyone else only once they
        // actually have something to act on (mirrors canReviewApprovals).
        $canReviewLegacy = $user->can('inventory.stock.approve') || $user->can('purchasing.po.approve');
        // ⚠ Bug hunt 16 Oct 2026: a Finance or HR approver who cleared the last
        // item was sent back here and met a 403 (and so did one watching the
        // page while a colleague decided it). Anyone an approval stage admits
        // gets the empty inbox; somebody no stage admits is still refused.
        // ⭐ Corrections wave 2 (24 Oct 2026): what this person SENT that still
        // waits, with Withdraw (or why not). A sender with nothing to approve
        // may open the page for these alone.
        $mine = ApprovalRequest::query()
            ->where('requested_by', $user->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->with('approvable')
            ->latest('id')
            ->limit(50)
            ->get();

        abort_unless($actionable->isNotEmpty() || $mine->isNotEmpty() || $canReviewLegacy || $this->anyStageAdmits($user), 403);

        $requests = $actionable->map(fn (ApprovalRequest $approvalRequest) => $cards->present($approvalRequest));

        return Inertia::render('approvals/index', [
            'requests' => $requests->values(),
            'counts' => $requests->groupBy('type')
                ->map(fn ($rows, $type) => ['label' => $type, 'count' => $rows->count()])
                ->values(),
            'mine' => $mine->filter(fn (ApprovalRequest $r) => $r->approvable !== null)->map(function (ApprovalRequest $r) use ($cards, $user) {
                $card = $cards->present($r);

                return [
                    'id' => $r->id,
                    'type' => $card['type'] ?? '',
                    'summary' => $card['summary'] ?? '',
                    'view_url' => $card['view_url'] ?? null,
                    'requested_at' => $r->created_at?->toDateTimeString(),
                    'withdraw_blocked' => WithdrawApprovalRequest::whyNot($r, $user),
                    'withdraw_url' => route('approvals.withdraw', $r),
                ];
            })->values(),
            'withdrawalReasons' => CorrectionScreen::reasons('withdrawal'),
        ]);
    }

    private function anyStageAdmits(User $user): bool
    {
        return ApprovalWorkflowStage::query()
            ->whereHas('workflow', fn ($query) => $query->where('is_active', true))
            ->get()
            ->contains(fn (ApprovalWorkflowStage $stage) => $stage->allows($user));
    }

    /**
     * Where a decided request goes (Joy, 28 Sep 2026: "after the approval where
     * the[y go], so the authority can see them"). The inbox only holds what is
     * still pending; this is the read-only record of what has been settled -
     * approved, rejected or handed back - and who settled it, for anyone entitled
     * to review that kind of request.
     */
    public function history(Request $request, ApprovalInbox $inbox, ApprovalCards $cards): Response
    {
        $user = $request->user();

        // The tabs, and what each one means in ApprovalStatus terms.
        $filters = [
            'all' => [ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Returned],
            'approved' => [ApprovalStatus::Approved],
            'rejected' => [ApprovalStatus::Rejected],
            'returned' => [ApprovalStatus::Returned],
        ];

        $active = in_array($request->query('status'), ['approved', 'rejected', 'returned'], true)
            ? $request->query('status')
            : 'all';

        $canReviewLegacy = $user->can('inventory.stock.approve') || $user->can('purchasing.po.approve');

        $decided = $inbox->decidedVisibleFor($user, $filters[$active]);

        // ⚠ Not the pending inbox's gate: someone may have decisions to look back
        // on without anything pending right now. Show the page to anyone who can
        // see at least one decided request, or who is a legacy approver.
        // ⭐ Round 3 (16 Oct 2026): History is now a tab beside Approvals with
        // the same gate (canReviewApprovals), so somebody whose first request
        // is waiting on them, and who has decided nothing yet, gets the empty
        // page rather than a 403 from a tab they were shown.
        abort_unless($decided->isNotEmpty() || $canReviewLegacy || $active !== 'all' || $inbox->countFor($user) > 0, 403);

        $requests = $decided->map(function (ApprovalRequest $approvalRequest) use ($cards) {
            $card = $cards->present($approvalRequest);

            // History is read-only: strip the action affordances the inbox card
            // carries, and state the outcome instead.
            return array_merge($card, [
                'approve_url' => null,
                'reject_url' => null,
                'send_back_url' => null,
                'status' => $approvalRequest->status->value,
                'reviewed_by' => $approvalRequest->reviewedBy?->name,
                'reviewed_at' => $approvalRequest->reviewed_at?->format('j M Y, g:i a'),
                'review_note' => $approvalRequest->review_note,
            ]);
        });

        return Inertia::render('approvals/history', [
            'requests' => $requests->values(),
            'active' => $active,
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'approved', 'label' => 'Approved'],
                ['key' => 'rejected', 'label' => 'Rejected'],
                ['key' => 'returned', 'label' => 'Sent back'],
            ],
        ]);
    }
}
