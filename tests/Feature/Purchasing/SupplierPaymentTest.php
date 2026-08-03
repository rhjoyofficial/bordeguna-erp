<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Paying a supplier - the event that did not exist.
 *
 * ⚠⚠ Until this slice the ERP could post a liability to Accounts Payable and had
 * no way to settle it: the only related action set a payment-status string that
 * moved no money and reached no ledger. Payables would have grown forever and
 * every supplier would have read as permanently unpaid. Found by the coverage
 * matrix, which is exactly what the matrix was for.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Pay Co', 'slug' => 'pay-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'CAP-38', 'name' => 'Bottle cap 38mm',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Chittagong Caps', 'code' => 'SUP-002',
    ]);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank - Current',
        'type' => MoneyAccountType::Bank,
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $warehouse,
        lines: [['product' => $product, 'quantity' => '100', 'unit_cost' => '20.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '100']], $this->user);

    $this->bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-5001',
        billDate: Carbon::parse('2026-08-02'),
        lines: [['line' => $line, 'quantity' => '100', 'unitCost' => '20.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

it('settles a supplier bill and takes the money out of the bank', function () {
    expect($this->bill->outstanding()->format())->toBe('2,000.00')
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-200000);

    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2000.00']],
        createdBy: $this->user,
        reference: 'TRX-88213',
    );

    // ⚠ The payable is cleared and the bank is down by the same amount. Before
    // this slice the first half was impossible.
    expect(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-200000)
        ->and($this->bill->fresh()->isSettled())->toBeTrue();

    $journal = Journal::where('source_type', (new SupplierPayment)->getMorphClass())->first();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and($journal->lines->first()->party->is($this->supplier))->toBeTrue();
});

it('records a part payment and leaves the rest outstanding', function () {
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '750.00']],
        createdBy: $this->user,
    );

    // ⚠ Outstanding is DERIVED from the allocations, never stored. A stored
    // amount_paid would drift the first time a payment was recorded by a path
    // that forgot to update it.
    expect($this->bill->fresh()->outstanding()->format())->toBe('1,250.00')
        ->and($this->bill->fresh()->isSettled())->toBeFalse()
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-125000);
});

it('refuses to pay more than the bill actually owes', function () {
    // ⚠ Overpaying is not a payment, it is an advance to the supplier - a
    // different event with a different account. Absorbing it would make the bill
    // read as overpaid and drive the payable negative.
    expect(fn () => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2500.00']],
        createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'only 2,000.00 outstanding');

    expect(SupplierPayment::count())->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-200000);
});

it('refuses to pay the same bill twice', function () {
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2000.00']], createdBy: $this->user,
    );

    // Nothing is left owing, so a second payment has nothing to settle. This is
    // how a supplier gets paid twice, and it is more often an accident than
    // anything else.
    expect(fn () => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2000.00']], createdBy: $this->user,
    ))->toThrow(ValidationException::class);

    expect(SupplierPayment::count())->toBe(1);
});

it('refuses a bill that belongs to a different supplier', function () {
    $other = Supplier::create(['business_id' => $this->business->id, 'name' => 'Someone Else', 'code' => 'SUP-003']);

    expect(fn () => app(RecordSupplierPayment::class)->handle(
        supplier: $other, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '100.00']], createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'different supplier');
});

it('refuses a payment dated into a closed month', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-07-31'), $this->user);

    expect(fn () => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-07-20',
        allocations: [['bill' => $this->bill, 'amount' => '100.00']], createdBy: $this->user,
    ))->toThrow(PeriodClosedException::class);
});

it('refuses to pay from an account with no ledger account behind it', function () {
    $tin = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Petty cash tin', 'type' => MoneyAccountType::Cash,
    ]);

    expect(fn () => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $tin, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '100.00']], createdBy: $this->user,
    ))->toThrow(MappingNotFound::class);

    expect(SupplierPayment::count())->toBe(0);
});

it('leaves the books balancing from receipt through to payment', function () {
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2000.00']], createdBy: $this->user,
    );

    // Stock 2,000 in, GRNI cleared, payable cleared, bank 2,000 out.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(200000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-200000)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);

    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});
