<?php

use App\Modules\Commerce\Http\Controllers\PageController;
use App\Modules\Commerce\Http\Controllers\PageSectionController;
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

    /* -------------------------------------------------------------------- */
    /* Pages and the section composer (slice 1.4) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('pages', [PageController::class, 'index'])->name('pages.index');
        Route::get('pages/{page}', [PageController::class, 'edit'])
            ->whereNumber('page')->name('pages.edit');
    });

    /**
     * ⚠⚠ CONTENT IS `commerce.content.manage`, WHICH STAFF HOLD, AND THAT
     * INCLUDES PUBLISHING A PAGE. This is a deliberate departure from the store
     * rule two blocks up, and the reason is measured. Taking a STORE live points
     * a real domain at real software and starts taking money, so it is the
     * Owner's. Publishing a PAGE is editorial, and gating it on the Owner
     * recreates exactly the bottleneck the shops are stuck in today: three
     * finished articles have sat unpublished for days, `/blog` renders zero
     * articles, and the whole GEO layer built for them is doing nothing,
     * because publishing needed somebody who was busy.
     *
     * ⛔ The safety here is not WHO presses the button, it is WHAT the button
     * refuses: a page cannot be published while any visible section is
     * incomplete, malformed, of an unknown type, or unrenderable by the store's
     * theme. That gate does not get tired or go on holiday.
     */
    Route::middleware('can:commerce.content.manage')->group(function () {
        Route::post('pages', [PageController::class, 'store'])->name('pages.store');
        Route::put('pages/{page}', [PageController::class, 'update'])
            ->whereNumber('page')->name('pages.update');
        Route::delete('pages/{page}', [PageController::class, 'destroy'])
            ->whereNumber('page')->name('pages.destroy');

        Route::post('pages/{page}/publish', [PageController::class, 'publish'])
            ->whereNumber('page')->name('pages.publish');
        Route::post('pages/{page}/unpublish', [PageController::class, 'unpublish'])
            ->whereNumber('page')->name('pages.unpublish');

        // ⚠ `reorder` is declared BEFORE the {section} routes. It is a POST to a
        // literal path and the others are number-constrained, so they cannot
        // collide today, but the ordering is kept explicit: this ERP has already
        // lost a day to a duplicate route silently replacing an earlier one.
        Route::post('pages/{page}/sections/reorder', [PageSectionController::class, 'reorder'])
            ->whereNumber('page')->name('pages.sections.reorder');

        Route::post('pages/{page}/sections', [PageSectionController::class, 'store'])
            ->whereNumber('page')->name('pages.sections.store');
        Route::put('pages/{page}/sections/{section}', [PageSectionController::class, 'update'])
            ->whereNumber('page')->whereNumber('section')->name('pages.sections.update');
        Route::post('pages/{page}/sections/{section}/visibility', [PageSectionController::class, 'toggleHidden'])
            ->whereNumber('page')->whereNumber('section')->name('pages.sections.visibility');
        Route::delete('pages/{page}/sections/{section}', [PageSectionController::class, 'destroy'])
            ->whereNumber('page')->whereNumber('section')->name('pages.sections.destroy');
    });
});
