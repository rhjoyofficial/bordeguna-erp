<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\ExpiryWindow;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * Low, out of and expiring stock, by the same rules as the low-stock and expiry
 * screens (Product::stockLevelIsLow, ExpiryWindow), so the counts match them.
 */
class StockAlertsWidget extends Widget
{
    public function key(): string
    {
        return 'stock_alerts';
    }

    public function title(): string
    {
        return 'Stock to watch';
    }

    public function description(): ?string
    {
        return 'Products at or under their low-stock line, out of stock, and batches expired or inside their warning window. Operational.';
    }

    public function category(): string
    {
        return 'Stock';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager];
    }

    public function permission(): ?string
    {
        return 'inventory.reports.view';
    }

    public function size(): string
    {
        return 'md';
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/inventory/low-stocks';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::STOCK_LOW];
    }

    public function notifications(): array
    {
        return [['watch' => 'low', 'sound' => 'attention', 'message' => 'A product has reached its low-stock line.']];
    }

    public function data(WidgetContext $context): array
    {
        $totals = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id')
            ->pluck('quantity', 'product_id');

        $products = Product::stocked()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'low_stock_threshold']);

        $low = [];
        $out = 0;

        foreach ($products as $product) {
            $total = (float) ($totals[$product->id] ?? 0);
            $threshold = $product->low_stock_threshold !== null ? (float) $product->low_stock_threshold : null;

            if ($total <= 0) {
                $out++;
            } elseif (Product::stockLevelIsLow($threshold, $total)) {
                $low[] = ['id' => $product->id, 'name' => $product->name, 'quantity' => $total, 'threshold' => $threshold];
            }
        }

        $today = Carbon::today();
        $batches = ExpiryWindow::batches($today);
        $expired = $batches->filter(fn (StockBatch $batch) => ExpiryWindow::isExpired($batch, $today))->count();
        $expiring = $batches->count() - $expired;

        usort($low, fn ($a, $b) => ($a['quantity'] / max($a['threshold'] ?? 1, 0.0001)) <=> ($b['quantity'] / max($b['threshold'] ?? 1, 0.0001)));

        return [
            'kind' => 'list',
            'parts' => [
                ['label' => 'Low', 'value' => count($low), 'unit' => 'count', 'tone' => count($low) > 0 ? 'warn' : null, 'url' => '/inventory/low-stocks'],
                ['label' => 'Out of stock', 'value' => $out, 'unit' => 'count', 'tone' => $out > 0 ? 'bad' : null, 'url' => '/inventory/low-stocks'],
                ['label' => 'Expiring', 'value' => $expiring, 'unit' => 'count', 'tone' => $expiring > 0 ? 'warn' : null, 'url' => '/inventory/expiry'],
                ['label' => 'Expired', 'value' => $expired, 'unit' => 'count', 'tone' => $expired > 0 ? 'bad' : null, 'url' => '/inventory/expiry'],
            ],
            'columns' => [
                ['key' => 'name', 'label' => 'Product'],
                ['key' => 'quantity', 'label' => 'On hand', 'align' => 'right', 'unit' => 'quantity'],
                ['key' => 'threshold', 'label' => 'Line', 'align' => 'right', 'unit' => 'quantity'],
            ],
            'rows' => array_map(fn ($row) => [
                'id' => $row['id'],
                'url' => '/inventory/low-stocks',
                'cells' => ['name' => $row['name'], 'quantity' => $row['quantity'], 'threshold' => $row['threshold']],
            ], array_slice($low, 0, 6)),
            'total_count' => count($low),
            'basis' => 'operational',
            'watch' => ['low' => count($low), 'out' => $out],
        ];
    }
}
