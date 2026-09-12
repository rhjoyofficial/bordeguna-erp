<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\BdGeo;

/**
 * Website Phase 2, slice 2.4: checkout produces a real ERP sales order (COD).
 *
 * ⭐⭐ THE SLICE EXIT, STATED AS TESTS. A customer places an order on a shop and
 * the ERP holds the same document it would hold if somebody had typed it in -
 * one customer, one sales order, agreed prices frozen onto the lines, a
 * destination a courier can route, and NOT ONE stock movement or journal line.
 *
 * ⭐ The two that matter most are the pricing pair. A website is a price group,
 * so the order must price the way the page priced - including for a buyer who
 * happens to be a dealer. The alternative is the shop quoting one number and
 * billing another, which is the defect 83bfd9e fixed one level down.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    $this->retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
    $this->dealer = PriceGroup::create(['code' => 'DEALER', 'name' => 'Dealer']);

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
        'theme_key' => 'bionic-classic', 'price_group_id' => $this->retail->id,
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $this->row = $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey']);
    $this->row->publish();

    $home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $home->forceFill(['published_at' => now()])->save();

    $inside = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Inside Dhaka', 'charge' => '60']);
    $this->outside = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Outside Dhaka', 'charge' => '120']);
    $this->outside->makeDefault();
    DistrictShippingZone::create(['store_id' => $this->store->id, 'district' => 'Dhaka', 'shipping_zone_id' => $inside->id]);

    // A real address in a district the shop prices at its catch-all rate, so
    // every happy-path assertion below has one destination to talk about.
    $this->address = [
        'name' => 'Karim Uddin',
        'phone' => '01711000000',
        'thana' => 'Fakirhat',
        'address_line' => 'House 4, Road 2',
        'payment_method' => 'cod',
    ];
});

/**
 * ⚠ Declared here rather than borrowed from CartTest or DeliveryPricingTest,
 * both of which have helpers of the same shape. Test files share one global
 * function namespace, so borrowing works in a full run and dies with "undefined
 * function" when this file runs alone - and a test that only passes alongside
 * another is not one anybody can trust.
 */
function checkoutPost(string $path, array $data = [])
{
    return test()->post('http://shop.test'.$path, $data);
}

function checkoutGet(string $path)
{
    return test()->get('http://shop.test'.$path);
}

function checkoutToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

/** ⚠ `withCookie`, not `withUnencryptedCookie`: EncryptCookies decrypts what the helper encrypts. */
function checkoutSameBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

/**
 * A browser holding a basket with $quantity of the honey and a delivery
 * district, which is the state every checkout test starts from.
 */
function readyBasket(int $quantity = 1, string $district = 'Bagerhat'): string
{
    $token = checkoutToken(checkoutPost('/cart/lines', [
        'product' => test()->product->id, 'quantity' => $quantity,
    ]));

    checkoutSameBrowser($token)->post('http://shop.test/cart/delivery', ['district' => $district]);

    return $token;
}

/* -------------------------------------------------------------------------
 | The exit: a basket becomes a real ERP sales order
 | ---------------------------------------------------------------------- */

it('writes one real sales order carrying the shop, the channel and the agreement', function () {
    $token = readyBasket(2);

    $response = checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    /** @var SalesOrder $order */
    $order = SalesOrder::query()->sole();

    $response->assertRedirect('http://shop.test/orders/'.$order->shop_reference);

    // The dimensions D17 asked for, and they are on the ORDER rather than on a
    // website-only table beside it.
    expect($order->store_id)->toBe($this->store->id)
        ->and($order->channel)->toBe(SalesChannel::Website)
        ->and($order->effectiveChannel())->toBe(SalesChannel::Website)
        // ⚠ Both numbers exist and they are different things: the ERP's own
        // sequential number, and the prefixed random reference the customer
        // quotes down the telephone.
        ->and($order->order_number)->toStartWith('SO-')
        ->and($order->shop_reference)->toStartWith('BNC-')
        ->and($order->payment_method)->toBe('cod')
        // Frozen from the rate table at order time, not resolved on read.
        ->and((string) $order->delivery_charge)->toBe('120.00')
        // ⚠ Null, deliberately: which warehouse fulfils a web order is decided
        // when somebody picks the goods.
        ->and($order->warehouse_id)->toBeNull()
        ->and($order->created_by)->toBe(SystemActor::id());

    $line = $order->lines->sole();

    expect((float) $line->quantity_ordered)->toBe(2.0)
        ->and((float) $line->unit_price)->toBe(850.0)
        // ⚠ The resolver's own explanation travels with the number, exactly as
        // it does for an order typed at the counter.
        ->and($line->price_note)->not->toBeNull();
});

