<?php

use App\Models\User;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\IncomeVoucher;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Models\Voucher;
use App\Modules\Accounting\Models\VoucherLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\ExpenseVoucher;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\QuickPurchase;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Models\SupplierRefund;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Support\Documents\DocumentLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * PR5 connected-360 (18 Oct 2026): from one supplier or customer, every
 * document of every type, each row opening its own page, and a row the
 * viewer may not read left out rather than shown dead.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Connected Co', 'slug' => 'connected-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach ([
        'sales.reports.view', 'sales.orders.see', 'sales.orders.record', 'purchasing.reports.view',
        'purchasing.landed_cost.view', 'accounting.view', 'accounting.journal.post',
    ] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->as = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->biz = ['business_id' => $this->business->id];
    $this->cash = MoneyAccount::create($this->biz + ['name' => 'Cash box', 'type' => 'cash']);
    $this->warehouse = Warehouse::create($this->biz + ['name' => 'Main', 'code' => 'MAIN']);
    $this->supplier = Supplier::create($this->biz + ['code' => 'RAZZAK', 'name' => 'Razzak Oil Mill']);
    $this->customer = Customer::create($this->biz + ['code' => 'DHK-01', 'name' => 'Dhaka Dealer', 'type' => 'dealer']);
    $this->account = Account::create(['code' => '1110', 'name' => 'Cash in hand', 'type' => AccountType::Asset, 'is_postable' => true, 'is_active' => true]);
});

/** One of every supplier document, the minimum each table needs. */
function everySupplierDocument($test): array
{
    $b = $test->biz;
    $by = ['created_by' => $test->user->id];
    $supplier = ['supplier_id' => $test->supplier->id];

    $po = PurchaseOrder::create($b + $supplier + $by + ['po_number' => 'PO-0001', 'warehouse_id' => $test->warehouse->id, 'status' => 'open', 'order_date' => '2026-10-01']);
    $receipt = GoodsReceipt::create($b + ['purchase_order_id' => $po->id, 'received_by' => $test->user->id, 'received_at' => '2026-10-02', 'supplier_challan_number' => 'CH-9']);
    $return = PurchaseReturn::create($b + ['purchase_order_id' => $po->id, 'returned_by' => $test->user->id, 'returned_at' => '2026-10-03']);
    $bill = SupplierBill::create($b + $supplier + $by + ['purchase_order_id' => $po->id, 'bill_number' => 'BILL-0001', 'bill_date' => '2026-10-04', 'due_date' => '2026-10-20', 'total' => '1000']);
    $payment = SupplierPayment::create($b + $supplier + $by + ['money_account_id' => $test->cash->id, 'paid_on' => '2026-10-05', 'amount' => '300', 'reference' => 'PAY-1']);
    $advance = SupplierAdvance::create($b + $supplier + $by + ['number' => 'SADV-0001', 'kind' => 'advance', 'purchase_order_id' => $po->id, 'money_account_id' => $test->cash->id, 'paid_on' => '2026-10-01', 'amount' => '200']);
    $application = SupplierAdvanceApplication::create($b + $supplier + $by + ['number' => 'SAAP-0001', 'supplier_advance_id' => $advance->id, 'supplier_bill_id' => $bill->id, 'applied_on' => '2026-10-06', 'amount' => '100']);
    $advanceRefund = SupplierAdvanceRefund::create($b + $supplier + $by + ['number' => 'SARF-0001', 'supplier_advance_id' => $advance->id, 'money_account_id' => $test->cash->id, 'refunded_on' => '2026-10-07', 'amount' => '50']);
    $refund = SupplierRefund::create($b + $supplier + $by + ['number' => 'SREF-0001', 'money_account_id' => $test->cash->id, 'received_on' => '2026-10-08', 'amount' => '20']);
    $credit = SupplierCreditNote::create($b + $supplier + $by + ['purchase_return_id' => $return->id, 'credit_note_number' => 'SCN-0001', 'credit_date' => '2026-10-09', 'total' => '40', 'tax_total' => '0']);
    $discount = SupplierDiscount::create($b + $supplier + $by + ['number' => 'SD-0001', 'kind' => 'settlement', 'supplier_bill_id' => $bill->id, 'discount_on' => '2026-10-10', 'amount' => '10', 'basis' => 'amount', 'reference' => 'Letter 7', 'reason' => 'Paid early']);
    $landed = LandedCost::create($b + $by + ['number' => 'LC-0001', 'goods_receipt_id' => $receipt->id, 'status' => 'draft', 'incurred_on' => '2026-10-02', 'total_paisa' => 5000]);
    $expense = ExpenseVoucher::create($b + $supplier + $by + ['voucher_number' => 'EXP-0001', 'spent_on' => '2026-10-11', 'money_account_id' => $test->cash->id, 'paid_to' => 'Razzak', 'total' => '70']);
    $quick = QuickPurchase::create($b + $supplier + $by + ['request_key' => 'qp-1', 'purchased_on' => '2026-10-12', 'status' => 'done', 'goods_paisa' => 10000, 'extra_costs_paisa' => 0, 'paid_paisa' => 0]);
    $adjustment = PartyAdjustment::create($b + $supplier + $by + ['kind' => 'discount', 'adjusted_on' => '2026-10-13', 'amount' => '5', 'reason' => 'Rounding']);
    $setOff = SetOff::create($b + $supplier + $by + ['number' => 'SO-0001', 'customer_id' => $test->customer->id, 'set_off_on' => '2026-10-14', 'amount' => '15', 'reason' => 'Both sides']);
    $voucher = Voucher::create($b + $by + ['type' => VoucherType::Payment, 'status' => 'draft', 'voucher_number' => 'PV-0001', 'voucher_date' => '2026-10-15', 'money_account_id' => $test->cash->id, 'total_minor' => 2500]);
    VoucherLine::create($b + ['voucher_id' => $voucher->id, 'line_no' => 1, 'account_id' => $test->account->id, 'side' => 'debit', 'amount_minor' => 2500, 'party_type' => $test->supplier->getMorphClass(), 'party_id' => $test->supplier->id]);

    return compact('po', 'receipt', 'return', 'bill', 'payment', 'advance', 'application', 'advanceRefund', 'refund', 'credit', 'discount', 'landed', 'expense', 'quick', 'adjustment', 'setOff', 'voucher');
}

