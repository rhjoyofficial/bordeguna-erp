<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Raw materials at their own address (Inventory redesign, 15 Oct 2026).
 *
 * ⚠ The sidebar and the tab row match on the PATH and drop the query string,
 * so `/inventory/products?kind=raw` could never be its own tab: it lit
 * Products and the breadcrumb said Products. Now `/inventory/raw-materials`
 * is the same screen with the kind fixed by the route, and the old address
 * redirects so nothing bookmarked breaks.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Raw Co', 'slug' => 'raw-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

    Product::create([
        'business_id' => $this->business->id, 'sku' => 'FG-1', 'name' => 'Finished Honey',
        'type' => 'finished_good', 'unit_of_measure_id' => $this->unit->id,
    ]);
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'RM-1', 'name' => 'Raw Beeswax',
        'type' => 'raw_material', 'unit_of_measure_id' => $this->unit->id,
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };
});

it('lists only raw materials at /inventory/raw-materials, on the products screen with the kind fixed', function () {
    ($this->asRole)('Manager')->get('/inventory/raw-materials')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/products/index')
            ->where('kind', 'raw')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Raw Beeswax'));
});

it('redirects the old ?kind=raw address to the new one, keeping the other filters', function () {
    ($this->asRole)('Manager')->get('/inventory/products?kind=raw')
        ->assertRedirect('/inventory/raw-materials');

    ($this->asRole)('Manager')->get('/inventory/products?kind=raw&search=wax')
        ->assertRedirect('/inventory/raw-materials?search=wax');

    // Anything else on the products address is the products screen, as before.
    ($this->asRole)('Manager')->get('/inventory/products?kind=anything')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('kind', 'products'));
});

it('gates the raw-materials screen like the products screen', function () {
    ($this->asRole)('Staff')->get('/inventory/raw-materials')->assertOk();

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    $this->actingAs($outsider)->get('/inventory/raw-materials')->assertForbidden();
});

it('saves a raw material with no MRP and no price rows', function () {
    ($this->asRole)('Manager')
        ->post('/inventory/products', [
            'sku' => 'RM-SALT',
            'name' => 'Bulk salt',
            'type' => 'raw_material',
            'unit_of_measure_id' => $this->unit->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $product = Product::where('sku', 'RM-SALT')->firstOrFail();

    expect($product->mrp)->toBeNull()
        ->and($product->brand_id)->toBeNull()
        ->and($product->type->value)->toBe('raw_material');

    // The JSON path the dialog uses answers the same way.
    ($this->asRole)('Manager')
        ->postJson('/inventory/products', [
            'sku' => 'RM-SUGAR',
            'name' => 'Bulk sugar',
            'type' => 'raw_material',
            'unit_of_measure_id' => $this->unit->id,
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Bulk sugar');
});

it('offers the status chips with their counts for the kind shown', function () {
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'RM-OLD', 'name' => 'Retired spice',
        'type' => 'raw_material', 'unit_of_measure_id' => $this->unit->id, 'is_active' => false,
    ]);

    ($this->asRole)('Manager')->get('/inventory/raw-materials')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.inactive', 1)
            ->where('counts.low_stock', 0)
            ->where('counts.expired', 0)
            ->where('counts.all', 2));

    ($this->asRole)('Manager')->get('/inventory/raw-materials?flag=inactive')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'RM-OLD'));

    ($this->asRole)('Manager')->get('/inventory/products')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.all', 1)
            ->where('counts.no_brand', 1)
            ->where('counts.weight_missing', 1)
            ->where('counts.service', 0));
});
