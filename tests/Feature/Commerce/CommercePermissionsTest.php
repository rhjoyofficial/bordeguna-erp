<?php

use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1, slice 1.1: who may run the websites.
 *
 * ⛔ The decision this file pins is that publishing a store is a SEPARATE
 * permission from configuring one, and only the Owner holds it. Taking a store
 * live points a real domain at real software and starts taking money from the
 * public, which is not the same decision as renaming it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::findOrCreate($name, 'web');
    }
});

it('lets only the owner publish a store', function () {
    (new CommercePermissionsSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::where('name', 'Owner')->first()->hasPermissionTo('commerce.stores.publish'))->toBeTrue()
        ->and(Role::where('name', 'Manager')->first()->hasPermissionTo('commerce.stores.publish'))->toBeFalse()
        ->and(Role::where('name', 'Staff')->first()->hasPermissionTo('commerce.stores.publish'))->toBeFalse();
});

it('lets staff write content but not configure a store', function () {
    (new CommercePermissionsSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $staff = Role::where('name', 'Staff')->first();

    // Writing a product story is ordinary work. Setting the order prefix, the
    // price group and the availability policy decides what a customer is
    // charged and whose money a shared settlement is.
    expect($staff->hasPermissionTo('commerce.content.manage'))->toBeTrue()
        ->and($staff->hasPermissionTo('commerce.stores.view'))->toBeTrue()
        ->and($staff->hasPermissionTo('commerce.stores.manage'))->toBeFalse();
});

it('adds its permissions without taking away another module is', function () {
    $existing = Permission::firstOrCreate(['name' => 'inventory.products.manage', 'guard_name' => 'web']);
    Role::where('name', 'Manager')->first()->givePermissionTo($existing);

    (new CommercePermissionsSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // ⚠⚠ The reason `givePermissionTo` is used and `syncPermissions` is not.
    // A sync would wipe whatever Inventory, Purchasing, Sales, Accounting and
    // CRM had already granted this role, silently, on a live system.
    expect(Role::where('name', 'Manager')->first()->hasPermissionTo('inventory.products.manage'))->toBeTrue()
        ->and(Role::where('name', 'Manager')->first()->hasPermissionTo('commerce.stores.manage'))->toBeTrue();
});

it('can be run twice without complaint', function () {
    (new CommercePermissionsSeeder)->run();
    (new CommercePermissionsSeeder)->run();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Every module seeder in this ERP is re-run on deploy, so a second run has
    // to be a no-op rather than a duplicate-key error.
    expect(Permission::where('name', 'commerce.stores.publish')->count())->toBe(1);
});
