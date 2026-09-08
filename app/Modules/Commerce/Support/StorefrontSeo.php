<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Support\BusinessIdentity;
use Illuminate\Http\Request;

/**
 * What a shop tells a machine about itself.
 *
 * ⛔⛔ THE ONE RULE: NEVER CLAIM IN SCHEMA WHAT THE PAGE DOES NOT HAVE. A rating
 * on a product nobody has reviewed, a stock status this system does not hold for
 * the web, an address the business never entered - each is a lie told to a
 * search engine in a machine-readable format, which is the most efficient way to
 * be discounted as a source. Everything below is built from a real record or
 * left out.
 */
final class StorefrontSeo
{
    /**
     * The address this page should be known by.
     *
     * ⚠⚠ THE QUERY STRING IS KEPT. A canonical built from the path alone told
     * search engines that `?page=4` was page 1 - so three quarters of a listing
     * claimed to be the same document, and every `utm_` and `fbclid` minted a
     * duplicate URL pointing at the wrong place. That defect was measured on
     * laladia.com on 5 September.
     *
     * ⚠ Built on the store's PRIMARY hostname, never on the host that happened
     * to arrive. Two hostnames each claiming to be canonical is how a shop ends
     * up as two complete copies of itself in an index.
     */
    public static function canonical(Store $store, Request $request): string
    {
        $host = self::primaryHost($store) ?? $request->getHost();
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        return 'https://'.$host.$path.($query === null ? '' : '?'.$query);
    }

    public static function primaryHost(Store $store): ?string
    {
        return $store->domains()->where('is_primary', true)->value('hostname');
    }

    /**
     * ⭐ A shop is invisible until somebody says otherwise. See the migration:
     * a preview shop indexed under the company's name is very hard to undo, and
     * undoing it is a request to a third party rather than a change made here.
     */
    public static function robots(Store $store): string
    {
        return $store->is_indexable ? 'index, follow' : 'noindex, nofollow';
    }

    public static function title(Store $store, ?Page $page, ?string $fallback = null): string
    {
        $own = $page?->seo_title ?: $page?->title ?: $fallback;
        $suffix = $store->seo_title ?: $store->name;

        return $own === null || $own === '' ? $suffix : $own.' | '.$suffix;
    }

    public static function description(Store $store, ?Page $page): ?string
    {
        // ⚠ A page's own words first, the shop's default second, and NOTHING
        // invented from the body copy. A description assembled from the first
        // sentence of a page reads as machine-written, which is what it is.
        return $page?->seo_description ?: ($store->seo_description ?: null);
    }

    /**
     * Organization and WebSite, from what the business has actually saved.
     *
     * ⚠ BusinessIdentity already refuses to invent an address or a phone number,
     * and that refusal carries through here: a field the business never filled
     * in is absent from the schema rather than guessed at. An invented postal
     * address in structured data is a claim about a physical place.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function organisation(Store $store, Request $request): array
    {
        $identity = BusinessIdentity::forCurrent();
        $home = 'https://'.(self::primaryHost($store) ?? $request->getHost());

        $organisation = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $store->name,
            'url' => $home,
            'email' => $identity->email,
            'telephone' => $identity->phone,
        ], fn ($value) => $value !== null && $value !== '');

        return [
            $organisation,
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $store->name,
                'url' => $home,
                // ⛔ NO SearchAction. That element promises a working search
                // endpoint, and this storefront has none - Phase 1 is read only.
                // Declaring one would send a search engine to a URL that 404s.
            ],
        ];
    }

    /**
     * A product, described only as far as the shop actually knows it.
     *
     * ⛔ NO `aggregateRating`: nothing here has been reviewed, and a rating in
     * schema that no visible review supports is the exact signal that gets a
     * brand discounted as a source.
     *
     * ⛔ NO `availability`: this ERP holds stock, but the web sells made-to-order
     * goods under a status policy (ADR-004), so "InStock" would be a number
     * stated about a warehouse that means something else. An offer with a price
     * and no availability is honest; one with an invented availability is not.
     *
     * @param  array<string, mixed>  $product  as StorefrontPage::present returns it
     * @return array<string, mixed>
     */
    public static function product(Store $store, array $product, Request $request): array
    {
        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['title'] ?? null,
            'description' => $product['summary'] ?? null,
            'image' => $product['image']['url'] ?? null,
            'url' => 'https://'.(self::primaryHost($store) ?? $request->getHost()).($product['url'] ?? ''),
        ], fn ($value) => $value !== null && $value !== '');

        // ⚠ The offer exists only when a price does. A product with no price in
        // force renders "Price on request" to a person, and says nothing at all
        // to a machine rather than saying zero.
        if (! empty($product['price'])) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => str_replace(',', '', (string) $product['price']),
                'priceCurrency' => 'BDT',
            ];
        }

        return $schema;
    }
}
