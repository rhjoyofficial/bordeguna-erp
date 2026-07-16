<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApplyStockAdjustment;
use App\Modules\Inventory\Actions\ApplyStockTransfer;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;

function createBusinessContext(): Business
{
    $business = Business::create(['name' => 'Test Co', 'slug' => 'test-co-'.uniqid()]);
    app(BusinessContext::class)->set($business->id);

    return $business;
}

function createProduct(Business $business, ProductType $type = ProductType::RawMaterial): Product
{
    $unit = UnitOfMeasure::firstOrCreate(
        ['business_id' => $business->id, 'abbreviation' => 'kg'],
        ['name' => 'Kilogram']
    );

    return Product::create([
        'business_id' => $business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-'.uniqid(),
        'name' => 'Test Product',
        'type' => $type,
    ]);
}

function createWarehouse(Business $business, string $code = 'MAIN'): Warehouse
{
    return Warehouse::create(['business_id' => $business->id, 'name' => 'Warehouse '.$code, 'code' => $code]);
}

beforeEach(function () {
    $this->business = createBusinessContext();
    $this->user = User::factory()->create();
    $this->product = createProduct($this->business);
    $this->warehouse = createWarehouse($this->business);
});

it('records stock in by creating a batch and an in movement', function () {
    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '50',
        unitCost: '10',
        performedBy: $this->user,
    );

    expect((string) $movement->quantity)->toBe('50.0000');
    expect($this->product->currentStockQuantity())->toBe('50');
    expect(StockBatch::count())->toBe(1);
});

it('rejects stocking a service-type product', function () {
    $service = createProduct($this->business, ProductType::Service);

    app(RecordStockIn::class)->handle(
        product: $service,
        warehouse: $this->warehouse,
        quantity: '1',
        unitCost: '1',
        performedBy: $this->user,
    );
})->throws(DomainException::class);

it('consumes stock FIFO across multiple batches by expiry date', function () {
    $recordIn = app(RecordStockIn::class);

    // Batch B expires sooner than batch A despite being created second —
    // FIFO must consume B first regardless of creation order.
    $recordIn->handle($this->product, $this->warehouse, '20', '10', $this->user, expiryDate: '2027-01-01'); // batch A
    $recordIn->handle($this->product, $this->warehouse, '30', '12', $this->user, expiryDate: '2026-06-01'); // batch B

    // Requesting more than batch B alone holds forces consumption to spill into batch A.
    $movements = app(RecordStockOut::class)->handle($this->product, $this->warehouse, '35', $this->user);

    expect($movements)->toHaveCount(2);
    $first = $movements->first();
    expect((string) $first->unit_cost)->toBe('12.0000'); // batch B (earlier-expiring) consumed first
    expect((string) $first->quantity)->toBe('30.0000'); // batch B fully drained
    expect((string) $movements->last()->quantity)->toBe('5.0000'); // remainder from batch A
    expect($this->product->currentStockQuantity())->toBe('15');
});

it('throws when consuming more stock than is available, without partial consumption', function () {
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '5', $this->user);

    try {
        app(RecordStockOut::class)->handle($this->product, $this->warehouse, '999', $this->user);
    } catch (InsufficientStockException) {
        // expected
    }

    // Nothing should have been consumed — the whole operation rolled back.
    expect($this->product->currentStockQuantity())->toBe('10');
});

it('applies a direct increase adjustment as a new batch', function () {
    app(ApplyStockAdjustment::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        direction: AdjustmentDirection::Increase,
        quantity: '15',
        performedBy: $this->user,
        note: 'stock count correction',
        unitCost: '8',
    );

    expect($this->product->currentStockQuantity())->toBe('15');
});

it('applies a direct decrease adjustment by consuming stock', function () {
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '20', '10', $this->user);

    app(ApplyStockAdjustment::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        direction: AdjustmentDirection::Decrease,
        quantity: '5',
        performedBy: $this->user,
        note: 'damaged goods',
    );

    expect($this->product->currentStockQuantity())->toBe('15');
});

it('transfers stock between warehouses, pairing both legs with the same transfer_group_id', function () {
    $target = createWarehouse($this->business, 'SECOND');
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '40', '10', $this->user);

    $movements = app(ApplyStockTransfer::class)->handle(
        product: $this->product,
        source: $this->warehouse,
        target: $target,
        quantity: '15',
        performedBy: $this->user,
    );

    expect($movements)->toHaveCount(2);
    expect($movements->pluck('transfer_group_id')->unique())->toHaveCount(1);

    $sourceQty = StockBatch::where('warehouse_id', $this->warehouse->id)->sum('current_quantity');
    $targetQty = StockBatch::where('warehouse_id', $target->id)->sum('current_quantity');

    expect((string) $sourceQty)->toBe('25');
    expect((string) $targetQty)->toBe('15');
});