it('lists every supplier document type on the supplier 360, each with a link that resolves', function () {
    everySupplierDocument($this);

    $response = ($this->as)(['purchasing.reports.view', 'purchasing.landed_cost.view', 'accounting.view', 'sales.reports.view'])
        ->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('purchasing/suppliers/show')
        ->has('all_documents.rows.data', 17)
        ->where('all_documents.rows.total', 17));

    $rows = collect($response->viewData('page')['props']['all_documents']['rows']['data']);

    expect($rows->pluck('type')->unique()->sort()->values()->all())->toBe(collect([
        'purchase_order', 'goods_receipt', 'purchase_return', 'supplier_bill', 'supplier_payment', 'supplier_advance',
        'advance_application', 'advance_refund', 'supplier_refund', 'supplier_credit_note', 'supplier_discount',
        'landed_cost', 'expense_voucher', 'quick_purchase', 'party_adjustment', 'set_off', 'voucher',
    ])->sort()->values()->all());

    // Newest first.
    expect($rows->first()['type'])->toBe('voucher');

    // Every link is a GET route of this app.
    $rows->pluck('url')->filter()->each(function (string $url) {
        $route = Route::getRoutes()->match(Request::create(strtok($url, '#'), 'GET'));
        expect($route->isFallback)->toBeFalse("{$url} does not resolve");
    });

    expect($rows->firstWhere('type', 'supplier_bill')['url'])->toBe('/purchasing/bills/'.SupplierBill::first()->id)
        ->and($rows->firstWhere('type', 'goods_receipt')['note'])->toContain('PO-0001')
        ->and($rows->firstWhere('type', 'supplier_bill')['amount'])->toBe('1,000.00');
});

