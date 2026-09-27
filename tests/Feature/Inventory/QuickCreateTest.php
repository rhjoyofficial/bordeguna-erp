<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 27 Sep 2026 (Joy): a unit, category, brand or supplier that a dropdown is
 * missing can be created INSTANTLY from inside the form, without visiting
 * another page. These pin the JSON quick-create endpoints behind those pickers:
 * the same permission as the full screen, the same shape of row, and a supplier
 * code invented when none is given.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Quick Co', 'slug' => 'quick-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
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

it('creates a unit and hands back its id and label', function () {
    $response = ($this->asRole)('Owner')
        ->postJson(route('inventory.quick.units'), ['name' => 'Kilogram', 'abbreviation' => 'kg']);

    $response->assertCreated()->assertJson(['label' => 'Kilogram (kg)']);
    expect(UnitOfMeasure::where('abbreviation', 'kg')->exists())->toBeTrue()
        ->and($response->json('id'))->toBe(UnitOfMeasure::where('abbreviation', 'kg')->value('id'));
});

it('creates a category, code optional and upper-cased', function () {
    ($this->asRole)('Owner')->postJson(route('inventory.quick.categories'), ['name' => 'Spices'])
        ->assertCreated()->assertJson(['label' => 'Spices']);
    expect(Category::where('name', 'Spices')->value('code'))->toBeNull();

    ($this->asRole)('Owner')->postJson(route('inventory.quick.categories'), ['name' => 'Oils', 'code' => 'oil'])
        ->assertCreated();
    expect(Category::where('name', 'Oils')->value('code'))->toBe('OIL');
});

it('creates a brand, code upper-cased', function () {
    ($this->asRole)('Owner')->postJson(route('inventory.quick.brands'), ['name' => 'Bor de Guna Fresh', 'code' => 'bdf'])
        ->assertCreated()->assertJson(['label' => 'Bor de Guna Fresh']);
    expect(Brand::where('slug', 'bor-de-guna-fresh')->value('code'))->toBe('BDF');
});

it('creates a supplier from a name alone, inventing a unique code', function () {
    $first = ($this->asRole)('Owner')->postJson(route('purchasing.suppliers.quick'), ['name' => 'Padma Traders']);
    $first->assertCreated();
    $firstCode = Supplier::where('name', 'Padma Traders')->orderBy('id')->value('code');
    expect($firstCode)->toBe('PADMATRA')
        ->and($first->json('label'))->toBe("Padma Traders ({$firstCode})");

    // A second supplier with the same name gets a DIFFERENT, still-unique code.
    ($this->asRole)('Owner')->postJson(route('purchasing.suppliers.quick'), ['name' => 'Padma Traders'])
        ->assertCreated();
    $codes = Supplier::where('name', 'Padma Traders')->pluck('code');
    expect($codes)->toHaveCount(2)->and($codes->unique())->toHaveCount(2);
});

it('honours a supplier code given explicitly', function () {
    ($this->asRole)('Owner')->postJson(route('purchasing.suppliers.quick'), ['name' => 'Meghna Foods', 'code' => 'MEGHNA'])
        ->assertCreated();
    expect(Supplier::where('code', 'MEGHNA')->exists())->toBeTrue();
});

it('validates: a unit needs an abbreviation', function () {
    ($this->asRole)('Owner')->postJson(route('inventory.quick.units'), ['name' => 'Litre'])
        ->assertStatus(422)->assertJsonValidationErrors('abbreviation');
});

it('refuses a user without the catalogue permission', function () {
    ($this->asRole)('Staff')->postJson(route('inventory.quick.units'), ['name' => 'Gram', 'abbreviation' => 'g'])
        ->assertForbidden();
    ($this->asRole)('Staff')->postJson(route('inventory.quick.brands'), ['name' => 'X', 'code' => 'XX'])
        ->assertForbidden();
});

it('refuses a user without the supplier permission', function () {
    ($this->asRole)('Staff')->postJson(route('purchasing.suppliers.quick'), ['name' => 'Nobody'])
        ->assertForbidden();
});
