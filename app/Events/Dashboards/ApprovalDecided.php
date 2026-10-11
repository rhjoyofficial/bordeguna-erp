<?php

namespace App\Events\Dashboards;

/**
 * An approval was decided (the requester also hears it on their own channel).
 * Carries only the approval id (see DashboardBroadcast).
 */
class ApprovalDecided extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $approvalId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::APPROVAL_DECIDED, $businessId, ['approval' => $approvalId], $actorId, $userIds);
    }
}
