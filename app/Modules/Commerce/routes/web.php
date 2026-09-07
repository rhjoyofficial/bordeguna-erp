<?php

use App\Modules\Commerce\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

/**
 * Website management.
 *
 * ⚠ The same split every other module in this ERP uses: reading is one
 * permission, changing is another, and the one act that reaches the public has
 * its own. Gating the group rather than each method keeps the permission a
 * route can be read off this file rather than hunted for in a controller.
 */
Route::middleware(['web', 'auth', 'business'])->prefix('commerce')->name('commerce.')->group(function () {
    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
        Route::get('stores/{store}', [StoreController::class, 'edit'])
            ->whereNumber('store')->name('stores.edit');
    });

    Route::middleware('can:commerce.stores.manage')->group(function () {
        Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
        Route::put('stores/{store}', [StoreController::class, 'update'])
            ->whereNumber('store')->name('stores.update');
        Route::post('stores/{store}/domains', [StoreController::class, 'addDomain'])
            ->whereNumber('store')->name('stores.domains.store');
        Route::delete('stores/{store}/domains/{domain}', [StoreController::class, 'removeDomain'])
            ->whereNumber('store')->whereNumber('domain')->name('stores.domains.destroy');
    });

    // ⛔ Owner only. Publishing points a real domain at real software and starts
    // taking money from the public.
    Route::middleware('can:commerce.stores.publish')->group(function () {
        Route::post('stores/{store}/publish', [StoreController::class, 'publish'])
            ->whereNumber('store')->name('stores.publish');
        Route::post('stores/{store}/unpublish', [StoreController::class, 'unpublish'])
            ->whereNumber('store')->name('stores.unpublish');
    });
});
