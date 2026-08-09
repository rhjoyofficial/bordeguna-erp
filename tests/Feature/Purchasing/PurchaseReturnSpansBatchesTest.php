<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnLine;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * ⚠⚠ A RETURN THAT SPANS TWO DELIVERIES USED TO BE VALUED AS ONE.
 *
 * `ReverseStockReceipt` empties the line's receipts oldest-first, so a return
 * bigger than the first delivery touches two batches at two different unit
 * costs. `RecordPurchaseReturn` then wrote ONE `purchase_return_lines` row and
 * stored `$movements->first()?->id` on it - the first batch's movement, and
 * nothing at all for the rest.
 *
 * `PostPurchaseJournals::forReturn` values the credit from that column, so the
 * ledger credited Stock on Hand with the FIRST batch's value while BOTH batches
 * had physically emptied. The stock sub-ledger and the general ledger then
 * disagreed for ever, nothing compared them, and every journal balanced
 * throughout.
 *
 * ▶ Found by the code review of 2026-08-09. Joy: an accounting and inventory
 * correctness issue, to be fixed before deployment rather than deferred.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    $this->business = Business::create(['name' => 'Split Co', 'slug' => 'split-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Ad-Deen Food', 'code' => 'ADDEEN']);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

/** An order received in TWO deliveries, so the line owns two cost layers. */
function orderReceivedTwice($test, Product $product, string $first, string $second, string $unitCost)
{
    $total = (string) ((float) $first + (float) $second);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $product, 'quantity' => $total, 'unit_cost' => $unitCost]],
        createdBy: $test->user,
    );
    $order->approvalRequest->approve($test->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => $first]], $test->user);
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => $second]], $test->user);

    return $order->fresh('lines');
}

// ── The plain case: no conversion, two batches ──────────────────────────────

it('records one return line per batch when a return spans two deliveries', function () {
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-LOITTA',
        'name' => 'Loitta Shutki',
        'type' => ProductType::FinishedGood,
    ]);

    $order = orderReceivedTwice($this, $jars, '40', '60', '25.00');

    // 70 is more than the first delivery of 40, so it must eat into the second.
    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user);

    $lines = PurchaseReturnLine::orderBy('id')->get();

    // ⚠⚠ THE DEFECT IN ONE ASSERTION. This was 1 before the fix.
    expect($lines)->toHaveCount(2);

    // Each row names its own movement, and none is null.
    expect($lines->pluck('stock_movement_id')->filter()->unique())->toHaveCount(2);

    // The shares still add up to what was returned.
    expect($lines->sum(fn ($l) => (float) $l->quantity_returned))->toBe(70.0)
        ->and((float) $order->fresh('lines')->lines->first()->quantity_returned)->toBe(70.0);
});

it('empties both batches physically, and by the right amounts', function () {
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-CHURI',
        'name' => 'Churi Shutki',
        'type' => ProductType::FinishedGood,
    ]);

    $order = orderReceivedTwice($this, $jars, '40', '60', '25.00');

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user);

    $batches = StockBatch::where('product_id', $jars->id)->orderBy('id')->get();

    // First delivery gone entirely, 30 taken from the second.
    expect((string) $batches[0]->current_quantity)->toBe('0.0000')
        ->and((string) $batches[1]->current_quantity)->toBe('30.0000');

    $out = StockMovement::where('product_id', $jars->id)
        ->where('direction', MovementDirection::Out)->get();

    expect($out)->toHaveCount(2)
        ->and($out->sum(fn ($m) => (float) $m->quantity))->toBe(70.0);
});

