<?php

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Sections\SectionCatalogue;
use App\Modules\Commerce\Support\StorefrontPage;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Facades\View;

/**
 * Phase 1, slice 1.8: the storefront. ⭐ This is the phase exit.
 *
 * A hostname resolves to a store, the store names a theme, the theme draws the
 * page's sections. Read only, by construction: there is no cart, no checkout and
 * no account, and they are ABSENT rather than disabled - a disabled feature is
 * still a URL somebody can find.
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
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic',
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $this->home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $this->home->forceFill(['published_at' => now()])->save();
});

function shopGet(string $path)
{
    return test()->get('http://shop.test'.$path);
}

it('keeps every promise the theme made about what it can draw', function () {
    // ⭐⭐ THIS IS WHY SLICE 1.2 EXISTED. A theme declares the section types it
    // renders and slice 1.5 refuses a theme switch on the strength of that
    // answer. A declared type with no template does not error - it draws
    // NOTHING, which is the silent failure the whole declaration was invented to
    // prevent. So the promise is checked here rather than by a customer.
    $missing = [];

    foreach (app(ThemeRegistry::class)->all() as $theme) {
        /** @var Theme $theme */
        foreach ($theme->sections() as $support) {
            $view = StorefrontPage::viewFor($theme, $support->typeKey);

            if (! View::exists($view)) {
                $missing[] = $theme->key().' declares '.$support->typeKey.' but has no template ('.$view.')';
            }
        }
    }

    expect($missing)->toBe([]);
});

it('serves a live shop on its own hostname', function () {
    shopGet('/')->assertOk()->assertSee('Bionic Garden', false);
});

it('does not serve a shop that nobody has taken live', function () {
    // ⛔ Publishing is the moment a hostname starts answering the public. A shop
    // readable before that decision makes the decision meaningless.
    $this->store->forceFill(['status' => 'draft'])->save();

    shopGet('/')->assertNotFound();
});

it('does not serve an unpublished page to somebody who knows its address', function () {
    $this->home->forceFill(['published_at' => null])->save();

    shopGet('/')->assertNotFound();
});

it('refuses to dress a shop in a theme nothing is registered under', function () {
    // ⛔ No fallback theme. A shop silently wearing somebody else's design is
    // worse than a shop that is honestly not ready.
    $this->store->forceFill(['theme_key' => 'a-theme-that-was-deleted'])->save();

    shopGet('/')->assertStatus(503);
});

it('does not put the ERP on a shop hostname', function () {
    // ⛔⛔ Without this, /dashboard on the company's own storefront would send a
    // customer to an internal login form - which tells a stranger what this
    // system is and invites them to try it.
    shopGet('/dashboard')->assertNotFound()->assertDontSee('Dashboard', false);
    shopGet('/login')->assertNotFound();
});

it('offers an address the ERP happens to claim to the shop instead of refusing it', function () {
    // ⭐ A shop page legitimately called "files" would otherwise 404 for a reason
    // no shopkeeper could discover, because an unrelated ERP route matched first.
    $page = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Custom, 'slug' => 'files', 'title' => 'Our Files',
    ]);
    $page->forceFill(['published_at' => now()])->save();

    shopGet('/files')->assertOk()->assertSee('Our Files', false);
});

it('leaves the ERP alone on its own hostname', function () {
    // ⚠ The middleware is global, so the thing to prove is that it does nothing
    // at all when the host is not a shop.
    $this->get('/')->assertRedirect('/login');
});

it('has no account, because it does not exist', function () {
    // ⛔ ABSENT, not disabled. A disabled feature is a URL somebody can still
    // find, and a half-built checkout on a real hostname takes an order this
    // system cannot fulfil.
    //
    // ⚠ TWO PATHS HAVE LEFT THIS LIST, EACH WHEN ITS SLICE SHIPPED COMPLETE:
    // `/cart` at slice 2.1 and `/checkout` at slice 2.4. The rule never
    // changed; the set of things that exist did, and asserting the absence of
    // something that now works would be asserting the past. Customer accounts
    // are slice 2.6 and are still genuinely absent.
    foreach (['/account', '/login-customer'] as $path) {
        shopGet($path)->assertNotFound();
    }
});

it('shows a product at the price its own shop quotes', function () {
    $this->store->products()->create([
        'product_id' => $this->product->id, 'slug' => 'sundarban-honey',
    ])->publish();

    shopGet('/products/sundarban-honey')
        ->assertOk()
        ->assertSee('Sundarban Honey 500g', false)
        ->assertSee('850.00', false);
});

it('stops showing a product the shop has taken off sale', function () {
    // ⚠ Taking something off sale has to mean that everywhere, not only on the
    // listing somebody remembered to edit.
    $row = $this->store->products()->create([
        'product_id' => $this->product->id, 'slug' => 'sundarban-honey',
    ]);
    $row->publish();

    shopGet('/products/sundarban-honey')->assertOk();

    $row->unpublish();

    shopGet('/products/sundarban-honey')->assertNotFound();
});

it('draws a section through the theme', function () {
    $type = collect(SectionCatalogue::all())->firstWhere('key', 'story.prose');

    $this->home->sections()->create([
        'type_key' => 'story.prose',
        'type_version' => $type->version,
        'position' => 1,
        'payload' => ['heading' => 'From the Sundarbans', 'body' => 'Harvested by hand.'],
    ]);

    shopGet('/')->assertOk()
        ->assertSee('From the Sundarbans', false)
        ->assertSee('Harvested by hand.', false);
});

it('never lets stored copy put markup on a public page', function () {
    // ⚠ Escaped first, then line breaks restored. The other order is an
    // injection on a page a stranger opens.
    $type = collect(SectionCatalogue::all())->firstWhere('key', 'story.prose');

    $this->home->sections()->create([
        'type_key' => 'story.prose',
        'type_version' => $type->version,
        'position' => 1,
        'payload' => ['heading' => 'Story', 'body' => '<script>alert(1)</script>'],
    ]);

    shopGet('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
});

it('leaves a hidden section off the page', function () {
    $type = collect(SectionCatalogue::all())->firstWhere('key', 'story.prose');

    $this->home->sections()->create([
        'type_key' => 'story.prose',
        'type_version' => $type->version,
        'position' => 1,
        'is_hidden' => true,
        'payload' => ['heading' => 'Not ready yet', 'body' => 'Draft copy.'],
    ]);

    shopGet('/')->assertOk()->assertDontSee('Not ready yet', false);
});

it('tells crawlers to stay away from a preview hostname', function () {
    // ⛔ A preview shop indexed under the company's name is very hard to undo.
    // Slice 1.9 makes this a real setting; until then the safe answer stands.
    shopGet('/')->assertSee('noindex', false);
});
