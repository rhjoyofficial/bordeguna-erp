<?php

namespace App\Events\Dashboards;

/**
 * A customer payment was recorded.
 * Carries only the receipt id (see DashboardBroadcast).
 */
class CustomerPaymentReceived extends DashboardBroadcast
{
    /** @param list<int> $userIds */
    public static function for(int $businessId, int $receiptId, ?int $actorId = null, array $userIds = []): self
    {
        return new self(DashboardEventType::PAYMENT_RECEIVED, $businessId, ['receipt' => $receiptId], $actorId, $userIds);
    }
}
