<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvailableToSell;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The low-stocks screen (DreamsPOS gap INV-2): what needs reordering, and what
 * is already gone, with the per-warehouse picture on every row.
 *
 * ⚠ "Low" is decided by Product::stockLevelIsLow - the ONE definition the
 * inventory dashboard also uses. A product with no threshold set is simply
 * never low; this screen does not invent a default.
 *
 * v1 is a screen only: the DreamsPOS reference sends notify EMAILS, and mail
 * is off by policy here. An in-app daily notification can follow if Joy wants
 * one.
 */
class LowStockController extends Controller
{
    public function index(): Response
    {
        $this->authorize('inventory.reports.view');

        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // One grouped query for the whole screen, not one per product.
        $sums = StockBatch::query()
            ->where('status', StockBatchStatus::Active)
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->groupBy('product_id');

        // ⭐ B17 (25 Oct 2026, Q4 = A): what is left to sell once confirmed
        // orders and approved requests take what they hold. A product is LOW
        // when that falls to its line: 500 on the shelf with 450 promised is
        // 50 to sell, and the reorder cannot wait for the challan.
        $sellable = AvailableToSell::totals(null);

        $rows = Product::stocked()->where('is_active', true)
            ->with('category:id,name')
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($sums, $warehouses, $sellable) {
                $perWarehouse = $sums->get($product->id, collect());
                $total = (float) $perWarehouse->sum('quantity');
                $reserved = (float) ($sellable[$product->id]['reserved'] ?? 0);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'total' => $total,
                    'reserved' => $reserved,
                    'available' => $reserved > 0 ? (float) ($sellable[$product->id]['available'] ?? 0) : $total,
                    'threshold' => $product->low_stock_threshold !== null ? (float) $product->low_stock_threshold : null,
                    'by_warehouse' => $warehouses->map(fn (Warehouse $warehouse) => [
                        'name' => $warehouse->name,
                        'quantity' => (float) ($perWarehouse->firstWhere('warehouse_id', $warehouse->id)?->quantity ?? 0),
                    ])->filter(fn (array $cell) => $cell['quantity'] > 0)->values(),
                ];
            });

        return Inertia::render('inventory/low-stocks/index', [
            'low' => $rows
                ->filter(fn (array $row) => $row['total'] > 0
                    && Product::stockLevelIsLow($row['threshold'], $row['available']))
                ->values(),
            'out' => $rows->filter(fn (array $row) => $row['total'] <= 0)->values(),
            'without_threshold' => $rows->filter(fn (array $row) => $row['threshold'] === null && $row['total'] > 0)->count(),
        ]);
    }
}
