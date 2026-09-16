<?php

use App\Modules\Assets\Http\Controllers\AssetCategoryController;
use App\Modules\Assets\Http\Controllers\AssetItemController;
use App\Modules\Assets\Http\Controllers\AssetLabelController;
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
        Route::post('parties', [AssetPartyController::class, 'store'])->name('parties.store');
        Route::put('parties/{party}', [AssetPartyController::class, 'update'])->whereNumber('party')->name('parties.update');
    });

    Route::middleware('can:assets.items.view')->group(function () {
        Route::get('items', [AssetItemController::class, 'index'])->name('items.index');
        Route::get('items/labels', [AssetLabelController::class, 'index'])->name('items.labels');
        Route::get('items/labels/print', [AssetLabelController::class, 'print'])->name('items.labels.print');
        // A scanned QR code. It opens the item, and changes nothing.
        Route::get('items/t/{tag}', [AssetItemController::class, 'byTag'])
            ->where('tag', '[A-Za-z0-9\-]+')->name('items.by-tag');
        Route::get('items/{item}', [AssetItemController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::get('categories', [AssetCategoryController::class, 'index'])->name('categories.index');
        Route::get('movements', [AssetMovementController::class, 'index'])->name('movements.index');
        Route::get('movements/{movement}', [AssetMovementController::class, 'show'])->whereNumber('movement')->name('movements.show');
        Route::get('parties', [AssetPartyController::class, 'index'])->name('parties.index');
    });
});

// My assets (slice A2): self-scope like My profile, no permission. Under /hrm
// so it sits with the other self-service tabs.
Route::middleware(['web', 'auth', 'business'])->prefix('hrm')->name('hrm.')->group(function () {
    Route::get('my-assets', [MyAssetsController::class, 'index'])->name('my-assets');
    Route::post('my-assets/{item}/acknowledge', [MyAssetsController::class, 'acknowledge'])->whereNumber('item')->name('my-assets.acknowledge');
});
