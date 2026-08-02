<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use Inertia\Inertia;
use Inertia\Response;

class InventoryDashboardController extends Controller
{
    public function index(): Response
    {
        $this->authorize('inventory.reports.view');

        $stockedProducts = Product::stocked()->where('is_active', true)->get();

        // ⚠ I7. `number_format` is not decoration here. SUM() over decimal
        // columns stringifies at full scale, which is how the main dashboard
        // came to read "Stock value 19200.00000000" on 2026-07-29. That instance
        // was fixed in DashboardController and this identical one was left
        // standing, so the bug kept shipping on the Inventory dashboard.
        //
        // ⚠ Any aggregate over a decimal column that reaches a user needs
        // explicit formatting. Fourth appearance of this pattern on this project.
        $totalStockValue = number_format((float) (StockBatch::where('status', StockBatchStatus::Active)
            ->selectRaw('SUM(current_quantity * unit_cost) as value')
            ->value('value') ?? 0), 2);

        $lowStockProducts = $stockedProducts->filter->isBelowLowStockThreshold()->values();

        return Inertia::render('inventory/dashboard', [
            'total_skus' => Product::where('is_active', true)->count(),
            'low_stock_count' => $lowStockProducts->count(),
            'low_stock_products' => $lowStockProducts->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'current_quantity' => $product->currentStockQuantity(),
                'low_stock_threshold' => $product->low_stock_threshold,
            ])->values(),
            'total_stock_value' => $totalStockValue,
        ]);
    }
}
