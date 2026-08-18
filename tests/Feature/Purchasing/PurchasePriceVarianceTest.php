<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * D-SB-2: the supplier invoiced a different price from the one the order agreed.
 *
 * ⚠⚠ THE POLICY, and the thing these tests exist to hold still: GRNI is cleared
 * at the RECEIVED value, which is billed quantity times the PURCHASE ORDER's
 * unit cost, and the gap between that and the invoice goes to Purchase Price
 * Variance. Accounts Payable carries the actual bill.
 *
 * ⚠⚠ FIFO IS NOT TOUCHED. No batch is revalued, no stock movement is written,
 * and cost of goods sold already released is never rewritten. Bor De Guna is a
 * FIFO business and a purchase order carries an agreed price, NOT a standard
 * cost. These tests assert that absence as hard as they assert the journal.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Variance Co', 'slug' => 'variance-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mohammadpur', 'code' => 'MOH']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'OIL-BULK',
        'name' => 'Mustard oil bulk',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'name' => 'Razzak Oil Mill',
        'code' => 'SUP-RZK',
    ]);

    // Joy's worked example: 100 kg at 320.
    $this->order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '320.00']],
        createdBy: $this->user,
    );
    $this->order->approvalRequest->approve($this->user);
    $this->order = $this->order->fresh(['lines', 'approvalRequest']);
    $this->line = $this->order->lines->first();

    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '100'],
    ], $this->user);

    $this->line = $this->line->fresh();

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');

    $this->bill = fn (string $unitCost, string $quantity = '100', string $number = 'RZK-001') => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: $number,
        billDate: '2026-08-03',
        lines: [['line' => $this->line->fresh(), 'quantity' => $quantity, 'unitCost' => $unitCost]],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ▶▶ CASE 1. The price matched. The variance line must not exist at all.
it('posts exactly two lines when the bill agrees with the order', function () {
    ($this->bill)('320.00');

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3200000)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(0);

    // ⚠ Not merely a zero balance: the line must be absent. A zero-value
    // journal line is noise on a report somebody has to read.
    $variance = Account::bySlug('purchase_price_variance');
    expect(JournalLine::where('account_id', $variance->id)->count())->toBe(0);
});

// ▶▶ CASE 2. Joy's worked example, to the paisa.
it('sends an unfavourable difference to Purchase Price Variance', function () {
    ($this->bill)('330.00');

    // GRNI cleared at the RECEIVED value, not at the bill total.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3300000);

    // The trial balance, stated as one number.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ▶▶ CASE 3. A favourable variance is a CREDIT on an expense account.
it('sends a favourable difference to Purchase Price Variance as a credit', function () {
    ($this->bill)('310.00');

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(-100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3100000)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ⚠⚠ THE PROMISE TO JOY, ASSERTED AS AN ABSENCE. A supplier bill must never
// touch inventory. If this ever fails, the system has quietly become something
// other than FIFO.
it('never revalues a FIFO batch and never writes a stock movement', function () {
    $batch = $this->product->batches()->sole();
    $costBefore = (string) $batch->unit_cost;
    $movementsBefore = $this->product->movements()->count();

    ($this->bill)('330.00');

    expect((string) $batch->fresh()->unit_cost)->toBe($costBefore)
        ->and($this->product->movements()->count())->toBe($movementsBefore)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(3200000);
});

it('leaves every journal balanced and the ledger consistent', function () {
    ($this->bill)('330.00');

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);
});

// ⚠ Partial billing carries its own share of the variance, never the whole of it.
it('applies the variance only to the quantity actually billed', function () {
    ($this->bill)('330.00', '40', 'RZK-PART-1');

    // ⚠ The receipt CREDITED GRNI with 32,000, so it carries a credit balance
    // and billing part of it debits some back. 40 at the order's 320 relieves
    // 12,800, leaving 19,200 still credited. The variance is 40 x 10 = 400 and
    // Accounts Payable takes the invoice at 40 x 330 = 13,200.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-1920000)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(40000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-1320000);
});

// ⚠ Money is exact. 0.1 at 0.29 is where a float shows first, and this project
// has paid for decimal handling four separate times.
it('keeps the variance exact on awkward decimals', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '3', 'unit_cost' => '0.29']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '3']], $this->user);

    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'RZK-DEC-1',
        billDate: '2026-08-03',
        lines: [['line' => $line->fresh(), 'quantity' => '3', 'unitCost' => '0.31']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    // 3 x 0.29 = 0.87 out of GRNI, 3 x 0.02 = 0.06 variance, AP 0.93.
    expect(($this->balanceOn)('purchase_price_variance'))->toBe(6)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ▶▶ D-SB-8, and the focused test Joy asked for by name:
// PO → receipt → partial return → bill. Returned goods are not billable.
it('refuses to bill goods that were received and then returned', function () {
    app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line->fresh(), 'quantity' => '30'],
    ], $this->user);

    // 100 received, 30 returned, so 70 is the ceiling.
    expect(fn () => ($this->bill)('320.00', '71'))
        ->toThrow(InvalidArgumentException::class);

    // And 70 is genuinely billable.
    ($this->bill)('320.00', '70', 'RZK-RET-1');

    expect((float) $this->line->fresh()->quantity_invoiced)->toBe(70.0);
});

it('clears GRNI exactly after receive, return and bill together', function () {
    app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line->fresh(), 'quantity' => '30'],
    ], $this->user);

    ($this->bill)('320.00', '70', 'RZK-RET-2');

    // Received 100 credited GRNI 32,000; the return debited back 30 x 320 =
    // 9,600; the bill debited 70 x 320 = 22,400. Nothing should remain.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0);
});
