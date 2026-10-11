<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * My orders that need me: sent back to fix, still waiting for approval, or
 * approved and waiting to be delivered.
 */
class OrdersNeedingActionWidget extends Widget
{
    public function key(): string
    {
        return 'orders_needing_action';
    }

    public function title(): string
    {
        return 'Orders needing action';
    }

    public function description(): ?string
    {
        return 'Your open orders: sent back to you, waiting for approval, or approved and ready to deliver.';
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
        return 'None of your orders needs anything right now.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/orders?status=open';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::APPROVAL_DECIDED, DashboardEventType::ORDER_APPROVED];
    }

    public function notifications(): array
    {
        return [['watch' => 'sent_back', 'sound' => 'attention', 'message' => 'One of your orders was sent back to you.'],
            ['watch' => 'ready', 'sound' => 'success', 'message' => 'One of your orders was approved.']];
    }

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $orders = OrderBrand::onOrders(SalesOrder::query(), $context->brandId)
            ->where('created_by', $context->user->id)
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->with('customer:id,name')
            ->orderBy('ordered_at')
            ->get();

        $reason = function (SalesOrder $order): string {
            return match ($order->approvalRequest?->status) {
                ApprovalStatus::Returned => 'Sent back to you',
                ApprovalStatus::Pending => 'Waiting for approval',
                ApprovalStatus::Rejected => 'Rejected',
                default => $order->status === SalesOrderStatus::PartiallyDelivered ? 'Part delivered' : 'Ready to deliver',
            };
        };

        $rows = $orders->map(fn (SalesOrder $order) => ['order' => $order, 'reason' => $reason($order)])
            ->reject(fn ($row) => $row['reason'] === 'Rejected')
            ->sortBy(fn ($row) => match ($row['reason']) {
                'Sent back to you' => 0, 'Ready to deliver' => 1, 'Part delivered' => 2, default => 3,
            })
            ->values();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'number', 'label' => 'Order'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'reason', 'label' => 'What next'],
            ],
            'rows' => $rows->take(8)->map(fn ($row) => [
                'id' => $row['order']->id,
                'url' => "/sales/orders/{$row['order']->id}",
                'tone' => $row['reason'] === 'Sent back to you' ? 'bad' : null,
                'cells' => [
                    'number' => $row['order']->order_number,
                    'customer' => $row['order']->customer?->name,
                    'reason' => $row['reason'],
                ],
            ])->all(),
            'total_count' => $rows->count(),
            'basis' => 'operational',
            'watch' => [
                'sent_back' => $rows->where('reason', 'Sent back to you')->count(),
                'ready' => $rows->where('reason', 'Ready to deliver')->count(),
            ],
        ];
    }
}
