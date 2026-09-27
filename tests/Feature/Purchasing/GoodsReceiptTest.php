<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Receiving Co', 'slug' => 'receiving-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->stockedProduct = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-STOCK',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->serviceProduct = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-SERVICE',
        'name' => 'Installation service',
        'type' => ProductType::Service,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
});

function approvedPurchaseOrder(Business $business, Supplier $supplier, Warehouse $warehouse, User $createdBy, array $lines): PurchaseOrder
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $supplier,
        warehouse: $warehouse,
        lines: $lines,
        createdBy: $createdBy,
    );

    approveIfPending($order->approvalRequest, $createdBy);

    return $order->fresh(['lines', 'approvalRequest']);
}

it('moves stock into Inventory via RecordStockIn when a stocked line is fully received', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15'],
    ]);
    $line = $order->lines->first();

    $receipt = app(RecordGoodsReceipt::class)->handle(
        order: $order,
        lineReceipts: [['line' => $line, 'quantity' => '20']],
        receivedBy: $this->manager,
    );

    expect($receipt->lines)->toHaveCount(1);
    expect($receipt->lines->first()->stock_movement_id)->not->toBeNull();
    expect($this->stockedProduct->fresh()->currentStockQuantity())->toBe('20');

    $batch = StockBatch::where('product_id', $this->stockedProduct->id)->where('warehouse_id', $this->warehouse->id)->sole();
    expect((string) $batch->unit_cost)->toBe('15.0000');

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('supports receiving a line across two partial shipments', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15'],
    ]);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '8']], $this->manager);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
    expect($this->stockedProduct->fresh()->currentStockQuantity())->toBe('8');

    app(RecordGoodsReceipt::class)->handle($order->fresh(), [['line' => $line->fresh(), 'quantity' => '12']], $this->manager);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    expect($this->stockedProduct->fresh()->currentStockQuantity())->toBe('20');
});

it('refuses to receive more than the remaining quantity on a line', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15'],
    ]);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '25']], $this->manager);
})->throws(InvalidArgumentException::class);

it('refuses to receive against a purchase order that is still pending approval', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15']],
        createdBy: $this->manager,
    );
    // Force it back to pending — simulates a Staff-created order nobody approved yet.
    $order->approvalRequest->update(['status' => ApprovalStatus::Pending]);

    app(RecordGoodsReceipt::class)->handle($order->fresh(['lines', 'approvalRequest']), [
        ['line' => $order->lines->first(), 'quantity' => '20'],
    ], $this->manager);
})->throws(RuntimeException::class);

it('refuses to receive against a cancelled purchase order', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15'],
    ]);
    $order->update(['status' => PurchaseOrderStatus::Cancelled]);

    app(RecordGoodsReceipt::class)->handle($order->fresh(['lines', 'approvalRequest']), [
        ['line' => $order->lines->first(), 'quantity' => '20'],
    ], $this->manager);
})->throws(RuntimeException::class);

it('marks a service line received without creating any stock movement', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->serviceProduct, 'quantity' => '1', 'unit_cost' => '500'],
    ]);
    $line = $order->lines->first();

    $receipt = app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '1']], $this->manager);

    expect($receipt->lines->first()->stock_movement_id)->toBeNull();
    expect(StockBatch::count())->toBe(0);
    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});

// ─── A PRODUCT THAT TRACKS EXPIRY (Joy, 26 Sep 2026, Q8 and Q9) ─────────────

it('refuses to receive a product that tracks expiry without its batch and expiry, and takes it with them', function () {
    $this->stockedProduct->update(['tracks_expiry' => true]);
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '20', 'unit_cost' => '15'],
    ]);
    $line = $order->lines->first();

    expect(fn () => app(RecordGoodsReceipt::class)->handle(order: $order, lineReceipts: [['line' => $line, 'quantity' => '20']], receivedBy: $this->manager))
        ->toThrow(InvalidArgumentException::class, 'tracks expiry');
    expect(StockBatch::where('product_id', $this->stockedProduct->id)->count())->toBe(0);

    $receipt = app(RecordGoodsReceipt::class)->handle(
        order: $order->fresh(['lines']),
        lineReceipts: [['line' => $line->fresh(), 'quantity' => '20', 'batch_number' => 'B-7', 'expiry_date' => now()->addYear()->toDateString()]],
        receivedBy: $this->manager,
    );

    expect($receipt->lines)->toHaveCount(1)
        ->and(StockBatch::where('product_id', $this->stockedProduct->id)->sole()->batch_number)->toBe('B-7');
});

it('still takes a product that does not track expiry with no batch or date at all', function () {
    $order = approvedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, [
        ['product' => $this->stockedProduct, 'quantity' => '5', 'unit_cost' => '15'],
    ]);

    $receipt = app(RecordGoodsReceipt::class)->handle(order: $order, lineReceipts: [['line' => $order->lines->first(), 'quantity' => '5']], receivedBy: $this->manager);

    expect($receipt->lines)->toHaveCount(1);
});

it('warns, and never refuses, when a tracked batch has less shelf life than the product wants', function () {
    expect(ReceivedBatchDates::shelfLifeWarning(now()->addDays(30)->toDateString(), 90))->toContain('30 day(s)', 'below 90')
        ->and(ReceivedBatchDates::shelfLifeWarning(now()->addDays(120)->toDateString(), 90))->toBeNull()
        ->and(ReceivedBatchDates::shelfLifeWarning(null, 90))->toBeNull()
        ->and($this->stockedProduct->shelfLifeWarningDays())->toBe(90);

    $this->stockedProduct->update(['shelf_life_warning_days' => 10]);
    expect($this->stockedProduct->fresh()->shelfLifeWarningDays())->toBe(10)
        ->and(ReceivedBatchDates::shelfLifeWarning(now()->addDays(30)->toDateString(), 10))->toBeNull();
});
