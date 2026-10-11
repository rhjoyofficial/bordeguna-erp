<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Approved orders still waiting to go out (open or part delivered), oldest
 * first, each linking to its order.
 */
class OrdersToDispatchWidget extends Widget
{
    public function key(): string
    {
        return 'orders_to_dispatch';
    }

    public function title(): string
    {
        return 'Orders awaiting dispatch';
    }

    public function description(): ?string
    {
        return 'Approved orders not yet fully delivered, oldest first. Operational.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Manager, DashboardKey::Owner];
    }

    public function permission(): ?string
    {
        return 'sales.reports.view';
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 30;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    public function defaultVisible(): bool
    {
        return true;
    }

    public function emptyText(): string
    {
        return 'Every approved order has gone out.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/orders?status=open';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::ORDER_APPROVED, DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'waiting', 'sound' => 'success', 'message' => 'An approved order is ready to dispatch.']];
    }

    public function data(WidgetContext $context): array
    {
        $orders = SalesOrder::query()
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->with('customer:id,name')
            ->orderBy('ordered_at')
            ->orderBy('id')
            ->get();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'number', 'label' => 'Order'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'age', 'label' => 'Ordered', 'align' => 'right'],
            ],
            'rows' => $orders->take(8)->map(fn (SalesOrder $order) => [
                'id' => $order->id,
                'url' => "/sales/orders/{$order->id}",
                'cells' => [
                    'number' => $order->order_number,
                    'customer' => $order->customer?->name,
                    'state' => $order->status === SalesOrderStatus::PartiallyDelivered ? 'Part delivered' : 'To deliver',
                    'age' => $order->ordered_at?->format('j M'),
                ],
            ])->values()->all(),
            'total_count' => $orders->count(),
            'basis' => 'operational',
            'watch' => ['waiting' => $orders->count()],
        ];
    }
}
