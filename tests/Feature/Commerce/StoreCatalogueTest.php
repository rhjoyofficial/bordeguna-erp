<?php

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1, slice 1.7: catalogue publishing and the store price group.
 *
 * ⭐ The exit criterion from the plan: a catalogue published to one shop and not
 * to another, with prices resolved from the store's own price group.
 *
 * ⛔ The product master is not forked. A row says a product APPEARS on a shop
 * and how it is presented; it never restates what the product is, and it carries
 * no price at all - ADR-005 makes a website a price group, so what a visitor
 * pays comes from the ordinary price list.
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

    $this->bionic = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);
    $this->laladia = Store::create(['key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD']);
});

function priceIt(int $productId, ?int $groupId, string $from = '2026-01-01', ?string $to = null): ProductPrice
{
    return ProductPrice::create([
        'product_id' => $productId,
        'price_group_id' => $groupId,
        'unit_price' => '850',
        'effective_from' => $from,
        'effective_to' => $to,
    ]);
}

it('refuses to publish a product nobody has priced for this shop', function () {
    // ⭐⭐ THE GATE, AND IT IS ABOUT MONEY. A product published with no price in
    // force does not error - it renders a page with a blank where the price
    // goes, and the first person to find out is a customer.
    $refusal = CataloguePublishing::refusalFor($this->bionic, $this->product);

    expect($refusal)->toBeString()
        ->and($refusal)->toContain('No price is in force');
});

it('publishes once a price exists in the shop price group', function () {
    priceIt($this->product->id, $this->retail->id);
    $this->bionic->update(['price_group_id' => $this->retail->id]);

    expect(CataloguePublishing::refusalFor($this->bionic->fresh(), $this->product))->toBeNull();
});

it('does not accept another group price as this shop price', function () {
    // ⛔ A dealer price is not a retail price. Publishing on the strength of one
    // is how a public website ends up quoting trade rates.
    priceIt($this->product->id, $this->dealer->id);
    $this->bionic->update(['price_group_id' => $this->retail->id]);

    expect(CataloguePublishing::refusalFor($this->bionic->fresh(), $this->product))->toContain('No price is in force');
});

it('accepts a price with no group at all, because that is the default every group falls back to', function () {
    priceIt($this->product->id, null);
    $this->bionic->update(['price_group_id' => $this->retail->id]);

    expect(CataloguePublishing::refusalFor($this->bionic->fresh(), $this->product))->toBeNull();
});

it('refuses a price that has already expired', function () {
    // ⚠ "In force", not "exists". A price that ran out last month prices nothing.
    priceIt($this->product->id, null, '2026-01-01', '2026-01-31');

    expect(CataloguePublishing::refusalFor($this->bionic, $this->product))->toContain('No price is in force');
});

it('refuses a product that is switched off in the catalogue', function () {
    priceIt($this->product->id, null);
    $this->product->update(['is_active' => false]);

    expect(CataloguePublishing::refusalFor($this->bionic, $this->product->fresh()))
        ->toContain('switched off in the catalogue');
});

it('publishes a catalogue to one shop and not to the other', function () {
    // ⭐ The slice exit criterion, stated as a test.
    priceIt($this->product->id, null);

    $this->bionic->products()->create([
        'product_id' => $this->product->id,
        'slug' => 'sundarban-honey-500g',
        'published_at' => now(),
    ]);

    expect($this->bionic->products()->count())->toBe(1)
        ->and($this->laladia->products()->count())->toBe(0);
});

it('lets two shops use the same address for their own product', function () {
    // ⚠ Slugs are unique PER STORE. Two shops in this group both sell honey and
    // both want /products/honey; a global rule would hand the second one a worse
    // address for no reason, and a cutover has to keep the URLs it has.
    priceIt($this->product->id, null);

    $this->bionic->products()->create(['product_id' => $this->product->id, 'slug' => 'honey']);
    $this->laladia->products()->create(['product_id' => $this->product->id, 'slug' => 'honey']);

    expect(StoreProduct::where('slug', 'honey')->count())->toBe(2);
});

it('gives a colliding product inside one shop its own address rather than refusing', function () {
    priceIt($this->product->id, null);
    $this->bionic->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey-500g']);

    expect(CataloguePublishing::slugFor($this->bionic, 'Sundarban Honey 500g'))->toBe('sundarban-honey-500g-2');
});

it('keeps no price, no stock and no product name of its own', function () {
    // ⛔⛔ THE ABSENCE IS THE ENFORCEMENT, the same rule as the section contract.
    // A price here would be a second opinion about money, editable from a
    // content screen and invisible to every report that reads the price list.
    $columns = Schema::getColumnListing('store_products');

    foreach (['price', 'unit_price', 'stock', 'quantity', 'available', 'sku', 'unit'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('falls back to the catalogue name when a shop overrides nothing', function () {
    priceIt($this->product->id, null);

    $row = $this->bionic->products()->create(['product_id' => $this->product->id, 'slug' => 'honey']);

    expect($row->displayTitle())->toBe('Sundarban Honey 500g');

    $row->update(['title_override' => 'Wild Honey from the Sundarbans']);

    expect($row->fresh()->displayTitle())->toBe('Wild Honey from the Sundarbans');
});

it('says which group a shop prices at, and that no group means retail', function () {
    // ⚠ Shown as a sentence rather than a blank, because a blank reads as a
    // misconfiguration when it is in fact the default.
    expect(CataloguePublishing::priceGroupLabel($this->bionic))->toContain('default');

    $this->bionic->update(['price_group_id' => $this->dealer->id]);

    expect(CataloguePublishing::priceGroupLabel($this->bionic->fresh()))->toBe('Dealer');
});

it('keeps one business catalogue away from another', function () {
    priceIt($this->product->id, null);
    $this->bionic->products()->create(['product_id' => $this->product->id, 'slug' => 'honey']);

    $other = Business::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
    app(BusinessContext::class)->set($other->id);

    expect(StoreProduct::count())->toBe(0);
});
