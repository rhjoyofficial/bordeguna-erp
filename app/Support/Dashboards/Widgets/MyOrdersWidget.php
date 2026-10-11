<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * The orders I entered in the range (today by default on the sales desk), with
 * their agreed value, compared with the previous period. Operational.
 */
class MyOrdersWidget extends Widget
{
    public function key(): string
    {
        return 'my_orders';
    }

    public function title(): string
    {
        return 'My orders';
    }

    public function description(): ?string
    {
        return 'Sales orders you entered in the range, by order date, and their agreed value. Operational.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Sales];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    public function authorize(User $user): bool
    {
        return $user->canAny(['sales.orders.record', 'sales.sell']);
    }

    public function size(): string
    {
        return 'md';
    }

    public function dateBasis(): ?string
    {
        return 'order date';
    }

    public function refreshSeconds(): int
    {
        return 30;
    }

    public function cacheSeconds(): int
    {
        return 10;
    }

    public function emptyText(): string
    {
        return 'No orders from you in this range yet.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/orders';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::ORDER_CREATED, DashboardEventType::ORDER_APPROVED];
    }

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $mine = fn ($range) => OrderBrand::onOrders(SalesOrder::query(), $context->brandId)
            ->where('created_by', $context->user->id)
            ->whereBetween('ordered_at', [$range->fromDate(), $range->toDate()]);

        $orders = $mine($context->range)->with('customer:id,name')->latest('ordered_at')->latest('id')->get();
        $previous = $mine($context->range->previous())->count();
        $value = $orders->sum(fn (SalesOrder $order) => (float) $order->agreedTotal());

        return [
            'kind' => 'list',
            'parts' => [
                ['label' => 'Orders', 'value' => $orders->count(), 'unit' => 'count', 'previous' => $previous],
                ['label' => 'Agreed value', 'value' => round($value, 2), 'unit' => 'money'],
            ],
            'columns' => [
                ['key' => 'number', 'label' => 'Order'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'total', 'label' => 'Value', 'align' => 'right', 'unit' => 'money'],
            ],
            'rows' => $orders->take(8)->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'url' => "/sales/orders/{$order->id}",
                'cells' => [
                    'number' => $order->order_number,
                    'customer' => $order->customer?->name,
                    'status' => ucwords(str_replace('_', ' ', $order->status->value)),
                    'total' => round((float) $order->agreedTotal(), 2),
                ],
            ])->values()->all(),
            'total_count' => $orders->count(),
            'basis' => 'operational',
        ];
    }
}
