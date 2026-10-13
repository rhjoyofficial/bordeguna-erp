<?php

use App\Modules\Assets\Http\Controllers\AssetCategoryController;
use App\Modules\Assets\Http\Controllers\AssetCountController;
use App\Modules\Assets\Http\Controllers\AssetImportController;
use App\Modules\Assets\Http\Controllers\AssetItemController;
use App\Modules\Assets\Http\Controllers\AssetLabelController;
use App\Modules\Assets\Http\Controllers\AssetMaintenanceController;
use App\Modules\Assets\Http\Controllers\AssetMaintenancePlanController;
use App\Modules\Assets\Http\Controllers\AssetMovementController;
use App\Modules\Assets\Http\Controllers\AssetPartyController;
use App\Modules\Assets\Http\Controllers\DepreciationController;
use App\Modules\Assets\Http\Controllers\FixedAssetController;
use App\Modules\Assets\Http\Controllers\MyAssetsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'business'])->prefix('assets')->name('assets.')->group(function () {
    // ⚠ Reading the register is a permission of its own rather than open to the
    // whole business, unlike purchase orders. What the business owns and what it
    // paid for it is closer to the payroll register than to a stock list.
    Route::middleware('can:assets.view')->group(function () {
        Route::get('/', [FixedAssetController::class, 'index'])->name('index');
        Route::get('depreciation', [DepreciationController::class, 'index'])->name('depreciation.index');
    });

    Route::post('/', [FixedAssetController::class, 'store'])
        ->middleware('can:assets.manage')->name('store');

    // ⚠⚠ Owner only. One run posts cost against EVERY asset at once and cannot
    // be undone by running it again - the idempotence guard makes a second run
    // harmless, not reversible.
    Route::post('depreciation', [DepreciationController::class, 'store'])
        ->middleware('can:assets.depreciation.run')->name('depreciation.store');

    // ⭐ The PHYSICAL register (asset management slice A1, 16 September 2026):
    // what the business keeps, where it is, what shape it is in. Its own
    // permissions, so an Asset Keeper keeps the things without seeing a price.
    //
    // ⚠ Literal segments (create, labels, t/{tag}) register before the
    // `{item}` wildcard.
    Route::middleware('can:assets.items.manage')->group(function () {
        Route::get('items/create', [AssetItemController::class, 'create'])->name('items.create');
        // The things sheet (bulk import, 13 Oct 2026): template, preview, apply.
        // ⚠ A browser that reloads or goes back after a preview lands on the
        // GET of the preview URL: sent to the import page with any message
        // kept, never on a 405 (the catalogue importer's lesson, 29 Sep 2026).
        Route::get('items/import', [AssetImportController::class, 'create'])->name('items.import');
        Route::get('items/import/template', [AssetImportController::class, 'template'])->name('items.import.template');
        Route::get('items/import/preview', fn () => tap(redirect()->route('assets.items.import'), fn () => session()->reflash()))->name('items.import.preview.back');
        Route::post('items/import/preview', [AssetImportController::class, 'preview'])->name('items.import.preview');
        Route::post('items/import', [AssetImportController::class, 'store'])->name('items.import.store');
        Route::post('items', [AssetItemController::class, 'store'])->name('items.store');
        Route::get('items/{item}/edit', [AssetItemController::class, 'edit'])->whereNumber('item')->name('items.edit');
        Route::put('items/{item}', [AssetItemController::class, 'update'])->whereNumber('item')->name('items.update');
        Route::post('items/{item}/photos', [AssetItemController::class, 'storePhotos'])->whereNumber('item')->name('items.photos.store');
        Route::delete('items/{item}/photos/{attachment}', [AssetItemController::class, 'destroyPhoto'])
            ->whereNumber(['item', 'attachment'])->name('items.photos.destroy');
        Route::post('categories', [AssetCategoryController::class, 'store'])->name('categories.store');
        Route::put('categories/{category}', [AssetCategoryController::class, 'update'])->whereNumber('category')->name('categories.update');

        // Hand-overs (slice A2). Never edited: a mistake is another movement.
        Route::get('movements/create', [AssetMovementController::class, 'create'])->name('movements.create');
        Route::post('movements', [AssetMovementController::class, 'store'])->name('movements.store');
        Route::post('movements/{movement}/receive', [AssetMovementController::class, 'receive'])->whereNumber('movement')->name('movements.receive');
        // Stock-takes (slice A3). Confirming a loss is checked inside the action
        // (assets.items.write_off), since the same route settles every gap.
        Route::post('counts', [AssetCountController::class, 'store'])->name('counts.store');
        Route::post('counts/{count}/mark', [AssetCountController::class, 'mark'])->whereNumber('count')->name('counts.mark');
        Route::post('counts/{count}/found', [AssetCountController::class, 'addFound'])->whereNumber('count')->name('counts.found');
        Route::post('counts/{count}/close', [AssetCountController::class, 'close'])->whereNumber('count')->name('counts.close');
        Route::post('discrepancies/{discrepancy}/resolve', [AssetCountController::class, 'resolve'])->whereNumber('discrepancy')->name('discrepancies.resolve');
        // Maintenance (slice A4). Records are never edited once done or
        // cancelled; only their money changes, and that route is below.
        Route::get('maintenance/create', [AssetMaintenanceController::class, 'create'])->name('maintenance.create');
        Route::post('maintenance', [AssetMaintenanceController::class, 'store'])->name('maintenance.store');
        Route::post('maintenance/{record}/complete', [AssetMaintenanceController::class, 'complete'])->whereNumber('record')->name('maintenance.complete');
        Route::post('maintenance/{record}/cancel', [AssetMaintenanceController::class, 'cancel'])->whereNumber('record')->name('maintenance.cancel');
        Route::post('maintenance/{record}/photos', [AssetMaintenanceController::class, 'storePhotos'])->whereNumber('record')->name('maintenance.photos.store');
        Route::get('maintenance/plans/create', [AssetMaintenancePlanController::class, 'create'])->name('maintenance.plans.create');
        Route::post('maintenance/plans', [AssetMaintenancePlanController::class, 'store'])->name('maintenance.plans.store');
        Route::get('maintenance/plans/{plan}/edit', [AssetMaintenancePlanController::class, 'edit'])->whereNumber('plan')->name('maintenance.plans.edit');
        Route::put('maintenance/plans/{plan}', [AssetMaintenancePlanController::class, 'update'])->whereNumber('plan')->name('maintenance.plans.update');
        Route::post('maintenance/plans/{plan}/active', [AssetMaintenancePlanController::class, 'setActive'])->whereNumber('plan')->name('maintenance.plans.active');
        Route::post('parties', [AssetPartyController::class, 'store'])->name('parties.store');
        Route::put('parties/{party}', [AssetPartyController::class, 'update'])->whereNumber('party')->name('parties.update');
    });

    Route::middleware('can:assets.items.view')->group(function () {
        Route::get('items', [AssetItemController::class, 'index'])->name('items.index');
        Route::get('items/labels', [AssetLabelController::class, 'index'])->name('items.labels');
        Route::get('items/labels/print', [AssetLabelController::class, 'print'])->name('items.labels.print');
        // ⚠ Export is gated with VIEWING, not importing: reading out what you
        // can already see. Prices leave only with `assets.view`.
        Route::get('items/export', [AssetImportController::class, 'export'])->name('items.export');
        // A scanned QR code. It opens the item, and changes nothing.
        Route::get('items/t/{tag}', [AssetItemController::class, 'byTag'])
            ->where('tag', '[A-Za-z0-9\-]+')->name('items.by-tag');
        Route::get('items/{item}', [AssetItemController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::get('categories', [AssetCategoryController::class, 'index'])->name('categories.index');
        Route::get('movements', [AssetMovementController::class, 'index'])->name('movements.index');
        Route::get('movements/{movement}', [AssetMovementController::class, 'show'])->whereNumber('movement')->name('movements.show');
        Route::get('parties', [AssetPartyController::class, 'index'])->name('parties.index');
        Route::get('counts', [AssetCountController::class, 'index'])->name('counts.index');
        Route::get('counts/{count}', [AssetCountController::class, 'show'])->whereNumber('count')->name('counts.show');
        Route::get('maintenance', [AssetMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('maintenance/{record}', [AssetMaintenanceController::class, 'show'])->whereNumber('record')->name('maintenance.show');
    });

    // ⚠ What maintenance cost is money: the financial register's readers only
    // (plan §2.14). The action checks again.
    Route::post('maintenance/{record}/money', [AssetMaintenanceController::class, 'money'])
        ->middleware('can:assets.view')->whereNumber('record')->name('maintenance.money');
});

// My assets (slice A2): self-scope like My profile, no permission. Under /hrm
// so it sits with the other self-service tabs.
Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    Route::get('my-assets', [MyAssetsController::class, 'index'])->name('my-assets');
    Route::post('my-assets/{item}/acknowledge', [MyAssetsController::class, 'acknowledge'])->whereNumber('item')->name('my-assets.acknowledge');
    // Slice A4: the holder says something is wrong with it.
    Route::post('my-assets/{item}/report', [MyAssetsController::class, 'report'])->whereNumber('item')->name('my-assets.report');
});
