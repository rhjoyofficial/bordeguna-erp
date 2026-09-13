<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\PageSection;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;

/**
 * Website Phase 2, slice 2.5b: a coupon from the basket to the order.
 *
 * ⭐⭐ Joy's three answers of 13 September, stated as tests: a cancelled order
 * does not use a coupon up; a store-wide coupon discounts bundles too; and a
 * coupon is an ORDER-level discount (billed on the invoice to 4210).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-13 10:00:00');

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    $retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $make = function (string $sku, string $name, string $price) use ($unit): Product {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name, 'type' => ProductType::FinishedGood, 'is_active' => true,
        ]);
        ProductPrice::create(['product_id' => $product->id, 'unit_price' => $price, 'effective_from' => '2026-01-01']);

        return $product;
    };

    $this->hilsa = $make('PIC-HIL-400', 'Hilsa Fish Pickle 400g', '999');
    $this->beef = $make('PIC-BEF-400', 'Royal Beef Pickle 400g', '1350');
    $this->honey = $make('HON-250', 'Sundarban Honey 250g', '450');

    $this->store = Store::create([
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD',
        'theme_key' => 'bionic-classic', 'price_group_id' => $retail->id,
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    foreach ([$this->hilsa, $this->beef, $this->honey] as $product) {
        $this->store->products()->create(['product_id' => $product->id, 'slug' => strtolower($product->sku)])->publish();
    }

    $home = Page::create(['store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome']);
    $home->forceFill(['published_at' => now()])->save();
    $this->home = $home;

    ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Outside Dhaka', 'charge' => '120'])->makeDefault();

    $this->coupon = fn (array $attributes = []) => StoreCoupon::create(array_merge([
        'store_id' => $this->store->id, 'code' => 'WELCOME10', 'type' => 'percent', 'value' => '10',
        'applies_to' => 'all', 'is_active' => true,
    ], $attributes));

    $this->address = [
        'name' => 'Karim Uddin', 'phone' => '01711000000', 'thana' => 'Fakirhat',
        'address_line' => 'House 4, Road 2', 'payment_method' => 'cod',
    ];
});

afterEach(fn () => Carbon::setTestNow());

/** ⚠ Own names: test files share one global function namespace. */
function couponToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

function couponBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

function couponBasket(array $lines, ?string $code = null): string
{
    $token = null;

    foreach ($lines as [$product, $quantity]) {
        $response = ($token === null ? test() : couponBrowser($token))
            ->post('http://shop.test/cart/lines', ['product' => $product->id, 'quantity' => $quantity]);
        $token ??= couponToken($response);
    }

    couponBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);

    if ($code !== null) {
        couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => $code])->assertSessionHasNoErrors();
    }

    return $token;
}

function basketOfToken(string $token): array
{
    return couponBrowser($token)->get('http://shop.test/cart')->original->getData()['cart'];
}

it('takes a percentage off the basket and states it', function () {
    ($this->coupon)();

    // A customer typing the code off a flyer in lowercase has typed the code.
    $token = couponBasket([[$this->hilsa, 2]], 'welcome10');
    $cart = basketOfToken($token);

    // 1,998 less 10% is 199.80 off; with delivery 120 the total is 1,918.20.
    expect($cart['coupon']['code'])->toBe('WELCOME10')
        ->and($cart['coupon']['discount'])->toBe('199.80')
        ->and($cart['total'])->toBe('1,918.20');
});

it('refuses a code that does not exist with the same sentence as one that is switched off', function () {
    ($this->coupon)(['code' => 'OFFCODE', 'is_active' => false]);

    $token = couponBasket([[$this->hilsa, 1]]);

    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'NOSUCH'])
        ->assertSessionHasErrors(['code' => 'That code is not valid.']);
    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'OFFCODE'])
        ->assertSessionHasErrors(['code' => 'That code is not valid.']);

    // ⚠ Not stored: a refused code does not sit on the basket with an error.
    expect(Cart::sole()->coupon_code)->toBeNull();
});

it('does not honour another shop\'s code', function () {
    $other = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    StoreCoupon::create(['store_id' => $other->id, 'code' => 'BIONIC20', 'type' => 'percent', 'value' => '20', 'applies_to' => 'all']);

    $token = couponBasket([[$this->hilsa, 1]]);

    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'BIONIC20'])
        ->assertSessionHasErrors(['code' => 'That code is not valid.']);
});

