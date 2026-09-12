<?php

use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;

/**
 * Website Phase 2, slice 2.1: availability, and a basket that survives a reload.
 *
 * ⭐ The slice exit criteria, stated as tests. The basket records WHAT SOMEBODY
 * CHOSE and never what it costs, so every figure here is resolved from the
 * price list at read time - which is what lets the last test pass.
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

    $this->price = ProductPrice::create([
        'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic',
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $this->row = $this->store->products()->create([
        'product_id' => $this->product->id, 'slug' => 'sundarban-honey',
    ]);
    $this->row->publish();

    $home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $home->forceFill(['published_at' => now()])->save();
});

function shopPost(string $path, array $data = [])
{
    return test()->post('http://shop.test'.$path, $data);
}

/**
 * The basket token the shop just handed this browser.
 *
 * ⚠ Decrypted, because `EncryptCookies` encrypts everything on the way out.
 * Reading the raw value gives ciphertext that matches no row.
 */
function basketToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

/**
 * A second request from the SAME browser, carrying the basket cookie back.
 *
 * ⚠ `withCookie`, not `withUnencryptedCookie`: the test helper encrypts what it
 * is given, which is exactly what `EncryptCookies` then decrypts. Handing it a
 * raw token instead produces a cookie the middleware cannot decrypt, and a
 * cookie it cannot decrypt is silently dropped - so the shop sees no basket at
 * all and every assertion fails for the wrong reason.
 */
function sameBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

function basketOf(string $token): ?Cart
{
    return Cart::query()->where('token', $token)->first();
}

/* -------------------------------------------------------------------- */
/* Availability: the policy, and what it lets a shop say                 */
/* -------------------------------------------------------------------- */

it('keeps selling at zero on a website, because the goods are made to order', function () {
    // ⭐ ADR-004. There is one stock number; the CHANNEL decides what to do
    // with a zero. A website keeps selling and promises a lead time.
    $decision = Availability::decide($this->store, $this->product);

    expect($decision->sellable)->toBeTrue()
        ->and($decision->madeToOrder)->toBeTrue()
        ->and($decision->message())->toBe('Made to order.');
});

it('refuses a sale at zero on a channel that cannot take one', function () {
    $this->store->update(['availability_policy' => AvailabilityPolicy::Strict]);

    $decision = Availability::decide($this->store->fresh(), $this->product);

    expect($decision->sellable)->toBeFalse()
        ->and($decision->reason)->toBe('Out of stock.');
});

it('lets one product refuse a sale at zero on a shop that would otherwise take it', function () {
    // ⚠ The override exists for goods that genuinely cannot be made to order -
    // imported stock, seasonal fruit.
    $this->product->update(['availability_override' => AvailabilityPolicy::Strict]);

    expect(Availability::decide($this->store, $this->product->fresh())->sellable)->toBeFalse();
});

it('never lets a product override widen what a channel will sell', function () {
    // ⛔ The override only ever NARROWS. A product must not be able to make the
    // counter sell what is not on the shelf, where the customer is standing there.
    $this->store->update(['availability_policy' => AvailabilityPolicy::Strict]);
    $this->product->update(['availability_override' => AvailabilityPolicy::StatusBased]);

    expect(Availability::decide($this->store->fresh(), $this->product->fresh())->sellable)->toBeFalse();
});

it('promises a date only when somebody recorded one', function () {
    // ⛔ "Ships in a few days" is a promise somebody would then have to keep.
    expect(Availability::decide($this->store, $this->product)->message())->toBe('Made to order.');

    $this->product->update(['lead_time_days' => 3]);

    expect(Availability::decide($this->store, $this->product->fresh())->message())
        ->toBe('Made to order, ready in about 3 days.');
});

it('keeps an out-of-season product URL alive instead of throwing it away', function () {
    // ⭐ Today a switched-off product 404s, which throws away that URL's search
    // authority every year and earns it back from scratch every season.
    $this->product->update(['is_active' => false]);

    $decision = Availability::decide($this->store, $this->product->fresh());

    expect($decision->sellable)->toBeFalse()
        ->and($decision->reason)->toContain('Out of season');
});

/* -------------------------------------------------------------------- */
/* The basket                                                            */
/* -------------------------------------------------------------------- */

it('keeps a basket across a reload and a new tab on the same browser', function () {
    // ⭐ THE SLICE EXIT CRITERION. A table plus a token cookie, not a session:
    // a basket somebody means to come back to has to outlive a session cookie.
    $add = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 2]);
    $add->assertRedirect('/cart');

    $token = basketToken($add);

    // A fresh request carrying only the cookie: a reload, or a new tab.
    sameBrowser($token)->get('http://shop.test/cart')
        ->assertOk()
        ->assertSee('Sundarban Honey 500g', false);

    expect((int) basketOf($token)->lines()->sum('quantity'))->toBe(2);
});

