<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\PeriodLockedException;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryPeriodLock;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 3: ONE inventory adjustment workflow.
 *
 * ⚠⚠ Before this, there were TWO ways to change stock and they did not agree.
 * A user holding `inventory.stock.adjust` called `ApplyStockAdjustment`
 * directly: stock changed, movements were written, and nothing recorded that a
 * decision had been made, by whom, or when it applied. A user without it went
 * through `StockRequest` and got a record and an approval. Same outcome,
 * different evidence.
 *
 * Joy's rule: "Everything that changes inventory quantity or value must go
 * through the same movement ledger and approval pipeline. I never want multiple
 * code paths that can modify inventory independently."
 *
 * So every correction is now a row, always, including auto-approved ones, and
 * the approval flow, audit trail and movement generation are identical
 * regardless of type.
 *
 * See INVENTORY-ADJUSTMENT-WORKFLOW-ARCHITECTURE.md.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Adjust Co', 'slug' => 'adjust-co']);
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
        'sku' => 'SKU-ADJ',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Damaged in handling',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(['name' => 'Inventory Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $managerRole->givePermissionTo('inventory.stock.adjust');

    $founderRole = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $founderRole->givePermissionTo(['inventory.stock.adjust', 'inventory.stock.approve']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole($managerRole);

    $this->founder = User::factory()->create();
    $this->founder->businesses()->attach($this->business->id);
    $this->founder->assignRole($founderRole);

    Carbon::setTestNow('2026-08-02 09:00:00');

    $this->stockIn = fn (string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->manager,
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeAdjustment($test, array $overrides = []): InventoryAdjustment
{
    return app(CreateInventoryAdjustment::class)->handle(...array_merge([
        'type' => InventoryAdjustmentType::Damaged,
        'direction' => AdjustmentDirection::Decrease,
        'product' => $test->product,
        'warehouse' => $test->warehouse,
        'quantity' => '2',
        'reason' => $test->reason,
        'createdBy' => $test->manager,
        'note' => 'Two crushed in the van',
    ], $overrides));
}

it('records every correction as a row, even one that approves itself', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);
    ($this->stockIn)('10', '100');

    $adjustment = makeAdjustment($this);

    // ⚠ The heart of it. The old direct path changed stock and left no record
    // that a decision was ever made.
    expect(InventoryAdjustment::count())->toBe(1)
        ->and($adjustment->approvalRequest->isComplete())->toBeTrue()
        ->and($adjustment->type)->toBe(InventoryAdjustmentType::Damaged)
        ->and($adjustment->note)->toBe('Two crushed in the van')
        ->and($adjustment->reason_id)->toBe($this->reason->id);

    expect((float) StockBatch::sum('current_quantity'))->toBe(8.0);
});

it('requires approval by default, because no threshold means nobody self-approves', function () {
    // ⚠ Safe by default. An unset threshold must not read as "unlimited", which
    // is how a constant somebody guessed too high lets a write-off through.
    ($this->stockIn)('10', '100');

    $adjustment = makeAdjustment($this);

    expect($adjustment->approvalRequest->isPending())->toBeTrue()
        ->and((float) StockBatch::sum('current_quantity'))->toBe(10.0);
});

it('sends a high-value correction to the Founder instead of approving it', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '100']);
    ($this->stockIn)('10', '100');

    // 2 x 100 = 200, over the 100 threshold.
    $adjustment = makeAdjustment($this);

    expect($adjustment->approvalRequest->isPending())->toBeTrue()
        ->and((float) $adjustment->estimated_value)->toBe(200.0)
        ->and((float) StockBatch::sum('current_quantity'))->toBe(10.0);

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->founder);

    expect($adjustment->fresh()->approvalRequest->isComplete())->toBeTrue()
        ->and((float) StockBatch::sum('current_quantity'))->toBe(8.0);
});

