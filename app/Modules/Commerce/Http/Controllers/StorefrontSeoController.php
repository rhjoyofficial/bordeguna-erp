<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Support\StorefrontSeo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `robots.txt` and `sitemap.xml`, as ROUTES.
 *
 * ⛔⛔ NEVER STATIC FILES, AND THIS PROJECT HAS ALREADY PAID FOR THAT LESSON. On
 * 5 September a static `public/robots.txt` was found copied verbatim into a
 * forked shop, so **bionic.garden told every crawler that its sitemap lived at
 * laladia.com**. Confirmed afterwards in Search Console: Bionic had never had a
 * sitemap submitted, 0 of 0. A file cannot know which shop is serving it; a
 * route resolves the store from the hostname and cannot get it wrong.
 */
class StorefrontSeoController
{
    public function robots(Request $request): Response
    {
        $store = $this->store($request);
        $host = StorefrontSeo::primaryHost($store) ?? $request->getHost();

        $lines = $store->is_indexable
            ? [
                'User-agent: *',
                'Allow: /',
                '',
                // ⚠ This shop's OWN hostname, resolved from the request, which
                // is the entire reason this is not a file.
                'Sitemap: https://'.$host.'/sitemap.xml',
            ]
            : [
                // ⛔ A shop nobody has made indexable says so plainly. See the
                // migration: a preview shop in an index is very hard to undo.
                'User-agent: *',
                'Disallow: /',
            ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Every address this shop wants found, and nothing else.
     *
     * ⚠⚠ ONLY URLS THAT ANSWER 200. A sitemap listing a redirect is how
     * laladia.com came to have "page with redirect" as one of its largest
     * exclusion buckets in Search Console. So this lists published pages and
     * published products, both read from the same tables the storefront serves
     * from - never a hand-kept list.
     *
     * ⛔ A shop that is not indexable serves an EMPTY sitemap rather than a 404.
     * A 404 reads as a broken site to a crawler; an empty one truthfully says
     * there is nothing here to index yet.
     */
    public function sitemap(Request $request): Response
    {
        $store = $this->store($request);
        $host = StorefrontSeo::primaryHost($store) ?? $request->getHost();
        $base = 'https://'.$host;

        $urls = [];

        if ($store->is_indexable) {
            foreach (Page::query()->where('store_id', $store->id)->whereNotNull('published_at')->get() as $page) {
                $urls[] = [
                    'loc' => $base.$page->path(),
                    'lastmod' => $page->updated_at?->toAtomString(),
                ];
            }

            foreach (StoreProduct::query()->where('store_id', $store->id)->whereNotNull('published_at')->get() as $row) {
                $urls[] = [
                    'loc' => $base.'/products/'.$row->slug,
                    'lastmod' => $row->updated_at?->toAtomString(),
                ];
            }

            // ⚠ Bundles (slice 2.5a): every published one answers 200, even while
            // it cannot be sold, because its page stays and explains itself. The
            // listing is offered only when there is something on it.
            $combos = StoreCombo::query()->where('store_id', $store->id)->whereNotNull('published_at')->get();

            if ($combos->isNotEmpty()) {
                $urls[] = ['loc' => $base.'/combos', 'lastmod' => null];
            }

            foreach ($combos as $combo) {
                $urls[] = [
                    'loc' => $base.'/combos/'.$combo->slug,
                    'lastmod' => $combo->updated_at?->toAtomString(),
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'
                .($url['lastmod'] === null ? '' : '<lastmod>'.e($url['lastmod']).'</lastmod>')
                .'</url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function store(Request $request): Store
    {
        $store = ResolveStorefront::current($request);

        abort_if($store === null, 404);

        return $store;
    }
}
