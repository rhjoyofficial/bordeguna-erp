<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\PermissionRegistrar;

/**
 * I3 - receiving had a lost-update race.
 *
 * `RecordGoodsReceipt` read `quantityRemaining()`, checked it, then wrote
 * `quantity_received = current + qty`, with no lock and no re-read. Two requests
 * arriving together both saw the same starting value, both passed the remaining
 * check, both created stock, and the second write overwrote the first.
 *
 * ⚠⚠ The realistic trigger is NOT two warehouse clerks. It is one clerk
 * DOUBLE-CLICKING Receive, which is the most common way this class of bug
 * reaches production.
 *
 * ⚠ How these tests reproduce it without real concurrency: two separate model
 * instances of the same row, each loaded BEFORE the other wrote. That is exactly
 * the state two simultaneous requests are in, and it is deterministic. A test
 * that spawned real threads would be slower, flakier and prove less.
 *
 * ⚠ `lockForUpdate()` is a no-op on SQLite, so the lock alone would not make
 * these pass. The fix has to RE-READ the line inside the transaction and trust
 * that instead of the caller's copy - which is what makes it correct on both
 * engines rather than only on MySQL.
 *
 * See INVENTORY-READINESS-REVIEW.md §4 I3.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Race Co', 'slug' => 'race-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-RACE',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

function racedOrder($test, string $quantity = '10', string $unitCost = '15')
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $test->product, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        createdBy: $test->user,
    );

    approveIfPending($order->approvalRequest, $test->user);

    return $order->fresh(['lines', 'approvalRequest']);
}

it('does not let a double-clicked receipt take more than the order allows', function () {
    // ⚠ Deliberately PARTIAL quantities (6 + 6 against an order of 10). A first
    // draft of this test used two full receipts and passed for the wrong reason:
    // a full receipt flips the order to Received, so `isReceivable()` refused the
    // second click before the race could happen. The status guard masks the
    // defect in exactly one case and leaves it wide open in every other.
    $order = racedOrder($this, '10');

    // Two requests, each holding its own copy of the line, loaded before either
    // has written. This is a double click.
    $lineA = PurchaseOrderLine::find($order->lines->first()->id);
    $lineB = PurchaseOrderLine::find($order->lines->first()->id);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $lineA, 'quantity' => '6'],
    ], $this->user);

    // The second click. Before the fix this checked 6 against a stale remaining
    // of 10, passed, and created a second batch - so 12 units of stock existed
    // against an order for 10.
    expect(fn () => app(RecordGoodsReceipt::class)->handle($order->fresh(), [
        ['line' => $lineB, 'quantity' => '6'],
    ], $this->user))->toThrow(InvalidArgumentException::class);

    expect((float) $order->lines->first()->fresh()->quantity_received)->toBe(6.0);

    // ⚠ The half that actually costs money: stock must not have been created
    // twice. The order recording 6 while the warehouse holds 12 is the
    // permanent, silent disagreement this whole finding is about.
    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');
    expect((float) $onHand)->toBe(6.0);
});

it('adds up two legitimate partial receipts instead of losing one', function () {
    $order = racedOrder($this, '10');

    $lineA = PurchaseOrderLine::find($order->lines->first()->id);
    $lineB = PurchaseOrderLine::find($order->lines->first()->id);

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $lineA, 'quantity' => '4']], $this->user);
    app(RecordGoodsReceipt::class)->handle($order->fresh(), [['line' => $lineB, 'quantity' => '3']], $this->user);

    // Before the fix the second call wrote 3, not 7, because it added to the
    // stale 0 it was holding. The lost update is the defect; the over-receipt
    // above is only its most visible symptom.
    expect((float) $order->lines->first()->fresh()->quantity_received)->toBe(7.0);

    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');
    expect((float) $onHand)->toBe(7.0);
});

it('accumulates two returns on the same line instead of losing one', function () {
    // ⚠ Asserted on the resulting STATE rather than on an exception type. The
    // first draft expected the second return to be refused, and it was - but by
    // InsufficientStockException, because the stock had already gone. That would
    // have been a test passing on an unrelated guard while `quantity_returned`
    // stayed silently wrong.
    $order = racedOrder($this, '10');

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '10'],
    ], $this->user);

    $lineA = PurchaseOrderLine::find($order->lines->first()->id);
    $lineB = PurchaseOrderLine::find($order->lines->first()->id);

    app(RecordPurchaseReturn::class)->handle($order->fresh(), [['line' => $lineA, 'quantity' => '4']], $this->user);
    app(RecordPurchaseReturn::class)->handle($order->fresh(), [['line' => $lineB, 'quantity' => '4']], $this->user);

    // Before the fix the second call added to the stale 0 it was holding and
    // wrote 4, so the order claimed 4 returned while 8 had physically gone back.
    expect((float) $order->lines->first()->fresh()->quantity_returned)->toBe(8.0);

    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');
    expect((float) $onHand)->toBe(2.0);
});
