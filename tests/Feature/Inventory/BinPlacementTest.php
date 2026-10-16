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
use App\Modules\Inventory\Models\WarehouseBin;
use App\Modules\Inventory\Support\PickPlan;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Placing a batch in a bin (C-BIN-1/2/6/7): the floor act that gives the pick
 * list its shelf address. Placement writes `bin_id` and an activity row and
 * nothing else - no journal, no movement, no quantity.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Placement Co', 'slug' => 'placement-co']);
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

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-TST-001', 'name' => 'Test pack', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    $this->batch = StockBatch::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'warehouse_id' => $this->main->id, 'batch_number' => 'B-001',
        'unit_cost' => '100', 'conversion_factor' => '1',
        'initial_quantity' => '5', 'current_quantity' => '5',
        'status' => 'active', 'received_at' => now(),
    ]);

    $this->mainBin = WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->main->id, 'code' => 'A-1',
    ]);
    $this->coldBin = WarehouseBin::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->cold->id, 'code' => 'C-1',
    ]);

    $this->withPermissions = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };
});

it('renders the placement screen for stock adjusters and refuses viewers', function () {
    ($this->withPermissions)(['inventory.stock.adjust'])
        ->get('/inventory/bin-placement')
        ->assertOk();

    ($this->withPermissions)(['inventory.reports.view'])
        ->get('/inventory/bin-placement')
        ->assertForbidden();
});

it('places a batch in a bin of its own warehouse and logs who did it', function () {
    ($this->withPermissions)(['inventory.stock.adjust'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => $this->mainBin->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->batch->refresh()->bin_id)->toBe($this->mainBin->id);

    // C-BIN-7: the placement is the ONLY thing about a batch the activity log
    // carries - quantity history lives in stock movements already.
    $log = Activity::query()
        ->where('subject_type', StockBatch::class)
        ->where('subject_id', $this->batch->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['attributes']['bin_id'])->toBe($this->mainBin->id);
});

it('refuses a bin from another warehouse', function () {
    // C-BIN-2. The batch is in Main; the bin is in Cold Storage.
    ($this->withPermissions)(['inventory.stock.adjust'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => $this->coldBin->id])
        ->assertSessionHasErrors('bin_id');

    expect($this->batch->refresh()->bin_id)->toBeNull();
});

it('refuses a switched-off bin but keeps existing placements readable', function () {
    $this->mainBin->update(['is_active' => false]);

    ($this->withPermissions)(['inventory.stock.adjust'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => $this->mainBin->id])
        ->assertSessionHasErrors('bin_id');

    expect($this->batch->refresh()->bin_id)->toBeNull();
});

it('clears a placement with a null bin', function () {
    $this->batch->update(['bin_id' => $this->mainBin->id]);

    ($this->withPermissions)(['inventory.stock.adjust'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => null])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($this->batch->refresh()->bin_id)->toBeNull();
});

it('refuses placement to someone without stock.adjust', function () {
    ($this->withPermissions)(['inventory.reports.view'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => $this->mainBin->id])
        ->assertForbidden();
});

it('carries the bin code on the pick plan beside the batch it guides to', function () {
    // C-BIN-6: the payoff line - the FIFO batch the ledger will consume and
    // the shelf it sits on, together.
    $this->batch->update(['bin_id' => $this->mainBin->id]);

    $unplaced = StockBatch::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'warehouse_id' => $this->main->id, 'batch_number' => 'B-002',
        'unit_cost' => '100', 'conversion_factor' => '1',
        'initial_quantity' => '5', 'current_quantity' => '5',
        'status' => 'active', 'received_at' => now()->addMinute(),
    ]);

    $plan = PickPlan::for($this->product, $this->main, 8.0);

    expect($plan['batches'])->toHaveCount(2)
        ->and($plan['batches'][0]['batch_number'])->toBe('B-001')
        ->and($plan['batches'][0]['bin'])->toBe('A-1')
        ->and($plan['batches'][1]['batch_number'])->toBe('B-002')
        ->and($plan['batches'][1]['bin'])->toBeNull();
});

// ⚠ Bug hunt 16 Oct 2026 (02 L2): another business's bin passed validation and
// then the scoped lookup gave a bare 404 instead of a sentence.
it('refuses another business bin as a field error', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $foreignWarehouse = Warehouse::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Theirs', 'code' => 'THEIRS']);
    $foreignBin = WarehouseBin::withoutGlobalScopes()->create(['business_id' => $other->id, 'warehouse_id' => $foreignWarehouse->id, 'code' => 'X-1']);

    ($this->withPermissions)(['inventory.stock.adjust'])
        ->patch("/inventory/stock/batches/{$this->batch->id}/bin", ['bin_id' => $foreignBin->id])
        ->assertSessionHasErrors('bin_id');
});
