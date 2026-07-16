<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;

beforeEach(function () {
    $this->laladia = Business::create(['name' => 'LaLaDia', 'slug' => 'tenant-test-laladia']);
    $this->bionic = Business::create(['name' => 'Bionic', 'slug' => 'tenant-test-bionic']);
});

it('does not leak products across businesses', function () {
    app(BusinessContext::class)->set($this->laladia->id);
    $unit = UnitOfMeasure::create(['business_id' => $this->laladia->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $laladiaProduct = Product::create([
        'business_id' => $this->laladia->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SHARED-SKU',
        'name' => 'LaLaDia Rice',
        'type' => ProductType::RawMaterial,
    ]);

    app(BusinessContext::class)->set($this->bionic->id);

    expect(Product::count())->toBe(0);
    expect(Product::find($laladiaProduct->id))->toBeNull();

    // Same SKU string is fine in a different business — proves the unique
    // index is scoped by business_id, not global.
    $unit2 = UnitOfMeasure::create(['business_id' => $this->bionic->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $bionicProduct = Product::create([
        'business_id' => $this->bionic->id,
        'unit_of_measure_id' => $unit2->id,
        'sku' => 'SHARED-SKU',
        'name' => 'Bionic Soil',
        'type' => ProductType::RawMaterial,
    ]);

    expect($bionicProduct->exists)->toBeTrue();
});

it('does not leak warehouses, and route-model-binding 404s across businesses', function () {
    app(BusinessContext::class)->set($this->laladia->id);
    $warehouse = Warehouse::create(['business_id' => $this->laladia->id, 'name' => 'Main', 'code' => 'MAIN']);

    app(BusinessContext::class)->set($this->bionic->id);

    expect(Warehouse::find($warehouse->id))->toBeNull();
});

it('fails closed for Inventory models when no business context is set', function () {
    app(BusinessContext::class)->set($this->laladia->id);
    UnitOfMeasure::create(['business_id' => $this->laladia->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    Warehouse::create(['business_id' => $this->laladia->id, 'name' => 'Main', 'code' => 'MAIN']);

    app(BusinessContext::class)->forget();

    expect(Product::count())->toBe(0);
    expect(UnitOfMeasure::count())->toBe(0);
    expect(Warehouse::count())->toBe(0);
});