it('credits Stock on Hand for BOTH batches, not just the first', function () {
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-FAISA',
        'name' => 'Modhu Faisa Chapa',
        'type' => ProductType::FinishedGood,
    ]);

    // ⚠ Deliberately received at TWO DIFFERENT COSTS, so valuing one batch
    // instead of two is not merely incomplete, it is a different number.
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $jars, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '40']], $this->user);
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '60']], $this->user);

    $stockAfterReceipt = ($this->balanceOn)('stock_on_hand');
    expect($stockAfterReceipt)->toBe(250000);   // 100 x 25.00

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user);

    // ⚠⚠ 70 x 25.00 = 1,750.00 must leave stock. Before the fix only the first
    // batch's 40 x 25.00 = 1,000.00 did, and 750.00 of stock stayed on the
    // balance sheet for goods that had physically gone back to the supplier.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(250000 - 175000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-(250000 - 175000));

    Journal::all()->each(fn (Journal $j) => expect($j->balance()->isZero())->toBeTrue());
});

it('leaves the sub-ledger and the general ledger agreeing after the return', function () {
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-KACHKI',
        'name' => 'Mourala Kachki',
        'type' => ProductType::FinishedGood,
    ]);

    $order = orderReceivedTwice($this, $jars, '40', '60', '25.00');

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user);

    // ⚠⚠ THE CHECK THAT WOULD HAVE CAUGHT THIS ORIGINALLY: what the warehouse
    // says it holds, valued at cost, must equal what the ledger says stock is
    // worth. Nothing in the application compares these two, which is exactly why
    // the divergence could have run for months unnoticed.
    $onHandValue = StockBatch::where('product_id', $jars->id)->get()
        ->sum(fn (StockBatch $b) => (float) $b->current_quantity * (float) $b->unit_cost);

    expect((int) round($onHandValue * 100))->toBe(($this->balanceOn)('stock_on_hand'));
});

// ── The converted case: two batches AND a unit conversion ───────────────────

it('spans two batches correctly for a product bought in kilograms and stocked in litres', function () {
    $oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->litre->id,
        'sku' => 'OIL-MUSTARD',
        'name' => 'Mustard Oil',
        'type' => ProductType::FinishedGood,
    ]);

    // ⚠ TEST DATA. Not the confirmed Bor De Guna factor - see the architecture note.
    ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '1.09051254',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'PROVISIONAL - general reference, not yet confirmed',
    );
    $oil->refresh();

    $order = orderReceivedTwice($this, $oil, '40', '60', '320.00');

    // 70 KILOGRAMS back, spanning both deliveries.
    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user);

    $lines = PurchaseReturnLine::orderBy('id')->get();

    expect($lines)->toHaveCount(2)
        // ⚠ The shares are in KILOGRAMS, the unit the supplier credits, and they
        // still sum to the 70 that was returned.
        ->and(round($lines->sum(fn ($l) => (float) $l->quantity_returned), 4))->toBe(70.0);

    $batches = StockBatch::where('product_id', $oil->id)->orderBy('id')->get();

    // 40 kg became 43.6205 L and all of it went back; 30 of the second delivery's
    // 60 kg went back as 32.7154 L, leaving 65.4307 - 32.7154.
    expect((string) $batches[0]->current_quantity)->toBe('0.0000')
        ->and((float) $batches[1]->current_quantity)->toBeGreaterThan(0);

    // ⚠⚠ And the two ledgers still agree, which is the point of the whole fix.
    $onHandValue = StockBatch::where('product_id', $oil->id)->get()
        ->sum(fn (StockBatch $b) => (float) $b->current_quantity * (float) $b->unit_cost);

    expect(abs((int) round($onHandValue * 100) - ($this->balanceOn)('stock_on_hand')))->toBeLessThanOrEqual(2);

    Journal::all()->each(fn (Journal $j) => expect($j->balance()->isZero())->toBeTrue());
});

it('still records a single line when the return fits inside one batch', function () {
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-SHRIMP',
        'name' => 'Shrimp Dry Fish',
        'type' => ProductType::FinishedGood,
    ]);

    $order = orderReceivedTwice($this, $jars, '40', '60', '25.00');

    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '10'],
    ], $this->user);

    // ⚠ The ordinary case must not have grown rows. One batch touched, one row.
    expect(PurchaseReturnLine::count())->toBe(1)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(250000 - 25000);
});

