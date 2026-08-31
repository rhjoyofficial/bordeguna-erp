<?php

namespace App\Http\Controllers;

use App\Modules\Foundation\Models\ApprovalRequest;
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
        abort_unless($actionable->isNotEmpty() || $canReviewLegacy, 403);

        $requests = $actionable->map(fn (ApprovalRequest $approvalRequest) => $cards->present($approvalRequest));

        return Inertia::render('approvals/index', [
            'requests' => $requests->values(),
            'counts' => $requests->groupBy('type')
                ->map(fn ($rows, $type) => ['label' => $type, 'count' => $rows->count()])
                ->values(),
        ]);
    }
}
