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
use App\Modules\Purchasing\Models\WithholdingRate;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tax held back from a supplier payment (finance decision C4, 29 Sep 2026):
 * the payable is settled in full, the bank pays the net, NBR is owed the rest.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Tax Co', 'slug' => 'tax-co', 'fiscal_year_start_month' => 7]);
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
        'business_id' => $this->business->id, 'name' => 'Chittagong Caps', 'code' => 'SUP-002', 'supplier_type' => 'goods',
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
    approveIfPending($order->approvalRequest, $this->user);
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


it('holds TDS and VDS back: payable cleared in full, bank pays the net, both taxes owed', function () {
    $tds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'goods', 'name' => 'Supply of goods', 'rate' => '5', 'effective_from' => '2026-07-01']);
    $vds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'vds', 'supplier_type' => null, 'name' => 'Any supply', 'rate' => '2.5', 'effective_from' => '2026-07-01']);

    $payment = app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '2000.00']], createdBy: $this->user,
        tdsRate: $tds, vdsRate: $vds,
    );

    expect($payment->netPaid()->format())->toBe('1,850.00')
        ->and($this->bill->fresh()->outstanding()->minor)->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0)
        ->and(($this->balanceOn)('cash_at_bank'))->toBe(-185000)
        ->and(($this->balanceOn)('tds_payable'))->toBe(-10000)
        ->and(($this->balanceOn)('vds_payable'))->toBe(-5000);
});

it('refuses a rate for another supplier type, a rate not yet in force, and a TDS rate as VDS', function () {
    $service = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'service', 'name' => 'Services', 'rate' => '10', 'effective_from' => '2026-07-01']);
    $later = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => null, 'name' => 'Next year', 'rate' => '7', 'effective_from' => '2027-07-01']);
    $pay = fn (?WithholdingRate $t, ?WithholdingRate $v = null) => app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '100.00']], createdBy: $this->user, tdsRate: $t, vdsRate: $v,
    );

    expect(fn () => $pay($service))->toThrow(ValidationException::class, 'does not apply')
        ->and(fn () => $pay($later))->toThrow(ValidationException::class, 'does not apply')
        ->and(fn () => $pay(null, $later))->toThrow(ValidationException::class, 'does not apply')
        ->and(SupplierPayment::count())->toBe(0);
});
