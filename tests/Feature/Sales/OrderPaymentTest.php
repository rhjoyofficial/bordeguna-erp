<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Ageing;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SSLCommerz slice P1: money a customer pays for an order before its invoice
 * exists (Joy, 13 September). It is an ordinary receipt held as credit on the
 * customer's account for the order, and the order's invoice takes it - an
 * allocation, no journal.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Prepaid Co', 'slug' => 'prepaid-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Mangrove Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Online buyer', 'type' => CustomerType::Retail]);

    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    Carbon::setTestNow('2026-09-14 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    // 2 x 850 of goods plus 120 delivery = 1,820.
    $this->order = fn (string $quantity = '2', ?string $deliveryCharge = '120'): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $this->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $deliveryCharge),
    );

    $this->deliver = fn (SalesOrder $order, string $quantity = '2'): Delivery => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
        deliveredAt: '2026-09-13 11:00:00',
    );

    $this->pay = fn (SalesOrder $order, string $amount, ?User $as = null, ?MoneyAccount $into = null) => $this->actingAs($as ?? $this->staff)
        ->post("/sales/orders/{$order->id}/payments", [
            'money_account_id' => ($into ?? $this->bkash)->id,
            'amount' => $amount,
            'received_on' => '2026-09-14',
            'reference' => 'TRX9A7B',
        ]);

    $this->refund = fn (SalesOrder $order, string $amount, ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->post("/sales/orders/{$order->id}/refunds", [
            'money_account_id' => $this->bkash->id,
            'amount' => $amount,
            'refunded_on' => '2026-09-14',
        ]);

    $this->book = fn (Delivery $delivery, string $cod) => $this->actingAs($this->staff)
        ->post("/sales/deliveries/{$delivery->id}/consignment", [
            'money_account_id' => $this->courier->id,
            'consignment_number' => 'DB140926PAID',
            'cod_amount' => $cod,
        ]);

    $this->report = fn (Consignment $consignment, string $outcome, string $collected) => $this->actingAs($this->staff)
        ->post("/sales/consignments/{$consignment->id}/outcome", [
            'outcome' => $outcome,
            'outcome_on' => '2026-09-14',
            'collected_amount' => $collected,
        ]);

    $this->props = fn (SalesOrder $order, ?User $as = null) => $this->actingAs($as ?? $this->owner)
        ->get('/sales/orders/'.$order->id)
        ->original->getData()['page']['props'];

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    // Only the receivable: this fixture's stock-in posts no journal, which the stock check would name.
    $this->reconciles = fn () => array_values(array_filter(
        SubledgerReconciliation::reconcileCurrentBusiness()['problems'],
        fn (string $problem) => str_contains($problem, 'Receivable'),
    ));
});

afterEach(fn () => Carbon::setTestNow());

it('holds money paid for an order on the customer account until the invoice takes it', function () {
    $order = ($this->order)();

    ($this->pay)($order, '1820')->assertSessionHasNoErrors();

    $receipt = CustomerReceipt::query()->sole();
    $ageing = Ageing::receivables(Carbon::today());

    expect($receipt->sales_order_id)->toBe($order->id)
        ->and($receipt->allocations()->count())->toBe(0)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(182000)
        ->and($order->fresh()->stillToPay()->minor)->toBe(0)
        // Dr the money account / Cr 1140, exactly like any receipt.
        ->and(($this->posted)('cash_in_hand'))->toBe(182000)
        ->and(($this->posted)('accounts_receivable'))->toBe(-182000)
        // Credit on the customer's account, and the ageing still ties to 1140.
        ->and($ageing->rows->first()->onAccount->minor)->toBe(-182000)
        ->and($ageing->tiesOut())->toBeTrue()
        ->and(($this->reconciles)())->toBe([]);

    $payments = ($this->props)($order)['payments'];

    expect($payments['paid'])->toBe('1820.00')
        ->and($payments['held'])->toBe('1820.00')
        ->and($payments['still_to_pay'])->toBe('0.00')
        ->and($payments['refund_owed'])->toBeNull()
        ->and($payments['receipts'][0]['reference'])->toBe('TRX9A7B');
});

