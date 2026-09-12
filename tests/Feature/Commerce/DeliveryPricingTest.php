<?php

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\CartPresenter;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;

/**
 * Website Phase 2, slice 2.3: what delivery costs.
 *
 * ⭐⭐ THE DEFECT THIS SLICE EXISTS TO NOT REPEAT. LaLaDia's checkout asked the
 * customer for their district AND for a "Delivery Zone" - which is really the
 * price band - and nothing in the codebase compared the two answers. Somebody in
 * Bagerhat could pick "Inside Dhaka" and pay 60 instead of 120, on every order,
 * for months.
 *
 * Here the band is never an input at all. The customer states a district; the
 * shop states the price. There is nothing to disagree about, and the first test
 * below is what holds that.
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

    $row = $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey']);
    $row->publish();

    $home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $home->forceFill(['published_at' => now()])->save();

    // Three bands, the shape every Bangladeshi shop actually uses.
    $this->inside = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Inside Dhaka', 'charge' => '60']);
    $this->suburb = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Dhaka Suburbs', 'charge' => '90']);
    $this->outside = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Outside Dhaka', 'charge' => '120']);
    $this->outside->makeDefault();

    DistrictShippingZone::create(['store_id' => $this->store->id, 'district' => 'Dhaka', 'shipping_zone_id' => $this->inside->id]);
    DistrictShippingZone::create(['store_id' => $this->store->id, 'district' => 'Gazipur', 'shipping_zone_id' => $this->suburb->id]);

    $this->quote = fn (?string $district) => DeliveryQuote::for($this->store->fresh(), $district);
});

/**
 * ⚠ Declared here rather than borrowed from CartTest, although that file has
 * the same three helpers. Test files share a global function namespace, so
 * calling its `deliveryPost()` works in a full run and fails with "undefined
 * function" when this file is run on its own - and a test that only passes
 * alongside another is not a test anybody can trust.
 */
function deliveryPost(string $path, array $data = [])
{
    return test()->post('http://shop.test'.$path, $data);
}

/**
 * ⚠ Decrypted, because `EncryptCookies` encrypts everything on the way out;
 * the raw value is ciphertext that matches no row.
 */
function deliveryToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

/**
 * ⚠ `withCookie`, not `withUnencryptedCookie`: the helper encrypts what it is
 * given, which is what the middleware then decrypts. A cookie it cannot decrypt
 * is silently dropped, so the shop sees no basket and assertions fail for
 * entirely the wrong reason.
 */
function deliverySameBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

function deliveryBasketOf(string $token): ?Cart
{
    return Cart::query()->where('token', $token)->first();
}

/* -------------------------------------------------------------------------
 | The charge is derived, never stated
 | ---------------------------------------------------------------------- */

it('never lets the customer state a band or a charge', function () {
    // ⭐⭐ THE WHOLE SLICE IN ONE TEST. The only delivery input the shop accepts
    // is a district. A browser posting a zone, a band or a charge alongside it
    // is ignored entirely - so a Bagerhat address cannot be paired with the
    // Dhaka rate, however the request is crafted.
    $response = deliveryPost('/cart/delivery', [
        'district' => 'Bagerhat',
        'shipping_zone_id' => $this->inside->id,
        'zone' => 'Inside Dhaka',
        'charge' => '0',
        'delivery_charge' => '1',
    ]);

    $response->assertRedirect('/cart');

    $cart = deliveryBasketOf(deliveryToken($response));
    $quote = DeliveryQuote::for($this->store, $cart->delivery_district);

    expect($cart->delivery_district)->toBe('Bagerhat')
        ->and($quote->zoneName())->toBe('Outside Dhaka')
        ->and($quote->charge())->toBe('120.00');
});

it('charges what the district says', function (string $district, string $zone, string $charge) {
    $quote = ($this->quote)($district);

    expect($quote->zoneName())->toBe($zone)
        ->and($quote->charge())->toBe($charge)
        ->and($quote->cannotQuote())->toBeFalse();
})->with([
    ['Dhaka', 'Inside Dhaka', '60.00'],
    ['Gazipur', 'Dhaka Suburbs', '90.00'],
    // Not mapped, so the catch-all - which is a real rate somebody set.
    ['Bagerhat', 'Outside Dhaka', '120.00'],
]);

it('prices a courier spelling correctly', function () {
    // ⚠ The rate table stores the dataset's own spelling, and the lookup
    // resolves through BdGeo first, so the two always meet. Store "Chittagong"
    // and the customer's "Chattogram" would otherwise fall to the catch-all and
    // the shop would quietly charge the wrong rate with nothing saying so.
    DistrictShippingZone::create([
        'store_id' => $this->store->id, 'district' => 'Chittagong', 'shipping_zone_id' => $this->suburb->id,
    ]);

    expect(DistrictShippingZone::where('district', 'Chattogram')->exists())->toBeTrue()
        ->and(($this->quote)('Chattogram')->charge())->toBe('90.00')
        ->and(($this->quote)('Chittagong')->charge())->toBe('90.00');
});

it('marks a catch-all rate as the fallback it is', function () {
    // ⚠ The theme has to tell these apart: "we deliver to Dhaka for 60" reads
    // differently from "anywhere else is 120", and an admin needs to see which
    // districts nobody has priced.
    expect(($this->quote)('Dhaka')->isFallback)->toBeFalse()
        ->and(($this->quote)('Bagerhat')->isFallback)->toBeTrue();
});

it('asks rather than guessing when no district has been chosen', function () {
    $quote = ($this->quote)(null);

    expect($quote->district)->toBeNull()
        ->and($quote->zone)->toBeNull()
        // ⚠ Distinct from "cannot quote": nothing is wrong, nobody has said
        // where they live yet.
        ->and($quote->cannotQuote())->toBeFalse();
});

