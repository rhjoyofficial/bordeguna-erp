<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Purchasing\Models\LandedCost;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sends a draft for approval through the `landed_cost` workflow (the purchase
 * order family). Whoever may post it approves it on the spot, exactly as a
 * Manager's purchase order approves itself; otherwise the approvers are told.
 *
 * ⚠ The allocation is planned FIRST and a plan with refusals is not submitted:
 * an approver should never be asked to approve a document that cannot post.
 */
class SubmitLandedCost
{
    public function __construct(
        private readonly PlanLandedCostAllocation $planner,
        private readonly ApproveLandedCost $approve,
    ) {}

    public function handle(LandedCost $landedCost, User $submittedBy): LandedCost
    {
        if (! $landedCost->isEditable()) {
            throw new RuntimeException("Landed cost {$landedCost->number} has already been sent.");
        }

        $plan = $this->planner->handle($landedCost);

        if (! $plan->isPostable()) {
            throw new RuntimeException(implode(' ', $plan->refusals));
        }

        return DB::transaction(function () use ($landedCost, $submittedBy) {
            $landedCost->resubmitForApproval($submittedBy);
            $landedCost = $landedCost->fresh(['approvalRequest', 'lines']);

            if ($submittedBy->can('purchasing.landed_cost.post')) {
                return $this->approve->handle($landedCost, $submittedBy, 'Within the submitter\'s own authority, so approved as it was raised.');
            }

            DB::afterCommit(fn () => app(NotifyApprovers::class)->handle(
                businessId: (int) $landedCost->business_id,
                permission: 'purchasing.landed_cost.post',
                summary: "Landed cost {$landedCost->number} on goods receipt #{$landedCost->goods_receipt_id} waits for approval",
                excludeUserId: $submittedBy->id,
            ));

            return $landedCost;
        });
    }
}
