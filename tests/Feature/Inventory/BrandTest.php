<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\BrandSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Database\QueryException;

/**
 * The brand dimension.
 *
 * ⚠⚠ THE POINT OF THIS DESIGN: LaLaDia and Bionic-Garden are BRANDS inside Bor
 * De Guna, not separate tenants. That keeps one catalogue, one set of FIFO cost
 * layers and one profit report covering all of them. Duplicating products across
 * businesses instead would have split stock and made per-product profitability
 * unanswerable.
 *
 * ⚠ So the tests that matter most here are not "can I store a brand" but
 * "does the tenant boundary still hold", which is the thing this change could
 * plausibly have broken and did not.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Brand Co', 'slug' => 'brand-co']);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'brand-rival-co']);

    TenantScope::enter($this->business->id);

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc',
    ]);

    $this->brand = Brand::create([
        'business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
    ]);
});

afterEach(fn () => TenantScope::leave());

it('holds a brand and its products', function () {
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'brand_id' => $this->brand->id,
        'sku' => 'HON-MAN-500G', 'name' => 'Mangrove Gold Honey 500gm',
        'type' => ProductType::FinishedGood,
    ]);

    expect($product->brand->name)->toBe('LaLaDia')
        ->and($this->brand->products)->toHaveCount(1);
});

/**
 * ⚠ D-5. Raw materials have no brand. The same sugar becomes LaLaDia sugar or
 * Bionic sugar depending only on what it is packed into, so forcing a brand
 * would mean inventing one.
 */
it('lets a product have no brand at all', function () {
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'SUG-RAW', 'name' => 'Cane sugar, sack',
        'type' => ProductType::RawMaterial,
    ]);

    expect($product->brand_id)->toBeNull()
        ->and($product->brand)->toBeNull();
});

// ⚠ A freshly created model does not carry its database defaults.
it('is active without being reloaded', function () {
    expect((new Brand)->is_active)->toBeTrue();
});

it('refuses two brands with the same code in one business', function () {
    Brand::create([
        'business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'Something Else', 'slug' => 'something-else',
    ]);
})->throws(QueryException::class);

// ⚠ The same code in a DIFFERENT business is fine. Brands are per business.
it('allows the same brand code in another business', function () {
    TenantScope::enter($this->other->id);

    $foreign = Brand::create([
        'business_id' => $this->other->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
    ]);

    expect($foreign->id)->not->toBe($this->brand->id);

    TenantScope::enter($this->business->id);
});

/**
 * ⚠⚠⚠ THE TENANT BOUNDARY, WHICH THIS CHANGE MUST NOT MOVE. A brand is
 * business-scoped exactly like a category, so BusinessScope hides another
 * business's brands entirely.
 */
it('does not leak brands across businesses', function () {
    TenantScope::enter($this->other->id);
    Brand::create(['business_id' => $this->other->id, 'code' => 'XYZ', 'name' => 'Theirs', 'slug' => 'theirs']);

    expect(Brand::count())->toBe(1)
        ->and(Brand::first()->code)->toBe('XYZ');

    TenantScope::enter($this->business->id);

    expect(Brand::count())->toBe(1)
        ->and(Brand::first()->code)->toBe('LLD');
});

/**
 * ⚠⚠⚠ C-BRD-8. THE STRUCTURAL INVARIANT, asserted over the whole table rather
 * than trusted to one controller.
 *
 * A product's brand must belong to the SAME business as the product. Nothing in
 * the database can enforce it - there is no composite foreign key on
 * (business_id, brand_id) - so it is enforced by a scoped `exists:` rule in the
 * controller, by the importer resolving brands inside the tenant, and by this
 * test. The bare `exists:` hole found on this very screen on 5 August is exactly
 * what it is here to catch.
 */
