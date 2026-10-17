<?php

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\MissingUnitConversionException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\PermissionRegistrar;

/**
 * Buying in one unit and stocking in another.
 *
 * ⚠⚠ ALL FIGURES HERE ARE TEST DATA. The factor 1.09051254 litres per kilogram
 * is the reciprocal of a GENERAL edible-oil reference (0.917 kg/L) that Joy
 * supplied explicitly as "not our confirmed Bor de Guna factor". It exists here
 * to exercise the arithmetic and NOWHERE in application code, seeders or
 * production data. The real factor is still outstanding.
 *
 * Design note and the twelve approved Corrections: UNIT-CONVERSION-ARCHITECTURE.md.
 */
const TEST_FACTOR = '1.09051254';

beforeEach(function () {
    $this->business = Business::create(['name' => 'Oil Co', 'slug' => 'oil-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);

    $this->oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->litre->id,   // held and sold in litres
        'sku' => 'OIL-MUSTARD',
        'name' => 'Mustard Oil',
        'type' => ProductType::FinishedGood,
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Ad-Deen Food']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

function recordConversion($test, string $factor = TEST_FACTOR, string $from = '2020-01-01'): ProductUnitConversion
{
    // ⚠ Through the CONTROLLED PATH, which is what production will use. Going
    // round it with ::create() would mean the reciprocal guard was never
    // exercised by anything.
    return ProductUnitConversion::record(
        businessId: $test->business->id,
        product: $test->oil,
        fromUnit: $test->kg,
        toUnit: $test->litre,
        stockUnitsPerPurchaseUnit: $factor,
        purchaseUnitsPerStockUnit: number_format(1 / (float) $factor, 8, '.', ''),
        effectiveFrom: $from,
        source: 'PROVISIONAL - general edible oil reference, not yet confirmed by Bor de Guna',
    );
}

function receiveKilograms($test, string $kilograms, string $costPerKilogram)
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $test->oil, 'quantity' => $kilograms, 'unit_cost' => $costPerKilogram]],
        createdBy: $test->user,
    );

    approveIfPending($order->approvalRequest, $test->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => $kilograms],
    ], $test->user);

    return $order->fresh('lines');
}

// ── The arithmetic, in isolation ────────────────────────────────────────────

it('converts a purchase quantity to stock units at four places', function () {
    // 100 x 1.09051254 = 109.051254, and the column holds four places.
    expect(PurchaseUnitConversion::toStockQuantity('100.0000', TEST_FACTOR))->toBe('109.0513');
});

it('derives the stock unit cost from the invoice total, not from the unit cost', function () {
    $total = Money::ofProduct('100.0000', '320.0000');   // 32,000.00

    expect(PurchaseUnitConversion::toStockUnitCost($total, '109.0513'))->toBe('293.4399');
});

it('keeps the derived value within a paisa of the invoice total', function () {
    $total = Money::ofProduct('100.0000', '320.0000');
    $quantity = PurchaseUnitConversion::toStockQuantity('100.0000', TEST_FACTOR);
    $unitCost = PurchaseUnitConversion::toStockUnitCost($total, $quantity);

    // ⚠ C-UOM-7. This CANNOT be exact - unit_cost is decimal(15,4) - and the
    // point of the test is to pin the bound rather than pretend otherwise.
    $rebuilt = Money::ofProduct($quantity, $unitCost);

    expect(abs($rebuilt->minor - $total->minor))->toBeLessThanOrEqual(1);
});

it('declares the purchase unit and the factor together, or not at all', function () {
    // ⚠⚠ `products.purchase_unit_id` is NOT fillable and is absent from the
    // product screen. Setting it alone would put the product into a state where
    // every goods receipt throws for ever (C-UOM-9) with no in-app remedy, so
    // recording the conversion is what declares the second unit.
    expect($this->oil->fresh()->purchase_unit_id)->toBeNull();

    recordConversion($this);

    expect($this->oil->fresh()->purchase_unit_id)->toBe($this->kg->id);
});

it('refuses a conversion that does not convert into the unit the product is stocked in', function () {
    $drum = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Drum', 'abbreviation' => 'drum']);

    expect(fn () => ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $this->oil,
        fromUnit: $this->kg,
        toUnit: $drum,
        stockUnitsPerPurchaseUnit: '1.09051254',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'TEST',
    ))->toThrow(InvalidArgumentException::class, 'stocked in');
});

