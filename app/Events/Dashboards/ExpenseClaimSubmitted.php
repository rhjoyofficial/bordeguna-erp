<?php

namespace App\Events\Dashboards;

/**
 * An expense claim was sent for approval.
 * Carries only the claim id (see DashboardBroadcast).
 */
class ExpenseClaimSubmitted extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $claimId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::CLAIM_SUBMITTED, $businessId, ['claim' => $claimId], $actorId, $userIds);
    }
}
