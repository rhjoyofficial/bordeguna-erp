<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Attention\AttentionItem;
use App\Support\Attention\NeedsAttention;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * The top of Needs attention (plan H.1, row 1): the most urgent items this
 * person may see, from the same NeedsAttention the /attention page reads, so
 * the two never disagree.
 *
 * Personal by construction (each source answers the viewer's own permissions,
 * approvals are the viewer's own), so it is cached per user and never shared.
 */
class NeedsAttentionWidget extends Widget
{
    public const SHOWN = 10;

    public function __construct(private readonly NeedsAttention $attention) {}

    public function key(): string
    {
        return 'needs_attention';
    }

    public function title(): string
    {
        return 'Needs attention';
    }

    public function description(): ?string
    {
        return 'The most urgent things to act on, ranked by severity and age.';
    }

    public function category(): string
    {
        return 'Work';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance, DashboardKey::Manager];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    /** Follows the dashboard's Brand switcher for per-parcel lines. */
    public function brandAware(): bool
    {
        return true;
    }

    public function size(): string
    {
        return 'lg';
    }

    public function refreshSeconds(): int
    {
        return 30;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/attention';
    }

    public function emptyText(): string
    {
        return 'Nothing needs attention right now.';
    }

    public function refreshOn(): array
    {
        return [
            ...DashboardEventType::COURIER,
            DashboardEventType::APPROVAL_REQUESTED,
            DashboardEventType::APPROVAL_DECIDED,
            DashboardEventType::STOCK_LOW,
            DashboardEventType::PAYMENT_RECEIVED,
        ];
    }

    public function notifications(): array
    {
        return [['watch' => 'critical', 'sound' => 'error', 'message' => 'Something critical needs attention.']];
    }

    public function data(WidgetContext $context): array
    {
        ['items' => $items, 'failed' => $failed] = $this->attention->for($context->user, $context->businessId);

        // The dashboard's brand: parcels of that brand, plus whatever is about
        // the whole business (the same rule as /attention?brand=).
        $items = array_values(array_filter($items, fn (AttentionItem $item) => $item->matchesBrand($context->brandId)));

        $brands = NeedsAttention::brandsKnown() ? OrderBrand::names($context->businessId) : [];
        $bySeverity = fn (string $severity) => count(array_filter($items, fn (AttentionItem $item) => $item->severity === $severity));

        return [
            'kind' => 'attention',
            'items' => array_map(fn (AttentionItem $item) => $item->toArray() + [
                'brand' => $item->brandId === null ? null : ($brands[$item->brandId] ?? null),
            ], array_slice($items, 0, self::SHOWN)),
            'total_count' => count($items),
            'counts' => [
                AttentionItem::CRITICAL => $bySeverity(AttentionItem::CRITICAL),
                AttentionItem::HIGH => $bySeverity(AttentionItem::HIGH),
                AttentionItem::NORMAL => $bySeverity(AttentionItem::NORMAL),
            ],
            'failed' => $failed,
            'basis' => 'operational',
            'watch' => ['critical' => $bySeverity(AttentionItem::CRITICAL), 'total' => count($items)],
        ];
    }
}
