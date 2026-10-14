<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
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

it('refuses with a sentence to delete a warehouse whose stock is used up, and a unit used only as a content unit (INV-004)', function () {
    $pieces = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $oil = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $pieces->id, 'content_unit_id' => $litre->id,
        'sku' => 'OIL-1', 'name' => 'Mustard oil', 'type' => ProductType::FinishedGood,
    ]);

    // Two in, two out: the Savar batch is used up, its history is not.
    app(RecordStockIn::class)->handle(product: $oil, warehouse: $this->savar, quantity: '2', unitCost: '100', performedBy: $this->user);
    app(RecordStockOut::class)->handle($oil, $this->savar, '2', $this->user);

    ($this->as)()->from(route('inventory.warehouses.index'))
        ->delete(route('inventory.warehouses.destroy', $this->savar))
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHas('error');
    expect(Warehouse::find($this->savar->id))->not->toBeNull();

    ($this->as)()->from('/inventory/units')
        ->delete(route('inventory.units.destroy', $litre))
        ->assertRedirect('/inventory/units')
        ->assertSessionHas('error');
    expect(UnitOfMeasure::find($litre->id))->not->toBeNull();
});

// Add and edit are one popup over the list since 14 Oct 2026 (Add was a form
// above it and Edit turned the row into inputs). The page carries what the
// popup draws, and the popup saves through the same routes - sending the row's
// own is_active back, which update() requires, and which store() ignores.
it('carries what the add and edit popup needs on the list, and saves through the same routes', function () {
    ($this->as)()->from(route('inventory.warehouses.index'))
        ->post(route('inventory.warehouses.store'), ['name' => 'Cold Storage', 'code' => 'COLD', 'is_active' => true])
        ->assertRedirect(route('inventory.warehouses.index'))
        ->assertSessionHasNoErrors();
    $cold = Warehouse::where('code', 'COLD')->sole();
    expect($cold->is_active)->toBeTrue()->and($cold->is_default)->toBeFalse();

    ($this->as)()->from(route('inventory.warehouses.index'))
        ->put(route('inventory.warehouses.update', $this->main), ['name' => 'Main Store', 'code' => 'MAIN', 'is_active' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
    expect($this->main->fresh()->name)->toBe('Main Store');

    ($this->as)()->get(route('inventory.warehouses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/warehouses/index')
            ->where('inventoryAbilities.manageCatalog', true)
            ->has('warehouses', 3)
            ->where('warehouses', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['name'] === 'Main Store' && $row['code'] === 'MAIN' && $row['is_active'] === true && $row['is_default'] === true)));
});
