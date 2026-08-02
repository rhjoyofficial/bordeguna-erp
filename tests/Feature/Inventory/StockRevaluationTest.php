<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A1 - inventory could never be REVALUED.
 *
 * ⚠⚠ Every adjustment had to change a quantity, so there was no way to change
 * what stock is WORTH without changing how much of it there is. That blocked
 * three ordinary accounting events: capitalising a landed cost that arrives on a
 * later invoice, writing stock down to net realisable value, and correcting a
 * mistyped cost. The only remedy available was to write the stock out and back
 * in - fabricating two movements that never physically happened, in the very
 * ledger the whole design exists to protect.
 *
 * A revaluation is a movement with `quantity = 0` and a `value_delta`.
 *
 * ⚠ The zero is what keeps it invisible to the quantity chain for free.
 * StockLedger::balanceForBatch() sums quantities, so slice 4's derivation and
 * all three verification levels keep working untouched.
 *
 * ⚠⚠ And it does NOT branch on adjustment type. The engine asks whether the
 * record moves units or money - a question about the data - so slice 3's
 * structural test still holds. `Revaluation` is a SEVENTH type that the engine
 * never reads, which is the first real test of that claim.
 *
 * See INVENTORY-ACCOUNTING-BOUNDARY-REVIEW.md §9.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Revalue Co', 'slug' => 'revalue-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.stock.adjust', 'inventory.stock.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-REV',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Landed cost',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['name' => 'Inventory Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['inventory.stock.adjust', 'inventory.stock.approve']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole($role);

    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);

    $this->batchOf = function (string $qty, string $cost) {
        $movement = app(RecordStockIn::class)->handle(
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: $qty,
            unitCost: $cost,
            performedBy: $this->manager,
        );

        return StockBatch::find($movement->batch_id);
    };

    $this->revalue = fn (StockBatch $batch, string $newCost) => app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Revaluation,
        direction: AdjustmentDirection::Increase,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: $this->reason,
        createdBy: $this->manager,
        note: 'Freight invoice arrived',
        batch: $batch,
        newUnitCost: $newCost,
    );
});

it('raises the value of a batch without moving a single unit', function () {
    // The landed-cost case: freight arrives on a separate invoice two weeks
    // after the goods, and capitalising it raises the value of stock that has
    // not moved.
    $batch = ($this->batchOf)('10', '100');

    ($this->revalue)($batch, '112');

    $batch->refresh();

    expect((float) $batch->current_quantity)->toBe(10.0)
        ->and((float) $batch->unit_cost)->toBe(112.0);
});

it('records the value change as a movement of zero quantity', function () {
    $batch = ($this->batchOf)('10', '100');

    ($this->revalue)($batch, '112');

    $movement = StockMovement::where('batch_id', $batch->id)
        ->where('quantity', '0.0000')
        ->sole();

    // 10 units x 12 more each. ⚠ STORED, not recomputed: the quantity on hand
    // changes over time, so recomputing this later would silently produce a
    // different number.
    expect((float) $movement->value_delta)->toBe(120.0)
        ->and((float) $movement->unit_cost)->toBe(112.0)
        ->and($movement->direction->value)->toBe('in');
});

it('leaves the quantity chain completely untouched', function () {
    // ⚠⚠ The property the whole design rests on. Slice 4 derives quantities by
    // summing movements; a zero adds nothing, so nothing about the derivation
    // or the three-level verifier had to change.
    $batch = ($this->batchOf)('10', '100');

    expect((float) StockLedger::balanceForBatch($batch))->toBe(10.0);

    ($this->revalue)($batch, '112');

    expect((float) StockLedger::balanceForBatch($batch->fresh()))->toBe(10.0)
        ->and(StockLedger::verify())->toBe([]);
});

it('does not rewrite the cost of stock that already left', function () {
    // ⚠ Historical cost of goods sold must not move. Units that went out at 100
    // went out at 100, whatever the batch is worth afterwards.
    $batch = ($this->batchOf)('10', '100');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '4',
        performedBy: $this->manager,
    );

    ($this->revalue)($batch, '112');

    $earlier = StockMovement::where('direction', 'out')->sole();
    expect((float) $earlier->unit_cost)->toBe(100.0);

    // But the next withdrawal leaves at the revalued cost.
    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '2',
        performedBy: $this->manager,
    );

    $later = StockMovement::where('direction', 'out')->orderByDesc('id')->first();
    expect((float) $later->unit_cost)->toBe(112.0);
});

it('writes stock down as well as up', function () {
    // Net realisable value: worth less than it cost, still on the shelf.
    $batch = ($this->batchOf)('10', '100');

    app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Revaluation,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: $this->reason,
        createdBy: $this->manager,
        batch: $batch,
        newUnitCost: '70',
    );

    $movement = StockMovement::where('quantity', '0.0000')->sole();

    expect((float) $batch->fresh()->unit_cost)->toBe(70.0)
        ->and((float) $movement->value_delta)->toBe(300.0)
        ->and($movement->direction->value)->toBe('out')
        ->and((float) $batch->fresh()->current_quantity)->toBe(10.0);
});

it('refuses a value change that names no batch, because value lives per cost layer', function () {
    ($this->batchOf)('10', '100');

    expect(fn () => app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Revaluation,
        direction: AdjustmentDirection::Increase,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: $this->reason,
        createdBy: $this->manager,
        newUnitCost: '112',
    ))->toThrow(InvalidArgumentException::class);
});

it('still refuses a quantity adjustment of zero', function () {
    // The old guard must survive. A zero-quantity adjustment is only meaningful
    // when it carries a new cost; otherwise it is a request to do nothing.
    ($this->batchOf)('10', '100');

    expect(fn () => app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: $this->reason,
        createdBy: $this->manager,
    ))->toThrow(InvalidArgumentException::class);
});

it('sends a large revaluation for approval like any other correction', function () {
    // ⚠ One pipeline. A revaluation is routed, approved and audited exactly as a
    // write-off is - the engine does not know or care which type it is.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '50']);

    $batch = ($this->batchOf)('10', '100');
    $adjustment = ($this->revalue)($batch, '112');

    expect($adjustment->approvalRequest->isPending())->toBeTrue()
        ->and((float) $adjustment->estimated_value)->toBe(120.0)
        ->and((float) $batch->fresh()->unit_cost)->toBe(100.0);

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->manager);

    expect((float) $batch->fresh()->unit_cost)->toBe(112.0);
});

it('adds a seventh adjustment type without the engine noticing', function () {
    // ⚠⚠ The claim slice 3 made, tested for real: Revaluation was added to the
    // enum and the engine still contains no match on type. If the structural
    // test in OneAdjustmentPipelineTest passes alongside this one, the claim
    // held under an actual new case rather than in principle.
    expect(InventoryAdjustmentType::cases())->toHaveCount(7)
        ->and(InventoryAdjustmentType::Revaluation->value)->toBe('revaluation');
});