it('posts movements dated when the correction applies, not when it was approved', function () {
    // ⚠ The point of slice 1. A count taken on the 30th and approved on the 2nd
    // belongs to the 30th, or every month-end lands in the following month.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);

    // ⚠ Received BEFORE the correction applies. A first draft received the stock
    // "now" and dated the damage three days earlier, and slice 1's guard
    // correctly refused it: you cannot damage goods that had not arrived. The
    // code was right and the scenario was impossible.
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->manager,
        occurredAt: '2026-07-01 09:00:00',
    );

    makeAdjustment($this, ['effectiveDate' => '2026-07-30 17:00:00']);

    $movement = StockMovement::where('direction', 'out')->sole();

    expect($movement->occurred_at->toDateTimeString())->toBe('2026-07-30 17:00:00')
        ->and($movement->created_at->toDateTimeString())->toBe('2026-08-02 09:00:00');
});

it('links every movement it produced, not just the first', function () {
    // ⚠ The lossy audit trail this replaces: ApproveStockRequest recorded
    // `$movements->first()?->id`, so a decrease spanning three cost layers wrote
    // three movements and the record remembered one.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);
    ($this->stockIn)('2', '100');
    ($this->stockIn)('2', '150');
    ($this->stockIn)('2', '200');

    $adjustment = makeAdjustment($this, ['quantity' => '5']);

    expect($adjustment->movements()->count())->toBe(3)
        ->and(StockMovement::whereNull('adjustment_id')->where('direction', 'out')->count())->toBe(0);
});

it('re-checks the period lock at approval, not only at creation', function () {
    // ⚠⚠ A month can close while an adjustment sits in the queue. Checking only
    // at creation leaves a hole exactly as wide as the approval queue is slow.
    ($this->stockIn)('10', '100');

    $adjustment = makeAdjustment($this, ['effectiveDate' => '2026-07-30 17:00:00']);
    expect($adjustment->approvalRequest->isPending())->toBeTrue();

    InventoryPeriodLock::closeThrough(Carbon::parse('2026-07-31'), $this->founder, 'July close');

    expect(fn () => app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->founder))
        ->toThrow(PeriodLockedException::class);

    expect((float) StockBatch::sum('current_quantity'))->toBe(10.0);
});

it('increases stock at the cost the approver settled on', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);

    $adjustment = makeAdjustment($this, [
        'type' => InventoryAdjustmentType::StockCountVariance,
        'direction' => AdjustmentDirection::Increase,
        'quantity' => '3',
        'unitCost' => '125',
    ]);

    expect($adjustment->approvalRequest->isComplete())->toBeTrue();

    $batch = StockBatch::sole();
    expect((float) $batch->current_quantity)->toBe(3.0)
        ->and((float) $batch->unit_cost)->toBe(125.0);
});

it('refuses an increase with no cost, because value cannot be invented', function () {
    expect(fn () => makeAdjustment($this, [
        'direction' => AdjustmentDirection::Increase,
        'quantity' => '3',
    ]))->toThrow(InvalidArgumentException::class);
});

it('treats all six types identically, changing only what is recorded', function () {
    // ⚠⚠ Joy's requirement: movement generation is identical regardless of type.
    // Six adjustments, six types, one direction, one quantity - the stock effect
    // and the movement shape must not vary.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);
    ($this->stockIn)('60', '100');

    foreach (InventoryAdjustmentType::cases() as $type) {
        makeAdjustment($this, ['type' => $type, 'quantity' => '1']);
    }

    $types = InventoryAdjustment::pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all();
    $expected = collect(InventoryAdjustmentType::cases())->map(fn ($t) => $t->value)->sort()->values()->all();

    expect($types)->toBe($expected)
        ->and(StockMovement::where('direction', 'out')->count())->toBe(count($expected))
        ->and((float) StockBatch::sum('current_quantity'))->toBe(60.0 - count($expected));
});

it('keeps the decision in the activity log', function () {
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '10000']);
    ($this->stockIn)('10', '100');

    $adjustment = makeAdjustment($this);

    expect(Activity::where('subject_type', InventoryAdjustment::class)
        ->where('subject_id', $adjustment->id)
        ->exists())->toBeTrue();
});
