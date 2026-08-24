<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Self-service closure (24 Aug 2026). Inventory and Purchasing reads were
 * "open to anyone in the business" - written when everyone in the business was
 * trusted staff. The zero-permission Self-service role ended that assumption:
 * a login meant to reach only its own profile could read the stock book, the
 * supplier bills and the product catalogue, on every device.
 *
 * These pin the closure at every layer that leaked: the routes, the shared
 * nav props, and the global search groups.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Visibility Co', 'slug' => 'visibility-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.reports.view', 'purchasing.reports.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::create(['name' => 'Self-service', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    Role::create(['name' => 'Viewer', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['inventory.reports.view', 'purchasing.reports.view']);

    $this->makeUser = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };
});

// Every read that was open before the closure, by module.
$inventoryReads = [
    '/inventory/products', '/inventory/categories', '/inventory/brands',
    '/inventory/units', '/inventory/warehouses', '/inventory/stock',
    '/inventory/stock/transfers', '/inventory/products/export',
];
$purchasingReads = [
    '/purchasing/suppliers', '/purchasing/purchase-orders', '/purchasing/payments',
    '/purchasing/bills', '/purchasing/credit-notes', '/purchasing/expenses',
];

it('refuses every formerly-open inventory read to a zero-permission login', function () use ($inventoryReads) {
    foreach ($inventoryReads as $url) {
        ($this->makeUser)('Self-service')->get($url)->assertForbidden();
    }
});

it('refuses every formerly-open purchasing read to a zero-permission login', function () use ($purchasingReads) {
    foreach ($purchasingReads as $url) {
        ($this->makeUser)('Self-service')->get($url)->assertForbidden();
    }
});

it('still serves those reads to anyone holding the view permissions', function () use ($inventoryReads, $purchasingReads) {
    foreach ([...$inventoryReads, ...$purchasingReads] as $url) {
        ($this->makeUser)('Viewer')->get($url)->assertSuccessful();
    }
});

it('tells the navigation the truth about both modules', function () {
    ($this->makeUser)('Self-service')->get('/dashboard')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('canViewInventory', false)
            ->where('canViewPurchasing', false));

    ($this->makeUser)('Viewer')->get('/dashboard')
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('canViewInventory', true)
            ->where('canViewPurchasing', true));
});

it('keeps products and suppliers out of a zero-permission search', function () {
    App\Modules\Inventory\Models\Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => App\Modules\Inventory\Models\UnitOfMeasure::create([
            'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs',
        ])->id,
        'sku' => 'SRCH-1', 'name' => 'Searchable Honey', 'type' => 'finished_good', 'is_active' => true,
    ]);

    $blind = ($this->makeUser)('Self-service')->get('/search?q=Searchable')->json('groups');
    expect(collect($blind)->pluck('title'))->not->toContain('Products');

    $sighted = ($this->makeUser)('Viewer')->get('/search?q=Searchable')->json('groups');
    expect(collect($sighted)->pluck('title'))->toContain('Products');
});
