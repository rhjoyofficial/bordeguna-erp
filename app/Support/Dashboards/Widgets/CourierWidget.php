<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * What the courier widgets share (realtime everywhere, 11 Oct 2026): the
 * category, the events that refresh them at once, and the few definitions two
 * of them must agree on.
 *
 * ⚠⚠ EVERY COURIER STAYS ITS OWN ROW. Joy, 11 Oct 2026: each courier
 * company's balance is separate. A widget may total a stage, never merge two
 * couriers into one line.
 */
abstract class CourierWidget extends Widget
{
    /** A parcel sitting this many days in one open courier status is stuck. */
    public const STUCK_DAYS = 3;

    /** Courier words that mean "we should look at this parcel". */
    public const PROBLEM_STATUSES = [
        CourierStatus::DeliveryFailed, CourierStatus::OnHold, CourierStatus::Lost, CourierStatus::Damaged,
    ];

    /** Courier words that mean the courier has the customer's money. */
    public const COLLECTED_STATUSES = [CourierStatus::Delivered, CourierStatus::PartiallyDelivered];

    public function category(): string
    {
        return 'Couriers';
    }

    public function refreshOn(): array
    {
        return DashboardEventType::COURIER;
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/consignments';
    }

    /** @param list<CourierStatus> $statuses @return list<string> */
    protected static function values(array $statuses): array
    {
        return array_map(fn (CourierStatus $s) => $s->value, $statuses);
    }

    /** A decimal(15,4) sum from the database, as paisa. */
    protected static function minor(mixed $sum): int
    {
        return (int) round(((float) ($sum ?? 0)) * 100);
    }
}