it('filters the supplier documents by type and pages them', function () {
    everySupplierDocument($this);

    foreach (range(2, 30) as $n) {
        SupplierBill::create($this->biz + ['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id, 'bill_number' => sprintf('BILL-%04d', $n), 'bill_date' => '2026-09-01', 'total' => '1']);
    }

    ($this->as)(['purchasing.reports.view'])
        ->get("/purchasing/suppliers/{$this->supplier->id}?doc_type=supplier_bill&docs_page=2")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('all_documents.type', 'supplier_bill')
            ->where('all_documents.rows.total', 30)
            ->where('all_documents.rows.current_page', 2)
            ->has('all_documents.rows.data', 5));
});

it('leaves out supplier documents the viewer may not read', function () {
    everySupplierDocument($this);

    $response = ($this->as)(['purchasing.reports.view'])
        ->get("/purchasing/suppliers/{$this->supplier->id}")
        ->assertOk();

    $types = collect($response->viewData('page')['props']['all_documents']['rows']['data'])->pluck('type');

    // No landed-cost, accounting or sales permission: those kinds are not shown.
    expect($types)->not->toContain('landed_cost')
        ->and($types)->not->toContain('voucher')
        ->and($types)->not->toContain('party_adjustment')
        ->and($types)->not->toContain('set_off')
        ->and($types)->toContain('supplier_bill');
});

it('lists every customer document type on the customer 360, each with a link that resolves', function () {
    $b = $this->biz;
    $by = ['created_by' => $this->user->id];
    $customer = ['customer_id' => $this->customer->id];

    $order = SalesOrder::create($b + $customer + $by + ['warehouse_id' => $this->warehouse->id, 'channel' => 'internal', 'order_number' => 'SO-0001', 'ordered_at' => '2026-10-01', 'status' => 'open', 'delivery_charge' => '0', 'order_discount' => '0']);
    $delivery = Delivery::create($b + $customer + $by + ['warehouse_id' => $this->warehouse->id, 'delivery_number' => 'DN-0001', 'delivered_at' => '2026-10-02']);
    $invoice = SalesInvoice::create($b + $customer + $by + ['invoice_number' => 'INV-0001', 'invoice_date' => '2026-10-03', 'due_date' => '2026-10-10', 'subtotal' => '500', 'invoice_discount' => '0', 'tax_total' => '0', 'total' => '500']);
    CustomerReceipt::create($b + $customer + $by + ['money_account_id' => $this->cash->id, 'received_on' => '2026-10-04', 'amount' => '200']);
    CustomerReceipt::create($b + $customer + $by + ['money_account_id' => $this->cash->id, 'sales_order_id' => $order->id, 'received_on' => '2026-10-01', 'amount' => '100']);
    $return = SalesReturn::create($b + $customer + $by + ['delivery_id' => $delivery->id, 'return_number' => 'RET-0001', 'returned_at' => '2026-10-05', 'credit_total' => '50']);
    CustomerRefund::create($b + $customer + $by + ['sales_return_id' => $return->id, 'money_account_id' => $this->cash->id, 'amount' => '50', 'refunded_on' => '2026-10-06']);
    PartyAdjustment::create($b + $customer + $by + ['kind' => 'discount', 'adjusted_on' => '2026-10-07', 'amount' => '5', 'reason' => 'Rounding']);
    SetOff::create($b + $customer + $by + ['number' => 'SO-0001', 'supplier_id' => $this->supplier->id, 'set_off_on' => '2026-10-08', 'amount' => '15', 'reason' => 'Both sides']);
    IncomeVoucher::create($b + $customer + $by + ['voucher_number' => 'INC-0001', 'received_on' => '2026-10-09', 'money_account_id' => $this->cash->id, 'received_from' => 'Dhaka Dealer', 'total' => '30']);
    $quotation = DB::table('quotations')->insertGetId($b + $customer + $by + ['quotation_number' => 'QT-0001', 'valid_until' => '2026-10-30', 'status' => 'sent', 'created_at' => '2026-10-10 10:00:00', 'updated_at' => '2026-10-10 10:00:00']);
    DB::table('quotation_lines')->insert($b + ['quotation_id' => $quotation, 'product_id' => 1, 'quantity' => '2', 'unit_price' => '125']);
    $voucher = Voucher::create($b + $by + ['type' => VoucherType::Receipt, 'status' => 'draft', 'voucher_number' => 'RV-0001', 'voucher_date' => '2026-10-11', 'money_account_id' => $this->cash->id, 'total_minor' => 1000]);
    VoucherLine::create($b + ['voucher_id' => $voucher->id, 'line_no' => 1, 'account_id' => $this->account->id, 'side' => 'credit', 'amount_minor' => -1000, 'party_type' => $this->customer->getMorphClass(), 'party_id' => $this->customer->id]);

    $response = ($this->as)(['sales.reports.view', 'sales.orders.see', 'sales.orders.record', 'accounting.view', 'purchasing.reports.view'])
        ->get("/sales/customers/{$this->customer->id}")
        ->assertOk();

    $rows = collect($response->viewData('page')['props']['all_documents']['rows']['data']);

    expect($rows->pluck('type')->unique()->sort()->values()->all())->toBe(collect([
        'sales_order', 'delivery', 'sales_invoice', 'customer_receipt', 'sales_return', 'customer_refund',
        'party_adjustment', 'set_off', 'income_voucher', 'quotation', 'voucher',
    ])->sort()->values()->all())
        ->and($rows)->toHaveCount(12)
        ->and($rows->firstWhere('label', 'Customer advance'))->not->toBeNull()
        ->and($rows->firstWhere('type', 'quotation')['amount'])->toBe('250.00')
        ->and($rows->firstWhere('type', 'sales_invoice')['url'])->toBe('/sales/invoices/'.$invoice->id);

    $rows->pluck('url')->filter()->each(function (string $url) {
        $route = Route::getRoutes()->match(Request::create(strtok($url, '#'), 'GET'));
        expect($route->isFallback)->toBeFalse("{$url} does not resolve");
    });
});

it('gives a document no link when the viewer may not open its page', function () {
    $bill = SupplierBill::create($this->biz + ['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id, 'bill_number' => 'BILL-9', 'bill_date' => '2026-10-01', 'total' => '1']);
    setPermissionsTeamId($this->business->id);

    $reader = User::factory()->create();
    $reader->givePermissionTo('purchasing.reports.view');
    $stranger = User::factory()->create();

    expect(DocumentLinks::url($bill, $reader))->toBe('/purchasing/bills/'.$bill->id)
        ->and(DocumentLinks::url($bill, $stranger))->toBeNull()
        ->and(DocumentLinks::ref($bill, $stranger)['number'])->toBe('BILL-9');
});
