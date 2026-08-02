<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
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
 * I1 - a purchase return used to relieve the WRONG stock.
 *
 * `RecordPurchaseReturn` called `RecordStockOut`, which consumes FIFO. So
 * sending goods back to a supplier ate the OLDEST batch in the warehouse, which
 * is almost never the batch that supplier delivered.
 *
 * ⚠⚠ It costs real money and reports nothing. The supplier credits you at the
 * price on their order while inventory is relieved at a different cost, the
 * batch that actually left still shows full, and no process compares the two.
 *
 * A return is not a withdrawal. It is the REVERSAL OF A SPECIFIC RECEIPT, and
 * the system already knew which one: `goods_receipt_lines.stock_movement_id`.
 * The movement it writes now carries `reverses_movement_id`, so the ledger says
 * what happened rather than merely that something left.
 *
 * See INVENTORY-READINESS-REVIEW.md §4 I1 and §10 (auditability).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Return Co', 'slug' => 'return-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-RET',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

function receivedOrder($test, string $quantity, string $unitCost)
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $test->product, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        createdBy: $test->user,
    );

    $order->approvalRequest->approve($test->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => $quantity],
    ], $test->user);

    return $order->fresh('lines');
}

it('relieves the batch that the supplier actually delivered, not the oldest one', function () {
    // The older, cheaper cost layer. FIFO would eat this one.
    $older = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    // The delivery we are sending back part of, at a different price.
    $order = receivedOrder($this, '10', '120');

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '5'],
    ], $this->user);

    $olderBatch = StockBatch::find($older->batch_id);
    $poBatch = StockBatch::where('unit_cost', '120.0000')->firstOrFail();

    // ⚠ The whole finding in two assertions. Before the fix these were the wrong
    // way round: the January stock left the building and the July stock stayed.
    expect((float) $olderBatch->current_quantity)->toBe(10.0)
        ->and((float) $poBatch->current_quantity)->toBe(5.0);
});

it('records the return at the price it was bought for, and says what it reverses', function () {
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    $order = receivedOrder($this, '10', '120');

    $receiptMovement = StockMovement::where('direction', MovementDirection::In)
        ->where('unit_cost', '120.0000')
        ->sole();

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '5'],
    ], $this->user);

    $returnMovement = StockMovement::where('direction', MovementDirection::Out)->sole();

    // Relieved at 120, the price the supplier is crediting, not at the 100 the
    // oldest layer happened to cost.
    expect((float) $returnMovement->unit_cost)->toBe(120.0)
        ->and((float) $returnMovement->quantity)->toBe(5.0)
        // The auditability rule from §10: the ledger states what this reverses.
        ->and($returnMovement->reverses_movement_id)->toBe($receiptMovement->id);
});

it('spreads a return across that order\'s own receipts when it was delivered in parts', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '120']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '4']], $this->user);
    app(RecordGoodsReceipt::class)->handle($order->fresh(), [['line' => $order->lines->first(), 'quantity' => '6']], $this->user);

    // More than the first shipment holds, so it must continue into the second.
    app(RecordPurchaseReturn::class)->handle($order->fresh(), [
        ['line' => $order->lines->first(), 'quantity' => '7'],
    ], $this->user);

    $batches = StockBatch::orderBy('id')->get();

    expect($batches)->toHaveCount(2)
        ->and((float) $batches[0]->current_quantity)->toBe(0.0)
        ->and((float) $batches[1]->current_quantity)->toBe(3.0);
});

it('refuses to return stock that has already left the warehouse', function () {
    // Received 10 from this supplier, sold 8, now try to send 5 back. Only 2 of
    // that delivery physically remain.
    //
    // ⚠ This follows from C-INV-2 (never go negative, reject instead) and it is
    // a BEHAVIOUR CHANGE worth Joy seeing: the old code would have succeeded
    // quietly by eating somebody else's cost layer. You cannot return goods you
    // no longer have, and pretending otherwise is what produced the silent drift.
    $order = receivedOrder($this, '10', '120');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '8',
        performedBy: $this->user,
    );

    expect(fn () => app(RecordPurchaseReturn::class)->handle($order->fresh(), [
        ['line' => $order->lines->first(), 'quantity' => '5'],
    ], $this->user))->toThrow(InsufficientStockException::class);

    // And nothing moved: the refusal is total, not partial.
    expect((float) $order->lines->first()->fresh()->quantity_returned)->toBe(0.0);
});