it('checks a minimum spend against the whole basket', function () {
    ($this->coupon)(['min_purchase' => '1400', 'applies_to' => 'products']);
    StoreCoupon::sole()->products()->sync([$this->honey->id]);

    // Honey alone is 450: below the minimum.
    $token = couponBasket([[$this->honey, 1]]);
    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'WELCOME10'])
        ->assertSessionHasErrors(['code' => 'This code needs a basket of at least BDT 1,400.00.']);

    // ⭐ With a hilsa beside it the BASKET clears 1,400 (450 + 999), and the discount still
    // applies only to the honey it covers: 10% of 450.
    couponBrowser($token)->post('http://shop.test/cart/lines', ['product' => $this->hilsa->id, 'quantity' => 1]);
    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'WELCOME10'])->assertSessionHasNoErrors();

    expect(basketOfToken($token)['coupon']['discount'])->toBe('45.00');
});

it('refuses a scoped code that covers nothing in the basket', function () {
    ($this->coupon)(['applies_to' => 'products']);
    StoreCoupon::sole()->products()->sync([$this->honey->id]);

    $token = couponBasket([[$this->hilsa, 1]]);

    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'WELCOME10'])
        ->assertSessionHasErrors(['code' => 'This code does not apply to anything in your basket.']);
});

it('discounts a bundle with a store-wide code', function () {
    // ⭐ Joy, 13 September.
    ($this->coupon)();

    $duo = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo', 'price' => '2000']);
    $duo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $duo->items()->create(['product_id' => $this->beef->id, 'quantity' => 1]);
    $duo->publish();

    $token = couponToken($this->post('http://shop.test/cart/combos', ['combo' => $duo->id, 'quantity' => 1]));
    couponBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);
    couponBrowser($token)->post('http://shop.test/cart/coupon', ['code' => 'WELCOME10'])->assertSessionHasNoErrors();

    expect(basketOfToken($token)['coupon']['discount'])->toBe('200.00');
});

it('caps a fixed amount at what it applies to', function () {
    ($this->coupon)(['type' => 'fixed', 'value' => '600', 'applies_to' => 'products']);
    StoreCoupon::sole()->products()->sync([$this->honey->id]);

    $token = couponBasket([[$this->honey, 1], [$this->hilsa, 1]], 'WELCOME10');

    expect(basketOfToken($token)['coupon']['discount'])->toBe('450.00');
});

