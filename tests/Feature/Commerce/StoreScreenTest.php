<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\Enums\CustomerType;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1, slice 1.1: the screens.
 *
 * ⚠ The reachability rule this ERP learned the hard way: routes, screens and
 * the sidebar entry ship in one commit. The Sales module was live in production
 * with no link to it anywhere, so the whole revenue side could only be reached
 * by typing a URL.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new CommercePermissionsSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');
});

function asUser(User $user, Business $business)
{
    return test()->actingAs($user)->withSession(['current_business_id' => $business->id]);
}

it('shows the stores screen to a permitted user', function () {
    asUser($this->owner, $this->business)
        ->get(route('commerce.stores.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('commerce/stores/index')->has('stores'));
});

it('offers only retail price groups, because a website is B2C', function () {
    asUser($this->owner, $this->business)
        ->get(route('commerce.stores.index'))
        ->assertInertia(function (Assert $page) {
            $values = collect($page->toArray()['props']['priceGroups'])->pluck('value');

            // ⛔ ADR-005. Wholesale, dealer and distributor selling stays manual
            // in the ERP. A dropdown offering "dealer" would invite somebody to
            // put trade pricing on a public website.
            expect($values)->not->toContain(CustomerType::Dealer->value)
                ->and($values)->not->toContain(CustomerType::Distributor->value)
                ->and($values)->toContain(CustomerType::Retail->value);
        });
});

it('creates a store as a draft', function () {
    asUser($this->owner, $this->business)
        ->post(route('commerce.stores.store'), [
            'key' => 'bionic',
            'name' => 'Bionic Garden',
            'order_prefix' => 'BNC',
            'price_group' => 'retail',
            'availability_policy' => 'status_based',
        ])
        ->assertRedirect();

    $store = Store::sole();

    expect($store->key)->toBe('bionic')
        ->and($store->status)->toBe(StoreStatus::Draft);
});

it('refuses a second store claiming the same order prefix', function () {
    asUser($this->owner, $this->business)->post(route('commerce.stores.store'), [
        'key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC',
        'price_group' => 'retail', 'availability_policy' => 'status_based',
    ]);

    asUser($this->owner, $this->business)
        ->post(route('commerce.stores.store'), [
            'key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'BNC',
            'price_group' => 'retail', 'availability_policy' => 'status_based',
        ])
        ->assertSessionHasErrors('order_prefix');
});

it('will not let the key or the order prefix be changed once the store exists', function () {
    $store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
    ]);

    asUser($this->owner, $this->business)
        ->put(route('commerce.stores.update', $store), [
            'name' => 'Bionic Garden BD',
            'key' => 'renamed',
            'order_prefix' => 'XXX',
            'price_group' => 'retail',
            'availability_policy' => 'status_based',
        ])
        ->assertRedirect();

    $store->refresh();

    // ⚠ The key builds every derived identifier and the prefix is already on
    // customers' invoices and on settlement lines at the payment gateway.
    expect($store->name)->toBe('Bionic Garden BD')
        ->and($store->key)->toBe('bionic')
        ->and($store->order_prefix)->toBe('BNC');
});

it('refuses to take a store live with no theme and no primary domain', function () {
    $store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);

    asUser($this->owner, $this->business)
        ->post(route('commerce.stores.publish', $store))
        ->assertSessionHasErrors('status');

    expect($store->fresh()->status)->toBe(StoreStatus::Draft);
});

it('takes a store live once it has a theme and a primary domain', function () {
    $store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    $store->update(['theme_key' => 'bionic-classic']);
    $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    asUser($this->owner, $this->business)
        ->post(route('commerce.stores.publish', $store))
        ->assertRedirect();

    expect($store->fresh()->status)->toBe(StoreStatus::Live);
});

it('lets a manager configure a store but not publish one', function () {
    $store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    $store->update(['theme_key' => 'bionic-classic']);
    $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    asUser($this->manager, $this->business)
        ->put(route('commerce.stores.update', $store), [
            'name' => 'Bionic BD', 'price_group' => 'retail', 'availability_policy' => 'status_based',
        ])
        ->assertRedirect();

    // ⛔ Publishing points a real domain at real software and starts taking
    // money from the public. Owner only.
    asUser($this->manager, $this->business)
        ->post(route('commerce.stores.publish', $store))
        ->assertForbidden();

    expect($store->fresh()->status)->toBe(StoreStatus::Draft);
});

it('will not remove the primary domain out from under a live store', function () {
    $store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    $store->update(['theme_key' => 'bionic-classic']);
    $domain = $store->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);
    $store->status = StoreStatus::Live;
    $store->save();

    asUser($this->owner, $this->business)
        ->delete(route('commerce.stores.domains.destroy', [$store, $domain]))
        ->assertSessionHasErrors('hostname');

    expect($store->domains()->count())->toBe(1);
});

it('refuses a hostname that already belongs to another store', function () {
    $bionic = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    $bionic->domains()->create(['hostname' => 'bionic.garden', 'is_primary' => true]);

    $econic = Store::create(['key' => 'econic', 'name' => 'Econic', 'order_prefix' => 'ECN']);

    asUser($this->owner, $this->business)
        ->post(route('commerce.stores.domains.store', $econic), ['hostname' => 'bionic.garden'])
        ->assertSessionHasErrors('hostname');
});

it('shows the store its own derived identifiers before it is live', function () {
    $store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);

    asUser($this->owner, $this->business)
        ->get(route('commerce.stores.edit', $store))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('commerce/stores/edit')
            ->where('store.derived.referral_cookie', 'bionic_ref')
            ->where('store.derived.webhook_header', 'X-Bionic-Signature'));
});

it('keeps a user with no commerce permission out', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    asUser($outsider, $this->business)
        ->get(route('commerce.stores.index'))
        ->assertForbidden();
});
