<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Support\StorefrontPage;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The shop a visitor sees.
 *
 * ⭐⭐ THE WHOLE PROGRAMME MEETS HERE: a hostname resolved to a store, the store
 * names a theme, the theme draws the page's sections. Nothing on this side
 * writes anything, and there is deliberately no cart, no checkout and no
 * account - Phase 1 is read only, so those are absent rather than disabled. An
 * absent route cannot be reached by guessing its URL.
 *
 * ⚠ It renders Blade, not the ERP's React. This is a public page that has to be
 * complete in the first response for a search engine and for a phone on a poor
 * connection, and it needs none of the interactivity the admin does.
 */
class StorefrontController
{
    /**
     * ⚠ The address comes from the request path, not from a route parameter,
     * because this method is reached two ways: as the fallback for an address no
     * route claimed, and as the hand-over when an ERP route matched on a shop
     * hostname. One entry point, so the two cannot answer differently.
     */
    public function page(Request $request): View
    {
        $store = $this->store($request);
        $path = trim($request->path(), '/');

        $page = $path === '' || $path === '/'
            ? Page::query()->where('store_id', $store->id)->where('type', PageType::Home)->first()
            : Page::query()->where('store_id', $store->id)->where('slug', $path)->first();

        // ⛔ An unpublished page is a 404, not a preview. Somebody who knows the
        // address of a draft is still a member of the public.
        abort_if($page === null || ! $page->isPublished(), 404);

        return $this->render($store, $page);
    }

    /**
     * ⚠ A product page is a PAGE, not a special case. It carries the same
     * sections everything else does, which is what stops a storefront growing
     * two rendering paths that drift apart. What differs is only that it also
     * knows which product it is about.
     */
    public function product(Request $request, string $slug): View
    {
        $store = $this->store($request);

        $row = StoreProduct::query()
            ->with('product', 'image')
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->whereNotNull('published_at')
            ->first();

        abort_if($row === null || $row->product === null, 404);

        $theme = $this->theme($store);

        return view('storefront.product', [
            'store' => $store,
            'theme' => $theme,
            'product' => StorefrontPage::present($store, $row),
            'title' => $row->displayTitle(),
        ]);
    }

    private function render(Store $store, Page $page): View
    {
        $theme = $this->theme($store);

        return view('storefront.page', [
            'store' => $store,
            'theme' => $theme,
            'page' => $page,
            'sections' => StorefrontPage::sectionsFor($store, $page, $theme),
            'title' => $page->title,
        ]);
    }

    /**
     * ⚠ Never resolved again here. The middleware already decided which shop
     * this is, before the tenant context existed; asking a second time is a
     * second chance to disagree.
     */
    private function store(Request $request): Store
    {
        $store = ResolveStorefront::current($request);

        abort_if($store === null, 404);

        return $store;
    }

    /**
     * ⛔ A store with no theme, or one naming a theme nothing is registered
     * under, does not fall back to another theme. A shop silently wearing
     * somebody else's design is worse than a shop that is honestly not ready,
     * and the store settings screen already says when this is the case.
     */
    private function theme(Store $store): Theme
    {
        $registry = app(ThemeRegistry::class);

        abort_if($store->theme_key === null || ! $registry->has($store->theme_key), 503);

        return $registry->get($store->theme_key);
    }
}
