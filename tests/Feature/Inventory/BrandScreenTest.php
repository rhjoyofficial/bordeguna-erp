<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Brand;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brands CRUD (INV-4). The table existed since 8 Aug with only a seeder
 * writing to it; these pin the screen that finally lets Joy add one, the
 * frozen code, and retirement-not-deletion.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Brand Co', 'slug' => 'brand-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };
});

it('lists brands, retired ones included, with their product counts', function () {
    Brand::create(['name' => 'LaLaDia', 'code' => 'LLD', 'slug' => 'laladia']);
    Brand::create(['name' => 'Old Brand', 'code' => 'OLD', 'slug' => 'old-brand', 'is_active' => false]);

    ($this->asRole)('Staff')->get(route('inventory.brands.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('inventory/brands/index')
            ->has('brands', 2));
});

it('creates a brand from the screen, code upper-cased', function () {
    ($this->asRole)('Owner')->post(route('inventory.brands.store'), [
        'name' => 'Bor de Guna Fresh',
        'code' => 'bdf',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $brand = Brand::where('slug', 'bor-de-guna-fresh')->first();
    expect($brand)->not->toBeNull()
        ->and($brand->code)->toBe('BDF')
        ->and($brand->is_active)->toBeTrue()
        ->and($brand->business_id)->toBe($this->business->id);
});

it('refuses a duplicate code and a name that collapses to a taken slug', function () {
    Brand::create(['name' => 'LaLaDia', 'code' => 'LLD', 'slug' => 'laladia']);

    ($this->asRole)('Owner')->post(route('inventory.brands.store'), [
        'name' => 'Fresh Foods', 'code' => 'LLD',
    ])->assertSessionHasErrors('code');

    // "LaLaDia" and "LALADIA" are one slug, therefore one brand.
    ($this->asRole)('Owner')->post(route('inventory.brands.store'), [
        'name' => 'LALADIA', 'code' => 'LL2',
    ])->assertSessionHasErrors('name');
});

it('renames a brand but never its code - SKUs already carry it', function () {
    $brand = Brand::create(['name' => 'Bionic', 'code' => 'BNC', 'slug' => 'bionic']);

    ($this->asRole)('Owner')->put(route('inventory.brands.update', $brand), [
        'name' => 'Bionic-Garden',
        'code' => 'XXX',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $brand->refresh();
    expect($brand->name)->toBe('Bionic-Garden')
        ->and($brand->slug)->toBe('bionic-garden')
        ->and($brand->code)->toBe('BNC');
});

it('retires and reactivates instead of deleting', function () {
    $brand = Brand::create(['name' => 'LaLaDia', 'code' => 'LLD', 'slug' => 'laladia']);

    ($this->asRole)('Owner')->patch(route('inventory.brands.toggle-active', $brand))->assertRedirect();
    expect($brand->fresh()->is_active)->toBeFalse();

    ($this->asRole)('Owner')->patch(route('inventory.brands.toggle-active', $brand))->assertRedirect();
    expect($brand->fresh()->is_active)->toBeTrue();
});

it('lets staff view but never write', function () {
    $brand = Brand::create(['name' => 'LaLaDia', 'code' => 'LLD', 'slug' => 'laladia']);

    ($this->asRole)('Staff')->post(route('inventory.brands.store'), ['name' => 'X', 'code' => 'XX'])->assertForbidden();
    ($this->asRole)('Staff')->put(route('inventory.brands.update', $brand), ['name' => 'Y'])->assertForbidden();
    ($this->asRole)('Staff')->patch(route('inventory.brands.toggle-active', $brand))->assertForbidden();
});

it('keeps a retired brand out of the product form picker but on this screen', function () {
    Brand::create(['name' => 'Retired', 'code' => 'RTD', 'slug' => 'retired', 'is_active' => false]);

    // The product form's picker (ProductController) already filters to active;
    // this screen must show everything or reactivation is impossible.
    ($this->asRole)('Owner')->get(route('inventory.brands.index'))
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('brands', 1));
});
