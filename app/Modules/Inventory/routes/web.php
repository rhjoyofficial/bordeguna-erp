<?php

use App\Modules\Inventory\Http\Controllers\BillOfMaterialController;
use App\Modules\Inventory\Http\Controllers\BinPlacementController;
use App\Modules\Inventory\Http\Controllers\BrandController;
use App\Modules\Inventory\Http\Controllers\CategoryController;
use App\Modules\Inventory\Http\Controllers\ExpiryController;
use App\Modules\Inventory\Http\Controllers\InventoryDashboardController;
use App\Modules\Inventory\Http\Controllers\LabelSheetController;
use App\Modules\Inventory\Http\Controllers\LowStockController;
use App\Modules\Inventory\Http\Controllers\OpeningStockController;
use App\Modules\Inventory\Http\Controllers\OpeningStockCountSheetController;
use App\Modules\Inventory\Http\Controllers\OpeningStockImportController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\ProductImportController;
use App\Modules\Inventory\Http\Controllers\ProductionRunController;
use App\Modules\Inventory\Http\Controllers\ProductionYieldController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockRequestController;
use App\Modules\Inventory\Http\Controllers\UnitConversionController;
use App\Modules\Inventory\Http\Controllers\UnitOfMeasureController;
use App\Modules\Inventory\Http\Controllers\WarehouseBinController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('/', [InventoryDashboardController::class, 'index'])->name('dashboard');

    // Report screens (DreamsPOS gaps INV-2/INV-3): what needs reordering, and
    // which batches are past or near their date. Both authorize
    // inventory.reports.view internally, like the dashboard above.
    Route::get('low-stocks', [LowStockController::class, 'index'])->name('low-stocks.index');
    Route::get('expiry', [ExpiryController::class, 'index'])->name('expiry.index');

    // Catalog viewing WAS open to the whole business ("needed to record stock
    // movements") - written before the zero-permission Self-service role
    // existed (24 Aug 2026), which turned "the whole business" into people
    // meant to reach only their own profile. Now gated with the same
    // reports.view every stock-recording role already holds; writes keep
    // their own inventory.catalog.manage gate below.
    Route::middleware('can:inventory.reports.view')->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
        Route::get('units', [UnitOfMeasureController::class, 'index'])->name('units.index');
        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        // C-BIN-4: bins reveal less than the stock screen already shows.
        Route::get('bins', [WarehouseBinController::class, 'index'])->name('bins.index');
    });

    // C-IMP-4. Importing is a different power from editing one row: replacing
    // three hundred products at once deserves its own permission, and its own
    // audit answer to "who did that".
    Route::middleware('can:inventory.catalogue.import')->group(function () {
        // ⚠ Products have carried a `barcode` column since 4 August and nothing
        // has ever rendered one. Same permission as the catalogue it prints
        // from, so no seeder change.
        Route::get('labels', [LabelSheetController::class, 'index'])->name('labels');
        Route::get('labels/print', [LabelSheetController::class, 'print'])->name('labels.print');
        Route::get('products/import', [ProductImportController::class, 'create'])->name('products.import');
        Route::post('products/import/preview', [ProductImportController::class, 'preview'])->name('products.import.preview');
        Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import.store');
    });

    // ⚠ Export is gated with VIEWING, not importing. Reading out what you can
    // already see on the products screen is not a privileged act, and locking
    // it would push people back to asking me for a file. "Viewing" now means
    // the same reports.view gate the products screen itself carries.
    Route::middleware('can:inventory.reports.view')->group(function () {
        Route::get('products/export', [ProductImportController::class, 'export'])->name('products.export');
    });

    // ⚠⚠ THE FOURTH UNREACHABLE ENGINE, closed 20 Aug. ProductUnitConversion has
    // been built, tested and deployed since 9 August with no route, no
    // controller and no caller for `record()` outside its own tests - so nobody
    // could tell the system that mustard oil is bought by the kilogram and
    // stocked by the litre, and every receipt for it would have thrown.
    // Gated with catalogue management: a conversion is master data about how a
    // product is bought, and it changes what every future receipt costs.
    Route::middleware('can:inventory.catalog.manage')->group(function () {
        Route::get('unit-conversions', [UnitConversionController::class, 'index'])->name('unit-conversions.index');
        Route::post('unit-conversions', [UnitConversionController::class, 'store'])->name('unit-conversions.store');
    });

    Route::middleware('can:inventory.catalog.manage')->group(function () {
        // C-SKU-2. Reads only, and writes nothing: it hands back a proposed SKU
        // for whoever is filling the form in. Gated with the rest of catalogue
        // management because it discloses the naming convention and whether a
        // code is already taken.
        //
        // ⚠⚠ A GET ON PURPOSE. EnsurePageBusinessMatches stamps and checks every
        // MUTATING request, and it does that through Inertia's own `before`
        // hook - which a hand-written fetch does not pass through. A POST here
        // would therefore be the one write-shaped request in the app that skips
        // the tenant assertion §13 exists to enforce. It reads, so it is safe,
        // so it is a GET, and the guard's own rule covers it: a stale GET shows
        // stale data and writes nothing.
        //
        // ⚠ Deliberately NOT a page. The reachability guard skips it on its own
        // because the controller declares a JsonResponse return type.
        Route::get('products/sku-proposal', [ProductController::class, 'skuProposal'])->name('products.sku-proposal');

        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        // Brands (DreamsPOS gap INV-4): the table existed since 8 Aug with only
        // a seeder writing to it, so Joy could not add a brand himself. Retire
        // by deactivation, never delete - products point at brands RESTRICT.
        Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
        Route::put('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
        Route::patch('brands/{brand}/toggle-active', [BrandController::class, 'toggleActive'])->name('brands.toggle-active');

        Route::post('units', [UnitOfMeasureController::class, 'store'])->name('units.store');
        Route::put('units/{unit}', [UnitOfMeasureController::class, 'update'])->name('units.update');
        Route::delete('units/{unit}', [UnitOfMeasureController::class, 'destroy'])->name('units.destroy');

        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::patch('warehouses/{warehouse}/toggle-active', [WarehouseController::class, 'toggleActive'])->name('warehouses.toggle-active');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');

        // WMS bins phase 1 (C-BIN-4): warehouse structure, the same power that
        // creates the warehouse around them. Nothing here moves stock.
        Route::post('bins', [WarehouseBinController::class, 'store'])->name('bins.store');
        Route::put('bins/{bin}', [WarehouseBinController::class, 'update'])->name('bins.update');
        Route::patch('bins/{bin}/toggle-active', [WarehouseBinController::class, 'toggleActive'])->name('bins.toggle-active');
        Route::delete('bins/{bin}', [WarehouseBinController::class, 'destroy'])->name('bins.destroy');
    });

    // Stock movements. Reading the stock book is gated like the catalog above
    // (same 24 Aug 2026 closure); the write routes keep their own gates.
    Route::middleware('can:inventory.reports.view')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    });
    // ⛔ C-FLOW-5: the generic 'receive stock' and 'record usage' routes are GONE.
    // They moved stock and posted NOTHING, so value entered and left the
    // warehouse without ever reaching the ledger. Stock now arrives through a
    // goods receipt or the opening stock document, and leaves through a sale or
    // an inventory adjustment - each of which knows its own accounting
    // consequence. See INVENTORY-ENTRY-POINTS-DESIGN.md.
    Route::middleware('can:inventory.stock.adjust')->group(function () {
        Route::get('opening-stock', [OpeningStockController::class, 'index'])->name('opening-stock.index');
        Route::post('opening-stock', [OpeningStockController::class, 'store'])->name('opening-stock.store');

        // C-IMP-8. The SAME permission as typing the document in by hand, not a
        // new import permission. The opening stock screen already posts a whole
        // warehouse in one submit, so a file is the same power through a
        // different door - and a new permission would mean a seeder in the
        // deploy of the week the count happens.
        Route::get('opening-stock/import', [OpeningStockImportController::class, 'create'])->name('opening-stock.import');
        Route::post('opening-stock/import/preview', [OpeningStockImportController::class, 'preview'])->name('opening-stock.import.preview');
        Route::post('opening-stock/import', [OpeningStockImportController::class, 'store'])->name('opening-stock.import.store');

        // Bin placement (C-BIN-4): the floor act that gives the pick list its
        // shelf address - the same hands that already move stock. It writes
        // `bin_id` and an activity row, never a movement or a journal.
        Route::get('bin-placement', [BinPlacementController::class, 'index'])->name('bin-placement.index');
        Route::patch('stock/batches/{batch}/bin', [BinPlacementController::class, 'update'])->name('stock.batches.bin');
    });
    // storeAdjustment/storeTransfer authorize inventory.stock.adjust internally
    Route::post('stock/adjustments', [StockController::class, 'storeAdjustment'])->name('stock.adjustments.store');
    Route::post('stock/adjustments/{adjustment}/approve', [StockController::class, 'approveAdjustment'])->name('stock.adjustments.approve');
    Route::post('stock/adjustments/{adjustment}/reject', [StockController::class, 'rejectAdjustment'])->name('stock.adjustments.reject');
    Route::post('stock/transfers', [StockController::class, 'storeTransfer'])->name('stock.transfers.store');
    Route::middleware('can:inventory.reports.view')->group(function () {
        Route::get('stock/transfers', [StockController::class, 'transfersIndex'])->name('stock.transfers.index');
    });
    // authorizes inventory.stock.adjust internally
    Route::post('stock/transfers/{stockTransfer}/receive', [StockController::class, 'receiveTransfer'])->name('stock.transfers.receive');

    // Slice D: the Bill of Materials (C-BOM). A recipe is master data about how
    // a product is MADE, which is a different power from adjusting stock and
    // from managing the catalogue - it decides what every future run suggests
    // consuming. Its own permission, seeded, Owner and Manager.
    //
    // ⚠⚠ Nothing behind this group moves stock or posts a journal. The
    // production run below remains the sixth and last sanctioned stock
    // document.
    Route::middleware('can:inventory.bom.manage')->group(function () {
        Route::get('bills-of-material', [BillOfMaterialController::class, 'index'])->name('bills-of-material.index');
        Route::post('bills-of-material', [BillOfMaterialController::class, 'store'])->name('bills-of-material.store');
        Route::put('bills-of-material/{billOfMaterial}', [BillOfMaterialController::class, 'update'])->name('bills-of-material.update');
        Route::post('bills-of-material/{billOfMaterial}/activate', [BillOfMaterialController::class, 'activate'])->name('bills-of-material.activate');
        Route::post('bills-of-material/{billOfMaterial}/deactivate', [BillOfMaterialController::class, 'deactivate'])->name('bills-of-material.deactivate');
        Route::delete('bills-of-material/{billOfMaterial}', [BillOfMaterialController::class, 'destroy'])->name('bills-of-material.destroy');
    });

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

        // The blank count sheet. A REPORT permission, not the opening stock
        // one: this reveals nothing the products screen does not already show
        // to everybody in the business, it writes nothing, and the person who
        // carries it around the warehouse is not the person allowed to post
        // the document it feeds.
        Route::get('opening-stock/count-sheet', [OpeningStockCountSheetController::class, 'show'])->name('opening-stock.count-sheet');
    });

    // Approval queue — index/store/approve/reject each authorize internally
    Route::get('stock-requests', [StockRequestController::class, 'index'])->name('stock-requests.index');
    Route::post('stock-requests', [StockRequestController::class, 'store'])->name('stock-requests.store');
    Route::post('stock-requests/{stockRequest}/approve', [StockRequestController::class, 'approve'])->name('stock-requests.approve');
    Route::post('stock-requests/{stockRequest}/reject', [StockRequestController::class, 'reject'])->name('stock-requests.reject');
});