it('adds to the quantity rather than making a second row for the same jar', function () {
    $first = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = basketToken($first);

    sameBrowser($token)->post('http://shop.test/cart/lines', ['product' => $this->product->id, 'quantity' => 2]);

    $cart = basketOf($token);

    expect($cart->lines()->count())->toBe(1)
        ->and($cart->lines()->first()->quantity)->toBe(3);
});

it('does not write a basket row for somebody who only looked at one', function () {
    // ⚠ A row per visitor would make the abandoned-cart screens meaningless.
    $this->get('http://shop.test/cart')->assertOk()->assertSee('Your basket is empty', false);

    expect(Cart::query()->count())->toBe(0);
});

it('refuses a product this shop has not published, and says why', function () {
    $this->row->unpublish();

    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1])
        ->assertSessionHasErrors(['product' => 'This product is not available on this shop.']);

    expect(Cart::query()->count())->toBe(0);
});

it('refuses a product whose price has run out, even though publishing once passed', function () {
    // ⚠ The publish gate checked this once. A price can expire afterwards, and
    // a basket must not carry a thing nobody can say the cost of.
    $this->price->update(['effective_to' => '2026-01-31']);

    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1])
        ->assertSessionHasErrors('product');
});

it('refuses a strict product at zero, and says why', function () {
    $this->product->update(['availability_override' => AvailabilityPolicy::Strict]);

    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1])
        ->assertSessionHasErrors(['product' => 'Out of stock.']);
});

it('refuses a quantity that is not a sensible whole number', function () {
    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 0])->assertSessionHasErrors('quantity');
    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 'two'])->assertSessionHasErrors('quantity');
    shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 500])->assertSessionHasErrors('quantity');
});

it('changes a price in the ERP and the basket follows, with no basket edit', function () {
    // ⭐⭐ THIS IS WHY A CART LINE HAS NO PRICE COLUMN. The basket records what
    // was chosen; what it costs is asked of the price list on every read.
    $add = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 2]);
    $token = basketToken($add);

    sameBrowser($token)->get('http://shop.test/cart')->assertSee('1,700.00', false);

    $this->price->update(['unit_price' => '900']);

    sameBrowser($token)->get('http://shop.test/cart')->assertSee('1,800.00', false);
});

it('still opens a basket holding something the shop can no longer sell', function () {
    // ⛔ LaLaDia threw a 500 on exactly this, so a customer whose jar sold out
    // could not open their basket to remove it.
    $add = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = basketToken($add);

    $this->row->unpublish();

    sameBrowser($token)->get('http://shop.test/cart')
        ->assertOk()
        ->assertSee('not available on this shop', false);
});

it('lets somebody change and remove what they put in', function () {
    $add = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = basketToken($add);
    $line = basketOf($token)->lines()->first();

    sameBrowser($token)->patch('http://shop.test/cart/lines/'.$line->id, ['quantity' => 4]);

    expect($line->fresh()->quantity)->toBe(4);

    // ⚠ Zero means remove: a customer typing 0 has said what they want clearly.
    sameBrowser($token)->patch('http://shop.test/cart/lines/'.$line->id, ['quantity' => 0]);

    expect($line->fresh())->toBeNull();
});

it('will not let one browser touch another browser basket', function () {
    // ⛔ A line number is guessable and a basket is somebody's.
    $add = shopPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = basketToken($add);
    $line = basketOf($token)->lines()->first();

    // A different browser: no cookie at all.
    $this->delete('http://shop.test/cart/lines/'.$line->id)->assertNotFound();

    expect($line->fresh())->not->toBeNull();
});

it('shows an add button on a product the shop can sell, and none on one it cannot', function () {
    $this->get('http://shop.test/products/sundarban-honey')
        ->assertOk()
        ->assertSee('Add to basket', false);

    $this->product->update(['availability_override' => AvailabilityPolicy::Strict]);

    $this->get('http://shop.test/products/sundarban-honey')
        ->assertOk()
        ->assertDontSee('Add to basket', false)
        ->assertSee('Out of stock.', false);
});

it('has no account and no coupon route, because they do not exist yet', function () {
    // ⛔ Absent, not disabled. A disabled feature is a URL somebody can still
    // find; each of these arrives in its own slice, complete.
    //
    // ⚠ NARROWED, NOT WEAKENED, when slice 2.4 shipped. `/checkout` was on this
    // list and is now a real route, so asserting its absence would be asserting
    // the past. Accounts (2.6) and coupons (2.5) are still genuinely absent and
    // that is what this test is now for.
    foreach (['/account', '/coupons'] as $path) {
        $this->get('http://shop.test'.$path)->assertNotFound();
    }
});

it('has no basket on the ERP own hostname', function () {
    // ⚠ The storefront routes are registered globally, so the thing to prove is
    // that they answer nothing when the host is not a shop.
    $this->get('/cart')->assertNotFound();
});