it('records a destination a courier can actually route, with the postcode derived', function () {
    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'landmark' => 'Beside the mosque']);

    $address = SalesOrder::query()->sole()->address;

    expect($address)->not->toBeNull()
        ->and($address->district)->toBe('Bagerhat')
        ->and($address->thana)->toBe('Fakirhat')
        ->and($address->recipient_name)->toBe('Karim Uddin')
        ->and($address->recipient_phone)->toBe('01711000000')
        ->and($address->address_line)->toBe('House 4, Road 2')
        ->and($address->landmark)->toBe('Beside the mosque');

    // ⭐⭐ DERIVED, NEVER POSTED. Slice 2.2 keeps the postcode off the page
    // precisely so a form cannot send one back, and this is where that work
    // finally pays: the shop worked it out from the pair the customer chose.
    expect($address->postcode)->toBe(BdGeo::postcodeFor('Bagerhat', 'Fakirhat'));
});

it('records the delivery date the shop already promised the customer', function () {
    // ⭐ The product page says "made to order, ready in N days" from
    // `products.lead_time_days`. Until this test, nothing carried that sentence
    // onto the order - so the one document that IS the promise left
    // `expected_date` empty on exactly the orders that carry a promise, and
    // whoever picks the goods had no idea what the buyer had been told.
    $this->product->forceFill(['lead_time_days' => 7])->save();

    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⚠ Made to order because the warehouse holds none of it, which is the
    // ordinary state of this catalogue - the ERP has taken no stock in yet.
    expect(SalesOrder::query()->sole()->expected_date?->toDateString())
        ->toBe(now()->addDays(7)->toDateString());
});

it('promises nothing when the shop has set no lead time', function () {
    // ⚠ Null promises nothing, which is why that column is nullable. Q7 - who
    // maintains lead time - is still open, and a shop that has not answered it
    // must not have a date invented on its behalf.
    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    expect(SalesOrder::query()->sole()->expected_date)->toBeNull();
});

it('moves no stock and posts no journal, because an order is a promise', function () {
    $token = readyBasket(3);

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⭐⭐ THE RULE ADR-004 INSISTS ON. A web sale at zero stock records a
    // COMMITMENT and never a movement - and a sales order already is one, so
    // this holds by construction rather than by care. `inventory:verify-ledger`
    // would catch a lie; there is no lie to catch.
    expect(SalesOrder::query()->count())->toBe(1)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(Journal::query()->count())->toBe(0);
});

it('leaves the order pending approval so somebody confirms it before goods leave', function () {
    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $order = SalesOrder::query()->sole();

    // ⭐ The feature, not an oversight. SystemActor holds no
    // `sales.orders.approve`, so a website order sits in the approval inbox -
    // which is LaLaDia's "confirm before dispatch" gate, on a shop where 66% of
    // COD orders were cancelled.
    expect($order->approvalRequest->isPending())->toBeTrue()
        ->and($order->isDeliverable())->toBeFalse();
});

/* -------------------------------------------------------------------------
 | The pricing pair: the order prices the way the page priced
 | ---------------------------------------------------------------------- */

it('bills the quantity-break price the basket showed, not the single-unit one', function () {
    // A break the shop genuinely set, in the store's own group.
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '800', 'min_quantity' => '5', 'effective_from' => '2026-01-01',
    ]);

    $token = readyBasket(5);

    $basket = checkoutSameBrowser($token)->get('http://shop.test/cart');
    $basket->assertSee('800');

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⭐⭐ EQUAL, not similar. Before 83bfd9e the storefront took the LOWEST
    // quantity break and the order engine the HIGHEST, so this basket would have
    // been quoted 850 and billed 800 - the shop showing one number and recording
    // another on exactly the product it chose to discount.
    expect((float) SalesOrder::query()->sole()->lines->sole()->unit_price)->toBe(800.0);
});

