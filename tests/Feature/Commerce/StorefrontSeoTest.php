<?php

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;

/**
 * Phase 1, slice 1.9: what a shop tells a machine about itself.
 *
 * ⛔⛔ THE RULE THE WHOLE SLICE SERVES: never claim in schema what the page does
 * not have. A rating nobody left, a stock status this system does not hold for
 * the web, an address the business never entered - each is a lie told to a
 * search engine in a machine-readable format, which is the most efficient way to
 * be discounted as a source.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-500', 'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC', 'theme_key' => 'bionic-classic',
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $this->home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $this->home->forceFill(['published_at' => now()])->save();

    $this->indexable = function () {
        $this->store->forceFill(['is_indexable' => true])->save();
    };
});

it('serves robots.txt as a route naming this shop own sitemap', function () {
    // ⛔⛔ THE DEFECT THIS PREVENTS WAS REAL AND MEASURED. A static
    // public/robots.txt was copied verbatim into a forked shop, so
    // bionic.garden told every crawler its sitemap lived at laladia.com. Search
    // Console afterwards: Bionic had never had a sitemap submitted, 0 of 0.
    ($this->indexable)();

    $this->get('http://shop.test/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Sitemap: https://shop.test/sitemap.xml', false)
        ->assertDontSee('laladia', false);
});

it('tells crawlers to stay out of a shop nobody has made indexable', function () {
    $this->get('http://shop.test/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /', false)
        ->assertDontSee('Sitemap:', false);
});

it('lists published pages and products in the sitemap, on its own domain', function () {
    ($this->indexable)();

    $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey'])->publish();

    $this->get('http://shop.test/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>https://shop.test/</loc>', false)
        ->assertSee('<loc>https://shop.test/products/sundarban-honey</loc>', false);
});

it('keeps anything that would not answer 200 out of the sitemap', function () {
    // ⚠⚠ A sitemap listing a redirect or a 404 is how laladia.com came to have
    // "page with redirect" as one of its largest exclusion buckets.
    ($this->indexable)();

    $draft = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Custom, 'slug' => 'not-ready', 'title' => 'Draft',
    ]);
    $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'off-sale']);

    $this->get('http://shop.test/sitemap.xml')
        ->assertOk()
        ->assertDontSee('not-ready', false)
        ->assertDontSee('off-sale', false);

    expect($draft->isPublished())->toBeFalse();
});

it('serves an empty sitemap rather than a 404 for a shop that is not indexable', function () {
    // ⛔ A 404 reads as a broken site to a crawler. An empty sitemap truthfully
    // says there is nothing here to index yet.
    $this->get('http://shop.test/sitemap.xml')
        ->assertOk()
        ->assertSee('<urlset', false)
        ->assertDontSee('<loc>', false);
});

it('keeps the query string in the canonical', function () {
    // ⚠⚠ Built from the path alone, the canonical told search engines that
    // ?page=4 was page 1 - so every utm_ and fbclid minted a duplicate URL
    // pointing at the wrong document. Measured on laladia.com, 5 September.
    ($this->indexable)();

    $this->get('http://shop.test/?page=4')
        ->assertOk()
        ->assertSee('rel="canonical" href="https://shop.test/?page=4"', false);
});

it('sends a secondary hostname to the primary one, once, with a 301', function () {
    // ⛔⛔ www.laladia.com served the ENTIRE site at 200 - not a redirect, a
    // second complete copy, each page claiming to be canonical because the tag
    // was built from the requested host.
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'www.shop.test', 'is_primary' => false]);

    $this->get('http://www.shop.test/about?utm_source=fb')
        ->assertStatus(301)
        ->assertRedirect('https://shop.test/about?utm_source=fb');
});

it('never redirects a request that carries a body', function () {
    // ⚠ A 301 on a POST may drop the body, and a dropped body on a shop is a
    // lost order. There is no checkout yet; the rule goes in before there is one.
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'www.shop.test', 'is_primary' => false]);

    $response = $this->post('http://www.shop.test/anything');

    // The invariant is only that it was NOT redirected.
    expect($response->status())->not->toBe(301);

    // ⚠ 405 in practice, and that is itself the Phase 1 rule showing through:
    // nothing on a read-only storefront accepts a POST at all.
    $response->assertStatus(405);
});

it('is invisible to crawlers until somebody says otherwise', function () {
    $this->get('http://shop.test/')->assertSee('content="noindex, nofollow"', false);

    ($this->indexable)();

    $this->get('http://shop.test/')->assertSee('content="index, follow"', false);
});

it('describes a product without claiming a rating or a stock level', function () {
    // ⛔ No aggregateRating: nothing here has been reviewed. No availability:
    // the web sells made-to-order goods under a status policy, so "InStock"
    // would be a number stated about a warehouse that means something else.
    ($this->indexable)();
    $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey'])->publish();

    $response = $this->get('http://shop.test/products/sundarban-honey')->assertOk();

    $response->assertSee('"@type":"Product"', false)
        ->assertSee('"price":"850.00"', false)
        ->assertSee('"priceCurrency":"BDT"', false)
        ->assertDontSee('aggregateRating', false)
        ->assertDontSee('availability', false)
        ->assertDontSee('InStock', false);
});

it('says nothing at all about price when none is in force', function () {
    ($this->indexable)();
    ProductPrice::query()->delete();
    $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey'])->publish();

    // ⚠ Absent, never zero. A machine told the price is 0 has been told a price.
    $this->get('http://shop.test/products/sundarban-honey')
        ->assertOk()
        ->assertDontSee('"offers"', false)
        ->assertSee('Price on request', false);
});

it('does not promise a search endpoint it does not have', function () {
    // ⛔ SearchAction declares a working search URL. Phase 1 is read only and
    // has none, so declaring one would send a crawler to a 404.
    ($this->indexable)();

    $this->get('http://shop.test/')
        ->assertSee('"@type":"WebSite"', false)
        ->assertDontSee('SearchAction', false);
});
