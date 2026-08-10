<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Price groups as rows: RETAIL, DEALER, DISTRIBUTOR, CORPORATE (D-PRC-2,
 * fixed by Joy 2026-08-10).
 *
 * ⚠⚠ The defect this table exists to prevent: free-text groups matched by
 * exact string, where a customer in `Distributor` and a rule for `distributor`
 * never meet and the resolver silently falls through to the retail price. The
 * FK makes that state unrepresentable; these tests pin the seams around it -
 * the seeder, the tenant boundary, and the either-customer-or-group rule.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Group Co', 'slug' => 'group-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    // ⚠ The seeder LEAVES the tenant when it finishes (TenantScope::leave
    // fails closed, correctly), so a test that queries scoped models after
    // seeding must re-enter - the same thing ResolveCurrentBusiness does for
    // every real request.
    $this->seedGroups = function (): void {
        (new PriceGroupSeeder)->run();
        TenantScope::enter($this->business->id);
    };
});

it('seeds the four official groups for every business', function () {
    $other = Business::create(['name' => 'Second Co', 'slug' => 'second-co']);

    ($this->seedGroups)();

    foreach ([$this->business->id, $other->id] as $businessId) {
        $codes = PriceGroup::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->pluck('code')
            ->all();

        expect($codes)->toBe(['RETAIL', 'DEALER', 'DISTRIBUTOR', 'CORPORATE']);
    }
});

// ⚠ Idempotent PER GROUP, not all-or-nothing - the seeder trap this project
// has hit twice. Re-running must change nothing; a group added by hand must
// survive it.
it('seeds idempotently and leaves existing groups alone', function () {
    ($this->seedGroups)();

    $retail = PriceGroup::where('code', 'RETAIL')->firstOrFail();
    $retail->update(['name' => 'Walk-in Retail']);

    ($this->seedGroups)();

    expect(PriceGroup::count())->toBe(4)
        ->and($retail->fresh()->name)->toBe('Walk-in Retail');
});

// The same code in ANOTHER business is fine - the seeder test above proves it
// by planting all four codes in two businesses.
it('refuses a second group with the same code in one business', function () {
    ($this->seedGroups)();

    expect(fn () => PriceGroup::create([
        'business_id' => $this->business->id,
        'code' => 'RETAIL',
        'name' => 'Duplicate',
    ]))->toThrow(QueryException::class);
});

// ⚠⚠ THE TENANT BOUNDARY (C-PRC-6). A group from another business must be
// unreachable from both screens that accept one. The global scope already
// hides it from lists; this pins the WRITE path.
it('rejects another business\'s price group on the customer screen', function () {
    ($this->seedGroups)();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-group-co']);
    $foreign = PriceGroup::withoutGlobalScopes()->create([
        'business_id' => $other->id,
        'code' => 'FOREIGN',
        'name' => 'Foreign',
    ]);

    ($this->asRole)('Manager')
        ->post('/sales/customers', ['name' => 'Sneaky Co', 'type' => 'retail', 'price_group_id' => $foreign->id])
        ->assertSessionHasErrors('price_group_id');
});

it('rejects another business\'s price group on the price screen', function () {
    ($this->seedGroups)();

    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-1',
        'name' => 'Honey',
        'type' => ProductType::FinishedGood,
    ]);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-price-co']);
    $foreign = PriceGroup::withoutGlobalScopes()->create([
        'business_id' => $other->id,
        'code' => 'FOREIGN',
        'name' => 'Foreign',
    ]);

    ($this->asRole)('Manager')
        ->post('/sales/prices', [
            'product_id' => $product->id,
            'unit_price' => '100',
            'effective_from' => '2026-08-10',
            'price_group_id' => $foreign->id,
        ])
        ->assertSessionHasErrors('price_group_id');
});

it('still refuses a price aimed at a customer AND a group at once', function () {
    ($this->seedGroups)();

    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-2',
        'name' => 'Dates',
        'type' => ProductType::FinishedGood,
    ]);
    $group = PriceGroup::where('code', 'DEALER')->firstOrFail();
    $customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Somebody',
    ]);

    ($this->asRole)('Manager')
        ->post('/sales/prices', [
            'product_id' => $product->id,
            'unit_price' => '100',
            'effective_from' => '2026-08-10',
            'customer_id' => $customer->id,
            'price_group_id' => $group->id,
        ])
        ->assertSessionHasErrors('price_group_id');
});
