<?php

namespace App\Events\Dashboards;

/**
 * The generic dashboard event, for whatever has no class of its own: courier
 * code fires these later (parcel status changed, booking failed, sync error,
 * statement imported), and the personal events (my leave decided, my payslip
 * ready) go out through `personal()`.
 *
 *     DashboardSignal::business(DashboardEventType::BOOKING_FAILED, $businessId, ['parcel' => $id])->dispatchSafely();
 *
 * ⚠ Ids only, never amounts or names (DashboardBroadcast).
 */
class DashboardSignal extends DashboardBroadcast
{
    /** @param array<string, int|string> $ids */
    public static function business(string $type, int $businessId, array $ids = [], ?int $actorId = null, ?string $sound = null): self
    {
        return new self($type, $businessId, $ids, $actorId, [], true, $sound);
    }

    /**
     * Only to one person's own channel.
     *
     * @param  array<string, int|string>  $ids
     */
    public static function personal(string $type, int $businessId, int $userId, array $ids = [], ?int $actorId = null): self
    {
        return new self($type, $businessId, $ids, $actorId, [$userId], false);
    }
}
