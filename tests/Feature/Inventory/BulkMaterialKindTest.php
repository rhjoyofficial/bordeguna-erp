<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * 31 Oct 2026: tick raw materials on the raw materials list and mark them Raw
 * or Packaging together. Only raw materials, only this business's, only for a
 * catalogue manager.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Kind Co', 'slug' => 'kind-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.catalog.manage', 'inventory.reports.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $make = fn (string $sku, ProductType $type, ?MaterialKind $kind) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => $sku, 'name' => $sku, 'type' => $type, 'material_kind' => $kind,
    ]);

    $this->label = $make('LABEL', ProductType::RawMaterial, MaterialKind::Raw);
    $this->cap = $make('CAP', ProductType::RawMaterial, MaterialKind::Raw);
    $this->pickle = $make('PICKLE', ProductType::FinishedGood, null);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->givePermissionTo(['inventory.catalog.manage', 'inventory.reports.view']);

    $this->post = fn (User $user, array $body) => $this->actingAs($user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->post('/inventory/products/material-kind', $body);
});

it('marks the ticked raw materials Packaging and leaves a finished good alone', function () {
    ($this->post)($this->manager, ['ids' => [$this->label->id, $this->cap->id, $this->pickle->id], 'material_kind' => 'packaging'])
        ->assertRedirect()
        ->assertSessionHas('success', '2 items marked Packaging.');

    expect($this->label->fresh()->material_kind)->toBe(MaterialKind::Packaging)
        ->and($this->cap->fresh()->material_kind)->toBe(MaterialKind::Packaging)
        ->and($this->pickle->fresh()->material_kind)->toBeNull();
});

it('marks them back to Raw', function () {
    $this->label->update(['material_kind' => MaterialKind::Packaging]);

    ($this->post)($this->manager, ['ids' => [$this->label->id], 'material_kind' => 'raw'])->assertSessionHasNoErrors();

    expect($this->label->fresh()->material_kind)->toBe(MaterialKind::Raw);
});

it('refuses an unknown kind and an empty selection', function () {
    ($this->post)($this->manager, ['ids' => [$this->label->id], 'material_kind' => 'finished'])->assertSessionHasErrors('material_kind');
    ($this->post)($this->manager, ['ids' => [], 'material_kind' => 'packaging'])->assertSessionHasErrors('ids');

    expect($this->label->fresh()->material_kind)->toBe(MaterialKind::Raw);
});

it('is for a catalogue manager only', function () {
    $viewer = User::factory()->create();
    $viewer->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $viewer->givePermissionTo('inventory.reports.view');

    ($this->post)($viewer, ['ids' => [$this->label->id], 'material_kind' => 'packaging'])->assertForbidden();

    expect($this->label->fresh()->material_kind)->toBe(MaterialKind::Raw);
});