it('prices a dealer as the website, because a website is a price group', function () {
    // This buyer already exists as a DEALER, with a dealer rate on the product.
    $dealer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01711000000', 'price_group_id' => $this->dealer->id,
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->dealer->id,
        'unit_price' => '700', 'effective_from' => '2026-01-01',
    ]);

    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $order = SalesOrder::query()->sole();

    // ⭐⭐ THE DECISION, HELD BY A TEST. Websites are B2C only, and the basket
    // was priced at the shop's group with no customer known. If the order priced
    // at the BUYER's group instead, this page would have quoted 850 and the
    // document would say 700.
    expect($order->customer_id)->toBe($dealer->id)
        ->and((float) $order->lines->sole()->unit_price)->toBe(850.0);

    // ⚠ And their own record is untouched, so the same person is still priced
    // as a dealer at the counter tomorrow.
    expect($dealer->fresh()->price_group_id)->toBe($this->dealer->id);
});

it('prices as the shop even when the shop points at no price group at all', function () {
    // ⚠⚠ THE CASE THAT NEARLY SLIPPED THROUGH. `stores.price_group_id` is
    // nullable (slice 1.7), and such a shop's basket is priced on the UNGROUPED
    // default tier. The first version decided "price as the channel" by asking
    // whether a group was set - so this shop alone would have fallen back to the
    // CUSTOMER path, and a dealer here would be quoted 850 on the page and
    // billed 700 on the document. The channel decides whose identity prices the
    // order; which group, or none, is a separate question.
    $this->store->forceFill(['price_group_id' => null])->save();

    $dealer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders',
        'phone' => '01711000000', 'price_group_id' => $this->dealer->id,
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->dealer->id,
        'unit_price' => '700', 'effective_from' => '2026-01-01',
    ]);

    $token = readyBasket();

    // The page and the document, asserted to be the same number rather than
    // each asserted against a constant - which is the only assertion that
    // actually holds "the shop bills what it showed".
    // ⚠ Asserted as MONEY, not as the bare number: the theme's own stylesheet
    // contains `font-weight: 700`, so a bare "700" matched the page and this
    // test failed on its own wording rather than on the code.
    $basket = checkoutSameBrowser($token)->get('http://shop.test/cart');
    $basket->assertSee('BDT 850.00');
    $basket->assertDontSee('BDT 700.00');

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $order = SalesOrder::query()->sole();

    expect($order->customer_id)->toBe($dealer->id)
        ->and((float) $order->lines->sole()->unit_price)->toBe(850.0);
});

it('never lets a price agreed with one customer reach a public checkout', function () {
    $buyer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Rahim Traders', 'phone' => '01711000000',
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'customer_id' => $buyer->id,
        'unit_price' => '500', 'effective_from' => '2026-01-01',
    ]);

    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⛔ A privately agreed rate is not a website rate, and a stranger who
    // guesses a phone number must not be able to buy at one.
    expect((float) SalesOrder::query()->sole()->lines->sole()->unit_price)->toBe(850.0);
});

/* -------------------------------------------------------------------------
 | Who the customer is
 | ---------------------------------------------------------------------- */

it('matches an existing customer on the normalised phone without rewriting them', function () {
    $existing = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Karim Uddin Sardar',
        'phone' => '+880 1711-000000', 'address' => 'The address they gave us in July',
    ]);

    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⚠ One customer, not two. `+880 1711-000000` and `01711000000` are the
    // same person, which is the whole job of PhoneIdentity.
    expect(Customer::query()->count())->toBe(1)
        ->and(SalesOrder::query()->sole()->customer_id)->toBe($existing->id);

    // ⚠⚠ AND NOTHING OF THEIRS WAS OVERWRITTEN. A stranger typing a name into a
    // public form must not be able to edit a customer record, and Joy's standing
    // rule is that we do not silently correct somebody's data.
    expect($existing->fresh()->name)->toBe('Karim Uddin Sardar')
        ->and($existing->fresh()->address)->toBe('The address they gave us in July');
});

