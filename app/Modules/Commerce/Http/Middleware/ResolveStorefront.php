<?php

namespace App\Modules\Commerce\Http\Middleware;

use App\Modules\Commerce\Http\Controllers\StorefrontController;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Support\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides, for every request, whether this hostname is a shop.
 *
 * ⚠⚠ THIS RUNS BEFORE ANY TENANT CONTEXT EXISTS, which is why `store_domains` is
 * not business-scoped and why hostnames are globally unique (slice 1.1). A
 * visitor has no session, no login and no business; the only thing the request
 * carries is a Host header, so that has to be enough to decide everything else.
 * The business is then set FROM the store, never the other way round.
 *
 * ⛔⛔ ON A SHOP HOSTNAME THE ERP DOES NOT EXIST. Without this, every admin route
 * would still answer on the shop's own domain - `/dashboard` would redirect a
 * customer to a login form on the company's storefront, which tells a stranger
 * what this system is and invites them to try it. So anything that is not a
 * storefront route is a 404 there, by name, rather than by a list of paths
 * somebody has to keep up to date.
 *
 * ⛔ And a DRAFT store is not served at all. Publishing is the moment a real
 * hostname starts answering the public; a shop readable before that decision
 * would make the decision meaningless.
 */
class ResolveStorefront
{
    /**
     * The one ERP route allowed to answer on a shop hostname, because it is the
     * shop's own front door: `/` is registered by the application and hands over
     * to the storefront when the host is a shop. Everything else is refused.
     */
    private const SHARED_ROUTE = 'home';

    public function handle(Request $request, Closure $next): Response
    {
        $store = Store::resolveByHost($request->getHost());

        if ($store === null) {
            // Not a shop hostname at all. The ERP behaves exactly as it always
            // has, and this middleware has done nothing.
            return $next($request);
        }

        if (! $store->status->isServable()) {
            // ⛔ A hostname that BELONGS to a shop, on a shop nobody has taken
            // live. It does not fall through to the ERP - that would put an
            // internal login form on a domain the company has published as a
            // storefront. It is simply not a website yet.
            abort(404);
        }

        // ⭐ The store decides the tenant, so every scoped model the storefront
        // touches - products, media, pages - is confined to the right business
        // by the same mechanism the ERP uses. The storefront gets no copy of
        // that rule of its own.
        TenantScope::enter($store->business_id);

        $request->attributes->set('storefront_store', $store);

        $name = (string) $request->route()?->getName();

        if ($name === self::SHARED_ROUTE || str_starts_with($name, 'storefront.')) {
            return $next($request);
        }

        // ⭐ NOT `abort(404)`. An ERP route matched only because its path happens
        // to look like one - a shop page legitimately called "files" or
        // "calendar" would otherwise 404 for a reason no shopkeeper could ever
        // discover. On a shop hostname the ERP is not refused, it is simply not
        // there, and the address is offered to the shop instead. If the shop has
        // no such page, THAT is the 404.
        // ⚠ Wrapped in a Response because a middleware must return one and a
        // controller may return a View. Laravel converts that for a route;
        // nothing converts it here.
        return response(app(StorefrontController::class)->page($request));
    }

    /**
     * The shop this request belongs to, or null on the ERP's own hostname.
     *
     * ⚠ Read from the request rather than resolved again: resolving twice is two
     * chances to disagree, and the second one would not be running before the
     * tenant context was set.
     */
    public static function current(Request $request): ?Store
    {
        $store = $request->attributes->get('storefront_store');

        return $store instanceof Store ? $store : null;
    }
}
