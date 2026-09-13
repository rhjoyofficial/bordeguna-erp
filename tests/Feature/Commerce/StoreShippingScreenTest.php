<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Commerce\Support\StandardDeliveryBands;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Shipping and District Pricing screens (13 September).
 *
 * ⚠⚠ Until these, only a developer could set a delivery rate. Joy's answers: rates
 * are changed by Owner and Manager, and a shop starts with LaLaDia's bands.
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

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);
});

it('gives a new store the standard delivery bands', function () {
    ($this->asRole)('Owner')->post(route('commerce.stores.store'), [
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD', 'availability_policy' => 'status_based',
    ])->assertSessionHasNoErrors();

    $store = Store::where('key', 'laladia')->sole();

    // Joy's LaLaDia bands: Dhaka 60, the five suburbs 90, everywhere else 120.
    expect(DeliveryQuote::for($store, 'Dhaka')->charge())->toBe('60.00')
        ->and(DeliveryQuote::for($store, 'Gazipur')->charge())->toBe('90.00')
        ->and(DeliveryQuote::for($store, 'Munshiganj')->charge())->toBe('90.00')
        ->and(DeliveryQuote::for($store, 'Bagerhat')->charge())->toBe('120.00')
        ->and(DeliveryQuote::for($store, 'Bagerhat')->isFallback)->toBeTrue();
});

it('never overwrites rates somebody has already set', function () {
    ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Everywhere', 'charge' => '100'])->makeDefault();

    expect(StandardDeliveryBands::applyTo($this->store))->toBeFalse();

    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/shipping/standard")
        ->assertSessionHasErrors(['zone' => 'This shop already has delivery rates. The standard bands are only for a shop with none.']);

    expect(ShippingZone::count())->toBe(1);
});

it('lets an Owner or Manager change a rate, and the next quote follows it', function () {
    StandardDeliveryBands::applyTo($this->store);
    $inside = ShippingZone::where('name', 'Inside Dhaka')->sole();

    ($this->asRole)('Manager')
        ->put("/commerce/stores/{$this->store->id}/shipping/zones/{$inside->id}", ['name' => 'Inside Dhaka', 'charge' => '70'])
        ->assertSessionHasNoErrors();

    expect(DeliveryQuote::for($this->store, 'Dhaka')->charge())->toBe('70.00');
});

it('lets content staff read the rates but not change them', function () {
    StandardDeliveryBands::applyTo($this->store);
    $inside = ShippingZone::where('name', 'Inside Dhaka')->sole();

    ($this->asRole)('Staff')
        ->get("/commerce/stores/{$this->store->id}/shipping")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('commerce/stores/shipping')
            ->where('can_manage', false)
            ->has('districts', 64)
            ->where('districts', fn ($districts) => collect($districts)->firstWhere('name', 'Dhaka')['zone_id'] === $inside->id));

    ($this->asRole)('Staff')
        ->put("/commerce/stores/{$this->store->id}/shipping/zones/{$inside->id}", ['name' => 'Inside Dhaka', 'charge' => '1'])
        ->assertForbidden();
});

it('saves which district pays which zone, and an empty choice means the catch-all', function () {
    StandardDeliveryBands::applyTo($this->store);
    $suburbs = ShippingZone::where('name', 'Dhaka suburbs')->sole();

    ($this->asRole)('Owner')
        ->put("/commerce/stores/{$this->store->id}/shipping/districts", ['districts' => [
            'Tangail' => $suburbs->id,
            'Gazipur' => null,
        ]])
        ->assertSessionHasNoErrors();

    expect(DeliveryQuote::for($this->store, 'Tangail')->charge())->toBe('90.00')
        // ⚠ Removed from the suburbs: now the catch-all, and no row points at nothing.
        ->and(DeliveryQuote::for($this->store, 'Gazipur')->charge())->toBe('120.00')
        ->and(DistrictShippingZone::where('district', 'Gazipur')->exists())->toBeFalse();
});

it('refuses a district the geography does not know, and another shop\'s zone', function () {
    StandardDeliveryBands::applyTo($this->store);

    $other = Store::create(['key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD']);
    $theirs = ShippingZone::create(['store_id' => $other->id, 'name' => 'Theirs', 'charge' => '1']);

    ($this->asRole)('Owner')
        ->put("/commerce/stores/{$this->store->id}/shipping/districts", ['districts' => ['Atlantis' => null]])
        ->assertSessionHasErrors(['districts' => 'We do not recognise the district Atlantis.']);

    ($this->asRole)('Owner')
        ->put("/commerce/stores/{$this->store->id}/shipping/districts", ['districts' => ['Dhaka' => $theirs->id]])
        ->assertSessionHasErrors('districts.Dhaka');

    ($this->asRole)('Owner')
        ->put("/commerce/stores/{$this->store->id}/shipping/zones/{$theirs->id}", ['name' => 'Mine now', 'charge' => '1'])
        ->assertNotFound();
});

it('will not delete the catch-all, and a deleted zone sends its districts to it', function () {
    StandardDeliveryBands::applyTo($this->store);
    $outside = ShippingZone::where('name', 'Outside Dhaka')->sole();
    $suburbs = ShippingZone::where('name', 'Dhaka suburbs')->sole();

    ($this->asRole)('Owner')
        ->delete("/commerce/stores/{$this->store->id}/shipping/zones/{$outside->id}")
        ->assertSessionHasErrors('zone');

    ($this->asRole)('Owner')
        ->delete("/commerce/stores/{$this->store->id}/shipping/zones/{$suburbs->id}")
        ->assertSessionHasNoErrors();

    expect(DeliveryQuote::for($this->store, 'Gazipur')->charge())->toBe('120.00');
});

it('makes the first zone of a store with none its catch-all', function () {
    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/shipping/zones", ['name' => 'Everywhere', 'charge' => '100'])
        ->assertSessionHasNoErrors();

    expect(DeliveryQuote::for($this->store, 'Sylhet')->charge())->toBe('100.00');
});

it('lets an Owner or Manager set the shop delivery promise, fastest first', function () {
    // ⭐ D24: one window in hours after confirmation, 24 to 72 unless changed.
    expect($this->store->fresh()->deliveryPromise())->toBe('Delivered within 24 to 72 hours after we confirm your order.');

    ($this->asRole)('Manager')
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden', 'availability_policy' => 'status_based',
            'delivery_promise_min_hours' => 48, 'delivery_promise_max_hours' => 24,
        ])
        ->assertSessionHasErrors('delivery_promise_max_hours');

    ($this->asRole)('Manager')
        ->put(route('commerce.stores.update', $this->store), [
            'name' => 'Bionic Garden', 'availability_policy' => 'status_based',
            'delivery_promise_min_hours' => 24, 'delivery_promise_max_hours' => 48,
        ])
        ->assertSessionHasNoErrors();

    expect($this->store->fresh()->deliveryPromise())->toBe('Delivered within 24 to 48 hours after we confirm your order.');
});