it('creates a new customer with no price group, so their counter price is untouched', function () {
    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $customer = Customer::query()->sole();

    // ⚠ Null means RETAIL (D-PRC-2). Stamping the shop's own group onto the
    // person would change what they are charged at the counter, which is not a
    // decision a public form is allowed to make.
    expect($customer->price_group_id)->toBeNull()
        ->and($customer->phone_normalized)->toBe('01711000000')
        ->and($customer->is_system)->toBeFalse();
});

/* -------------------------------------------------------------------------
 | The district cannot be argued with
 | ---------------------------------------------------------------------- */

it('takes the district from the basket and ignores one posted with the address', function () {
    $token = readyBasket(1, 'Bagerhat');

    // A crafted post naming the cheap district, plus a band and a charge for
    // good measure.
    checkoutSameBrowser($token)->post('http://shop.test/checkout', [
        ...$this->address,
        'district' => 'Dhaka',
        'zone' => 'Inside Dhaka',
        'charge' => '0',
        'delivery_charge' => '0',
    ]);

    $order = SalesOrder::query()->sole();

    // ⭐⭐ Slice 2.3's rule carried into the order: the only delivery input is
    // the district on the basket, so there is no disagreeing pair to refuse.
    expect($order->address->district)->toBe('Bagerhat')
        ->and((string) $order->delivery_charge)->toBe('120.00');
});

it('offers no band, charge or postcode field anywhere on the checkout page', function () {
    $token = readyBasket();

    $page = checkoutSameBrowser($token)->get('http://shop.test/checkout');

    // ⚠ At the PAGE level deliberately, like slice 2.3's pair: the next person
    // to touch this template is who would wire a zone selector back in, and a
    // server-side guard alone would let the form ask a question it then ignores.
    $page->assertOk()
        ->assertDontSee('name="district"', false)
        ->assertDontSee('name="zone"', false)
        ->assertDontSee('name="charge"', false)
        ->assertDontSee('name="delivery_charge"', false)
        ->assertDontSee('name="shipping_zone_id"', false)
        ->assertDontSee('name="postcode"', false);
});

it('hides the area list until a thana is known, rather than offering a dead control', function () {
    $token = readyBasket();

    // ⚠ Areas belong to a thana, and choosing a thana does not reload this page,
    // so on a first pass the server has no thana to list areas for. The row is
    // rendered hidden and the one script on the page fills it. A select that can
    // never be filled is a dead control, not a courtesy - and because area is
    // optional, a browser that runs no script still places the order.
    checkoutSameBrowser($token)->get('http://shop.test/checkout')
        ->assertOk()
        ->assertSee('id="co-area-row" hidden', false);
});

/* -------------------------------------------------------------------------
 | Refusals: every one a sentence, never a 500
 | ---------------------------------------------------------------------- */

it('refuses a basket with nothing in it', function () {
    checkoutPost('/checkout', $this->address)->assertRedirect('http://shop.test/cart');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses when no district has been chosen', function () {
    $token = checkoutToken(checkoutPost('/cart/lines', ['product' => $this->product->id, 'quantity' => 1]));

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors('district');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses rather than delivering free when the shop has no rate covering the district', function () {
    // ⛔ The shop's catch-all removed: slice 2.3 refuses to quote, and this is
    // the moment that decision either holds or quietly costs real money.
    $this->outside->forceFill(['is_default' => false])->save();

    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors('district');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses a basket holding something the shop can no longer sell, and says which', function () {
    $token = readyBasket();

    // Taken off sale after it went in the basket, which is the ordinary case.
    $this->row->forceFill(['published_at' => null])->save();

    $response = checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->first('lines'))->toContain('Sundarban Honey 500g');
    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses a thana that does not belong to the chosen district', function () {
    $token = readyBasket(1, 'Bagerhat');

    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'thana' => 'Gulshan'])
        ->assertSessionHasErrors('thana');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses an order with no street address', function () {
    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'address_line' => ''])
        ->assertSessionHasErrors('address_line');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('refuses any payment method other than cash on delivery', function () {
    $token = readyBasket();

    // ⛔ A form that can post this must not be able to record an order as
    // paid-by-gateway that no gateway ever saw.
    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'payment_method' => 'online'])
        ->assertSessionHasErrors('payment_method');

    expect(SalesOrder::query()->count())->toBe(0);
});

