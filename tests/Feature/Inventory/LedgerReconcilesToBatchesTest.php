<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\PermissionRegistrar;

/**
 * The §11 invariant, and the mechanism behind Joy's auditability rule:
 *
 *   "No stock quantity or cost should ever be edited directly. Everything must
 *    happen through movements, reversals or approved adjustments."
 *
 * ⚠⚠ `stock_batches.current_quantity` is a stored running total AND the current
 * source of truth: the consumable scope reads it, and both dashboards value
 * stock from it. `StockMovement` records what happened but nothing reads it back
 * to establish what is on hand. So the ledger is presently a narration of the
 * truth rather than the truth, and if the two ever disagree the batch silently
 * wins.
 *
 * Every finding in the review is a way for them to disagree: a missing lock, a
 * return relieving the wrong layer, arithmetic residue.
 *
 * This test is the net. For every batch, the movements that touch it must add up
 * to exactly what the batch says it holds. Any future code that adjusts a
 * quantity without writing a movement to explain it fails here, by name.
 *
 * ℹ Deriving stock on hand FROM the ledger, rather than reconciling to it, is
 * proposed as part of the Stock Count architecture - a physical count is exactly
 * a reconciliation between the ledger and reality, so they are one question.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ledger Co', 'slug' => 'ledger-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-LEDGER',
        'name' => 'Loose seed',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

/**
 * Every batch must equal its own movements. Returns the offenders by id so a
 * failure names the row rather than merely asserting that something is wrong.
 */
function batchesDisagreeingWithLedger(): array
{
    $offenders = [];

    foreach (StockBatch::all() as $batch) {
        $in = (float) StockMovement::where('batch_id', $batch->id)
            ->where('direction', MovementDirection::In)->sum('quantity');
        $out = (float) StockMovement::where('batch_id', $batch->id)
            ->where('direction', MovementDirection::Out)->sum('quantity');

        $expected = number_format($in - $out, 4, '.', '');
        $actual = number_format((float) $batch->current_quantity, 4, '.', '');

        if ($expected !== $actual) {
            $offenders[] = "batch #{$batch->id}: ledger says {$expected}, batch says {$actual}";
        }
    }

    return $offenders;
}

it('reconciles every batch to its own movements across a full trading day', function () {
    // A receipt against a purchase order.
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10.5', 'unit_cost' => '120']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '10.5'],
    ], $this->user);

    // A second, cheaper layer arriving directly.
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '4.25',
        unitCost: '95',
        performedBy: $this->user,
    );

    // A supplier return, which reverses a specific receipt. ⚠ Done BEFORE the
    // heavy consumption below, on purpose: a first draft returned last and the
    // I1 fix correctly refused it, because by then the delivery had already been
    // sold. That refusal was the code being right and the scenario being
    // impossible, which is worth more than a test that passes.
    app(RecordPurchaseReturn::class)->handle($order->fresh(), [
        ['line' => $order->lines->first(), 'quantity' => '2'],
    ], $this->user);

    // Ordinary consumption crossing a batch boundary.
    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '12',
        performedBy: $this->user,
    );

    // An adjustment down, with a reason, the way a manager would record damage.
    $reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Damaged',
        'is_active' => true,
    ]);

    // ⚠ ApplyStockAdjustment was deleted with C-FLOW-4c. This test is about one
    // invariant only - that every batch reconciles to its own movements - so it
    // writes the movement through Inventory's primitive directly rather than
    // standing up an approval workflow it does not exercise. What it needs is a
    // decrease on the day, tagged with a reason, which is exactly what the
    // deleted action produced.
    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0.75',
        performedBy: $this->user,
        reason: MovementReason::Adjustment,
        reasonId: $reason->id,
    );

    expect(batchesDisagreeingWithLedger())->toBe([]);

    // Sanity: the day actually moved stock, so an empty ledger cannot be what
    // made the invariant hold.
    expect(StockMovement::count())->toBeGreaterThan(4);
});

it('catches a quantity edited behind the ledger\'s back', function () {
    // ⚠ Proof that the invariant can FAIL. A guard nobody has seen fail is a
    // guard nobody should trust - the same reasoning as reintroducing the W1
    // defect to prove its structural test worked.
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    expect(batchesDisagreeingWithLedger())->toBe([]);

    // Exactly what Joy's rule forbids: a stock quantity changed with no movement
    // to explain it.
    $batch = StockBatch::firstOrFail();
    $batch->current_quantity = '3.0000';
    $batch->save();

    expect(batchesDisagreeingWithLedger())
        ->toHaveCount(1)
        ->and(batchesDisagreeingWithLedger()[0])->toContain('ledger says 10.0000, batch says 3.0000');
});
