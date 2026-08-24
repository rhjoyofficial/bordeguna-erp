<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The expiry screen (INV-3). The boundary under test is the consumable
 * scope's own rule: a batch is good THROUGH its expiry date, so "expired"
 * begins the day after - and the soon-window is inclusive of day 30.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-24 10:00:00');

    $this->business = Business::create(['name' => 'Expiry Co', 'slug' => 'expiry-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mohammadpur', 'code' => 'MH']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-CHU', 'name' => 'Churi Shutki 125g',
        'type' => ProductType::FinishedGood,
    ]);

    $this->batch = function (?string $expiry, string $quantity = '5', ?string $number = null) {
        return StockBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => $number,
            'expiry_date' => $expiry,
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

afterEach(fn () => Carbon::setTestNow());

it('splits batches at the good-through boundary: today is not expired, yesterday is', function () {
    ($this->batch)('2026-08-23', '3', 'B-YESTERDAY');
    ($this->batch)('2026-08-24', '4', 'B-TODAY');

    ($this->asRole)('Staff')->get(route('inventory.expiry.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('inventory/expiry/index')
            ->has('expired', 1)
            ->where('expired.0.batch_number', 'B-YESTERDAY')
            ->where('expired.0.days', 1)
            ->has('expiring', 1)
            ->where('expiring.0.batch_number', 'B-TODAY')
            ->where('expiring.0.days', 0));
});

it('includes day 30 in the soon window and excludes day 31', function () {
    ($this->batch)('2026-09-23', '2', 'B-DAY30');
    ($this->batch)('2026-09-24', '2', 'B-DAY31');

    ($this->asRole)('Staff')->get(route('inventory.expiry.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('expiring', 1)
            ->where('expiring.0.batch_number', 'B-DAY30')
            ->where('expiring.0.days', 30)
            ->has('expired', 0));
});

it('ignores batches with nothing left and batches with no expiry date', function () {
    ($this->batch)('2026-08-01', '0', 'B-DRAINED');
    ($this->batch)(null, '9', 'B-NODATE');

    ($this->asRole)('Staff')->get(route('inventory.expiry.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('expired', 0)
            ->has('expiring', 0));
});

it('refuses a user with no inventory role', function () {
    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);

    $this->actingAs($user)->get(route('inventory.expiry.index'))->assertForbidden();
});