it('refuses a negative factor even though two negatives multiply to one', function () {
    // ⚠⚠ reciprocalsAgree alone would PASS this pair. A negative factor makes a
    // goods receipt create a batch with negative quantity and post a negative
    // debit to Stock on Hand: a delivery that REDUCES inventory, ledger balanced.
    expect(fn () => ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $this->oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '-1.09051254',
        purchaseUnitsPerStockUnit: '-0.917',
        effectiveFrom: '2020-01-01',
        source: 'TEST',
    ))->toThrow(InvalidArgumentException::class, 'greater than zero');

    expect(ProductUnitConversion::count())->toBe(0);
});

it('refuses to record a conversion whose two directions are not reciprocals', function () {
    // ⚠⚠ The guard is now REACHABLE, not just tested in isolation. Both numbers
    // given as the density is the realistic mistake, and it is 21% wrong.
    expect(fn () => ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $this->oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '0.917',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'TEST - an inverted pair',
    ))->toThrow(InvalidArgumentException::class, 'not reciprocals');

    expect(ProductUnitConversion::count())->toBe(0);
});

it('records a conversion when the two directions do agree', function () {
    $conversion = ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $this->oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '1.09051254',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'TEST',
    );

    expect((string) $conversion->stock_units_per_purchase_unit)->toBe('1.09051254');
});

it('catches an inverted factor, which is the dangerous one', function () {
    // The right way round: 1 kg = 1.0905 L and 1 L = 0.917 kg.
    expect(PurchaseUnitConversion::reciprocalsAgree('1.09051254', '0.917'))->toBeTrue();

    // Inverted: both given as the density. 21% wrong, and every ledger balances.
    expect(PurchaseUnitConversion::reciprocalsAgree('0.917', '0.917'))->toBeFalse();
});

// ── The receipt ─────────────────────────────────────────────────────────────

it('stocks litres from a kilogram purchase and freezes the factor on the batch', function () {
    recordConversion($this);
    receiveKilograms($this, '100', '320');

    $batch = StockBatch::where('product_id', $this->oil->id)->sole();

    expect((string) $batch->initial_quantity)->toBe('109.0513')        // litres
        ->and((string) $batch->current_quantity)->toBe('109.0513')
        ->and((string) $batch->unit_cost)->toBe('293.4399')            // per litre
        ->and((string) $batch->purchase_quantity)->toBe('100.0000')    // kilograms
        ->and($batch->purchase_unit_id)->toBe($this->kg->id)
        ->and((string) $batch->conversion_factor)->toBe('1.09051254');
});

it('leaves the purchase side entirely in kilograms', function () {
    recordConversion($this);
    $order = receiveKilograms($this, '100', '320');

    // ⚠ C-UOM-6. The three-way match is the control that stops the business
    // paying for goods it did not receive. If any leg of it drifted into litres
    // it would be comparing litres against the supplier's kilograms.
    $line = $order->lines->first();

    expect((string) $line->quantity_ordered)->toBe('100.0000')
        ->and((string) $line->quantity_received)->toBe('100.0000')
        // Six places since 17 Oct 2026 (purchase-line totals), still kilograms.
        ->and((string) $line->unit_cost)->toBe('320.000000');
});

it('refuses the receipt when no conversion is in force, rather than assuming one to one', function () {
    // ⚠⚠ C-UOM-9. Defaulting to 1 would put 100 litres in stock for a 100 kg
    // drum and understate cost per litre by about 9%, with the ledger balancing
    // perfectly throughout.
    //
    // ⚠ The product is put into this state DELIBERATELY, bypassing
    // `record()`, because the controlled path can no longer produce it: the
    // purchase unit and the factor are declared together. This is the state a
    // hand-edited row, a restored backup or a future importer could still
    // create, and the engine must refuse it rather than trust that the only way
    // in is the good one.
    $this->oil->purchase_unit_id = $this->kg->id;
    $this->oil->save();

    expect(fn () => receiveKilograms($this, '100', '320'))
        ->toThrow(MissingUnitConversionException::class);

    expect(StockBatch::where('product_id', $this->oil->id)->count())->toBe(0);
});

it('refuses a receipt dated before the factor takes effect', function () {
    // ⚠ Since 16 Oct 2026 record() refuses a FIRST conversion dated in the
    // future (bug hunt), so the older state is written directly here.
    ProductUnitConversion::create([
        'business_id' => $this->business->id, 'product_id' => $this->oil->id,
        'from_unit_id' => $this->kg->id, 'to_unit_id' => $this->litre->id,
        'stock_units_per_purchase_unit' => TEST_FACTOR, 'effective_from' => '2030-01-01', 'source' => 'TEST',
    ]);
    $this->oil->forceFill(['purchase_unit_id' => $this->kg->id])->save();
    $this->oil->refresh();

    expect(fn () => receiveKilograms($this, '100', '320'))
        ->toThrow(MissingUnitConversionException::class);
});

