<?php

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Support\ComboPart;
use App\Modules\Commerce\Support\ComboPricing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;

/**
 * Website slice 2.5a: what a bundle costs, and how its saving is shared.
 *
 * ⭐⭐ The two rules that matter: every part is priced by the PRICE LIST at the
 * quantity the order line will carry, and a custom bundle price is a saving
 * shared across the parts in proportion to their value (Joy, 13 September),
 * whose shares add back to the saving to the paisa.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    $this->retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic', 'price_group_id' => $this->retail->id,
    ]);

    $this->product = function (string $sku, string $name, string $price, ?string $breakAt = null, ?string $breakPrice = null): Product {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $this->unit->id,
            'sku' => $sku, 'name' => $name, 'type' => ProductType::FinishedGood, 'is_active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id, 'price_group_id' => $this->retail->id,
            'unit_price' => $price, 'min_quantity' => '1', 'effective_from' => '2026-01-01',
        ]);

        if ($breakAt !== null) {
            ProductPrice::create([
                'product_id' => $product->id, 'price_group_id' => $this->retail->id,
                'unit_price' => $breakPrice, 'min_quantity' => $breakAt, 'effective_from' => '2026-01-01',
            ]);
        }

        return $product;
    };

    $this->combo = function (array $items, ?string $price = null): StoreCombo {
        $combo = StoreCombo::create([
            'store_id' => $this->store->id, 'slug' => 'pair-'.uniqid(), 'title' => 'Pickle Duo', 'price' => $price,
        ]);

        foreach ($items as [$product, $quantity]) {
            $combo->items()->create(['product_id' => $product->id, 'quantity' => $quantity]);
        }

        return $combo->fresh();
    };

    $this->honey = ($this->product)('HON-500', 'Sundarban Honey 500g', '850');
    $this->ghee = ($this->product)('GHE-500', 'Royal Ghee 500g', '1200');
});

it('is simply the sum of its parts when no bundle price is set', function () {
    $quote = ComboPricing::quote($this->store, ($this->combo)([[$this->honey, 1], [$this->ghee, 1]]));

    expect($quote->sellable())->toBeTrue()
        ->and($quote->total->toTaka())->toBe('2050.00')
        ->and($quote->saving()->toTaka())->toBe('0.00')
        ->and(array_map(fn (ComboPart $part) => $part->discount->toTaka(), $quote->parts))->toBe(['0.00', '0.00']);
});

it('shares a bundle saving across the parts in proportion to their value', function () {
    $quote = ComboPricing::quote($this->store, ($this->combo)([[$this->honey, 1], [$this->ghee, 1]], '1800'));

    // Saving 250. Honey is 850/2050 of the value: 103.658... rounds to 103.66.
    // Ghee takes 146.34. The jars themselves stay at 850 and 1200.
    expect($quote->total->toTaka())->toBe('1800.00')
        ->and($quote->saving()->toTaka())->toBe('250.00')
        ->and(array_map(fn (ComboPart $part) => $part->unitPrice, $quote->parts))->toBe(['850.0000', '1200.0000'])
        ->and(array_map(fn (ComboPart $part) => $part->discount->toTaka(), $quote->parts))->toBe(['103.66', '146.34']);
});

it('makes the shares add back to the saving to the paisa', function () {
    $a = ($this->product)('A', 'A', '100');
    $b = ($this->product)('B', 'B', '100');
    $c = ($this->product)('C', 'C', '100');

    // A saving of 10.00 split three ways rounds to 3.33 each and loses a paisa.
    $quote = ComboPricing::quote($this->store, ($this->combo)([[$a, 1], [$b, 1], [$c, 1]], '290'));

    $shares = array_map(fn (ComboPart $part) => $part->discount->minor, $quote->parts);

    expect(array_sum($shares))->toBe(1000)
        ->and($shares)->toEqualCanonicalizing([333, 333, 334]);
});

it('never gives a part a discount larger than the part', function () {
    // A bundle given away free: every share must equal its line exactly.
    $quote = ComboPricing::quote($this->store, ($this->combo)([[$this->honey, 1], [$this->ghee, 2]], '0'));

    foreach ($quote->parts as $part) {
        expect($part->discount->minor)->toBe($part->gross->minor);
    }

    expect($quote->total->toTaka())->toBe('0.00');
});

it('prices each part at the quantity its order line will carry', function () {
    // ⚠⚠ Three jars per bundle, two bundles: the order line is SIX jars, and a
    // 5+ break applies to it. Pricing a part at one jar here would quote more
    // than the sales order then records.
    $jar = ($this->product)('JAR', 'Mustard Oil 1L', '400', '5', '350');

    $quote = ComboPricing::quote($this->store, ($this->combo)([[$jar, 3]]), 2);

    expect($quote->parts[0]->quantity)->toBe(6)
        ->and($quote->parts[0]->unitPrice)->toBe('350.0000')
        ->and($quote->total->toTaka())->toBe('2100.00');
});

it('refuses a bundle priced above its parts rather than charging a mark-up', function () {
    $quote = ComboPricing::quote($this->store, ($this->combo)([[$this->honey, 1], [$this->ghee, 1]], '2100'));

    expect($quote->sellable())->toBeFalse()
        ->and($quote->refusal)->toContain('more than its contents cost bought separately');
});

it('notices when a price list change pushes a bundle above its parts', function () {
    $combo = ($this->combo)([[$this->honey, 1], [$this->ghee, 1]], '2000');

    expect(ComboPricing::quote($this->store, $combo)->sellable())->toBeTrue();

    // Ghee is cut to 1000: the parts now cost 1850 and the bundle still says 2000.
    // Checked once when the price was typed, this would keep selling.
    ProductPrice::create([
        'product_id' => $this->ghee->id, 'price_group_id' => $this->retail->id,
        'unit_price' => '1000', 'min_quantity' => '1', 'effective_from' => '2026-02-01',
    ]);

    expect(ComboPricing::quote($this->store, $combo->fresh())->sellable())->toBeFalse();
});

it('is exactly as sellable as its least sellable part', function () {
    $this->ghee->update(['is_active' => false]);

    $quote = ComboPricing::quote($this->store, ($this->combo)([[$this->honey, 1], [$this->ghee, 1]], '1800'));

    expect($quote->sellable())->toBeFalse()
        ->and($quote->refusal)->toContain('Royal Ghee 500g');
});

it('refuses an empty bundle', function () {
    expect(ComboPricing::quote($this->store, ($this->combo)([]))->refusal)->toBe('This bundle has nothing in it yet.');
});
