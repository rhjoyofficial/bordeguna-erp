<?php

use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;

/**
 * ⭐⭐ THE SHOP AND THE SALES ORDER MUST QUOTE THE SAME NUMBER.
 *
 * The storefront used to run its own price query - group rules before default
 * ones, ordered by `min_quantity` ASC, take the first. That reads like the
 * pricing engine's rule and is not: the engine takes the HIGHEST quantity break
 * a line qualifies for, and the storefront took the LOWEST, so it ignored
 * quantity breaks completely.
 *
 * ⚠⚠ IT WAS ABOUT TO BECOME A TRANSACTION. Slice 2.4's checkout creates a real
 * sales order through `CreateSalesOrder`, which prices through the engine - so a
 * basket of five would have SHOWN the single-unit price and BILLED the break
 * price, on exactly the products a shop chose to discount. Measured on
 * production: 5 of 52 price rules carry a quantity break.
 *
 * These tests exist so the two can never drift apart again.
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

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic', 'price_group_id' => $this->retail->id,
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();

    $home = Page::create([
        'store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome',
    ]);
    $home->forceFill(['published_at' => now()])->save();
});

it('gives a quantity break to a shop customer who has earned it', function () {
    // A shop that sets a 5+ rate means it. The website used to ignore this
    // entirely and quote 850 for a basket of five.
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '850', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '800', 'min_quantity' => '5', 'effective_from' => '2026-01-01',
    ]);

    expect(CataloguePublishing::priceFor($this->store, $this->product, null, '1'))->toBe('850.0000')
        ->and(CataloguePublishing::priceFor($this->store, $this->product, null, '4'))->toBe('850.0000')
        ->and(CataloguePublishing::priceFor($this->store, $this->product, null, '5'))->toBe('800.0000');
});

it('quotes exactly what a sales order for the same goods would record', function () {
    // ⭐⭐ THE TEST THAT MATTERS. A customer priced in the store's own group is
    // what a website order IS (ADR-005), so the two answers have to be equal at
    // every quantity - not merely similar.
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '850', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '800', 'min_quantity' => '5', 'effective_from' => '2026-01-01',
    ]);

    $customer = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-0001', 'name' => 'Web customer',
        'price_group_id' => $this->retail->id, 'is_active' => true,
    ]);

    $engine = app(ResolveSellingPrice::class);

    foreach (['1', '4', '5', '12'] as $quantity) {
        expect(CataloguePublishing::priceFor($this->store, $this->product, null, $quantity))
            ->toBe($engine->resolve($this->product, $customer, $quantity)->unitPrice, "quantity {$quantity}");
    }
});

it('prices a shop in its own group and not in somebody else group', function () {
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '850', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->dealer->id,
        'unit_price' => '600', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);

    // ⛔ A retail shop must never be handed the dealer rate because the engine
    // happened to see the row. This is why the group is passed explicitly rather
    // than left to fall through to whatever matches.
    expect(CataloguePublishing::priceFor($this->store, $this->product))->toBe('850.0000');

    $this->store->update(['price_group_id' => $this->dealer->id]);

    expect(CataloguePublishing::priceFor($this->store->fresh(), $this->product))->toBe('600.0000');
});

it('falls back to the ungrouped default when its own group has no rule', function () {
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => null,
        'unit_price' => '900', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);

    expect(CataloguePublishing::priceFor($this->store, $this->product))->toBe('900.0000');
});

it('never lets a price agreed with one customer leak onto a public page', function () {
    // ⛔ A website has no customer, which is the whole content of ADR-005. A
    // deal struck with one buyer must not become the shelf price for everybody.
    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '850', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);

    $customer = Customer::create([
        'business_id' => $this->business->id, 'code' => 'CUS-0002', 'name' => 'Big buyer',
        'price_group_id' => $this->retail->id, 'is_active' => true,
    ]);

    ProductPrice::create([
        'product_id' => $this->product->id, 'customer_id' => $customer->id,
        'unit_price' => '500', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);

    expect(CataloguePublishing::priceFor($this->store, $this->product))->toBe('850.0000');
});

it('still refuses to publish a product with no price in force', function () {
    // ⚠ The publish gate asks "is there any price at all", at quantity one.
    // Measured on production before this change: every one of the 46 priced
    // products has a rule applying at quantity 1, so no product's
    // publishability moved.
    expect(CataloguePublishing::hasPrice($this->store, $this->product))->toBeFalse()
        ->and(CataloguePublishing::refusalFor($this->store, $this->product))
        ->toContain('No price is in force');

    ProductPrice::create([
        'product_id' => $this->product->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '850', 'min_quantity' => '1', 'effective_from' => '2026-01-01',
    ]);

    expect(CataloguePublishing::hasPrice($this->store->fresh(), $this->product))->toBeTrue();
});