it('does not convert a product bought in the unit it is stocked in', function () {
    $plain = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-LOITTA',
        'name' => 'Loitta Shutki',
        'type' => ProductType::FinishedGood,
    ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $plain, 'quantity' => '50', 'unit_cost' => '2520']],
        createdBy: $this->user,
    );
    approveIfPending($order->approvalRequest, $this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '50'],
    ], $this->user);

    $batch = StockBatch::where('product_id', $plain->id)->sole();

    // ⚠ No conversion row exists for this product and none is needed. The
    // overwhelming majority of the catalogue takes this path unchanged.
    expect((string) $batch->initial_quantity)->toBe('50.0000')
        ->and((string) $batch->unit_cost)->toBe('2520.0000')
        ->and($batch->conversion_factor)->toBeNull()
        ->and($batch->purchase_quantity)->toBeNull();
});

// ── History must not move ───────────────────────────────────────────────────

it('never changes a historical batch when a later factor is introduced', function () {
    recordConversion($this);
    receiveKilograms($this, '100', '320');

    $batch = StockBatch::where('product_id', $this->oil->id)->sole();
    $originalQuantity = (string) $batch->initial_quantity;
    $originalCost = (string) $batch->unit_cost;
    $originalFactor = (string) $batch->conversion_factor;

    // A corrected factor arrives later. C-UOM-10: a NEW effective-dated row.
    ProductUnitConversion::create([
        'business_id' => $this->business->id,
        'product_id' => $this->oil->id,
        'from_unit_id' => $this->kg->id,
        'to_unit_id' => $this->litre->id,
        'stock_units_per_purchase_unit' => '1.20000000',
        'effective_from' => now()->addDay()->toDateString(),
        'source' => 'TEST - a later correction',
    ]);

    $batch->refresh();

    // ⚠⚠ THE REQUIREMENT IN THREE ASSERTIONS. If the batch merely POINTED at a
    // conversion row, all three of these would now be wrong - and the trial
    // balance would still balance, which is why this is a test and not a hope.
    expect((string) $batch->initial_quantity)->toBe($originalQuantity)
        ->and((string) $batch->unit_cost)->toBe($originalCost)
        ->and((string) $batch->conversion_factor)->toBe($originalFactor);
});

it('applies the factor in force on the receipt date, not the newest one', function () {
    recordConversion($this, '1.00000000', '2020-01-01');
    recordConversion($this, '2.00000000', '2099-01-01');

    receiveKilograms($this, '100', '320');

    $batch = StockBatch::where('product_id', $this->oil->id)->sole();

    expect((string) $batch->conversion_factor)->toBe('1.00000000')
        ->and((string) $batch->initial_quantity)->toBe('100.0000');
});

// ── Returns ─────────────────────────────────────────────────────────────────

it('reverses a purchase return in kilograms using the original batch factor', function () {
    recordConversion($this);
    $order = receiveKilograms($this, '100', '320');

    // The supplier's credit note is for 10 KILOGRAMS, not litres.
    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '10'],
    ], $this->user);

    $batch = StockBatch::where('product_id', $this->oil->id)->sole();

    // 10 kg at the batch's own factor is 10.9051 litres.
    // 109.0513 - 10.9051 = 98.1462.
    expect((string) $batch->current_quantity)->toBe('98.1462');

    // ⚠ C-UOM-6 again: the purchase line counts kilograms returned.
    expect((string) $order->fresh('lines')->lines->first()->quantity_returned)->toBe('10.0000');
});

it('uses the ORIGINAL factor on a return even after the factor has changed', function () {
    recordConversion($this);
    $order = receiveKilograms($this, '100', '320');

    ProductUnitConversion::create([
        'business_id' => $this->business->id,
        'product_id' => $this->oil->id,
        'from_unit_id' => $this->kg->id,
        'to_unit_id' => $this->litre->id,
        'stock_units_per_purchase_unit' => '2.00000000',
        'effective_from' => now()->subDay()->toDateString(),
        'source' => 'TEST - a change that must not reach yesterday',
    ]);

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '10'],
    ], $this->user);

    $batch = StockBatch::where('product_id', $this->oil->id)->sole();

    // ⚠⚠ At the NEW factor 10 kg would be 20 litres and the batch would read
    // 89.0513. It reads 98.1462, because the batch converts with the factor it
    // was created with. C-UOM-8.
    expect((string) $batch->current_quantity)->toBe('98.1462');
});