it('settles a prepaid parcel on delivery with nothing for the courier to collect', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    $delivery = ($this->deliver)($order);

    expect(($this->props)($order)['challans'][0]['cod_preview'])->toBe('0.00');

    ($this->book)($delivery, '0')->assertSessionHasNoErrors();
    ($this->report)(Consignment::query()->sole(), 'delivered', '0')->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    expect((float) $invoice->total)->toBe(1820.0)
        ->and($invoice->outstanding()->minor)->toBe(0)
        // The advance receipt took the invoice; no receipt went into the courier.
        ->and(CustomerReceipt::query()->count())->toBe(1)
        ->and($invoice->allocations()->sole()->customer_receipt_id)->toBe(CustomerReceipt::query()->sole()->id)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and(($this->posted)('courier_receivable'))->toBe(0)
        ->and(($this->posted)('accounts_receivable'))->toBe(0)
        ->and(Ageing::receivables(Carbon::today())->total()->minor)->toBe(0)
        ->and(($this->reconciles)())->toBe([]);
});

it('tells the courier to collect only what was not paid in advance', function () {
    $order = ($this->order)();
    ($this->pay)($order, '500');
    $delivery = ($this->deliver)($order);

    expect(($this->props)($order)['challans'][0]['cod_preview'])->toBe('1320.00');

    ($this->book)($delivery, '1320');
    $consignment = Consignment::query()->sole();

    // The whole invoice collected at the door is refused, with the advance named.
    ($this->report)($consignment, 'delivered', '1820')
        ->assertSessionHasErrors(['collected_amount' => 'The invoice for what the customer took comes to 1,820.00 and 500.00 of it was paid in advance, so the courier should have collected 1,320.00, not 1,820.00. If part of the parcel came back, record a partial delivery; otherwise choose how the difference is settled and say why.']);

    expect(SalesInvoice::query()->count())->toBe(0);

    ($this->report)($consignment, 'delivered', '1320')->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    expect($invoice->outstanding()->minor)->toBe(0)
        ->and($invoice->allocations()->count())->toBe(2)
        ->and(($this->posted)('courier_receivable'))->toBe(132000)
        ->and(($this->posted)('accounts_receivable'))->toBe(0)
        ->and(($this->reconciles)())->toBe([]);
});

it('settles an open invoice of the order at once when the money arrives after it', function () {
    $order = ($this->order)();
    $delivery = ($this->deliver)($order);

    $invoice = app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: DeliveryBilling::lines($delivery->fresh(['lines.product', 'lines.orderLine', 'customer']), DeliveryBilling::everythingUninvoiced($delivery), app(ResolveSellingPrice::class), Carbon::today()),
        createdBy: $this->owner,
        invoiceDate: '2026-09-14',
    );

    ($this->pay)($order, '1000')->assertSessionHasNoErrors();

    $receipt = CustomerReceipt::query()->sole();

    expect($receipt->allocations()->sole()->sales_invoice_id)->toBe($invoice->id)
        ->and($receipt->unallocated()->minor)->toBe(0)
        ->and($invoice->fresh()->outstanding()->minor)->toBe(82000)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and($order->fresh()->stillToPay()->minor)->toBe(82000);
});

