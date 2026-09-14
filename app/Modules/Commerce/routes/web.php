<?php

use App\Modules\Commerce\Http\Controllers\MediaController;
use App\Modules\Commerce\Http\Controllers\MediaServeController;
use App\Modules\Commerce\Http\Controllers\PageController;
use App\Modules\Commerce\Http\Controllers\PageSectionController;
use App\Modules\Commerce\Http\Controllers\StoreCatalogueController;
use App\Modules\Commerce\Http\Controllers\StoreComboController;
use App\Modules\Commerce\Http\Controllers\StoreController;
use App\Modules\Commerce\Http\Controllers\StoreCouponController;
use App\Modules\Commerce\Http\Controllers\StorePaymentController;
use App\Modules\Commerce\Http\Controllers\StoreShippingController;
use Illuminate\Support\Facades\Route;

/**
 * Website management.
 *
 * ⚠ The same split every other module in this ERP uses: reading is one
 * permission, changing is another, and the one act that reaches the public has
 * its own. Gating the group rather than each method keeps the permission a
 * route can be read off this file rather than hunted for in a controller.
 */
/**
 * ⛔⛔ THE ONE PUBLIC ROUTE IN THIS MODULE, and it is outside `auth` and
 * `business` on purpose. A storefront image is fetched by strangers with no
 * session and no tenant context. It carries a uuid rather than an id so the
 * library of an unlaunched store cannot be walked, and it is the URL stored in
 * nothing: pages reference the media ROW, and the URL is derived. That means
 * nginx, which already mounts the storage volume read only, can take this exact
 * path over later without a single stored value changing.
 */
