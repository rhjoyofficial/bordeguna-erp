<?php

use App\Models\User;
use App\Modules\Accounting\Actions\FileVatReturn;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\database\seeders\TaxAccountMappingSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Accounting\Models\VatReturn;
use App\Modules\Accounting\Support\VatReturnBuilder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The VAT return (VAT plan D9): registers from the documents, totals from the
 * ledger, and a filing that settles the period without inventing a payment.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Return Co', 'slug' => 'return-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new TaxAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $standard = TaxClass::create(['business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard']);
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $standard->id, 'rate' => '15', 'effective_from' => '2026-01-01']);
    $turnover = TaxClass::create(['business_id' => $this->business->id, 'code' => 'TURNOVER', 'name' => 'Turnover tax']);
    TaxRate::create(['business_id' => $this->business->id, 'tax_class_id' => $turnover->id, 'rate' => '4', 'effective_from' => '2026-01-01']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HONEY', 'name' => 'Honey', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Distributor]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    // "Today" is in November, so October is a month that has ended.
    Carbon::setTestNow('2026-11-03 10:00:00');

    $this->regime = function (string $regime) {
        $this->business->forceFill([
            'vat_regime' => $regime, 'vat_enabled' => $regime === 'vat_standard', 'vat_effective_from' => '2026-10-01',
        ])->save();
        $this->business = $this->business->fresh();
    };

    $this->sell = function (string $unitPrice, string $on = '2026-10-10') {
        app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '100', $this->user, occurredAt: '2026-09-01 09:00:00');
        $lines = app(RecordDelivery::class)->handle(
            customer: $this->customer, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '10']], createdBy: $this->user, deliveredAt: $on,
        )->lines;

        return app(RecordSalesInvoice::class)->handle(
            $this->customer, [['deliveryLine' => $lines[0], 'quantity' => '10', 'unitPrice' => $unitPrice]], $this->user, $on, '0', null, null, false,
        );
    };

    $this->buy = function (string $unitCost, string $on = '2026-10-12') {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier, warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => $unitCost]], createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);
        $order = $order->fresh(['lines', 'approvalRequest']);
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '10']], $this->user);

        return app(RecordSupplierBill::class)->handle(
            supplier: $this->supplier, billNumber: 'ACME/'.uniqid(), billDate: $on,
            lines: [['line' => $order->fresh('lines')->lines->first(), 'quantity' => '10', 'unitCost' => $unitCost]],
            createdBy: $this->user, purchaseOrder: $order,
        );
    };

    $this->october = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];
    $this->balance = fn (string $code) => (int) JournalLine::where('account_id', Account::where('code', $code)->sole()->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('builds both registers and agrees with the ledger', function () {
    ($this->regime)('vat_standard');
    ($this->sell)('1000');
    ($this->buy)('400');

    $figures = app(VatReturnBuilder::class)->build($this->business, ...$this->october);

    expect($figures['sales'])->toHaveCount(1)
        ->and($figures['purchases'])->toHaveCount(1)
        ->and($figures['output_tax_minor'])->toBe(150000)
        ->and($figures['input_tax_minor'])->toBe(60000)
        ->and($figures['agrees'])->toBeTrue()
        ->and($figures['net_payable_minor'])->toBe(90000);
});

it('files the month: clears output and input, and leaves what is owed on VAT Settlement', function () {
    ($this->regime)('vat_standard');
    ($this->sell)('1000');
    ($this->buy)('400');

    $return = app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]);

    expect($return->return_number)->toBe('VAT-2026-10')
        ->and((int) $return->net_payable_minor)->toBe(90000)
        ->and(($this->balance)('2170'))->toBe(0)
        ->and(($this->balance)('1185'))->toBe(0)
        ->and(($this->balance)('2175'))->toBe(-90000);
});

it('carries a credit forward instead of inventing a payment', function () {
    ($this->regime)('vat_standard');
    ($this->buy)('400');

    $october = app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]);

    // 600 of input and no sales: the business is owed 600, as a debit on 2175.
    expect((int) $october->net_payable_minor)->toBe(0)
        ->and((int) $october->credit_carried_forward_minor)->toBe(60000)
        ->and(($this->balance)('2175'))->toBe(60000);

    Carbon::setTestNow('2026-12-03 10:00:00');
    ($this->sell)('1000', '2026-11-10');

    $november = app(VatReturnBuilder::class)->build($this->business, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

    // 1,500 of output less the 600 brought forward.
    expect($november['credit_brought_forward_minor'])->toBe(60000)
        ->and($november['net_payable_minor'])->toBe(90000);
});

it('files a month once', function () {
    ($this->regime)('vat_standard');
    ($this->sell)('1000');
    app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]);

    expect(fn () => app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]))
        ->toThrow(ValidationException::class);
    expect(VatReturn::count())->toBe(1);
});

it('refuses to file a month that has not ended', function () {
    ($this->regime)('vat_standard');

    expect(fn () => app(FileVatReturn::class)->handle($this->business, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), $this->user))
        ->toThrow(ValidationException::class);
});

it('refuses to file for a business that is not registered', function () {
    expect(fn () => app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]))
        ->toThrow(ValidationException::class);
});

it('charges turnover tax on the month\'s sales, as an expense, with nothing on the invoice', function () {
    ($this->regime)('turnover_tax');
    $invoice = ($this->sell)('1000');

    expect((float) $invoice->tax_total)->toBe(0.0)->and((float) $invoice->total)->toBe(10000.0);

    $return = app(FileVatReturn::class)->handle($this->business, ...[...$this->october, $this->user]);

    // 4% of 10,000.
    expect((int) $return->net_payable_minor)->toBe(40000)
        ->and(($this->balance)('6511'))->toBe(40000)
        ->and(($this->balance)('2175'))->toBe(-40000);
});

it('shows the screen, and keeps filing for the Owner', function () {
    ($this->regime)('vat_standard');
    Permission::findOrCreate('accounting.view', 'web');
    Permission::findOrCreate('accounting.chart.manage', 'web');
    $role = Role::create(['name' => 'Finance', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('accounting.view');
    $this->user->assignRole('Finance');

    $as = $this->actingAs($this->user)->withSession(['current_business_id' => $this->business->id]);

    $as->get('/accounting/vat-return?period=2026-10')->assertOk()
        ->assertInertia(fn ($page) => $page->component('accounting/vat-return/index')->where('can_file', false)->where('period', '2026-10'));
    $as->post('/accounting/vat-return', ['period' => '2026-10'])->assertForbidden();
    $as->get('/accounting/vat-return/export?period=2026-10')->assertOk();
});