it('drops an area it does not recognise rather than losing the sale over it', function () {
    $token = readyBasket();

    // ⚠ The dataset's area coverage is uneven, and the district/thana pair is
    // what routes a parcel. Refusing here would lose an order over our own data.
    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'area' => 'Nowhere In Particular']);

    expect(SalesOrder::query()->sole()->address->area)->toBeNull();
});

/* -------------------------------------------------------------------------
 | Pressing the button twice
 | ---------------------------------------------------------------------- */

it('writes one order however many times the button is pressed', function () {
    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);
    $first = SalesOrder::query()->sole();

    // ⚠ A double tap on a poor connection is not an edge case. The basket knows
    // which order it became, so the second press finds it.
    $again = checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);

    $again->assertRedirect('http://shop.test/orders/'.$first->shop_reference);
    expect(SalesOrder::query()->count())->toBe(1);
});

it('shows a customer their order, not an empty basket, once it is placed', function () {
    // ⭐⭐ FOUND BY A TEST THAT PRESSED THE BUTTON TWICE. The action refuses to
    // write a twin order for a converted basket - but `CurrentCart::find()`
    // deliberately ignores converted baskets, so through a browser that guard
    // was unreachable and the second press landed on an EMPTY BASKET seconds
    // after ordering. A guard nothing can reach is not a guard, and "my order
    // vanished" is how a second order or a support call gets made.
    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);
    $reference = SalesOrder::query()->sole()->shop_reference;

    checkoutSameBrowser($token)->get('http://shop.test/checkout')
        ->assertRedirect('http://shop.test/orders/'.$reference);
});

it('marks the basket converted and points it at the order it became', function () {
    $token = readyBasket();

    checkoutSameBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'email' => 'karim@example.test']);

    /** @var Cart $cart */
    $cart = Cart::query()->where('token', $token)->sole();
    $order = SalesOrder::query()->sole();

    expect($cart->isConverted())->toBeTrue()
        ->and($cart->sales_order_id)->toBe($order->id)
        // ⚠ The contact AS TYPED stays on the basket too. The cart table exists
        // so a basket that never became an order is still recoverable - and
        // LaLaDia's recovery campaign had zero recipients because its carts
        // stored no contact at all.
        ->and($cart->contact_name)->toBe('Karim Uddin')
        ->and($cart->contact_phone)->toBe('01711000000')
        ->and($cart->contact_email)->toBe('karim@example.test');
});

/* -------------------------------------------------------------------------
 | The order page
 | ---------------------------------------------------------------------- */

it('shows the order back to the customer with the figures it agreed', function () {
    $token = readyBasket(2);

    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);
    $order = SalesOrder::query()->sole();

    $page = checkoutGet('/orders/'.$order->shop_reference);

    $page->assertOk()
        ->assertSee($order->shop_reference)
        ->assertSee('Karim Uddin')
        // Goods 1,700 plus 120 delivery.
        ->assertSee('1,820')
        // ⛔ Never the ERP's own sequential number, which tells a reader how
        // much the business sold between any two orders.
        ->assertDontSee($order->order_number)
        // ⚠ noindex on every version of this page, whatever the store says.
        ->assertSee('noindex', false);
});

it('never renders another shop\'s order, even inside the same business', function () {
    $token = readyBasket();
    checkoutSameBrowser($token)->post('http://shop.test/checkout', $this->address);
    $reference = SalesOrder::query()->sole()->shop_reference;

    $other = Store::create([
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD',
        'theme_key' => 'bionic-classic', 'price_group_id' => $this->retail->id,
    ]);
    StoreDomain::create(['store_id' => $other->id, 'hostname' => 'other.test', 'is_primary' => true]);
    $other->forceFill(['status' => 'live'])->save();
    Page::create(['store_id' => $other->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome'])
        ->forceFill(['published_at' => now()])->save();

    test()->get('http://other.test/orders/'.$reference)->assertNotFound();
});

it('never exposes an internal order on a public page', function () {
    // An order typed into the ERP carries no shop reference at all, so there is
    // nothing to look up - but the controller scopes to website orders as well,
    // because the absence of a value is a weaker guard than a stated rule.
    checkoutGet('/orders/SO-FY2026-0001')->assertNotFound();
});
