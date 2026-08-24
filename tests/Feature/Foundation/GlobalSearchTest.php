<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Models\Customer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The header search. The tests that matter are the ones about who sees what:
 * a search box that lists records its user cannot open is a directory leak
 * with autocomplete.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Search Co', 'slug' => 'search-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    // Since the 24 Aug 2026 closure, even the Products group needs
    // inventory.reports.view - Staff here models the real Staff role, which
    // holds it; a zero-permission login is SelfServiceVisibilityTest's job.
    foreach (['sales.reports.view', 'hrm.employees.manage', 'inventory.reports.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'hrm.employees.manage', 'inventory.reports.view']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.reports.view']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'BNC-HON-505G',
        'name' => 'Sundarban Honey 505g',
        'type' => ProductType::FinishedGood,
        'is_active' => true,
    ]);
    Customer::create(['business_id' => $this->business->id, 'name' => 'Honey House Traders']);
});

it('finds a product by name or sku', function () {
    $byName = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->getJson('/search?q=Sundarban')->assertOk()->json('groups');

    expect(collect($byName)->firstWhere('title', 'Products')['items'][0]['label'])->toBe('Sundarban Honey 505g');

    $bySku = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->getJson('/search?q=BNC-HON')->assertOk()->json('groups');

    expect(collect($bySku)->firstWhere('title', 'Products'))->not->toBeNull();
});

it('shows customers only to somebody who can see sales', function () {
    $owner = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->getJson('/search?q=Honey')->assertOk()->json('groups');

    expect(collect($owner)->firstWhere('title', 'Customers'))->not->toBeNull();

    // Staff see the product (they work the shelves) but not the customer book.
    $staff = $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->getJson('/search?q=Honey')->assertOk()->json('groups');

    expect(collect($staff)->firstWhere('title', 'Customers'))->toBeNull()
        ->and(collect($staff)->firstWhere('title', 'Products'))->not->toBeNull();
});

it('answers nothing for a one-letter query rather than scanning everything', function () {
    $groups = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->getJson('/search?q=S')->assertOk()->json('groups');

    expect($groups)->toBe([]);
});

it('refuses a guest', function () {
    $this->getJson('/search?q=honey')->assertUnauthorized();
});
