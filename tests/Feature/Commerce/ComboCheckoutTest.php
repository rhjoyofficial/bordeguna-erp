<?php

use App\Modules\Accounting\Models\Journal;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartComboLine;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * Website Phase 2, slice 2.5a: a bundle travels from the shop to the order.
 *
 * ⭐⭐ THE SLICE, STATED AS TESTS. A bundle is priced by `ComboPricing` on the page
 * and in the basket, explodes into its real product lines at checkout (D25),
 * each part billed at the price list with the bundle's saving as that line's
 * agreed discount (Joy, 13 September) - and the order records, to the paisa, the
 * figure the basket showed.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    $this->retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
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

    $this->store = Store::create([
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD',
        'theme_key' => 'bionic-classic', 'price_group_id' => $this->retail->id,
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    // ⚠ The hilsa is published on its own; the beef deliberately is not. A part
    // does not need a page of its own to be sold in a bundle.
    $this->store->products()->create(['product_id' => $this->hilsa->id, 'slug' => 'hilsa-fish-pickle'])->publish();

    $home = Page::create(['store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome']);
    $home->forceFill(['published_at' => now()])->save();

    ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Outside Dhaka', 'charge' => '120'])->makeDefault();

    // Pickle Duo: 999 + 1350 = 2349, sold for 1998. Saving 351.
    $this->duo = StoreCombo::create([
        'store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo', 'price' => '1998',
    ]);
    $this->duo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $this->duo->items()->create(['product_id' => $this->beef->id, 'quantity' => 1]);
    $this->duo->publish();

    $this->address = [
        'name' => 'Karim Uddin', 'phone' => '01711000000', 'thana' => 'Fakirhat',
        'address_line' => 'House 4, Road 2', 'payment_method' => 'cod',
    ];
});

/** ⚠ Own names: test files share one global function namespace. */
function comboPost(string $path, array $data = [])
{
    return test()->post('http://shop.test'.$path, $data);
}

function comboToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

function comboBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

function basketWithDuo(int $quantity = 1): string
{
    $token = comboToken(comboPost('/cart/combos', ['combo' => test()->duo->id, 'quantity' => $quantity]));
    comboBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);

    return $token;
}

it('shows a bundle page with its contents, its price and a real saving', function () {
    $this->get('http://shop.test/combos/pickle-duo')
        ->assertOk()
        ->assertSee('Pickle Duo')
        ->assertSee('1 &times; Hilsa Fish Pickle 400g', false)
        ->assertSee('1 &times; Royal Beef Pickle 400g', false)
        ->assertSee('BDT 1,998.00')
        ->assertSee('You save BDT 351.00')
        ->assertSee('action="/cart/combos"', false);
});

it('does not show an unpublished bundle, or another shop\'s', function () {
    $this->duo->unpublish();
    $this->get('http://shop.test/combos/pickle-duo')->assertNotFound();

    $other = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC', 'theme_key' => 'bionic-classic']);
    $theirs = StoreCombo::create(['store_id' => $other->id, 'slug' => 'their-duo', 'title' => 'Theirs']);
    $theirs->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 2]);
    $theirs->publish();

    $this->get('http://shop.test/combos/their-duo')->assertNotFound();

    // ⛔ And it cannot be added by id either.
    comboPost('/cart/combos', ['combo' => $theirs->id, 'quantity' => 1])->assertSessionHasErrors('combo');
});

it('holds a bundle in the basket, priced and itemised', function () {
    $token = basketWithDuo(2);

    comboBrowser($token)->get('http://shop.test/cart')
        ->assertOk()
        ->assertSee('Pickle Duo')
        ->assertSee('1 &times; Hilsa Fish Pickle 400g', false)
        ->assertSee('BDT 3,996.00')
        ->assertSee('You save BDT 702.00')
        ->assertSee('action="/cart/combos/', false);
});

it('refuses to put a bundle priced above its parts in the basket', function () {
    $this->duo->update(['price' => '2400']);

    comboPost('/cart/combos', ['combo' => $this->duo->id, 'quantity' => 1])
        ->assertSessionHasErrors(['combo' => 'This bundle is priced at BDT 2,400.00, which is more than its contents cost bought separately (BDT 2,349.00). It cannot be sold until its price is corrected.']);

    expect(CartComboLine::count())->toBe(0);
});

