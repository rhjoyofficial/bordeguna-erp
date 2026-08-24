<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The low-stocks screen (INV-2). The rule under test is the ONE definition in
 * Product::stockLevelIsLow: at or below threshold is low, no threshold is
 * never low - and the screen must agree with the dashboard by construction.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Low Co', 'slug' => 'low-stock-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'Dry Fish', 'slug' => 'dry-fish']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mohammadpur', 'code' => 'MH']);
    $this->second = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SV']);

    $this->product = function (string $sku, ?string $threshold) {
        return Product::create([
            'business_id' => $this->business->id,
            'unit_of_measure_id' => $this->unit->id,
            'category_id' => $this->category->id,
            'sku' => $sku, 'name' => "Product {$sku}",
            'type' => ProductType::FinishedGood,
            'low_stock_threshold' => $threshold,
        ]);
    };

    $this->batch = function (Product $product, string $quantity, ?Warehouse $warehouse = null) {
        return StockBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'unit_cost' => '100', 'conversion_factor' => '1',
            'initial_quantity' => $quantity, 'current_quantity' => $quantity,
            'status' => 'active', 'received_at' => now(),
        ]);
    };

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };
});

it('lists a product at exactly its threshold as low, with the warehouse split', function () {
    $atThreshold = ($this->product)('SKU-AT', '10');
    ($this->batch)($atThreshold, '6');
    ($this->batch)($atThreshold, '4', $this->second);

    $above = ($this->product)('SKU-OK', '10');
    ($this->batch)($above, '11');

    ($this->asRole)('Staff')->get(route('inventory.low-stocks.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('inventory/low-stocks/index')
            ->has('low', 1)
            ->where('low.0.sku', 'SKU-AT')
            ->where('low.0.total', 10)
            ->has('low.0.by_warehouse', 2)
            ->has('out', 0));
});

it('never calls a product low when nobody set a threshold', function () {
    $noThreshold = ($this->product)('SKU-NT', null);
    ($this->batch)($noThreshold, '0.5');

    ($this->asRole)('Staff')->get(route('inventory.low-stocks.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('low', 0)
            ->has('out', 0)
            // The quiet line: it is invisible to "low" and the screen says so.
            ->where('without_threshold', 1));
});

it('lists a product with nothing on hand as out of stock, batches or none', function () {
    $drained = ($this->product)('SKU-DR', '5');
    ($this->batch)($drained, '0');
    ($this->product)('SKU-NB', null); // never received at all

    ($this->asRole)('Staff')->get(route('inventory.low-stocks.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('out', 2)
            ->has('low', 0));
});

it('agrees with the dashboard about what is low, by construction', function () {
    $product = ($this->product)('SKU-EQ', '10');
    ($this->batch)($product, '10');

    // The model method the dashboard filters with must say the same thing.
    expect($product->fresh()->isBelowLowStockThreshold())->toBeTrue()
        ->and(Product::stockLevelIsLow('10', 10.0))->toBeTrue()
        ->and(Product::stockLevelIsLow('10', 10.0001))->toBeFalse()
        ->and(Product::stockLevelIsLow(null, 0.0))->toBeFalse();
});

it('refuses a user with no inventory role', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);

    $this->actingAs($user)->get(route('inventory.low-stocks.index'))->assertForbidden();
});
