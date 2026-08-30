<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\PermissionRegistrar;

/**
 * The warehouses page (30 Aug 2026 audit, root cause C). The status used to
 * be a label; `update` accepted `is_active` all along and nothing on the
 * screen ever sent it. Now the status is a control, the row can be edited in
 * place, and the one rule that matters is pinned here: THE DEFAULT WAREHOUSE
 * CAN NEVER BE SWITCHED OFF, because every stock document that does not name
 * a warehouse lands in it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Warehouse Co', 'slug' => 'warehouse-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $this->savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SVR']);

    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->user->businesses()->attach($this->business->id);

    $this->as = fn () => $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id]);
});

it('lists warehouses with their default and active flags', function () {
    ($this->as)()->get(route('inventory.warehouses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/warehouses/index')
            ->has('warehouses', 2)
            ->where('warehouses.0.name', 'Main')
            ->where('warehouses.0.is_default', true)
            ->where('warehouses.0.is_active', true));
});

it('switches a warehouse off and on again from its status', function () {
    ($this->as)()->from(route('inventory.warehouses.index'))
        ->patch(route('inventory.warehouses.toggle-active', $this->savar))
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHas('success');

    expect($this->savar->fresh()->is_active)->toBeFalse();

    ($this->as)()->patch(route('inventory.warehouses.toggle-active', $this->savar));

    expect($this->savar->fresh()->is_active)->toBeTrue();
});

it('refuses to switch the default warehouse off', function () {
    ($this->as)()->from(route('inventory.warehouses.index'))
        ->patch(route('inventory.warehouses.toggle-active', $this->main))
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHas('error');

    expect($this->main->fresh()->is_active)->toBeTrue();
});

it('refuses is_active=false for the default warehouse through update as well', function () {
    ($this->as)()->from(route('inventory.warehouses.index'))
        ->put(route('inventory.warehouses.update', $this->main), ['name' => 'Main', 'code' => 'MAIN', 'is_active' => false])
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHas('error');

    expect($this->main->fresh()->is_active)->toBeTrue();
});

it('edits a name and code in place without touching the status', function () {
    $this->savar->update(['is_active' => false]);

    ($this->as)()->from(route('inventory.warehouses.index'))
        ->put(route('inventory.warehouses.update', $this->savar), ['name' => 'Savar Depot', 'code' => 'SVD', 'is_active' => false])
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHas('success');

    $fresh = $this->savar->fresh();
    expect($fresh->name)->toBe('Savar Depot')
        ->and($fresh->code)->toBe('SVD')
        ->and($fresh->is_active)->toBeFalse();
});
