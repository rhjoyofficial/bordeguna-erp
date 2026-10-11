<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales orders by where they stand. The open queues (awaiting approval, to
 * deliver, part delivered) are positions as at now; delivered and cancelled
 * count orders DATED in the range. Operational: orders entered in the ERP only.
 */
class OrdersByStateWidget extends Widget
{
    public function key(): string
    {
        return 'orders_by_state';
    }

    public function title(): string
    {
        return 'Orders by state';
    }

    public function description(): ?string
    {
        return 'Open queues as at now; delivered and cancelled by order date in the range. Orders entered in the ERP only.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager];
    }

    public function permission(): ?string
    {
        return 'sales.reports.view';
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
        return 15;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/orders';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::ORDER_CREATED, DashboardEventType::ORDER_APPROVED, DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'orders_today', 'sound' => 'success', 'message' => 'A new sales order came in.']];
    }

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $open = [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered];
        $approval = fn (ApprovalStatus $status) => fn (Builder $q) => $q->where('status', $status);
        $inRange = fn (Builder $q) => $q->whereBetween('ordered_at', [$context->range->fromDate(), $context->range->toDate()]);

        $awaiting = OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->whereIn('status', $open)->whereHas('approvalRequest', $approval(ApprovalStatus::Pending))->count();
        $sentBack = OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->whereIn('status', $open)->whereHas('approvalRequest', $approval(ApprovalStatus::Returned))->count();
        $toDeliver = OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->where('status', SalesOrderStatus::Open)->whereHas('approvalRequest', $approval(ApprovalStatus::Approved))->count();
        $partial = OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->where('status', SalesOrderStatus::PartiallyDelivered)->count();
        $delivered = $inRange(OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->whereIn('status', [SalesOrderStatus::Delivered, SalesOrderStatus::Closed]))->count();
        $cancelled = $inRange(OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->where('status', SalesOrderStatus::Cancelled))->count();

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'count',
            'items' => [
                ['label' => 'Awaiting approval', 'value' => $awaiting, 'tone' => $awaiting > 0 ? 'warn' : null, 'url' => '/sales/orders?status=open'],
                ['label' => 'Sent back', 'value' => $sentBack, 'tone' => $sentBack > 0 ? 'warn' : null],
                ['label' => 'To deliver', 'value' => $toDeliver, 'url' => '/sales/orders?status=open'],
                ['label' => 'Part delivered', 'value' => $partial, 'url' => '/sales/orders?status=partially_delivered'],
                ['label' => 'Delivered (range)', 'value' => $delivered, 'tone' => 'good'],
                ['label' => 'Cancelled (range)', 'value' => $cancelled, 'tone' => $cancelled > 0 ? 'bad' : null],
            ],
            'basis' => 'operational',
            'watch' => ['orders_today' => OrderBrand::onOrders(SalesOrder::query(), $context->brandId)->whereDate('ordered_at', today())->count()],
        ];
    }
}
