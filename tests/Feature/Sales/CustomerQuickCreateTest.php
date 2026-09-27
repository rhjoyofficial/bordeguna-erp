<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 27 Sep 2026 (Joy): a customer created from inside a sale (counter, invoice,
 * quotation) via a JSON quick endpoint, so a new buyer never sends the cashier
 * to another page mid-sale. Same permission as managing customers.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Quick Cust Co', 'slug' => 'quick-cust-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();

    $this->retailGroup = PriceGroup::firstOrCreate(
        ['business_id' => $this->business->id, 'code' => 'RETAIL'],
        ['name' => 'Retail'],
    );

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

it('creates a customer from a name alone and defaults the type to retail', function () {
    $response = ($this->asRole)('Manager')->postJson(route('sales.customers.quick'), ['name' => 'Rahim Store']);

    $response->assertCreated()->assertJson(['label' => 'Rahim Store']);

    $customer = Customer::where('name', 'Rahim Store')->sole();
    expect($customer->type->value)->toBe('retail')
        ->and($response->json('id'))->toBe($customer->id);
});

it('labels a customer with a phone when one is given', function () {
    ($this->asRole)('Manager')->postJson(route('sales.customers.quick'), ['name' => 'Karim', 'phone' => '01712345678'])
        ->assertCreated()->assertJson(['label' => 'Karim (01712345678)']);
});

it('honours a chosen type', function () {
    ($this->asRole)('Manager')->postJson(route('sales.customers.quick'), ['name' => 'Padma Dealer', 'type' => 'dealer'])
        ->assertCreated();
    expect(Customer::where('name', 'Padma Dealer')->sole()->type->value)->toBe('dealer');
});

it('refuses a retail shop priced in the RETAIL (MRP) group', function () {
    ($this->asRole)('Manager')->postJson(route('sales.customers.quick'), [
        'name' => 'MRP Shop', 'type' => 'shop', 'price_group_id' => $this->retailGroup->id,
    ])->assertStatus(422)->assertJsonValidationErrors('price_group_id');
});

it('requires a name', function () {
    ($this->asRole)('Manager')->postJson(route('sales.customers.quick'), ['name' => ''])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

it('refuses a user without the customer-manage permission', function () {
    ($this->asRole)('Staff')->postJson(route('sales.customers.quick'), ['name' => 'Nobody'])
        ->assertForbidden();
});