Route::middleware('web')->get('media/{uuid}', MediaServeController::class)
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('commerce.media.show');

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
    // ── The catalogue: which products this shop sells (slice 1.7) ───────────
    // ⚠ Read with the store, written with content management - the same pair
    // the pages screen uses. Publishing a product is editorial work, not the
    // Owner-only act of taking a whole store live.
    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores/{store}/catalogue', [StoreCatalogueController::class, 'index'])
            ->whereNumber('store')->name('stores.catalogue');
    });

    Route::middleware('can:commerce.content.manage')->group(function () {
        Route::post('stores/{store}/catalogue', [StoreCatalogueController::class, 'store'])
            ->whereNumber('store')->name('stores.catalogue.store');
        Route::put('stores/{store}/catalogue/{storeProduct}', [StoreCatalogueController::class, 'update'])
            ->whereNumber('store')->whereNumber('storeProduct')->name('stores.catalogue.update');
        Route::post('stores/{store}/catalogue/{storeProduct}/unpublish', [StoreCatalogueController::class, 'unpublish'])
            ->whereNumber('store')->whereNumber('storeProduct')->name('stores.catalogue.unpublish');
        Route::post('stores/{store}/catalogue/{storeProduct}/publish', [StoreCatalogueController::class, 'publishAgain'])
            ->whereNumber('store')->whereNumber('storeProduct')->name('stores.catalogue.publish');
        Route::delete('stores/{store}/catalogue/{storeProduct}', [StoreCatalogueController::class, 'destroy'])
            ->whereNumber('store')->whereNumber('storeProduct')->name('stores.catalogue.destroy');
    });

    /* -------------------------------------------------------------------- */
    /* Bundles (website slice 2.5a) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores/{store}/combos', [StoreComboController::class, 'index'])
            ->whereNumber('store')->name('stores.combos');
    });

    // ⚠ Composing is content work. The PRICE inside these same requests is
    // additionally gated by `sales.prices.manage` in the controller.
    Route::middleware('can:commerce.content.manage')->group(function () {
        Route::post('stores/{store}/combos', [StoreComboController::class, 'store'])
            ->whereNumber('store')->name('stores.combos.store');
        Route::put('stores/{store}/combos/{combo}', [StoreComboController::class, 'update'])
            ->whereNumber('store')->whereNumber('combo')->name('stores.combos.update');
        Route::post('stores/{store}/combos/{combo}/publish', [StoreComboController::class, 'publish'])
            ->whereNumber('store')->whereNumber('combo')->name('stores.combos.publish');
        Route::post('stores/{store}/combos/{combo}/unpublish', [StoreComboController::class, 'unpublish'])
            ->whereNumber('store')->whereNumber('combo')->name('stores.combos.unpublish');
        Route::delete('stores/{store}/combos/{combo}', [StoreComboController::class, 'destroy'])
            ->whereNumber('store')->whereNumber('combo')->name('stores.combos.destroy');
    });

    /* -------------------------------------------------------------------- */
    /* Shipping and district pricing (13 September) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores/{store}/shipping', [StoreShippingController::class, 'index'])
            ->whereNumber('store')->name('stores.shipping');
    });

    // ⚠ Owner and Manager (Joy, 13 September): a delivery rate decides what a
    // customer is charged, the same class of decision as the price group.
    Route::middleware('can:commerce.stores.manage')->group(function () {
        Route::post('stores/{store}/shipping/zones', [StoreShippingController::class, 'storeZone'])
            ->whereNumber('store')->name('stores.shipping.zones.store');
        Route::put('stores/{store}/shipping/zones/{zone}', [StoreShippingController::class, 'updateZone'])
            ->whereNumber('store')->whereNumber('zone')->name('stores.shipping.zones.update');
        Route::post('stores/{store}/shipping/zones/{zone}/default', [StoreShippingController::class, 'makeDefault'])
            ->whereNumber('store')->whereNumber('zone')->name('stores.shipping.zones.default');
        Route::delete('stores/{store}/shipping/zones/{zone}', [StoreShippingController::class, 'destroyZone'])
            ->whereNumber('store')->whereNumber('zone')->name('stores.shipping.zones.destroy');
        Route::put('stores/{store}/shipping/districts', [StoreShippingController::class, 'saveDistricts'])
            ->whereNumber('store')->name('stores.shipping.districts');
        Route::post('stores/{store}/shipping/standard', [StoreShippingController::class, 'applyStandard'])
            ->whereNumber('store')->name('stores.shipping.standard');
    });

    /* -------------------------------------------------------------------- */
    /* Online payment (SSLCommerz slice P2a) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores/{store}/payments', [StorePaymentController::class, 'index'])
            ->whereNumber('store')->name('stores.payments');
    });

    // ⚠ The Owner's permission: taking money online is the same class of decision
    // as going live, and the merchant password is the most sensitive thing a
    // store holds.
    Route::middleware('can:commerce.stores.publish')->group(function () {
        Route::put('stores/{store}/payments', [StorePaymentController::class, 'update'])
            ->whereNumber('store')->name('stores.payments.update');
    });

    /* -------------------------------------------------------------------- */
    /* Coupons (website slice 2.5b) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('stores/{store}/coupons', [StoreCouponController::class, 'index'])
            ->whereNumber('store')->name('stores.coupons');
    });

    // ⚠ A coupon is a price decision, so writing one needs the permission that
    // guards the selling price list, not the content permission.
    Route::middleware('can:sales.prices.manage')->group(function () {
        Route::post('stores/{store}/coupons', [StoreCouponController::class, 'store'])
            ->whereNumber('store')->name('stores.coupons.store');
        Route::post('stores/{store}/coupons/bulk', [StoreCouponController::class, 'bulk'])
            ->whereNumber('store')->name('stores.coupons.bulk');
        Route::put('stores/{store}/coupons/{coupon}', [StoreCouponController::class, 'update'])
            ->whereNumber('store')->whereNumber('coupon')->name('stores.coupons.update');
        Route::delete('stores/{store}/coupons/{coupon}', [StoreCouponController::class, 'destroy'])
            ->whereNumber('store')->whereNumber('coupon')->name('stores.coupons.destroy');
    });

    Route::middleware('can:commerce.stores.publish')->group(function () {
        Route::post('stores/{store}/publish', [StoreController::class, 'publish'])
            ->whereNumber('store')->name('stores.publish');
        // ⭐ Same permission as publishing, deliberately: being findable by the
        // whole internet is the same kind of decision as going live, and it is
        // the harder of the two to undo.
        Route::post('stores/{store}/indexing', [StoreController::class, 'indexing'])
            ->whereNumber('store')->name('stores.indexing');
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

    /* -------------------------------------------------------------------- */
    /* The media library (slice 1.6) */
    /* -------------------------------------------------------------------- */

    Route::middleware('can:commerce.stores.view')->group(function () {
        Route::get('media', [MediaController::class, 'index'])->name('media.index');
    });

    Route::middleware('can:commerce.content.manage')->group(function () {
        Route::post('media', [MediaController::class, 'store'])->name('media.store');
        // ⚠ `medium` because Laravel's singular of `media` is `medium`, and the
        // route model binding is what decides the parameter name, not taste.
        Route::put('media/{medium}', [MediaController::class, 'update'])
            ->whereNumber('medium')->name('media.update');
        Route::delete('media/{medium}', [MediaController::class, 'destroy'])
            ->whereNumber('medium')->name('media.destroy');
    });
});
