<?php

use App\Modules\Commerce\Http\Controllers\CartController;
use App\Modules\Commerce\Http\Controllers\GeoController;
use App\Modules\Commerce\Http\Controllers\StorefrontController;
use App\Modules\Commerce\Http\Controllers\StorefrontSeoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public shop
|--------------------------------------------------------------------------
|
| ⛔⛔ WHAT IS NOT HERE IS STILL THE POINT. Phase 1 was read only; Phase 2
| slice 2.1 adds the BASKET and nothing else. There is still no checkout, no
| account, no wishlist and no coupon route. They are ABSENT rather than
| disabled: a disabled feature is a URL somebody can still find, and a
| half-built checkout on a real hostname is how a shop takes an order it
| cannot fulfil. Each arrives in its own slice, complete.
|
| ⚠ Every route is named `storefront.*`, and that prefix is load bearing:
| ResolveStorefront refuses anything else on a shop hostname by NAME rather than
| by a list of paths that would go stale the first time somebody adds a screen.
|
| ⚠ The page route is a FALLBACK. A shop's pages have arbitrary one-segment
| addresses, so a plain `/{slug}` would shadow every ERP route on the ERP's own
| hostname. A fallback is matched only when nothing else did, which is exactly
| the rule wanted.
*/

Route::middleware('web')->group(function () {
    // ⛔ ROUTES, NEVER FILES. A static robots.txt was copied into a forked shop
    // and told crawlers its sitemap lived at the other shop's domain. See
    // StorefrontSeoController.
    Route::get('/robots.txt', [StorefrontSeoController::class, 'robots'])->name('storefront.robots');
    Route::get('/sitemap.xml', [StorefrontSeoController::class, 'sitemap'])->name('storefront.sitemap');

    Route::get('/products/{slug}', [StorefrontController::class, 'product'])
        ->where('slug', '[a-z0-9-]+')
        ->name('storefront.product');

    // The basket (Phase 2, slice 2.1). ⚠ Declared BEFORE the fallback, and
    // every one carries the `storefront.` prefix ResolveStorefront keys on.
    // ⚠ On the ERP's own hostname these answer 404 like every storefront
    // route: the controller refuses when no store was resolved.
    Route::get('/cart', [CartController::class, 'show'])->name('storefront.cart');
    Route::post('/cart/lines', [CartController::class, 'add'])->name('storefront.cart.add');
    Route::patch('/cart/lines/{line}', [CartController::class, 'update'])
        ->whereNumber('line')->name('storefront.cart.update');
    Route::delete('/cart/lines/{line}', [CartController::class, 'remove'])
        ->whereNumber('line')->name('storefront.cart.remove');

    // Where the customer lives (Phase 2, slice 2.2). ⚠ Read only and cached: it
    // answers questions about Bangladesh, not about this shop or this customer.
    Route::get('/geo/districts', [GeoController::class, 'districts'])->name('storefront.geo.districts');
    Route::get('/geo/thanas', [GeoController::class, 'thanas'])->name('storefront.geo.thanas');
    Route::get('/geo/areas', [GeoController::class, 'areas'])->name('storefront.geo.areas');

    Route::fallback([StorefrontController::class, 'page'])->name('storefront.page');
});
