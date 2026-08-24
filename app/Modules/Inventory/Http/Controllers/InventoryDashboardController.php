<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inventory dashboard, rebuilt to the DreamsERP anatomy Joy chose
 * (INV-1) - real data only. Where the reference invents (capacity rings on
 * warehouses that never declared a capacity), this shows what is true instead
 * (each warehouse's share of stock value); where the data does not exist yet
 * (no purchases before cutover), the panel says WHY it is empty.
 *
 * ⚠ There is deliberately NO "total units" tile. Summing 125 g packs, litres
 * of oil and kilograms of salt into one number would be unit soup; the honest
 * quantity-flavoured fact is how many products actually have stock.
 */
class InventoryDashboardController extends Controller
{
    /** Months shown in the movement trend, current month included. */
    private const TREND_MONTHS = 6;

    public function index(): Response
    {
        $this->authorize('inventory.reports.view');

        $today = Carbon::today();

        // One grouped pass over active batches feeds the tiles, the warehouse
        // panel and the category bars - not one query per product.
        $batchSums = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity, SUM(current_quantity * unit_cost) as value')
            ->groupBy('product_id', 'warehouse_id')
            ->get();

        // ⚠ I7. `number_format` is not decoration: SUM() over decimal columns
        // stringifies at full scale, and "19200.00000000" already shipped once.
        $totalValue = number_format((float) $batchSums->sum('value'), 2);

        $inStockProductIds = $batchSums->where('quantity', '>', 0)->pluck('product_id')->unique();

        $products = Product::stocked()->where('is_active', true)->get(['id', 'name', 'sku', 'category_id', 'low_stock_threshold']);

        $quantityByProduct = $batchSums->groupBy('product_id')->map(fn ($rows) => (float) $rows->sum('quantity'));

        $lowStock = $products
            ->filter(fn (Product $product) => ($quantityByProduct[$product->id] ?? 0.0) > 0
                && Product::stockLevelIsLow($product->low_stock_threshold, $quantityByProduct[$product->id] ?? 0.0))
            ->values();

        return Inertia::render('inventory/dashboard', [
            'total_skus' => Product::where('is_active', true)->count(),
            'in_stock_products' => $inStockProductIds->count(),
            'stocked_products' => $products->count(),
            'total_stock_value' => $totalValue,
            'low_stock_count' => $lowStock->count(),
            'low_stock_products' => $lowStock->take(5)->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'current_quantity' => $quantityByProduct[$product->id] ?? 0.0,
                'low_stock_threshold' => (float) $product->low_stock_threshold,
            ])->values(),
            'categories' => $this->categoryDistribution($batchSums, $products),
            'warehouses' => $this->warehousePanel($batchSums),
            'trend' => $this->movementTrend($today),
            'suppliers' => $this->topSuppliers(),
            'recent_movements' => $this->recentMovements(),
            'expiry' => $this->expiryStrip($today),
        ]);
    }

    /**
     * Stock VALUE per category. Value rather than quantity, because taka are
     * summable across categories and quantities of mixed units are not.
     *
     * @param  \Illuminate\Support\Collection<int, StockBatch>  $batchSums
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function categoryDistribution($batchSums, $products): array
    {
        $categoryByProduct = $products->pluck('category_id', 'id');
        $names = Category::orderBy('name')->pluck('name', 'id');

        $byCategory = $batchSums
            ->filter(fn ($row) => (float) $row->value > 0)
            ->groupBy(fn ($row) => $categoryByProduct[$row->product_id] ?? 0)
            ->map(fn ($rows) => (float) $rows->sum('value'));

        $total = (float) $byCategory->sum();

        return $byCategory
            ->map(fn (float $value, $categoryId) => [
                'name' => $names[$categoryId] ?? 'Uncategorised',
                'value' => round($value, 2),
                'share' => $total > 0 ? round($value / $total * 100) : 0,
            ])
            ->sortByDesc('value')
            ->values()
            ->all();
    }

    /**
     * Each ACTIVE warehouse's stock value and its share of the whole - the
     * honest replacement for the reference's capacity rings, since no
     * warehouse here has ever declared a capacity.
     *
     * @param  \Illuminate\Support\Collection<int, StockBatch>  $batchSums
     * @return array<int, array<string, mixed>>
     */
    private function warehousePanel($batchSums): array
    {
        $byWarehouse = $batchSums->groupBy('warehouse_id');
        $total = (float) $batchSums->sum('value');

        return Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(function (Warehouse $warehouse) use ($byWarehouse, $total) {
                $rows = $byWarehouse->get($warehouse->id, collect());
                $value = (float) $rows->sum('value');

                return [
                    'name' => $warehouse->name,
                    'value' => round($value, 2),
                    'share' => $total > 0 ? round($value / $total * 100) : 0,
                    'products' => $rows->where('quantity', '>', 0)->pluck('product_id')->unique()->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Money in and out of stock per month, latest six. Value rather than
     * quantity (unit soup again), empty months drawn at ZERO - skipping them
     * invents a pattern.
     *
     * @return array<int, array<string, mixed>>
     */
    private function movementTrend(Carbon $today): array
    {
        $windowStart = $today->copy()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        // Aggregated in PHP so the month key stays database-agnostic; the
        // window is six months of a nine-person business, not a data lake.
        // ⚠ Valued through StockValue - THE definition. Reading value_delta
        // directly here would price every ordinary receipt at zero, since
        // only adjustments carry a delta (the exact defect C-IVL-1 fixed).
        $movements = StockMovement::query()
            ->where('occurred_at', '>=', $windowStart)
            ->get([...StockValue::columns(), 'occurred_at']);

        return collect(range(self::TREND_MONTHS - 1, 0))
            ->map(function (int $back) use ($today, $movements) {
                $month = $today->copy()->startOfMonth()->subMonths($back);
                $inMonth = $movements->filter(fn (StockMovement $movement) => $movement->occurred_at->isSameMonth($month));

                return [
                    'label' => $month->format('M'),
                    'in' => (float) StockValue::sum(
                        $inMonth->filter(fn (StockMovement $m) => $m->direction === MovementDirection::In)
                    )->toTaka(),
                    'out' => abs((float) StockValue::sum(
                        $inMonth->filter(fn (StockMovement $m) => $m->direction === MovementDirection::Out)
                    )->toTaka()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Top suppliers by the VALUE actually received from them - receipt lines
     * point at the stock movements they created, movements carry the value.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topSuppliers(): array
    {
        // Which movement came from which supplier, via the receipt chain.
        $lines = GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->join('purchase_orders', 'goods_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->get([
                'goods_receipt_lines.stock_movement_id',
                'goods_receipt_lines.goods_receipt_id',
                'suppliers.name as supplier_name',
            ]);

        if ($lines->isEmpty()) {
            return [];
        }

        // ⚠ Valued through StockValue, per movement (C-IVL-1): a receipt
        // movement carries no value_delta, so a SQL sum over that column
        // would price every supplier at zero.
        $movements = StockMovement::query()
            ->whereIn('id', $lines->pluck('stock_movement_id')->filter())
            ->get([...StockValue::columns(), 'id'])
            ->keyBy('id');

        return $lines
            ->groupBy('supplier_name')
            ->map(fn ($group, string $name) => [
                'name' => $name,
                'value' => abs((float) StockValue::sum(
                    $group->pluck('stock_movement_id')->filter()->map(fn ($id) => $movements->get($id))->filter()
                )->toTaka()),
                'receipts' => $group->pluck('goods_receipt_id')->unique()->count(),
            ])
            ->sortByDesc('value')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentMovements(): array
    {
        return StockMovement::query()
            ->with(['product:id,name,sku', 'warehouse:id,name'])
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'occurred_at' => $movement->occurred_at->toDateString(),
                'product' => $movement->product?->name,
                'sku' => $movement->product?->sku,
                'warehouse' => $movement->warehouse?->name,
                'direction' => $movement->direction->value,
                'reason' => str_replace('_', ' ', $movement->getAttributes()['reason'] ?? ''),
                'quantity' => (float) $movement->quantity,
                // Valued through THE definition; a raw value_delta is null on
                // every ordinary receipt and sale (C-IVL-1).
                'value' => abs((float) StockValue::of($movement)->toTaka()),
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function expiryStrip(Carbon $today): array
    {
        $held = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->whereNotNull('expiry_date');

        return [
            'expired' => (clone $held)->whereDate('expiry_date', '<', $today)->count(),
            'expiring' => (clone $held)
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(ExpiryController::SOON_DAYS))
                ->count(),
        ];
    }
}