it('explodes a bundle into its real lines, each carrying its share of the saving', function () {
    $token = basketWithDuo();

    comboBrowser($token)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    $order = SalesOrder::with('lines')->sole();
    $lines = $order->lines->keyBy('product_id');

    // Each jar at its price-list price. 351 shared by value: the hilsa is
    // 999/2349 of it (149.2758, so 149.28) and the beef 1350/2349 (201.7241,
    // so 201.72). They add back to 351.00 exactly.
    expect((string) $lines[$this->hilsa->id]->unit_price)->toBe('999.0000')
        ->and((string) $lines[$this->beef->id]->unit_price)->toBe('1350.0000')
        ->and((string) $lines[$this->hilsa->id]->line_discount)->toBe('149.2800')
        ->and((string) $lines[$this->beef->id]->line_discount)->toBe('201.7200')
        ->and($lines->pluck('bundle_label')->unique()->all())->toBe(['Pickle Duo'])
        ->and($order->goodsTotal())->toBe('1998.00')
        ->and($order->agreedTotal())->toBe('2118.00')
        // ⛔ Still a promise: nothing moves, nothing posts.
        ->and(StockMovement::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('records the same total the basket showed for a mixed basket', function () {
    $token = basketWithDuo(2);
    comboBrowser($token)->post('http://shop.test/cart/lines', ['product' => $this->hilsa->id, 'quantity' => 3]);

    $basket = comboBrowser($token)->get('http://shop.test/cart')->original->getData()['cart'];

    comboBrowser($token)->post('http://shop.test/checkout', $this->address)->assertRedirect();

    $order = SalesOrder::with('lines')->sole();

    // The loose hilsa is its own line, never merged into the bundle's hilsa.
    expect($order->lines)->toHaveCount(3)
        ->and($basket['subtotal'])->toBe('6,993.00')
        ->and($order->goodsTotal())->toBe('6993.00');
});

it('refuses at checkout when a bundle stopped being sellable after it was added', function () {
    $token = basketWithDuo();

    $this->beef->update(['is_active' => false]);

    comboBrowser($token)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors('lines');

    expect(SalesOrder::count())->toBe(0);
});

it('writes no order when a price changes between quoting and recording', function () {
    $token = basketWithDuo();

    // ⚠ The race, made deterministic: a new price for the hilsa lands the moment
    // the order row is created - after the basket was quoted, before its lines
    // are priced. Without the agreement check the customer is billed a figure
    // they never saw.
    SalesOrder::creating(function () {
        ProductPrice::create(['product_id' => $this->hilsa->id, 'unit_price' => '1100', 'effective_from' => now()->toDateString()]);
    });

    comboBrowser($token)->post('http://shop.test/checkout', $this->address)
        ->assertSessionHasErrors(['lines' => 'A price changed while you were ordering. Please check your basket and place the order again.']);

    expect(SalesOrder::count())->toBe(0);
});

it('lists only bundles that can be sold right now', function () {
    $broken = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'gift-box', 'title' => 'Gift Box', 'price' => '9999']);
    $broken->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $broken->publish();

    $this->get('http://shop.test/combos')
        ->assertOk()
        ->assertSee('Pickle Duo')
        ->assertDontSee('Gift Box');
});

it('lets a browser change and remove only its own bundle line', function () {
    $token = basketWithDuo();
    $line = CartComboLine::sole();

    comboBrowser($token)->patch("http://shop.test/cart/combos/{$line->id}", ['quantity' => 3])->assertRedirect('/cart');
    expect($line->fresh()->quantity)->toBe(3);

    // ⛔ Another browser's basket, the same line id: not found, not changed.
    // ⚠ Made directly rather than through a request, because the test client
    // carries the first browser's cookie into every later request.
    $stranger = Cart::create(['store_id' => $this->store->id, 'token' => Cart::newToken()]);
    comboBrowser($stranger->token)->delete("http://shop.test/cart/combos/{$line->id}")->assertNotFound();
    expect($line->fresh())->not->toBeNull();

    comboBrowser($token)->delete("http://shop.test/cart/combos/{$line->id}")->assertRedirect('/cart');
    expect(CartComboLine::count())->toBe(0);
});

it('tells the customer on the order page what the bundle saved', function () {
    $token = basketWithDuo();

    $response = comboBrowser($token)->post('http://shop.test/checkout', $this->address);

    // ⚠ The redirect already carries the host.
    comboBrowser($token)->get($response->headers->get('Location'))
        ->assertOk()
        ->assertSee('In Pickle Duo')
        ->assertSee('less BDT 149.28')
        ->assertSee('BDT 1,998.00');
});

it('lists published bundles in the sitemap and leaves drafts out', function () {
    $this->store->forceFill(['is_indexable' => true])->save();

    $draft = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'not-ready', 'title' => 'Not ready']);
    $draft->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 2]);

    $this->get('http://shop.test/sitemap.xml')
        ->assertOk()
        ->assertSee('<loc>https://shop.test/combos</loc>', false)
        ->assertSee('<loc>https://shop.test/combos/pickle-duo</loc>', false)
        ->assertDontSee('/combos/not-ready', false);
});
