<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Documents\PartyLifetime;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B23 lifetime views (25 Oct 2026; R75, R77): everything under one supplier
 * (or customer): every document of BOTH sides when the firm is also our
 * customer, totals per kind, and one running net, all time by default.
 *
 * The world: Razzak Oil Mill sells us oil (bill 1,000, we paid 300) and buys
 * our pickle as "Razzak Store" (invoice 500, they paid 200). Net: we owe them
 * 700, they owe us 300, so we owe them 400.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');

    $this->business = Business::create(['name' => 'Lifetime Co', 'slug' => 'lifetime-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.reports.view', 'sales.orders.see', 'purchasing.reports.view', 'accounting.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->reader = function (array $permissions): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $user;
    };
    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $b = ['business_id' => $this->business->id];
    $by = ['created_by' => $this->user->id];
    $this->cash = MoneyAccount::create($b + ['name' => 'Cash box', 'type' => 'cash']);
    $warehouse = Warehouse::create($b + ['name' => 'Main', 'code' => 'MAIN']);
    $this->supplier = Supplier::create($b + ['code' => 'RAZZAK', 'name' => 'Razzak Oil Mill']);
    $this->customer = Customer::create($b + ['code' => 'RZK-C', 'name' => 'Razzak Store', 'type' => 'dealer']);
    // The link is set by its own screen (accounting.parties.link), never mass assigned.
    $this->customer->forceFill(['supplier_id' => $this->supplier->id])->save();

    $po = PurchaseOrder::create($b + $by + ['supplier_id' => $this->supplier->id, 'po_number' => 'PO-0001', 'warehouse_id' => $warehouse->id, 'status' => 'open', 'order_date' => '2026-10-01']);
    SupplierBill::create($b + $by + ['supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id, 'bill_number' => 'BILL-0001', 'bill_date' => '2026-10-04', 'due_date' => '2026-10-20', 'total' => '1000']);
    SupplierPayment::create($b + $by + ['supplier_id' => $this->supplier->id, 'money_account_id' => $this->cash->id, 'paid_on' => '2026-10-05', 'amount' => '300', 'reference' => 'PAY-1']);
    SalesInvoice::create($b + $by + ['customer_id' => $this->customer->id, 'invoice_number' => 'INV-0001', 'invoice_date' => '2026-10-06', 'due_date' => '2026-10-10', 'subtotal' => '500', 'invoice_discount' => '0', 'tax_total' => '0', 'total' => '500']);
    CustomerReceipt::create($b + $by + ['customer_id' => $this->customer->id, 'money_account_id' => $this->cash->id, 'received_on' => '2026-10-07', 'amount' => '200']);

    $this->both = ($this->reader)(['purchasing.reports.view', 'sales.reports.view']);
});

afterEach(fn () => Carbon::setTestNow());

it('puts the linked customer\'s sales documents and receipts under the supplier, with one running net', function () {
    $props = ($this->as)($this->both)->get("/purchasing/suppliers/{$this->supplier->id}")->assertOk()->viewData('page')['props'];

    $rows = collect($props['all_documents']['rows']['data']);
    expect($rows->pluck('type')->all())->toContain('sales_invoice', 'customer_receipt', 'supplier_bill', 'supplier_payment')
        ->and($rows->firstWhere('type', 'sales_invoice')['side'])->toBe('customer')
        ->and($rows->firstWhere('type', 'supplier_bill')['side'])->toBe('supplier');

    $lifetime = $props['lifetime'];
    expect($lifetime['combined'])->toBeTrue()
        ->and($lifetime['other'])->toBe(['side' => 'customer', 'id' => $this->customer->id, 'name' => 'Razzak Store'])
        ->and($lifetime['range']['all_time'])->toBeTrue();

    $totals = collect($lifetime['totals'])->keyBy('type');
    expect($totals['supplier_bill']['amount'])->toBe('1,000.00')
        ->and($totals['sales_invoice']['amount'])->toBe('500.00')
        ->and($totals['sales_invoice']['side'])->toBe('customer');

    // Bill +1000 owed, paid 300, invoiced 500, received 200: we owe them 400.
    $timeline = $lifetime['timeline'];
    expect($timeline['combined'])->toBeTrue()
        ->and($timeline['lines'])->toHaveCount(4)
        ->and($timeline['closing'])->toBe(['net' => '400.00', 'net_side' => 'them'])
        ->and(collect($timeline['lines'])->pluck('side')->unique()->sort()->values()->all())->toBe(['customer', 'supplier']);
});

it('shows the same firm from the customer page, the supplier side included', function () {
    $lifetime = ($this->as)($this->both)->get("/sales/customers/{$this->customer->id}")->assertOk()->viewData('page')['props']['lifetime'];

    expect($lifetime['combined'])->toBeTrue()
        ->and($lifetime['other']['side'])->toBe('supplier')
        ->and($lifetime['timeline']['closing'])->toBe(['net' => '400.00', 'net_side' => 'them'])
        ->and(collect($lifetime['totals'])->pluck('type')->all())->toContain('supplier_bill', 'sales_invoice');
});

it('keeps the other side away from a reader of one side only', function () {
    $buyer = ($this->reader)(['purchasing.reports.view']);
    $props = ($this->as)($buyer)->get("/purchasing/suppliers/{$this->supplier->id}")->assertOk()->viewData('page')['props'];

    $rows = collect($props['all_documents']['rows']['data']);
    expect($rows->pluck('type')->all())->not->toContain('sales_invoice')
        ->and($props['lifetime']['combined'])->toBeFalse()
        ->and($props['lifetime']['other'])->toBeNull()
        // Their own account: bill 1,000 less 300 paid.
        ->and($props['lifetime']['timeline']['combined'])->toBeFalse()
        ->and($props['lifetime']['timeline']['closing'])->toBe(['net' => '700.00', 'net_side' => 'them']);

    $seller = ($this->reader)(['sales.reports.view']);
    $customer = ($this->as)($seller)->get("/sales/customers/{$this->customer->id}")->assertOk()->viewData('page')['props']['lifetime'];
    expect($customer['timeline']['closing'])->toBe(['net' => '300.00', 'net_side' => 'us'])
        ->and(collect($customer['totals'])->pluck('type')->all())->not->toContain('supplier_bill');
});

it('narrows the documents, totals and timeline to a date window, the net carried in as an opening', function () {
    $lifetime = ($this->as)($this->both)->get("/purchasing/suppliers/{$this->supplier->id}?from=2026-10-06&to=2026-10-31")
        ->assertOk()->viewData('page')['props'];

    $rows = collect($lifetime['all_documents']['rows']['data']);
    expect($rows->pluck('type')->all())->not->toContain('supplier_bill')
        ->and($lifetime['lifetime']['range'])->toBe(['from' => '2026-10-06', 'to' => '2026-10-31', 'all_time' => false])
        // Before the window: owed 1,000, paid 300, so we owed them 700.
        ->and($lifetime['lifetime']['timeline']['opening'])->toBe(['net' => '700.00', 'net_side' => 'them'])
        ->and($lifetime['lifetime']['timeline']['lines'])->toHaveCount(2)
        ->and($lifetime['lifetime']['timeline']['closing'])->toBe(['net' => '400.00', 'net_side' => 'them']);
});

it('adds a reversed document to the count but not to the amount', function () {
    SupplierBill::create(['business_id' => $this->business->id, 'created_by' => $this->user->id, 'supplier_id' => $this->supplier->id, 'bill_number' => 'BILL-0002', 'bill_date' => '2026-10-08', 'total' => '250', 'reversed_at' => now(), 'reversed_on' => '2026-10-09']);

    $totals = collect(($this->as)($this->both)->get("/purchasing/suppliers/{$this->supplier->id}")->viewData('page')['props']['lifetime']['totals'])->keyBy('type');

    expect($totals['supplier_bill']['count'])->toBe(2)
        ->and($totals['supplier_bill']['amount'])->toBe('1,000.00');
});

it('reads a supplier lifetime in a fixed number of queries however many documents there are', function () {
    $read = function () {
        $lifetime = PartyLifetime::forSupplier($this->supplier->fresh(), $this->both->fresh(), LifetimeRange::allTime());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $lifetime->props();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $first = $read();

    foreach (range(2, 6) as $i) {
        SupplierPayment::create(['business_id' => $this->business->id, 'created_by' => $this->user->id, 'supplier_id' => $this->supplier->id, 'money_account_id' => $this->cash->id, 'paid_on' => '2026-10-1'.$i, 'amount' => '10', 'reference' => "PAY-{$i}"]);
        CustomerReceipt::create(['business_id' => $this->business->id, 'created_by' => $this->user->id, 'customer_id' => $this->customer->id, 'money_account_id' => $this->cash->id, 'received_on' => '2026-10-1'.$i, 'amount' => '10']);
    }

    expect($read())->toBe($first);
});

it('exports the running net and every document as a spreadsheet, gated like the page', function () {
    $csv = ($this->as)($this->both)->get("/purchasing/suppliers/{$this->supplier->id}/lifetime-export")->assertOk()->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Running net')
        ->and($csv)->toContain('BILL-0001')
        ->and($csv)->toContain('INV-0001')
        ->and($csv)->toContain('we owe them');

    $customerCsv = ($this->as)($this->both)->get("/sales/customers/{$this->customer->id}/lifetime-export")->assertOk()->streamedContent();
    expect($customerCsv)->toContain('INV-0001');

    $stranger = ($this->reader)(['accounting.view']);
    ($this->as)($stranger)->get("/purchasing/suppliers/{$this->supplier->id}/lifetime-export")->assertForbidden();
    ($this->as)($stranger)->get("/sales/customers/{$this->customer->id}/lifetime-export")->assertForbidden();
});