it('refuses to quote rather than deliver free when the shop has no catch-all', function () {
    // ⛔⛔ Falling back to zero would be the shop paying for delivery it never
    // agreed to, silently, for every district nobody got round to pricing. Free
    // delivery is a decision somebody makes, not the absence of a decision.
    ShippingZone::query()->update(['is_default' => false]);

    $quote = ($this->quote)('Bagerhat');

    expect($quote->zone)->toBeNull()
        ->and($quote->charge())->toBeNull()
        ->and($quote->cannotQuote())->toBeTrue();
});

it('keeps exactly one catch-all however many times one is set', function () {
    $this->inside->makeDefault();

    expect(ShippingZone::where('store_id', $this->store->id)->where('is_default', true)->count())->toBe(1)
        ->and($this->inside->fresh()->is_default)->toBeTrue()
        ->and($this->outside->fresh()->is_default)->toBeFalse();
});

it('lets two shops charge different rates for the same district', function () {
    $other = Store::create([
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD', 'theme_key' => 'bionic-classic',
    ]);
    $otherZone = ShippingZone::create(['store_id' => $other->id, 'name' => 'Everywhere', 'charge' => '150']);
    $otherZone->makeDefault();
    DistrictShippingZone::create(['store_id' => $other->id, 'district' => 'Dhaka', 'shipping_zone_id' => $otherZone->id]);

    expect(DeliveryQuote::for($this->store, 'Dhaka')->charge())->toBe('60.00')
        ->and(DeliveryQuote::for($other, 'Dhaka')->charge())->toBe('150.00');
});

/* -------------------------------------------------------------------------
 | On the basket
 | ---------------------------------------------------------------------- */

it('adds delivery to the basket total, and follows a rate change with no basket edit', function () {
    $add = deliveryPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 2]);
    $token = deliveryToken($add);

    deliverySameBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Dhaka']);

    $cart = deliveryBasketOf($token);
    $presented = CartPresenter::present($this->store, $cart);

    expect($presented['subtotal'])->toBe('1,700.00')
        ->and($presented['delivery']['charge'])->toBe('60.00')
        ->and($presented['total'])->toBe('1,760.00');

    // ⚠⚠ THE SAME RULE THE PRODUCT PRICE FOLLOWS. The basket holds the district,
    // never the charge, so correcting a rate corrects every open basket on its
    // next read - and a basket can never quote a number the shop has stopped
    // charging.
    $this->inside->update(['charge' => '80']);

    expect(CartPresenter::present($this->store, $cart->fresh())['total'])->toBe('1,780.00');
});

it('states no total at all when it cannot quote delivery', function () {
    // ⚠ Showing the goods subtotal as if it were the total would tell a customer
    // a number the shop has not agreed to honour.
    ShippingZone::query()->update(['is_default' => false]);

    $add = deliveryPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = deliveryToken($add);
    deliverySameBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);

    $presented = CartPresenter::present($this->store, deliveryBasketOf($token));

    expect($presented['subtotal'])->toBe('850.00')
        ->and($presented['total'])->toBeNull()
        ->and($presented['delivery']['cannot_quote'])->toBeTrue();
});

it('refuses a district that is not a real place, and says so', function () {
    deliveryPost('/cart/delivery', ['district' => 'Atlantis'])
        ->assertSessionHasErrors(['district' => 'We do not recognise that district.']);
});

it('remembers the district a customer chose before they added anything', function () {
    $response = deliveryPost('/cart/delivery', ['district' => 'Gazipur']);

    $response->assertRedirect('/cart');

    expect(deliveryBasketOf(deliveryToken($response))?->delivery_district)->toBe('Gazipur');
});

it('offers the basket a district to pick and never a price band', function () {
    // ⛔⛔ THE SCREEN-LEVEL VERSION OF THE SAME RULE. It is not enough that the
    // server ignores a posted band - the page must never invite one, or the
    // next person to touch this template wires the two together again.
    // ⚠ With something in it: an empty basket hides the whole summary, delivery
    // included, because there is nothing to deliver yet.
    $add = deliveryPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);

    $page = deliverySameBrowser(deliveryToken($add))->get('http://shop.test/cart')->assertOk();

    $page->assertSee('name="district"', false)
        ->assertSee('Choose your district', false)
        ->assertDontSee('name="shipping_zone_id"', false)
        ->assertDontSee('name="zone"', false)
        ->assertDontSee('name="charge"', false);
});

it('shows the charge and the total on the basket once a district is chosen', function () {
    $add = deliveryPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = deliveryToken($add);
    deliverySameBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Gazipur']);

    deliverySameBrowser($token)->get('http://shop.test/cart')
        ->assertOk()
        ->assertSee('Dhaka Suburbs', false)
        ->assertSee('90.00', false)
        // 850 + 90, stated as one number the customer can act on.
        ->assertSee('940.00', false);
});

it('tells a customer plainly when it cannot work out their delivery', function () {
    // ⛔ Rather than showing nothing and letting them assume it is free.
    ShippingZone::query()->update(['is_default' => false]);

    $add = deliveryPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]);
    $token = deliveryToken($add);
    deliverySameBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);

    deliverySameBrowser($token)->get('http://shop.test/cart')
        ->assertOk()
        ->assertSee('cannot work out delivery to Bagerhat', false);
});

it('has no delivery route on the ERP own hostname', function () {
    $this->post('http://erp.test/cart/delivery', ['district' => 'Dhaka'])->assertNotFound();
});
