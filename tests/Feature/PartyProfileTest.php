<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierPaymentAllocation;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer 360 and Supplier 360 (C-360): the profile page owns nothing - every
 * number derives from the same documents the statement and the ageing read, so
 * the profile can never disagree with the reports it summarises.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Party Co', 'slug' => 'party-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['sales.reports.view', 'purchasing.reports.view', 'sales.customers.manage', 'purchasing.suppliers.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->withPermissions = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'DHK-01', 'name' => 'Dhaka Dealer', 'type' => 'dealer',
        'credit_limit' => '500',
    ]);

    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'code' => 'RAZZAK', 'name' => 'Razzak Oil Mill',
        'bank_account_number' => '0123456789',
    ]);

    $this->cash = \App\Modules\Foundation\Models\MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash box', 'type' => 'cash',
    ]);

    $this->warehouse = \App\Modules\Inventory\Models\Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN',
    ]);

    $this->invoice = fn (string $total, string $number = 'INV-0001') => SalesInvoice::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'invoice_number' => $number,
        'invoice_date' => '2026-08-20',
        'due_date' => '2026-08-25',
        'subtotal' => $total, 'invoice_discount' => '0', 'tax_total' => '0', 'total' => $total,
        'created_by' => $this->user->id,
    ]);

    $this->bill = fn (string $total, string $number = 'BILL-0001') => SupplierBill::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'bill_number' => $number,
        'bill_date' => '2026-08-20',
        'due_date' => '2026-08-25',
        'total' => $total,
        'created_by' => $this->user->id,
    ]);
});

it('shows a customer balance by the statement identity, with the open invoice beside it', function () {
    $invoice = ($this->invoice)('1000');

    $receipt = CustomerReceipt::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'money_account_id' => $this->cash->id,
        'received_on' => '2026-08-22',
        'amount' => '400',
        'created_by' => $this->user->id,
    ]);
    CustomerReceiptAllocation::create([
        'business_id' => $this->business->id,
        'customer_receipt_id' => $receipt->id,
        'sales_invoice_id' => $invoice->id,
        'amount' => '400',
    ]);

    ($this->withPermissions)(['sales.reports.view'])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/customers/show')
            ->where('customer.name', 'Dhaka Dealer')
            // invoices 1000 − receipts 400 = 600, the C-CST-2 identity.
            ->where('balance.amount', '600.00')
            ->where('open_invoices.0.invoice_number', 'INV-0001')
            ->where('open_invoices.0.outstanding', '600.00')
            ->has('recent', 2));
});

it('moves the customer balance for returns and refunds exactly as the statement does', function () {
    ($this->invoice)('1000');

    $delivery = \App\Modules\Sales\Models\Delivery::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'delivery_number' => 'DN-0001',
        'delivered_at' => '2026-08-21',
        'created_by' => $this->user->id,
    ]);

    $return = SalesReturn::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'delivery_id' => $delivery->id,
        'return_number' => 'RET-0001',
        'returned_at' => '2026-08-23',
        'credit_total' => '200',
        'created_by' => $this->user->id,
    ]);
    CustomerRefund::create([
        'business_id' => $this->business->id,
        'customer_id' => $this->customer->id,
        'sales_return_id' => $return->id,
        'money_account_id' => $this->cash->id,
        'refunded_on' => '2026-08-24',
        'amount' => '50',
        'created_by' => $this->user->id,
    ]);

    ($this->withPermissions)(['sales.reports.view'])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            // 1000 − 200 + 50 = 850 (a refund RAISES what they owe back).
            ->where('balance.amount', '850.00'));
});

it('flags a balance over the credit limit and stays quiet with no limit', function () {
    ($this->invoice)('1000');

    ($this->withPermissions)(['sales.reports.view'])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('credit.limit', '500.00')
            ->where('credit.over', true));

    $this->customer->update(['credit_limit' => null]);

    ($this->withPermissions)(['sales.reports.view'])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('credit.limit', null)
            ->where('credit.over', false));
});

it('shows a supplier payable by the ageing identity and drops settled bills from the open list', function () {
    $open = ($this->bill)('1500', 'BILL-0001');
    $settled = ($this->bill)('500', 'BILL-0002');

    $payment = SupplierPayment::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'money_account_id' => $this->cash->id,
        'paid_on' => '2026-08-22',
        'amount' => '500',
        'created_by' => $this->user->id,
    ]);
    SupplierPaymentAllocation::create([
        'business_id' => $this->business->id,
        'supplier_payment_id' => $payment->id,
        'supplier_bill_id' => $settled->id,
        'amount' => '500',
    ]);

    $po = \App\Modules\Purchasing\Models\PurchaseOrder::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->warehouse->id,
        'po_number' => 'PO-0001',
        'status' => 'open',
        'created_by' => $this->user->id,
    ]);
    $purchaseReturn = \App\Modules\Purchasing\Models\PurchaseReturn::create([
        'business_id' => $this->business->id,
        'purchase_order_id' => $po->id,
        'returned_by' => $this->user->id,
        'returned_at' => '2026-08-23',
    ]);

    SupplierCreditNote::create([
        'business_id' => $this->business->id,
        'supplier_id' => $this->supplier->id,
        'purchase_return_id' => $purchaseReturn->id,
        'credit_note_number' => 'SCN-0001',
        'credit_date' => '2026-08-23',
        'total' => '300',
        'created_by' => $this->user->id,
    ]);

    ($this->withPermissions)(['purchasing.reports.view'])
        ->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/suppliers/show')
            // bills 2000 − payments 500 − credits 300 = 1200.
            ->where('payable.amount', '1,200.00')
            ->has('open_bills', 1)
            ->where('open_bills.0.bill_number', 'BILL-0001'));
});

it('keeps supplier bank details off the profile page', function () {
    // ⚠ The supplier list hides bank fields behind suppliers.manage; the
    // profile must not become the side door (C-360-5).
    ($this->withPermissions)(['purchasing.reports.view'])
        ->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('supplier.bank_account_number')
            ->missing('supplier.bank_name'));
});

it('refuses both profiles without the read permission', function () {
    ($this->withPermissions)([])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertForbidden();

    ($this->withPermissions)([])
        ->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertForbidden();
});

it('leaves the template and export routes reachable beside the new show routes', function () {
    // C-360-6: a bare {customer} would swallow customers/template - the route
    // is whereNumber so the file routes keep working.
    ($this->withPermissions)(['sales.customers.manage'])
        ->get('/sales/customers/template')
        ->assertOk();

    ($this->withPermissions)(['purchasing.suppliers.manage'])
        ->get('/purchasing/suppliers/template')
        ->assertOk();
});
