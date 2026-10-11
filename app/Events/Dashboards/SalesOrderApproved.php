<?php

namespace App\Events\Dashboards;

/**
 * A sales order was approved.
 * Carries only the order id (see DashboardBroadcast).
 */
class SalesOrderApproved extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $orderId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::ORDER_APPROVED, $businessId, ['order' => $orderId], $actorId, $userIds);
    }
}
