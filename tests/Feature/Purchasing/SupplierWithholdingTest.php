<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
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
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierType;
use App\Modules\Purchasing\Models\WithholdingRate;
use App\Modules\Purchasing\Support\SupplierTypes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

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

// ⭐ 17 Oct 2026: supplier types are a list each business keeps. A rate kept
// for a type the owner added himself applies on payment exactly like one of
// the seven, through the real payment form.
it('applies a TDS rate kept for a type the owner added himself', function () {
    SupplierType::create(['business_id' => $this->business->id, 'key' => 'cnf_agent', 'name' => 'C&F agent', 'sort' => 7]);
    $this->supplier->update(['supplier_type' => 'cnf_agent']);
    $tds = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'cnf_agent', 'name' => 'C&F commission', 'rate' => '10', 'effective_from' => '2026-07-01']);
    // A rate for one of the seven does not reach this supplier any more.
    $goods = WithholdingRate::create(['business_id' => $this->business->id, 'kind' => 'tds', 'supplier_type' => 'goods', 'name' => 'Supply of goods', 'rate' => '5', 'effective_from' => '2026-07-01']);

    Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    (new PurchasingPermissionsSeeder)->run();
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Manager');

    $this->actingAs($this->user)->get('/purchasing/payments/new?supplier_id='.$this->supplier->id)
        ->assertInertia(fn (Assert $page) => $page->where('supplierType', 'cnf_agent'));

    $pay = fn (WithholdingRate $rate) => $this->actingAs($this->user)->post('/purchasing/payments', [
        'supplier_id' => $this->supplier->id, 'money_account_id' => $this->bank->id, 'paid_on' => '2026-08-03',
        'allocations' => [['supplier_bill_id' => $this->bill->id, 'amount' => '1000.00']],
        'tds_rate_id' => $rate->id,
    ]);

    $pay($goods)->assertSessionHasErrors('tds_rate_id');
    expect(SupplierPayment::count())->toBe(0);

    $pay($tds)->assertSessionHasNoErrors();

    expect(SupplierPayment::sole()->netPaid()->format())->toBe('900.00')
        ->and(($this->balanceOn)('tds_payable'))->toBe(-10000);
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

// Add and edit are a popup over the list since 14 Oct 2026 (Joy: "click add
// button pop up will add ... same when edit"). The page it opens from carries
// what the popup draws, each row carries what Edit fills in, and the popup
// writes through the same routes. There is no separate manage flag: the whole
// page sits behind purchasing.suppliers.manage, so a read-only user never sees it.
it('carries what the tax rate popup needs on the list, and saves through the same routes', function () {
    foreach (['Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new PurchasingPermissionsSeeder)->run();
    setPermissionsTeamId($this->business->id);

    $asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $asRole('Manager')->get('/purchasing/withholding-rates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/withholding-rates/index')
            ->where('kinds', WithholdingRate::KINDS)
            ->where('supplierTypes', SupplierTypes::options())
            // A new rate starts in force from today.
            ->where('today', '2026-08-03')
            ->has('rates', 0));

    $asRole('Manager')->post(route('purchasing.withholding-rates.store'), [
        'kind' => 'tds', 'supplier_type' => 'goods', 'name' => 'Supply of goods', 'rate' => '5',
        'effective_from' => '2026-07-01', 'effective_to' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $tds = WithholdingRate::query()->sole();

    // A payment made at it: from now on the popup locks the percentage and
    // posts the stored figure back, which the server accepts.
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier, moneyAccount: $this->bank, paidOn: '2026-08-03',
        allocations: [['bill' => $this->bill, 'amount' => '100.00']], createdBy: $this->user, tdsRate: $tds,
    );

    $response = $asRole('Manager')->get('/purchasing/withholding-rates')->assertOk();
    $row = $response->viewData('page')['props']['rates'][0];

    foreach (['id', 'kind', 'supplier_type', 'name', 'rate', 'effective_from', 'effective_to', 'used'] as $key) {
        expect($row)->toHaveKey($key);
    }

    expect($row['rate'])->toBe('5')->and($row['used'])->toBeTrue();

    $asRole('Manager')->put(route('purchasing.withholding-rates.update', $tds->id), [
        'kind' => $row['kind'], 'supplier_type' => $row['supplier_type'], 'name' => 'Supply of goods (old)', 'rate' => $row['rate'],
        'effective_from' => $row['effective_from'], 'effective_to' => '2026-12-31',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tds->refresh();

    expect($tds->name)->toBe('Supply of goods (old)')
        ->and($tds->effective_to->toDateString())->toBe('2026-12-31')
        ->and((float) $tds->rate)->toBe(5.0);

    // Someone who may not manage suppliers never reaches the page or the popup.
    $asRole('Staff')->get('/purchasing/withholding-rates')->assertForbidden();
});
