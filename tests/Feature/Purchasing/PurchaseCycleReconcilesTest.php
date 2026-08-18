<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * The whole purchase cycle, end to end, then every reconciliation.
 *
 * ⚠⚠ THE POINT OF THE SLICE, STATED AS ONE TEST: purchase order, goods receipt,
 * supplier bill, supplier payment. Before the bill existed this chain could not
 * be completed at all, so GRNI carried a balance forever and Accounts Payable
 * was never created from a purchase.
 *
 * ⚠ A balanced trial balance does not prove the accounting is correct, so this
 * asserts the SUBLEDGER reconciliation as well: the ledger agreeing with the
 * documents, not merely with itself.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Cycle Co', 'slug' => 'cycle-co', 'fiscal_year_start_month' => 7]);
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
        'business_id' => $this->business->id, 'name' => 'Razzak Oil Mill', 'code' => 'SUP-RZK',
    ]);
    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('completes purchase order to payment and reconciles every ledger', function () {
    // 1. Order 100 kg at 320.
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '320.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    // 2. Receive it all. Dr Stock 32,000 / Cr GRNI 32,000.
    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->user);

    expect(($this->balanceOn)('stock_on_hand'))->toBe(3200000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-3200000);

    // 3. The supplier invoices at 330. GRNI clears at the ORDER price and the
    //    difference becomes variance.
    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'RZK-CYCLE-1',
        billDate: '2026-08-03',
        lines: [['line' => $line->fresh(), 'quantity' => '100', 'unitCost' => '330.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
        dueDate: '2026-09-02',
    );

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3300000);

    // 4. Pay it in full.
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->cash,
        paidOn: '2026-08-03',
        allocations: [['bill' => $bill->fresh(), 'amount' => '33000.00']],
        createdBy: $this->user,
    );

    expect(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-3300000)
        ->and($bill->fresh()->outstanding()->minor)->toBe(0);

    // ⚠⚠ What the business is left holding: stock at the ORDER cost, because
    // FIFO layers are never revalued by a bill, and 1,000 of variance in the
    // profit and loss saying the supplier charged more than was agreed.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(3200000)
        ->and((float) $this->product->batches()->sole()->unit_cost)->toBe(320.0);

    // Every verifier, not just the trial balance.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([])
        ->and(StockLedger::verify())->toBe([])
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBe([]);
});

it('reconciles after a partial bill leaves GRNI genuinely outstanding', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '320.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->user);

    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'RZK-CYCLE-2',
        billDate: '2026-08-03',
        lines: [['line' => $line->fresh(), 'quantity' => '60', 'unitCost' => '320.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    // 40 kg still received but not invoiced: a real, correct GRNI balance.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-1280000)
        ->and(SubledgerReconciliation::reconcileCurrentBusiness()['problems'])->toBe([])
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);
});