/**
 * ⚠⚠ FOUND BY THE ROUND-2 CODE REVIEW, AFTER DEPLOYMENT.
 *
 * `ReverseStockReceipt` guards a rounding residue with
 * `if ($remaining > 0 && $remaining < 1)`. `$remaining` is an INT - it is seeded
 * from `scaled()` and every arm of the match yields an int - so that condition is
 * UNSATISFIABLE and the guard never fires. The comment above it describes exactly
 * the case it was written to prevent: a residue of 1, one ten-thousandth of a
 * kilogram, left by converting purchase units to stock units and back, throwing
 * InsufficientStock for goods that are all physically present.
 *
 * ▶ This test returns the WHOLE of a converted line delivered in two receipts,
 * which is precisely that case.
 */
it('lets a converted line be returned in full when it arrived in two deliveries', function () {
    $oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->litre->id,
        'sku' => 'OIL-MUSTARD-FULL',
        'name' => 'Mustard Oil',
        'type' => ProductType::FinishedGood,
    ]);

    ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '1.09051254',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'PROVISIONAL - general reference, not yet confirmed',
    );
    $oil->refresh();

    $order = orderReceivedTwice($this, $oil, '33.3333', '66.6667', '320.00');

    // The entire 100 kg goes back. Nothing is left in the warehouse, so the
    // return must be accepted.
    app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '100'],
    ], $this->user);

    $remaining = StockBatch::where('product_id', $oil->id)->get()
        ->sum(fn (StockBatch $b) => (float) $b->current_quantity);

    expect(round($remaining, 4))->toBe(0.0);
});

it('refuses a return quantity that rounds away to nothing', function () {
    // ⚠⚠ `numeric|min:0` on the screen and `> 0` in the action both let 0.00004
    // through, and it scales to 0 in the reversal. The old code handed back an
    // empty collection WITHOUT throwing, so a return line was written with no
    // movement behind it: the order line's returnable quantity shrank while no
    // stock moved and no journal posted.
    $jars = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->kg->id,
        'sku' => 'SHUTKI-TINY',
        'name' => 'Loitta Shutki',
        'type' => ProductType::FinishedGood,
    ]);

    $order = orderReceivedTwice($this, $jars, '40', '60', '25.00');

    expect(fn () => app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '0.00004'],
    ], $this->user))->toThrow(InvalidArgumentException::class, 'rounds to zero');

    // Nothing recorded, nothing consumed.
    expect(PurchaseReturnLine::count())->toBe(0)
        ->and((float) $order->fresh('lines')->lines->first()->quantity_returned)->toBe(0.0);
});

it('refuses a return whose deliveries straddle the recording of a conversion', function () {
    // ⚠⚠ Delivery 1 arrives BEFORE the conversion exists, so its batch has no
    // factor and its quantity is in the stock unit. Delivery 2 arrives after and
    // freezes a factor. `$quantity` on a return spanning both would be kilograms
    // for one batch and litres for the other - there is no single unit the clerk
    // could have meant, and reversing anyway debits GRNI in the wrong unit for
    // part of the line while every journal still balances.
    $oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->litre->id,
        'sku' => 'OIL-MUSTARD-STRADDLE',
        'name' => 'Mustard Oil',
        'type' => ProductType::FinishedGood,
    ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $oil, 'quantity' => '100', 'unit_cost' => '320.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    // Delivery 1: no conversion exists yet, so nothing converts.
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '40']], $this->user);

    ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $oil,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '1.09051254',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2020-01-01',
        source: 'PROVISIONAL - recorded after the first delivery',
    );
    $oil->refresh();

    // Delivery 2: converts, and freezes a factor on its batch.
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '60']], $this->user);

    $factors = StockBatch::where('product_id', $oil->id)->orderBy('id')->pluck('conversion_factor');
    expect($factors[0])->toBeNull()->and($factors[1])->not->toBeNull();

    expect(fn () => app(RecordPurchaseReturn::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '70'],
    ], $this->user))->toThrow(InvalidArgumentException::class, 'no single unit');
});
