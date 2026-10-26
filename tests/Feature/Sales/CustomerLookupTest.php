<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\PriceGroupBranch;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 26 Oct 2026: "while sell or others things, if i enter number or name
 * matches will auto fill the necessary fields."
 *
 * ⚠ What these pin: a whole phone, typed any way, finds exactly one customer;
 * a name only ever offers a list; and the lookup never shows a customer the
 * screen's own picker would hide (another business, a staff purchase account,
 * the walk-in row, an inactive customer).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lookup Co', 'slug' => 'lookup-co']);

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Viewer'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    (new WalkInCustomerSeeder)->run();

    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->as = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $this->sangram = PriceGroup::create(['code' => 'SANGRAM_NGO', 'name' => 'Sangram NGO', 'falls_back_to_mrp' => false]);
    $this->kuakata = PriceGroupBranch::create(['price_group_id' => $this->sangram->id, 'name' => 'Kuakata']);

    $this->karim = Customer::create([
        'code' => 'C-0101', 'name' => 'Karim Store', 'type' => CustomerType::Corporate, 'phone' => '01711-000000',
        'address' => 'Alipur, Kalapara', 'price_group_id' => $this->sangram->id, 'price_group_branch_id' => $this->kuakata->id,
    ]);
});

it('finds the one customer with that phone, however the number is typed', function (string $typed) {
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => $typed]))
        ->assertOk()
        ->assertJsonPath('match.id', $this->karim->id)
        ->assertJsonPath('match.code', 'C-0101')
        ->assertJsonPath('match.name', 'Karim Store')
        ->assertJsonPath('match.phone', '01711-000000')
        ->assertJsonPath('match.type', 'corporate')
        ->assertJsonPath('match.price_group_id', $this->sangram->id)
        ->assertJsonPath('match.price_group', 'Sangram NGO')
        ->assertJsonPath('match.branch', 'Kuakata')
        ->assertJsonPath('match.address', 'Alipur, Kalapara')
        ->assertJsonPath('match.saved_place', null)
        ->assertJsonPath('matches', []);
})->with(['01711000000', '+8801711000000', '8801711000000', '+880 1711-000000', '1711000000']);

it('selects nobody on half a number or an unknown one', function (string $typed) {
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => $typed]))
        ->assertOk()
        ->assertJsonPath('match', null)
        ->assertJsonPath('matches', []);
})->with(['01711', '01999999999', 'abc']);

it('offers every customer sharing a phone and selects none of them', function () {
    Customer::create(['name' => 'Karim Store Annex', 'type' => CustomerType::Retail, 'phone' => '+8801711000000']);

    $response = ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => '01711000000']))->assertOk();

    expect($response->json('match'))->toBeNull()
        ->and(collect($response->json('matches'))->pluck('name')->all())->toBe(['Karim Store', 'Karim Store Annex']);
});

it('lists customers by part of the name, never selecting one, at most eight', function () {
    Customer::create(['name' => 'Rahim Karim Traders', 'type' => CustomerType::Retail]);
    foreach (range(1, 9) as $n) {
        Customer::create(['name' => "Bulk Buyer {$n}", 'type' => CustomerType::Retail]);
    }

    $response = ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'karim']))->assertOk();
    expect($response->json('match'))->toBeNull()
        // A name that starts with what was typed comes first.
        ->and(collect($response->json('matches'))->pluck('name')->all())->toBe(['Karim Store', 'Rahim Karim Traders'])
        ->and($response->json('matches.0.branch'))->toBe('Kuakata');

    expect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'bulk']))->json('matches'))->toHaveCount(8);

    // One letter is too little to search on.
    expect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'k']))->json('matches'))->toBe([]);
});

it('lists customers by part of a phone', function () {
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => '+880 1711']))
        ->assertOk()
        ->assertJsonPath('matches.0.id', $this->karim->id);
});

it('never offers the walk-in row or an inactive customer', function () {
    Customer::create(['name' => 'Karim Old', 'type' => CustomerType::Retail, 'phone' => '01822000000', 'is_active' => false]);
    $walkIn = Customer::walkIn();
    $walkIn->update(['phone' => '01933000000']);

    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => '01822000000']))->assertJsonPath('match', null);
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => '01933000000']))->assertJsonPath('match', null);
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['id' => $walkIn->id]))->assertJsonPath('match', null);

    expect(collect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'karim']))->json('matches'))->pluck('name')->all())
        ->toBe(['Karim Store']);
    expect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'walk']))->json('matches'))->toBe([]);
});

it('hides a staff purchase account from a plain seller and shows it to the Owner', function () {
    $staffCustomer = Customer::create(['name' => 'Karim (staff)', 'type' => CustomerType::Staff, 'phone' => '01544000000']);

    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => '01544000000']))->assertJsonPath('match', null);
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['id' => $staffCustomer->id]))->assertJsonPath('match', null);
    expect(collect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'karim']))->json('matches'))->pluck('id'))
        ->not->toContain($staffCustomer->id);

    ($this->as)('Owner')->getJson(route('sales.customers.lookup', ['phone' => '01544000000']))
        ->assertJsonPath('match.id', $staffCustomer->id);
});

it('never reaches another business', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    TenantScope::enter($other->id);
    app(BusinessContext::class)->set($other->id);
    $stranger = Customer::create(['business_id' => $other->id, 'name' => 'Karim Elsewhere', 'type' => CustomerType::Retail, 'phone' => '01655000000']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['phone' => '01655000000']))->assertJsonPath('match', null);
    ($this->as)('Staff')->getJson(route('sales.customers.lookup', ['id' => $stranger->id]))->assertJsonPath('match', null);
    expect(collect(($this->as)('Staff')->getJson(route('sales.customers.lookup', ['q' => 'karim']))->json('matches'))->pluck('name')->all())
        ->toBe(['Karim Store']);
});

it('refuses somebody who may not sell', function () {
    ($this->as)('Viewer')->getJson(route('sales.customers.lookup', ['phone' => '01711000000']))->assertForbidden();
});