it('records the coupon as an order-level discount, and nothing moves or posts', function () {
    ($this->coupon)();
    $token = couponBasket([[$this->hilsa, 2]], 'WELCOME10');

    couponBrowser($token)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    $order = SalesOrder::with('lines')->sole();

    // ⚠ The line is at full price: the coupon is an ORDER discount, not a line one.
    expect((string) $order->lines->first()->line_discount)->toBe('0.0000')
        ->and((string) $order->order_discount)->toBe('199.8000')
        ->and($order->coupon_code)->toBe('WELCOME10')
        ->and($order->goodsTotal())->toBe('1998.00')
        ->and($order->agreedTotal())->toBe('1918.20')
        ->and(StockMovement::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('does not let a cancelled or rejected order use a coupon up', function () {
    // ⭐ Joy, 13 September: one use, and the first order is cancelled.
    ($this->coupon)(['usage_limit' => 1]);

    couponBrowser(couponBasket([[$this->hilsa, 1]], 'WELCOME10'))->post('http://shop.test/checkout', $this->address)->assertRedirect();

    SalesOrder::sole()->forceFill(['status' => SalesOrderStatus::Cancelled])->save();

    $second = couponBasket([[$this->hilsa, 1]], 'WELCOME10');
    couponBrowser($second)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    expect(SalesOrder::count())->toBe(2);

    // A rejected order does not count either.
    SalesOrder::latest('id')->first()->approvalRequest->forceFill(['status' => ApprovalStatus::Rejected])->save();

    $third = couponBasket([[$this->hilsa, 1]], 'WELCOME10');
    couponBrowser($third)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    expect(SalesOrder::count())->toBe(3);

    // ...but a live order does.
    couponBrowser(couponBasket([[$this->hilsa, 1]]))->post('http://shop.test/cart/coupon', ['code' => 'WELCOME10'])
        ->assertSessionHasErrors(['code' => 'This code has already been used as many times as it allows.']);
});

it('holds a customer to a per-customer limit at checkout, where the phone is known', function () {
    ($this->coupon)(['limit_per_customer' => 1]);

    couponBrowser(couponBasket([[$this->hilsa, 1]], 'WELCOME10'))->post('http://shop.test/checkout', $this->address)->assertRedirect();

    // The same phone again: the basket cannot know who it is, the checkout can.
    $again = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    couponBrowser($again)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors(['code' => 'Your code WELCOME10 cannot be used: You have already used this code as many times as it allows. Remove it from your basket to order without it.']);

    // A different customer is not refused.
    couponBrowser($again)->post('http://shop.test/checkout', [...$this->address, 'phone' => '01811000000'])->assertRedirect();

    expect(SalesOrder::count())->toBe(2);
});

it('refuses the order, with the reason, when a code expired after it was applied', function () {
    ($this->coupon)(['ends_at' => '2026-09-13 18:00:00']);
    $token = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    Carbon::setTestNow('2026-09-13 19:00:00');

    // ⚠ The basket still SHOWS the code, with its reason, and leaves it out of
    // the total; it is not dropped in silence.
    $cart = basketOfToken($token);
    expect($cart['coupon']['problem'])->toBe('This code has expired.')
        ->and($cart['total'])->toBe('1,119.00');

    couponBrowser($token)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors('code');

    expect(SalesOrder::count())->toBe(0);
});

it('gives a gift as a real line whose value is part of the order discount', function () {
    ($this->coupon)(['value' => '0', 'gift_product_id' => $this->honey->id, 'gift_quantity' => 1]);
    $token = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    expect(basketOfToken($token)['coupon']['gift'])->toBe(['title' => 'Sundarban Honey 250g', 'quantity' => 1, 'worth' => '450.00']);

    couponBrowser($token)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    $order = SalesOrder::with('lines')->sole();
    $gift = $order->lines->firstWhere('product_id', $this->honey->id);

    // The honey is priced by the engine, so its stock and cost are real, and the
    // customer pays nothing for it because its value is in the order discount.
    expect((string) $gift->unit_price)->toBe('450.0000')
        ->and($gift->bundle_label)->toBe('Free with WELCOME10')
        ->and((string) $order->order_discount)->toBe('450.0000')
        ->and($order->agreedTotal())->toBe('1119.00');
});

it('says so when a promised gift cannot be given, rather than dropping it', function () {
    ($this->coupon)(['gift_product_id' => $this->honey->id]);
    $this->honey->update(['is_active' => false]);

    $token = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    expect(basketOfToken($token)['coupon']['gift'])->toBeNull()
        ->and(basketOfToken($token)['coupon']['gift_problem'])->toContain('is not available right now');
});

it('delivers free with a free-delivery code', function () {
    ($this->coupon)(['value' => '0', 'is_free_delivery' => true]);
    $token = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    expect(basketOfToken($token)['delivery']['charge'])->toBe('0.00');

    couponBrowser($token)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    expect((string) SalesOrder::sole()->delivery_charge)->toBe('0.00');
});

it('refuses the second customer once the last use of a code is taken', function () {
    // ⚠ This proves the last use is COUNTED at the button. It cannot prove the
    // row lock: lockForUpdate is a no-op on SQLite, which the suite runs on.
    ($this->coupon)(['usage_limit' => 1]);

    $first = couponBasket([[$this->hilsa, 1]], 'WELCOME10');

    // ⚠ The second customer's basket is made directly: the test client carries
    // the first browser's cookie into every later request, so a second basket
    // built through requests would be the same basket.
    $other = Cart::create([
        'store_id' => $this->store->id, 'token' => Cart::newToken(),
        'delivery_district' => 'Bagerhat', 'coupon_code' => 'WELCOME10',
    ]);
    $other->lines()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $second = $other->token;

    couponBrowser($first)->post('http://shop.test/checkout', $this->address)->assertRedirect();
    couponBrowser($second)->post('http://shop.test/checkout', [...$this->address, 'phone' => '01811000000'])
        ->assertSessionHasErrors('code');

    expect(SalesOrder::count())->toBe(1);
});

it('advertises a code in the strip only while checkout would take it', function () {
    ($this->coupon)(['ends_at' => '2026-09-13 18:00:00']);

    $strip = PageSection::create([
        'page_id' => $this->home->id, 'type_key' => 'announcement.strip', 'type_version' => 1, 'position' => 0,
        'payload' => ['message' => 'Ten percent off', 'coupon_code' => 'WELCOME10'],
    ]);

    $this->get('http://shop.test/')->assertOk()->assertSee('<strong> WELCOME10</strong>', false);

    Carbon::setTestNow('2026-09-13 19:00:00');

    $this->get('http://shop.test/')->assertOk()->assertSee('Ten percent off')->assertDontSee('WELCOME10');
});
