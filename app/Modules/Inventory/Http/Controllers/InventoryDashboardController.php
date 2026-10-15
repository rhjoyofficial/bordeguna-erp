<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ExpiryWindow;
use App\Modules\Inventory\Support\OpenItems;
use App\Modules\Inventory\Support\StockValue;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function index(Request $request): Response
    {
        $this->authorize('inventory.reports.view');

        $today = Carbon::today();
        $user = $request->user();

        // ⭐ Redesign (15 Oct 2026): the warehouse chips narrow the page to one
        // warehouse. Only an ACTIVE warehouse's id is honoured; anything else
        // means "all", so a stale link never shows an empty page without saying.
        $activeWarehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $warehouseId = $request->integer('warehouse_id') ?: null;
        if ($warehouseId !== null && ! $activeWarehouses->contains('id', $warehouseId)) {
            $warehouseId = null;
        }

        // One grouped pass over active batches feeds the tiles, the warehouse
        // panel and the category bars - not one query per product.
        $allBatchSums = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity, SUM(current_quantity * unit_cost) as value')
            ->groupBy('product_id', 'warehouse_id')
            ->get();

        $batchSums = $warehouseId === null ? $allBatchSums : $allBatchSums->where('warehouse_id', $warehouseId)->values();

        // ⚠ I7. `number_format` is not decoration: SUM() over decimal columns
        // stringifies at full scale, and "19200.00000000" already shipped once.
        $totalValue = number_format((float) $batchSums->sum('value'), 2);

        $inStockProductIds = $batchSums->where('quantity', '>', 0)->pluck('product_id')->unique();

        $products = Product::stocked()->where('is_active', true)->get(['id', 'name', 'sku', 'category_id', 'low_stock_threshold', 'unit_of_measure_id']);
        $units = UnitOfMeasure::query()->pluck('abbreviation', 'id');

        // ⚠ Low stock is a product's TOTAL against its threshold (the Low stock
        // screen's rule), so it is never narrowed to one warehouse: a shop
        // counter holding 14 of a product with 200 in the main store is not low.
        $quantityByProduct = $allBatchSums->groupBy('product_id')->map(fn ($rows) => (float) $rows->sum('quantity'));

        $lowStock = $products
            ->filter(fn (Product $product) => ($quantityByProduct[$product->id] ?? 0.0) > 0
                && Product::stockLevelIsLow($product->low_stock_threshold, $quantityByProduct[$product->id] ?? 0.0))
            ->values();

        // ⚠ The expiry screen's own rule, each product's warning window, so the
        // strip's count, the "Needs you" row and the screen's list cannot
        // disagree (Slice 0, D7). Loaded once, used three times.
        $window = ExpiryWindow::batches($today);
        if ($warehouseId !== null) {
            $window = $window->where('warehouse_id', $warehouseId)->values();
        }
        [$expired, $expiring] = $window->partition(fn (StockBatch $batch) => ExpiryWindow::isExpired($batch, $today));

        return Inertia::render('inventory/dashboard', [
            'today' => $today->toDateString(),
            'total_skus' => Product::where('is_active', true)->count(),
            'in_stock_products' => $inStockProductIds->count(),
            'stocked_products' => $products->count(),
            'total_stock_value' => $totalValue,
            'low_stock_count' => $lowStock->count(),
            'low_stock_products' => $lowStock->take(5)->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $units[$product->unit_of_measure_id] ?? null,
                'current_quantity' => $quantityByProduct[$product->id] ?? 0.0,
                'low_stock_threshold' => (float) $product->low_stock_threshold,
            ])->values(),
            'categories' => $this->categoryDistribution($batchSums, $products),
            'warehouses' => $this->warehousePanel($allBatchSums, $activeWarehouses),
            'warehouse_id' => $warehouseId,
            'trend' => $this->movementTrend($today, $warehouseId),
            'suppliers' => $this->topSuppliers(),
            'recent_movements' => $this->recentMovements($warehouseId),
            'expiry' => [
                'expired' => $expired->count(),
                'expiring' => $expiring->count(),
            ],
            'expiring_soon' => $expired->concat($expiring)->take(5)->map(fn (StockBatch $batch) => [
                'id' => $batch->id,
                'product' => $batch->product?->name,
                'sku' => $batch->product?->sku,
                'batch_number' => $batch->batch_number,
                'warehouse' => $batch->warehouse?->name,
                'quantity' => (float) $batch->current_quantity,
                'expired' => ExpiryWindow::isExpired($batch, $today),
                // ⚠ Carbon diffInDays returns a float; cast where produced.
                'days' => (int) abs($today->diffInDays($batch->expiry_date, false)),
            ])->values(),
            // ⭐ "Needs you": the open documents the module already keeps, each
            // counted the way its own screen counts it (OpenItems). The expired
            // count reuses the collection loaded above: same rule, one query.
            'open_items' => [
                'stock_requests_waiting' => OpenItems::stockRequestsWaiting(),
                'transfers_in_transit' => OpenItems::transfersInTransit(),
                'counts_in_progress' => OpenItems::countsInProgress(),
                'draft_production_runs' => OpenItems::draftProductionRuns(),
                'expired_batches_on_shelf' => $expired->count(),
            ],
            // The runs list is a production.manage screen; the overview shows
            // the last few only to somebody who may open it.
            'last_runs' => $user->can('inventory.production.manage') ? $this->lastRuns() : null,
        ]);
    }

    /**
     * The last few production runs, as the runs screen lists them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lastRuns(): array
    {
        return ProductionRun::query()
            ->with(['outputProduct:id,name,sku'])
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (ProductionRun $run) => [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status->value,
                'output_product' => $run->outputProduct?->name,
                'output_quantity' => (float) $run->output_quantity,
                'consumed_value' => $run->consumed_value === null ? null : (float) $run->consumed_value,
                'waste_value' => $run->waste_value === null ? null : (float) $run->waste_value,
                'yield_flagged' => $run->yieldFlagged(),
                'occurred_at' => $run->occurred_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * Stock VALUE per category. Value rather than quantity, because taka are
     * summable across categories and quantities of mixed units are not.
     *
     * @param  Collection<int, StockBatch>  $batchSums
     * @param  Collection<int, Product>  $products
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
     * @param  Collection<int, StockBatch>  $batchSums
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<int, array<string, mixed>>
     */
    private function warehousePanel($batchSums, $warehouses): array
    {
        $byWarehouse = $batchSums->groupBy('warehouse_id');
        $total = (float) $batchSums->sum('value');

        return $warehouses
            ->map(function (Warehouse $warehouse) use ($byWarehouse, $total) {
                $rows = $byWarehouse->get($warehouse->id, collect());
                $value = (float) $rows->sum('value');

                return [
                    'id' => $warehouse->id,
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
    private function movementTrend(Carbon $today, ?int $warehouseId): array
    {
        $windowStart = $today->copy()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        // Aggregated in PHP so the month key stays database-agnostic; the
        // window is six months of a nine-person business, not a data lake.
        // ⚠ Valued through StockValue - THE definition. Reading value_delta
        // directly here would price every ordinary receipt at zero, since
        // only adjustments carry a delta (the exact defect C-IVL-1 fixed).
        $movements = StockMovement::query()
            ->where('occurred_at', '>=', $windowStart)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
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
    private function recentMovements(?int $warehouseId): array
    {
        return StockMovement::query()
            ->with(['product:id,name,sku,unit_of_measure_id', 'product.unitOfMeasure:id,abbreviation', 'warehouse:id,name'])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'occurred_at' => $movement->occurred_at->toDateString(),
                'product' => $movement->product?->name,
                'sku' => $movement->product?->sku,
                'unit' => $movement->product?->unitOfMeasure?->abbreviation,
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
}
