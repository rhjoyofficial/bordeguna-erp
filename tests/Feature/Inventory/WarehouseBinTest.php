<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseBin;
use Spatie\Permission\PermissionRegistrar;

/**
 * WMS bins phase 1 (C-BIN-1..8): a bin is a PLACEMENT record, never a stock
 * document. Nothing on this screen may touch a journal, a movement or a
 * quantity - if that ever stops being true this file fails first.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bin Co', 'slug' => 'bin-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->main = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true,
    ]);
    $this->cold = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Cold Storage', 'code' => 'COLD',
    ]);

    $this->withPermissions = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };
});

it('shows the bins screen with reports.view and refuses without it', function () {
    ($this->withPermissions)(['inventory.reports.view'])
        ->get('/inventory/bins')
        ->assertOk();

    ($this->withPermissions)([])
        ->get('/inventory/bins')
        ->assertForbidden();
});

it('creates a bin under catalogue management, touching no journal, movement or batch', function () {
    ($this->withPermissions)(['inventory.catalog.manage'])
        ->post('/inventory/bins', [
            'warehouse_id' => $this->main->id,
            'code' => 'A-1',
            'name' => 'Top rack, left',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $bin = WarehouseBin::firstOrFail();

    expect($bin->code)->toBe('A-1')
        ->and($bin->warehouse_id)->toBe($this->main->id)
        ->and($bin->is_active)->toBeTrue()
        ->and(Journal::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0);
});

it('refuses bin creation to someone who can only view', function () {
    ($this->withPermissions)(['inventory.reports.view'])
        ->post('/inventory/bins', ['warehouse_id' => $this->main->id, 'code' => 'A-1'])
        ->assertForbidden();
});

it('refuses a duplicate code in the same warehouse and allows it in another', function () {
    WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->main->id, 'code' => 'A-1',
    ]);

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->post('/inventory/bins', ['warehouse_id' => $this->main->id, 'code' => 'A-1'])
        ->assertSessionHasErrors('code');

    // C-BIN-3: "A-1" may exist in every warehouse, never twice in one.
    ($this->withPermissions)(['inventory.catalog.manage'])
        ->post('/inventory/bins', ['warehouse_id' => $this->cold->id, 'code' => 'A-1'])
        ->assertSessionHasNoErrors();

    expect(WarehouseBin::count())->toBe(2);
});

it('edits a bin in place and toggles it off and on', function () {
    $bin = WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->main->id, 'code' => 'A-1',
    ]);

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->put("/inventory/bins/{$bin->id}", ['code' => 'A-2', 'name' => 'Renumbered'])
        ->assertSessionHasNoErrors();

    expect($bin->refresh()->code)->toBe('A-2')
        ->and($bin->name)->toBe('Renumbered');

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->patch("/inventory/bins/{$bin->id}/toggle-active")
        ->assertRedirect();

    expect($bin->refresh()->is_active)->toBeFalse();

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->patch("/inventory/bins/{$bin->id}/toggle-active")
        ->assertRedirect();

    expect($bin->refresh()->is_active)->toBeTrue();
});

it('refuses to delete a bin a batch has pointed at, and deletes an unreferenced one', function () {
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-TST-001', 'name' => 'Test pack', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    $occupied = WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->main->id, 'code' => 'A-1',
    ]);
    $empty = WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->main->id, 'code' => 'A-2',
    ]);

    // ⚠ An EMPTIED batch still blocks deletion: the bin is part of where that
    // batch's history happened, the same reason a brand with products cannot go.
    StockBatch::create([
        'business_id' => $this->business->id, 'product_id' => $product->id,
        'warehouse_id' => $this->main->id, 'bin_id' => $occupied->id,
        'unit_cost' => '100', 'conversion_factor' => '1',
        'initial_quantity' => '5', 'current_quantity' => '0',
        'status' => 'active', 'received_at' => now(),
    ]);

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->delete("/inventory/bins/{$occupied->id}")
        ->assertSessionHas('error');

    expect(WarehouseBin::whereKey($occupied->id)->exists())->toBeTrue();

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->delete("/inventory/bins/{$empty->id}")
        ->assertSessionHas('success');

    expect(WarehouseBin::whereKey($empty->id)->exists())->toBeFalse();
});

it('refuses a bin in another business warehouse', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-bin-co']);
    $theirs = Warehouse::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Theirs', 'code' => 'THEIRS']);

    ($this->withPermissions)(['inventory.catalog.manage'])
        ->post('/inventory/bins', ['warehouse_id' => $theirs->id, 'code' => 'A-1'])
        ->assertSessionHasErrors('warehouse_id');

    expect(WarehouseBin::withoutGlobalScopes()->count())->toBe(0);
});

// Add and edit are one popup over the list since 14 Oct 2026 (Add was a form
// above it and Edit turned the row into inputs). The page carries what the popup
// draws - the active warehouses to choose from, and each bin's warehouse, code
// and name - and the popup saves through the same routes. Editing sends the
// greyed-out warehouse back too, and update() still never moves the bin.
it('carries what the add and edit popup needs on the list, and saves through the same routes', function () {
    $this->cold->update(['is_active' => false]);
    $manager = ['inventory.catalog.manage', 'inventory.reports.view'];

    ($this->withPermissions)($manager)
        ->post('/inventory/bins', ['warehouse_id' => $this->main->id, 'code' => 'A-1', 'name' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $bin = WarehouseBin::sole();

    ($this->withPermissions)($manager)
        ->put("/inventory/bins/{$bin->id}", ['warehouse_id' => $this->cold->id, 'code' => 'A-2', 'name' => 'Top rack'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($bin->refresh()->code)->toBe('A-2')
        ->and($bin->name)->toBe('Top rack')
        ->and($bin->warehouse_id)->toBe($this->main->id);

    ($this->withPermissions)($manager)
        ->get('/inventory/bins')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/bins/index')
            ->where('inventoryAbilities.manageCatalog', true)
            ->has('warehouses', 1)
            ->where('warehouses.0.id', $this->main->id)
            ->where('bins.0.warehouse_id', $this->main->id)
            ->where('bins.0.warehouse', 'Main')
            ->where('bins.0.code', 'A-2')
            ->where('bins.0.name', 'Top rack'));

    // Someone who may only look gets the list, not the popup.
    ($this->withPermissions)(['inventory.reports.view'])
        ->get('/inventory/bins')
        ->assertInertia(fn ($page) => $page->where('inventoryAbilities.manageCatalog', false));
});