it('never lets a product point at another business brand', function () {
    TenantScope::enter($this->other->id);
    $foreignBrand = Brand::create([
        'business_id' => $this->other->id, 'code' => 'BNC', 'name' => 'Theirs', 'slug' => 'theirs',
    ]);
    TenantScope::enter($this->business->id);

    // Written deliberately behind the scope, the way a bare `exists:` would let
    // an HTTP request write it.
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'brand_id' => $foreignBrand->id,
        'sku' => 'BAD-1', 'name' => 'Wrong brand', 'type' => ProductType::FinishedGood,
    ]);

    $mismatched = Product::withoutGlobalScopes()
        ->join('brands', 'brands.id', '=', 'products.brand_id')
        ->whereColumn('brands.business_id', '!=', 'products.business_id')
        ->pluck('products.sku')
        ->all();

    expect($mismatched)->toBe(['BAD-1'],
        'The structural check itself must be able to SEE a mismatch, or it proves nothing.');
});

it('reports no cross-business brand mismatch on a clean catalogue', function () {
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'brand_id' => $this->brand->id,
        'sku' => 'GOOD-1', 'name' => 'Right brand', 'type' => ProductType::FinishedGood,
    ]);

    $mismatched = Product::withoutGlobalScopes()
        ->join('brands', 'brands.id', '=', 'products.brand_id')
        ->whereColumn('brands.business_id', '!=', 'products.business_id')
        ->count();

    expect($mismatched)->toBe(0);
});

/**
 * ⚠ C-BRD-2. RESTRICT, not null-on-delete. A brand with products behind it must
 * not vanish in a way that silently unbrands a catalogue. Retiring a brand is
 * `is_active = false`, which keeps history readable.
 */
it('refuses to delete a brand that still has products', function () {
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'brand_id' => $this->brand->id,
        'sku' => 'HON-1', 'name' => 'Honey', 'type' => ProductType::FinishedGood,
    ]);

    $this->brand->delete();
})->throws(QueryException::class);

it('lets a brand with no products be deleted', function () {
    $spare = Brand::create([
        'business_id' => $this->business->id, 'code' => 'TMP', 'name' => 'Temp', 'slug' => 'temp',
    ]);

    $spare->delete();

    expect(Brand::where('code', 'TMP')->exists())->toBeFalse();
});

/**
 * ⚠⚠ D-7 and the seeder's shape. Seeded per brand rather than all-or-nothing,
 * so re-running is safe AND adding a brand later actually adds it - the
 * all-or-nothing seeder trap this project has already met.
 */
it('seeds exactly LaLaDia and Bionic-Garden into Bor De Guna', function () {
    TenantScope::leave();

    $bdg = Business::create(['name' => 'Bor De Guna', 'slug' => 'bor-de-guna']);

    (new BrandSeeder)->run();

    TenantScope::enter($bdg->id);

    expect(Brand::pluck('code')->sort()->values()->all())->toBe(['BNC', 'LLD'])
        ->and(Brand::where('code', 'LLD')->first()->name)->toBe('LaLaDia')
        ->and(Brand::where('code', 'BNC')->first()->name)->toBe('Bionic-Garden');
});

it('can be seeded twice without duplicating', function () {
    TenantScope::leave();

    $bdg = Business::create(['name' => 'Bor De Guna', 'slug' => 'bor-de-guna']);

    (new BrandSeeder)->run();
    (new BrandSeeder)->run();

    TenantScope::enter($bdg->id);

    expect(Brand::count())->toBe(2);
});

// ⚠ The seeder enters the tenant itself. A seeder run cold would read an empty
// table through `1 = 0` and write brands into no business at all.
it('seeds with no ambient business context', function () {
    TenantScope::leave();
    app(BusinessContext::class)->forget();

    $bdg = Business::create(['name' => 'Bor De Guna', 'slug' => 'bor-de-guna']);

    (new BrandSeeder)->run();

    expect(app(BusinessContext::class)->hasBusiness())->toBeFalse();

    TenantScope::enter($bdg->id);

    expect(Brand::count())->toBe(2)
        ->and(Brand::first()->business_id)->toBe($bdg->id);
});
