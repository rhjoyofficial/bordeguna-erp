<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * What the counter can sell right now: every active sellable product with the
 * quantity on hand across warehouses (active batches), searchable in the
 * browser. Operational; no reservations exist yet, so on hand is not "free".
 */
class StockLookupWidget extends Widget
{
    private const MAX_ROWS = 400;

    public function key(): string
    {
        return 'stock_lookup';
    }

    public function title(): string
    {
        return 'Stock lookup';
    }

    public function description(): ?string
    {
        return 'Sellable products and what is on hand across warehouses. Nothing is reserved for open orders yet.';
    }

    public function category(): string
    {
        return 'Stock';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Sales];
    }

    public function authorize(User $user): bool
    {
        return $user->canAny(['sales.sell', 'sales.orders.record', 'inventory.reports.view']);
    }

    public function size(): string
    {
        return 'md';
    }

    public function cacheSeconds(): int
    {
        return 30;
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return $context->user->can('inventory.reports.view') ? '/inventory/stock' : null;
    }

    public function data(WidgetContext $context): array
    {
        $onHand = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        $products = Product::query()
            ->where('is_active', true)
            ->where('type', ProductType::FinishedGood)
            ->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'name', 'sku', 'low_stock_threshold']);

        return [
            'kind' => 'lookup',
            'columns' => [
                ['key' => 'name', 'label' => 'Product'],
                ['key' => 'sku', 'label' => 'SKU'],
                ['key' => 'quantity', 'label' => 'On hand', 'align' => 'right', 'unit' => 'quantity'],
            ],
            'rows' => $products->map(function (Product $product) use ($onHand) {
                $quantity = (float) ($onHand[$product->id] ?? 0);

                return [
                    'id' => $product->id,
                    'tone' => $quantity <= 0 ? 'bad' : (Product::stockLevelIsLow($product->low_stock_threshold, $quantity) ? 'warn' : null),
                    'cells' => ['name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity],
                ];
            })->values()->all(),
            'total_count' => $products->count(),
            'basis' => 'operational',
        ];
    }
}
