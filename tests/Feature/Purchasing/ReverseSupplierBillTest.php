<?php

use App\Models\User;
use App\Modules\Accounting\Actions\RecordPartyAdjustment;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\LedgerIntegrity;
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
use App\Modules\Purchasing\Actions\ReverseSupplierBill;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * D-SB-6: a posted bill is immutable and its correction is a reversal.
 *
 * ⚠⚠ The state this replaces: a posted bill could be DELETED outright, leaving
 * its journal standing with nothing behind it and the purchase order line
 * marked invoiced forever, so the goods could never be billed again. Nothing
 * reconciled the two and the trial balance tied throughout.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Reverse Co', 'slug' => 'reverse-co', 'fiscal_year_start_month' => 7]);
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
        'business_id' => $this->business->id, 'name' => 'Dhaka Glassware', 'code' => 'SUP-001',
    ]);

    $this->order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    approveIfPending($this->order->approvalRequest, $this->user);
    $this->order = $this->order->fresh(['lines', 'approvalRequest']);
    $this->line = $this->order->lines->first();

    app(RecordGoodsReceipt::class)->handle($this->order, [
        ['line' => $this->line, 'quantity' => '100'],
    ], $this->user);

    $this->makeBill = fn (string $number = 'INV-1', string $unitCost = '25.00') => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: $number,
        billDate: '2026-08-03',
        lines: [['line' => $this->line->fresh(), 'quantity' => '100', 'unitCost' => $unitCost]],
        createdBy: $this->user,
        purchaseOrder: $this->order,
    );

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('unwinds Accounts Payable and GRNI and keeps the bill', function () {
    $bill = ($this->makeBill)();

    app(ReverseSupplierBill::class)->handle($bill, $this->user, 'Typed the wrong number');

    expect(($this->balanceOn)('accounts_payable'))->toBe(0)
        // GRNI goes back to what the receipt left there: 100 x 25 credited.
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-250000)
        ->and($bill->fresh()->isReversed())->toBeTrue()
        ->and($bill->fresh()->reversal_note)->toBe('Typed the wrong number');
});

// ⚠⚠ The whole point: the goods become billable again.
it('gives the invoiced quantity back so the goods can be billed again', function () {
    $bill = ($this->makeBill)();

    expect((float) $this->line->fresh()->quantity_invoiced)->toBe(100.0);

    app(ReverseSupplierBill::class)->handle($bill, $this->user);

    expect((float) $this->line->fresh()->quantity_invoiced)->toBe(0.0);

    // And a corrected bill can now be recorded for the same goods.
    $corrected = ($this->makeBill)('INV-1-CORRECTED');

    expect((float) $corrected->total)->toBe(2500.0)
        ->and((float) $this->line->fresh()->quantity_invoiced)->toBe(100.0);
});

it('never orphans a journal: the reversal is a new entry, not a deletion', function () {
    $bill = ($this->makeBill)();
    $original = Journal::where('source_type', $bill->getMorphClass())->sole();

    app(ReverseSupplierBill::class)->handle($bill, $this->user);

    expect(Journal::whereKey($original->id)->exists())->toBeTrue()
        ->and(Journal::where('reverses_journal_id', $original->id)->exists())->toBeTrue()
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);
});

it('unwinds the price variance too', function () {
    $bill = ($this->makeBill)('INV-VAR', '27.00');

    expect(($this->balanceOn)('purchase_price_variance'))->toBe(20000);

    app(ReverseSupplierBill::class)->handle($bill, $this->user);

    expect(($this->balanceOn)('purchase_price_variance'))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ⚠ Refused once money has moved. A credit note is the instrument then.
it('refuses to reverse a bill that has been paid', function () {
    $bill = ($this->makeBill)();

    $cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $cash,
        paidOn: '2026-08-03',
        allocations: [['bill' => $bill->fresh(), 'amount' => '2500.00']],
        createdBy: $this->user,
    );

    expect(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $this->user))
        ->toThrow(DomainException::class, 'already been paid');

    // And nothing moved.
    expect((float) $this->line->fresh()->quantity_invoiced)->toBe(100.0);
});

