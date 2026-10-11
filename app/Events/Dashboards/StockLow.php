<?php

namespace App\Events\Dashboards;

/**
 * A product fell to or under its low-stock line.
 * Carries only the product id (see DashboardBroadcast).
 */
class StockLow extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $productId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::STOCK_LOW, $businessId, ['product' => $productId], $actorId, $userIds);
    }
}
