<?php

namespace App\Events\Dashboards;

/**
 * Something was sent for approval.
 * Carries only the approval id (see DashboardBroadcast).
 */
class ApprovalRequested extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $approvalId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::APPROVAL_REQUESTED, $businessId, ['approval' => $approvalId], $actorId, $userIds);
    }
}
