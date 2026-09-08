<?php

use App\Modules\Commerce\Http\Controllers\StorefrontController;
use App\Modules\Commerce\Http\Controllers\StorefrontSeoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public shop
|--------------------------------------------------------------------------
|
| ⛔⛔ WHAT IS NOT HERE IS THE POINT OF PHASE 1. There is no cart, no checkout,
| no account, no wishlist and no coupon route, because Phase 1 is read only.
| They are ABSENT rather than disabled: a disabled feature is a URL somebody can
| still find, and a half-built checkout on a real hostname is how a shop takes
| an order it cannot fulfil.
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

    Route::fallback([StorefrontController::class, 'page'])->name('storefront.page');
});
