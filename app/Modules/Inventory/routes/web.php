<?php

use App\Modules\Inventory\Http\Controllers\CategoryController;
use App\Modules\Inventory\Http\Controllers\InventoryDashboardController;
use App\Modules\Inventory\Http\Controllers\OpeningStockController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\ProductionRunController;
use App\Modules\Inventory\Http\Controllers\ProductionYieldController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockRequestController;
use App\Modules\Inventory\Http\Controllers\UnitOfMeasureController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [InventoryDashboardController::class, 'index'])->name('dashboard');

    // Catalog: anyone in the business can view (needed to record stock movements);
    // only inventory.catalog.manage may create/edit/deactivate.
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('units', [UnitOfMeasureController::class, 'index'])->name('units.index');
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');

    Route::middleware('can:inventory.catalog.manage')->group(function () {
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::post('units', [UnitOfMeasureController::class, 'store'])->name('units.store');
        Route::put('units/{unit}', [UnitOfMeasureController::class, 'update'])->name('units.update');
        Route::delete('units/{unit}', [UnitOfMeasureController::class, 'destroy'])->name('units.destroy');

        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
    });

    // Stock movements
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    // ⛔ C-FLOW-5: the generic 'receive stock' and 'record usage' routes are GONE.
    // They moved stock and posted NOTHING, so value entered and left the
    // warehouse without ever reaching the ledger. Stock now arrives through a
    // goods receipt or the opening stock document, and leaves through a sale or
    // an inventory adjustment - each of which knows its own accounting
    // consequence. See INVENTORY-ENTRY-POINTS-DESIGN.md.
    Route::middleware('can:inventory.stock.adjust')->group(function () {
        Route::get('opening-stock', [OpeningStockController::class, 'index'])->name('opening-stock.index');
        Route::post('opening-stock', [OpeningStockController::class, 'store'])->name('opening-stock.store');
    });
    // storeAdjustment/storeTransfer authorize inventory.stock.adjust internally
    Route::post('stock/adjustments', [StockController::class, 'storeAdjustment'])->name('stock.adjustments.store');
    Route::post('stock/adjustments/{adjustment}/approve', [StockController::class, 'approveAdjustment'])->name('stock.adjustments.approve');
    Route::post('stock/adjustments/{adjustment}/reject', [StockController::class, 'rejectAdjustment'])->name('stock.adjustments.reject');
    Route::post('stock/transfers', [StockController::class, 'storeTransfer'])->name('stock.transfers.store');
    Route::get('stock/transfers', [StockController::class, 'transfersIndex'])->name('stock.transfers.index');
    // authorizes inventory.stock.adjust internally
    Route::post('stock/transfers/{stockTransfer}/receive', [StockController::class, 'receiveTransfer'])->name('stock.transfers.receive');

    // Manufacturing phase 1: the production run (C-MFG). One permission for
    // the whole document - D-MFG-4's one-step confirm by the authorised user.
    Route::middleware('can:inventory.production.manage')->group(function () {
        Route::get('production-runs', [ProductionRunController::class, 'index'])->name('production-runs.index');
        Route::get('production-runs/new', [ProductionRunController::class, 'create'])->name('production-runs.create');
        Route::post('production-runs', [ProductionRunController::class, 'store'])->name('production-runs.store');
        Route::get('production-runs/{run}', [ProductionRunController::class, 'show'])->name('production-runs.show');
        Route::post('production-runs/{run}/confirm', [ProductionRunController::class, 'confirm'])->name('production-runs.confirm');
        Route::post('production-runs/{run}/reverse', [ProductionRunController::class, 'reverse'])->name('production-runs.reverse');
        Route::delete('production-runs/{run}', [ProductionRunController::class, 'destroy'])->name('production-runs.destroy');
    });

    // The yield report reads like every other inventory report.
    Route::middleware('can:inventory.reports.view')->group(function () {
        Route::get('production-yield', [ProductionYieldController::class, 'index'])->name('production-yield');
    });

    // Approval queue — index/store/approve/reject each authorize internally
    Route::get('stock-requests', [StockRequestController::class, 'index'])->name('stock-requests.index');
    Route::post('stock-requests', [StockRequestController::class, 'store'])->name('stock-requests.store');
    Route::post('stock-requests/{stockRequest}/approve', [StockRequestController::class, 'approve'])->name('stock-requests.approve');
    Route::post('stock-requests/{stockRequest}/reject', [StockRequestController::class, 'reject'])->name('stock-requests.reject');
});
