<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
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
 * Phase 3: the purchasing half of the inventory integration.
 *
 * ⚠⚠ GRNI IS THE BRIDGE. Receiving credits it, the bill debits it, a return
 * debits it back. Between the lorry and the invoice the business owns stock it
 * has not been billed for, and that liability has to exist or the balance sheet
 * is short by the value of everything in transit.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Buy Co', 'slug' => 'buy-co', 'fiscal_year_start_month' => 7]);
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
        'sku' => 'JAR-500',
        'name' => 'Glass jar 500ml',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'name' => 'Dhaka Glassware',
        'code' => 'SUP-001',
    ]);

    // Built through the real action and approved through the real workflow, so
    // the order that reaches the posting is the one the application makes.
    $this->order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    $this->order->approvalRequest->approve($this->user);
    $this->order = $this->order->fresh(['lines', 'approvalRequest']);
    $this->line = $this->order->lines->first();

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

it('puts received stock on the balance sheet with a liability behind it', function () {
    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '40'],
    ], $this->user);

    // 40 jars at 25.00 = 1,000.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(100000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-100000);

    expect(Journal::first()->balance()->isZero())->toBeTrue();
});

it('moves the liability from GRNI to the supplier when the bill arrives', function () {
    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '40'],
    ], $this->user);

    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-9001',
        billDate: Carbon::parse('2026-08-03'),
        lines: [['line' => $this->line, 'quantity' => '40', 'unitCost' => '25.00']],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );

    // ⚠ The two entries clear each other exactly. A GRNI balance of zero means
    // everything received has been invoiced, which is the whole point of the
    // account: it is a report, not a suspense.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-100000)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(100000);

    $payableLine = JournalLine::where('account_id', Account::bySlug('accounts_payable')->id)->first();

    // The supplier is the line's party, which is what makes Accounts Payable a
    // ledger somebody can act on rather than one undifferentiated balance.
    expect($payableLine->party->is($this->supplier))->toBeTrue();
});

it('reverses the receipt entry when goods go back to the supplier', function () {
    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '40'],
    ], $this->user);

    app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '10'],
    ], $this->user);

    // ⚠⚠ The contra is GRNI, not Accounts Payable, because a return reverses a
    // RECEIPT and the receipt credited GRNI. 10 jars at 25.00 = 250 back out of
    // stock, leaving 750 received and 750 still uninvoiced.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(75000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-75000)
        // Nothing has been invoiced, so nothing is owed to the supplier yet.
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0);
});

it('leaves the books balancing across receive, bill and return', function () {
    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '40'],
    ], $this->user);
    app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-9002',
        billDate: Carbon::parse('2026-08-03'),
        lines: [['line' => $this->line, 'quantity' => '40', 'unitCost' => '25.00']],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );
    app(RecordPurchaseReturn::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '10'],
    ], $this->user);

    expect((int) JournalLine::sum('amount_minor'))->toBe(0);

    $result = LedgerIntegrity::verifyAllBusinesses();
    expect($result['problems'])->toBeEmpty();
});
