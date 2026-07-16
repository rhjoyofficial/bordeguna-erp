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

        $totalStockValue = StockBatch::where('status', StockBatchStatus::Active)
            ->selectRaw('SUM(current_quantity * unit_cost) as value')
            ->value('value') ?? '0';

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