it('owes back what a partly delivered prepaid parcel did not use, once the courier has answered', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    $delivery = ($this->deliver)($order);
    ($this->book)($delivery, '0');

    // With the courier, the whole parcel may yet be taken: nothing is owed back.
    expect(($this->props)($order)['payments']['refund_owed'])->toBeNull();

    $this->actingAs($this->staff)->post('/sales/consignments/'.Consignment::query()->sole()->id.'/outcome', [
        'outcome' => 'partially_delivered',
        'outcome_on' => '2026-09-14',
        'collected_amount' => '0',
        'lines' => [['delivery_line_id' => $delivery->lines()->first()->id, 'taken' => '1', 'written_off' => '0']],
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    // One jar at 850 and the whole 120 delivery charge, paid out of the advance.
    // The returned jar leaves the order reading "delivered" (C-SR-8), so nothing
    // more leaves against it and the 850 it did not use is the customer's.
    expect((float) $invoice->total)->toBe(970.0)
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(85000)
        ->and(($this->props)($order)['payments']['refund_owed'])->toBe('850.00')
        ->and(($this->props)($order)['payments']['still_to_pay'])->toBe('0.00')
        ->and(($this->reconciles)())->toBe([]);

    ($this->refund)($order, '850')->assertSessionHasNoErrors();

    expect($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and(($this->reconciles)())->toBe([]);
});

it('refunds goods returned from a prepaid invoice through the return, not the order', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    $delivery = ($this->deliver)($order);
    ($this->book)($delivery, '0');
    ($this->report)(Consignment::query()->sole(), 'delivered', '0')->assertSessionHasNoErrors();

    // A week later one jar comes back. The invoice was already paid from the
    // advance, so the return's credit has nothing to settle and becomes credit
    // on the customer's account - the existing return path, untouched by P1.
    $return = app(RecordSalesReturn::class)->handle(
        delivery: $delivery->fresh('lines'),
        lines: [['deliveryLine' => $delivery->fresh('lines')->lines->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
        createdBy: $this->owner,
    );

    expect($return->refundable()->minor)->toBe(85000)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and(($this->props)($order)['payments']['refund_owed'])->toBeNull()
        ->and(Ageing::receivables(Carbon::today())->rows->first()->onAccount->minor)->toBe(-85000)
        ->and(($this->reconciles)())->toBe([]);

    app(RecordCustomerRefund::class)->handle($return, $this->bkash, '850', $this->owner, '2026-09-14');

    expect(Ageing::receivables(Carbon::today())->total()->minor)->toBe(0)
        ->and(($this->reconciles)())->toBe([]);
});

it('owes back what an order closed short still holds', function () {
    // Two jars ordered, one delivered and invoiced by hand, the rest never sent.
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    $delivery = ($this->deliver)($order, '1');

    app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: DeliveryBilling::lines($delivery->fresh(['lines.product', 'lines.orderLine', 'customer']), DeliveryBilling::everythingUninvoiced($delivery), app(ResolveSellingPrice::class), Carbon::today()),
        createdBy: $this->owner,
        invoiceDate: '2026-09-14',
    );

    expect(($this->props)($order)['payments']['refund_owed'])->toBeNull();

    app(CloseSalesOrder::class)->handle($order->fresh(), $this->owner, 'Out of stock for the second jar');

    expect(($this->props)($order)['payments']['refund_owed'])->toBe('850.00')
        ->and(($this->props)($order)['payments']['still_to_pay'])->toBe('0.00');
});

it('refuses a payment above what the order still owes, into a courier account, or for a cancelled order', function () {
    $order = ($this->order)();

    ($this->pay)($order, '2000')->assertSessionHasErrors(['amount' => 'Order '.$order->order_number.' has only 1,820.00 left to pay.']);
    ($this->pay)($order, '100', into: $this->courier)->assertSessionHasErrors('money_account_id');

    ($this->pay)($order, '1820');
    ($this->pay)($order, '1')->assertSessionHasErrors(['amount' => 'Order '.$order->order_number.' is already paid in full.']);

    $second = ($this->order)();
    app(CancelSalesOrder::class)->handle($second);

    ($this->pay)($second, '100')->assertSessionHasErrors(['amount' => 'Order '.$second->order_number.' will not be delivered, so no payment is taken for it.']);

    expect(CustomerReceipt::query()->count())->toBe(1);
});

it('still records money a gateway already took for a cancelled order, and shows it owed back', function () {
    $order = ($this->order)();
    app(CancelSalesOrder::class)->handle($order);

    app(RecordOrderPayment::class)->handle($order, $this->bkash, '2026-09-14', '1820', $this->owner, moneyAlreadyTaken: true);

    expect(($this->props)($order)['payments']['refund_owed'])->toBe('1820.00')
        ->and(($this->props)($order)['payments']['can_take_payment'])->toBeFalse();
});

it('pays back what a cancelled order holds, and no more', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    app(CancelSalesOrder::class)->handle($order->fresh());

    expect(($this->props)($order)['payments']['refund_owed'])->toBe('1820.00');

    // Cash leaving the till is Owner/Manager only, as it is for a return.
    ($this->refund)($order, '1820', $this->staff)->assertForbidden();
    ($this->refund)($order, '1820.01')->assertSessionHasErrors(['amount' => 'Order '.$order->order_number.' holds only 1,820.00 of the customer\'s money.']);
    ($this->refund)($order, '1820')->assertSessionHasNoErrors();

    $refund = CustomerRefund::query()->sole();

    expect($refund->sales_order_id)->toBe($order->id)
        ->and($refund->sales_return_id)->toBeNull()
        ->and($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and(($this->props)($order)['payments']['refund_owed'])->toBeNull()
        // A cancelled order owes nothing, paid back or not (found on the walk).
        ->and(($this->props)($order)['payments']['still_to_pay'])->toBe('0.00')
        ->and(($this->posted)('cash_in_hand'))->toBe(0)
        ->and(($this->posted)('accounts_receivable'))->toBe(0)
        ->and(Ageing::receivables(Carbon::today())->total()->minor)->toBe(0)
        ->and(($this->reconciles)())->toBe([]);

    ($this->refund)($order, '1')->assertSessionHasErrors(['amount' => 'Order '.$order->order_number.' holds none of the customer\'s money.']);
});

it('does not let a refund leave an invoice to take money that was paid back', function () {
    $order = ($this->order)();
    ($this->pay)($order, '1820');
    ($this->refund)($order, '1000')->assertSessionHasNoErrors();

    $delivery = ($this->deliver)($order);
    ($this->book)($delivery, '1000');
    ($this->report)(Consignment::query()->sole(), 'delivered', '1000')->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    // 820 of the advance is left after the refund, and only that is applied.
    expect($invoice->outstanding()->minor)->toBe(0)
        ->and((int) round(((float) $invoice->allocations()->where('customer_receipt_id', CustomerReceipt::query()->whereNotNull('sales_order_id')->value('id'))->sum('amount')) * 100))->toBe(82000)
        ->and($order->fresh()->advanceHeld()->minor)->toBe(0)
        ->and(($this->reconciles)())->toBe([]);
});

it('takes money paid in advance off the customer exposure', function () {
    // An order the Owner records is approved on creation, so its 1,700 of goods
    // count (exposure has never counted a delivery charge).
    $order = ($this->order)();

    expect(($this->props)($order)['exposure']['total'])->toBe('1700.00');

    ($this->pay)($order, '500');
    $after = ($this->props)($order)['exposure'];

    expect($after['paid_in_advance'])->toBe('500.00')
        ->and($after['total'])->toBe('1200.00');
});

it('prints what a receipt holds for an order, and the invoice date as a Dhaka date', function () {
    $order = ($this->order)();
    ($this->pay)($order, '500');
    $receipt = CustomerReceipt::query()->sole();

    $print = $this->actingAs($this->owner)->get("/sales/receipts/{$receipt->id}/print")->original->getData()['page']['props'];

    expect($print['heldFor'])->toBe(['id' => $order->id, 'order_number' => $order->order_number, 'amount' => '500.00']);

    $slip = $this->actingAs($this->owner)->get("/sales/receipts/{$receipt->id}/slip")->original->getData()['page']['props'];

    expect(json_encode($slip))->toContain('Paid in advance for '.$order->order_number);

    // Delivered and invoiced on the 14th: the settling line prints the 14th.
    $delivery = ($this->deliver)($order);
    ($this->book)($delivery, '1320');
    ($this->report)(Consignment::query()->sole(), 'delivered', '1320');

    $print = $this->actingAs($this->owner)->get("/sales/receipts/{$receipt->id}/print")->original->getData()['page']['props'];

    expect($print['heldFor'])->toBeNull()
        ->and($print['receipt']['allocations'][0]['invoice']['invoice_date'])->toBe('2026-09-14');
});

it('refuses money typed into a payment gateway account by hand, and a refund paid out of one', function () {
    // SSLCommerz slice P2a: a gateway's account fills only from a payment the
    // gateway proved, the courier rule.
    $gateway = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);

    $order = ($this->order)();

    ($this->pay)($order, '500', into: $gateway)
        ->assertSessionHasErrors(['money_account_id' => "SSLCommerz is a payment gateway's account. Money reaches it when the gateway confirms a payment, not as a receipt."]);

    ($this->pay)($order, '1820');
    app(CancelSalesOrder::class)->handle($order->fresh());

    $this->actingAs($this->owner)->post("/sales/orders/{$order->id}/refunds", [
        'money_account_id' => $gateway->id, 'amount' => '100', 'refunded_on' => '2026-09-14',
    ])->assertSessionHasErrors(['money_account_id' => 'SSLCommerz holds money for somebody else. Pay the customer back from a bank, cash or mobile wallet account.']);

    expect(CustomerReceipt::query()->count())->toBe(1)
        ->and(CustomerRefund::query()->count())->toBe(0)
        ->and(collect(($this->props)($order)['money_accounts'])->pluck('name')->all())->toBe(['bKash merchant']);
});

it('sends an order that was waiting for its payment for approval once a payment is recorded on it', function () {
    // An online order the customer paid by bKash transfer instead (SSLCommerz slice
    // P2c): it was never sent for approval, and recording the money sends it.
    $order = ($this->order)();
    $order->approvalRequest->delete();

    ($this->pay)($order, '1820')->assertRedirect()->assertSessionHasNoErrors();

    expect($order->fresh('approvalRequest')->approvalRequest?->status)->toBe(ApprovalStatus::Pending);
});

it('refuses money held on account that names no order', function () {
    app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $this->bkash,
        receivedOn: '2026-09-14',
        allocations: [],
        createdBy: $this->owner,
        heldForOrder: '100',
    );
})->throws(ValidationException::class, 'Money not taken by an invoice must be paid for a named order.');