// ⚠ Audit 13 Oct: a discount or set-off settled part of the bill as surely as
// a payment did, and the reversal ignored it.
it('refuses to reverse a bill with a supplier discount against it', function () {
    $bill = ($this->makeBill)();

    app(RecordPartyAdjustment::class)->handle(
        'supplier_discount', '2026-08-03', null, $this->supplier, [], [$bill->id => '100.00'], 'Early payment discount', $this->user,
    );

    expect(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $this->user))
        ->toThrow(DomainException::class, 'discount or set-off');

    expect($bill->fresh()->isReversed())->toBeFalse()
        ->and((float) $this->line->fresh()->quantity_invoiced)->toBe(100.0);
});

it('refuses to reverse the same bill twice', function () {
    $bill = ($this->makeBill)();

    app(ReverseSupplierBill::class)->handle($bill, $this->user);

    expect(fn () => app(ReverseSupplierBill::class)->handle($bill->fresh(), $this->user))
        ->toThrow(RuntimeException::class, 'already been reversed');
});

// ⚠ PUR-001 (audit 14 Oct 2026): the supplier 360 card "We owe now" counted a
// reversed bill and ignored discounts and set-offs, while the AP ageing, the
// ledger and the payment form said otherwise. It is now the ageing's figure.
it('shows on the supplier page what the AP ageing says is owed, past a reversal and a discount (PUR-001)', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Role::create(['name' => 'Owner', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    (new PurchasingPermissionsSeeder)->run();
    TenantScope::enter($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Owner');

    $props = fn () => $this->actingAs($this->user)->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertOk()->original->getData()['page']['props'];

    app(ReverseSupplierBill::class)->handle(($this->makeBill)('INV-1'), $this->user, 'Typed the wrong number');

    expect($props()['payable']['amount'])->toBe('0.00')
        ->and($props()['totals']['billed'])->toBe('0.00');

    $bill = ($this->makeBill)('INV-2');
    app(RecordPartyAdjustment::class)->handle(
        'supplier_discount', '2026-08-03', null, $this->supplier, [], [$bill->id => '100.00'], 'Early payment discount', $this->user,
    );

    expect($props()['payable']['amount'])->toBe('2,400.00')
        ->and($props()['payable']['amount'])->toBe(Ageing::payables(Carbon::today())->total()->format())
        ->and($props()['totals']['billed'])->toBe('2,500.00');
});

// ⚠ PUR-004 (owner decision 14 Oct 2026): once a bill is reversed, its
// supplier invoice number is free for the corrected bill, so the stored number
// matches the supplier's paper. A number on a LIVE bill stays unique per
// supplier, in the action and in the database index.
it('lets the corrected bill reuse a reversed bill\'s number, and still refuses a live duplicate (PUR-004)', function () {
    $bill = ($this->makeBill)('INV-2');
    app(ReverseSupplierBill::class)->handle($bill, $this->user, 'Typed the wrong price');

    expect($bill->fresh()->active_number_key)->toBeNull();

    $corrected = ($this->makeBill)('INV-2');

    expect($corrected->bill_number)->toBe('INV-2')
        ->and($corrected->active_number_key)->toBe('INV-2')
        ->and(fn () => ($this->makeBill)('INV-2'))->toThrow(InvalidArgumentException::class, 'has already been recorded');

    // The database refuses a second live INV-2 even past the action's check.
    expect(fn () => SupplierBill::create([
        'supplier_id' => $this->supplier->id, 'bill_number' => 'INV-2', 'bill_date' => '2026-08-03',
        'total' => '1', 'created_by' => $this->user->id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Reversed again, the number is free again.
    app(ReverseSupplierBill::class)->handle($corrected, $this->user, 'Wrong price again');

    expect(($this->makeBill)('INV-2')->isReversed())->toBeFalse()
        ->and(SupplierBill::where('bill_number', 'INV-2')->count())->toBe(3);
});
