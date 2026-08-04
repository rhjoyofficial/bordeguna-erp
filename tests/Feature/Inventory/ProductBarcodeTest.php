<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\QueryException;

/**
 * SKU and barcode are different business concepts (Joy, 2026-08-04).
 *
 * ⚠ SKU is our permanent internal code and never changes. A barcode belongs to
 * the PACK, so it changes when packaging or a supplier changes, and a product is
 * real before one is printed. Merging them would have keyed a product's entire
 * trading history to a number that legitimately changes.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Barcode Co', 'slug' => 'barcode-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs',
    ]);

    $this->product = fn (string $sku, ?string $barcode = null) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => $sku,
        'barcode' => $barcode,
        'name' => "Product {$sku}",
        'type' => ProductType::FinishedGood,
    ]);
});

it('stores a barcode alongside the sku', function () {
    $product = ($this->product)('HON-MG-500', '8801234567890');

    expect($product->fresh()->barcode)->toBe('8801234567890')
        ->and($product->fresh()->sku)->toBe('HON-MG-500');
});

// ⚠ A product exists before anybody prints a barcode for it.
it('allows a product with no barcode', function () {
    expect(($this->product)('HON-MG-250')->fresh()->barcode)->toBeNull();
});

// ⚠ Optional but unique: MySQL permits many NULLs in a unique index, so this
// needs no partial index and no application rule.
it('allows many products with no barcode', function () {
    ($this->product)('SKU-1');
    ($this->product)('SKU-2');

    expect(Product::whereNull('barcode')->count())->toBe(2);
});

it('refuses the same barcode twice in one business', function () {
    ($this->product)('SKU-1', '8801234567890');
    ($this->product)('SKU-2', '8801234567890');
})->throws(QueryException::class);

// ⚠ Two businesses may legitimately stock the same manufactured item.
it('allows the same barcode in a different business', function () {
    ($this->product)('SKU-1', '8801234567890');

    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-barcode-co']);
    app(BusinessContext::class)->set($other->id);
    $unit = UnitOfMeasure::create(['business_id' => $other->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $theirs = Product::create([
        'business_id' => $other->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-1',
        'barcode' => '8801234567890',
        'name' => 'Theirs',
        'type' => ProductType::FinishedGood,
    ]);

    expect($theirs->exists)->toBeTrue();
});

// ⚠⚠ The whole point of keeping them separate: re-barcoding must not touch the
// identifier that purchase history and cost layers hang on.
it('keeps the sku stable when the barcode is reprinted', function () {
    $product = ($this->product)('HON-MG-500', '8801111111111');

    $product->update(['barcode' => '8802222222222']);

    expect($product->fresh()->sku)->toBe('HON-MG-500')
        ->and($product->fresh()->barcode)->toBe('8802222222222');
});
