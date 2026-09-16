<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Commerce\Actions\BookGatewayPayment;
use App\Modules\Commerce\Actions\BookGatewayRefund;
use App\Modules\Commerce\Actions\CheckGatewayRefund;
use App\Modules\Commerce\Actions\RequestGatewayRefund;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Enums\GatewayRefundStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\GatewayRefund;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCustomerRefund;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SSLCommerz slice P4: sending a customer's money back through SSLCommerz
 * (Joy, 16 September: the Owner only, partial refunds allowed).
 *
 * ⭐ The money is reserved on the order the moment the request is written; the
 * books move only when SSLCommerz says it is back with the customer; nothing a
 * retry does can refund twice.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Refund Co', 'slug' => 'refund-co']);
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

    Carbon::setTestNow('2026-09-14 10:00:00');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    setPermissionsTeamId($this->business->id);
    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    // ⚠ A Manager may refund by hand (sales.returns.refund) and still may NOT
    // refund through SSLCommerz.
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Online buyer', 'type' => CustomerType::Retail, 'phone' => '01711000000']);
    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);

    $this->gatewayAccount = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'provider' => 'SSLCommerz', 'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);
    $this->bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    $this->secret = 'sandboxpass@refund';
    $this->gateway = StorePaymentGateway::create([
        'store_id' => $this->store->id, 'provider' => StorePaymentGateway::SSLCOMMERZ, 'is_enabled' => true,
        'merchant_id' => 'bioni0test', 'merchant_secret' => $this->secret, 'is_live' => false,
        'money_account_id' => $this->gatewayAccount->id,
    ]);

    // A shop order of 1,820 (2 x 850 + 120 delivery), cancelled, whose online
    // payment of 1,820 (SSLCommerz kept 45.50) was booked: 1,820 refund owed.
    $this->paidOrder = function (string $bankTranId = 'BANK789'): array {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => '2']],
            createdBy: $this->owner,
            origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: '120'),
        );
        $order->forceFill([
            'store_id' => $this->store->id, 'channel' => SalesChannel::Website,
            'shop_reference' => 'BNC-20260914-REFND'.SalesOrder::count(), 'payment_method' => 'sslcommerz',
        ])->save();

        app(CancelSalesOrder::class)->handle($order->fresh());

        $payment = GatewayPayment::create([
            'business_id' => $this->business->id, 'store_id' => $this->store->id, 'sales_order_id' => $order->id,
            'provider' => StorePaymentGateway::SSLCOMMERZ, 'tran_id' => $order->shop_reference.'-1', 'attempt' => 1,
            'amount' => '1820.00', 'is_live' => false,
        ]);
        $payment->forceFill([
            'status' => GatewayPaymentStatus::Proven, 'val_id' => 'VAL'.$payment->id, 'bank_tran_id' => $bankTranId,
            'amount_paid' => '1820.00', 'store_amount' => '1774.50', 'proven_at' => Carbon::now(),
        ])->save();

        $payment = app(BookGatewayPayment::class)->handle($payment);
        app(BusinessContext::class)->set($this->business->id);

        return [$order->fresh(), $payment];
    };

    // What SSLCommerz's refund API answers: `start` to a refund request, `status`
    // to a status question. Either may be null (no answer: HTTP 500).
    $this->answers = ['start' => null, 'status' => null];
    $this->sslcommerz = function (?array $start, ?array $status = null) {
        $this->answers = ['start' => $start, 'status' => $status];
    };
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (! str_contains($request->url(), 'sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php')) {
            return null;
        }

        $answer = isset($request['refund_ref_id']) ? $this->answers['status'] : $this->answers['start'];

        return $answer === null ? Http::response('Service unavailable', 500) : Http::response($answer);
    });

    $this->accepted = ['APIConnect' => 'DONE', 'bank_tran_id' => 'BANK789', 'trans_id' => 'x', 'refund_ref_id' => 'REF1', 'status' => 'success', 'errorReason' => ''];
    $this->processing = ['APIConnect' => 'DONE', 'refund_ref_id' => 'REF1', 'status' => 'processing', 'initiated_on' => '2026-09-14 10:00:00', 'refunded_on' => ''];
    $this->refunded = ['APIConnect' => 'DONE', 'refund_ref_id' => 'REF1', 'status' => 'refunded', 'initiated_on' => '2026-09-14 10:00:00', 'refunded_on' => '2026-09-16 12:30:00'];

    $this->request = fn (GatewayPayment $payment, string $amount = '1820.00', string $remarks = 'Order cancelled') => app(RequestGatewayRefund::class)
        ->handle($payment->fresh(), $amount, $remarks, $this->owner);

    $this->byHand = fn (SalesOrder $order, string $amount) => app(RecordCustomerRefund::class)
        ->forOrder($order->fresh(), $this->bkash, $amount, $this->owner, '2026-09-14');

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->receivableProblems = fn () => array_values(array_filter(
        SubledgerReconciliation::reconcileCurrentBusiness()['problems'],
        fn (string $problem) => str_contains($problem, 'Receivable'),
    ));
});

afterEach(fn () => Carbon::setTestNow());

it('asks SSLCommerz under the refund\'s own id, reserves the money, and posts nothing yet', function () {
    [$order, $payment] = ($this->paidOrder)();
    ($this->sslcommerz)($this->accepted, $this->processing);
    $before = [($this->posted)('gateway_receivable'), ($this->posted)('accounts_receivable')];

    $refund = ($this->request)($payment);

    expect($refund->status)->toBe(GatewayRefundStatus::Processing)
        ->and($refund->refund_trans_id)->toBe($payment->tran_id.'-R1')
        ->and($refund->refund_ref_id)->toBe('REF1')
        ->and($refund->gateway_status)->toBe('processing')
        ->and($refund->problem)->toBeNull()
        // Reserved: nothing left for anyone to pay back, and nothing still to pay.
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00')
        ->and($order->fresh()->paidTowards()->toTaka())->toBe('0.00')
        ->and(CustomerRefund::query()->count())->toBe(0)
        ->and([($this->posted)('gateway_receivable'), ($this->posted)('accounts_receivable')])->toBe($before)
        ->and($before)->toBe([177450, -182000]);

    Http::assertSent(fn (Request $request) => isset($request['refund_trans_id'])
        && $request['refund_trans_id'] === $payment->tran_id.'-R1'
        && $request['bank_tran_id'] === 'BANK789'
        && $request['refund_amount'] === '1820.00'
        && $request['refund_remarks'] === 'Order cancelled'
        && $request['store_id'] === 'bioni0test'
        && $request['store_passwd'] === $this->secret);
    Http::assertSent(fn (Request $request) => ($request['refund_ref_id'] ?? null) === 'REF1');
});

it('books a confirmed refund once, out of the gateway account, however often it is checked', function () {
    [$order, $payment] = ($this->paidOrder)();
    ($this->sslcommerz)($this->accepted, $this->processing);
    $refund = ($this->request)($payment);

    // Two days later SSLCommerz has finished it.
    Carbon::setTestNow('2026-09-16 13:00:00');
    ($this->sslcommerz)($this->accepted, $this->refunded);
    $this->artisan('commerce:gateway-refunds')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);
    app(CheckGatewayRefund::class)->handle($refund);
    $this->artisan('commerce:gateway-refunds')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);

    $refund = $refund->fresh();
    $booked = CustomerRefund::query()->sole();

    expect($refund->status)->toBe(GatewayRefundStatus::Refunded)
        ->and($refund->customer_refund_id)->toBe($booked->id)
        ->and($refund->refunded_at->format('Y-m-d H:i'))->toBe('2026-09-16 12:30')
        ->and($booked->money_account_id)->toBe($this->gatewayAccount->id)
        ->and($booked->sales_order_id)->toBe($order->id)
        ->and($booked->amountMoney()->toTaka())->toBe('1820.00')
        ->and($booked->reference)->toBe('REF1')
        ->and($booked->refunded_on->toDateString())->toBe('2026-09-16')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00')
        // The customer is square; SSLCommerz keeps its 45.50 and nets it from a
        // later deposit, so 1146 ends at minus the fee.
        ->and(($this->posted)('accounts_receivable'))->toBe(0)
        ->and(($this->posted)('gateway_receivable'))->toBe(-4550)
        ->and(($this->posted)('payment_gateway_charge'))->toBe(4550)
        ->and(($this->receivableProblems)())->toBe([]);
});

it('allows part refunds, capped by what the payment has left and what the order holds', function () {
    [$order, $payment] = ($this->paidOrder)();
    ($this->sslcommerz)($this->accepted, $this->processing);

    ($this->request)($payment, '1000.00');

    // 820 left on the payment and on the order.
    expect(fn () => ($this->request)($payment, '900.00'))
        ->toThrow(ValidationException::class, 'has only 820.00 left to refund through SSLCommerz');

    // By hand, the reserved 1,000 cannot be paid back again; the 820 can.
    expect(fn () => ($this->byHand)($order, '900.00'))
        ->toThrow(ValidationException::class, "holds only 820.00 of the customer's money");
    ($this->byHand)($order, '820.00');

    // The payment still has 820 it could give, the order holds nothing.
    expect(fn () => ($this->request)($payment, '1.00'))
        ->toThrow(ValidationException::class, "holds none of the customer's money");

    expect(GatewayRefund::query()->count())->toBe(1)
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00');
});

it('releases the money when SSLCommerz refuses the request or cancels the refund', function () {
    [$order, $payment] = ($this->paidOrder)();

    ($this->sslcommerz)(['APIConnect' => 'INVALID_REQUEST', 'errorReason' => 'Invalid bank transaction id']);
    $refused = ($this->request)($payment);

    expect($refused->status)->toBe(GatewayRefundStatus::Failed)
        ->and($refused->problem)->toBe('SSLCommerz refused the request (INVALID_REQUEST): Invalid bank transaction id')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('1820.00');

    ($this->sslcommerz)(['APIConnect' => 'DONE', 'refund_ref_id' => 'REF2', 'status' => 'success'], ['APIConnect' => 'DONE', 'refund_ref_id' => 'REF2', 'status' => 'cancelled', 'errorReason' => 'Card issuer declined']);
    $cancelled = ($this->request)($payment);

    expect($cancelled->status)->toBe(GatewayRefundStatus::Cancelled)
        ->and($cancelled->refund_trans_id)->toBe($payment->tran_id.'-R2')
        ->and($cancelled->problem)->toBe('SSLCommerz cancelled the refund: Card issuer declined')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('1820.00')
        ->and(CustomerRefund::query()->count())->toBe(0);

    // Free to pay back another way now.
    ($this->byHand)($order, '1820.00');
    expect($order->fresh()->advanceHeld()->toTaka())->toBe('0.00');
});

it('keeps asking under the same id when SSLCommerz does not answer, and never starts a second refund', function () {
    [$order, $payment] = ($this->paidOrder)();

    ($this->sslcommerz)(null);
    $refund = ($this->request)($payment);

    expect($refund->status)->toBe(GatewayRefundStatus::Requested)
        ->and($refund->problem)->toBe('SSLCommerz did not answer. It is asked again at the next check.')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00');

    // An answer that cannot be read moves nothing either.
    ($this->sslcommerz)(['APIConnect' => 'DONE', 'status' => 'pending?', 'refund_ref_id' => 'REFX']);
    app(CheckGatewayRefund::class)->handle($refund);
    expect($refund->fresh()->status)->toBe(GatewayRefundStatus::Requested);

    ($this->sslcommerz)(['APIConnect' => 'DONE', 'refund_ref_id' => 'REF1', 'status' => 'processing'], $this->processing);
    $this->artisan('commerce:gateway-refunds')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);

    $starts = Http::recorded(fn (Request $request) => isset($request['refund_trans_id']));

    expect($refund->fresh()->status)->toBe(GatewayRefundStatus::Processing)
        ->and(GatewayRefund::query()->count())->toBe(1)
        ->and($starts)->toHaveCount(3)
        ->and($starts->map(fn ($pair) => $pair[0]['refund_trans_id'])->unique()->values()->all())->toBe([$payment->tran_id.'-R1']);
});

it('ends a request SSLCommerz says is another refund already in progress', function () {
    [$order, $payment] = ($this->paidOrder)();
    ($this->sslcommerz)($this->accepted, $this->processing);
    ($this->request)($payment, '500.00');

    // SSLCommerz answers the second request with the FIRST refund's reference.
    $second = ($this->request)($payment, '300.00');

    expect($second->status)->toBe(GatewayRefundStatus::Failed)
        ->and($second->refund_ref_id)->toBeNull()
        ->and($second->problem)->toContain('is already in progress')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('1320.00');
});

it('books a refund on today\'s date when SSLCommerz reports it ahead of this server\'s clock', function () {
    [, $payment] = ($this->paidOrder)();
    // The clock still reads 14 September; SSLCommerz says the 16th.
    ($this->sslcommerz)($this->accepted, $this->refunded);

    $refund = ($this->request)($payment);

    expect($refund->status)->toBe(GatewayRefundStatus::Refunded)
        ->and($refund->refunded_at->toDateString())->toBe('2026-09-14')
        ->and(CustomerRefund::query()->sole()->refunded_on->toDateString())->toBe('2026-09-14');

    // The real sandbox's empty date (seen 16 September) is no date at all.
    [, $second] = ($this->paidOrder)('BANK790');
    ($this->sslcommerz)(['APIConnect' => 'DONE', 'refund_ref_id' => 'REF9', 'status' => 'success'], ['APIConnect' => 'DONE', 'refund_ref_id' => 'REF9', 'status' => 'refunded', 'refunded_on' => '0000-00-00 00:00:00']);

    expect(($this->request)($second)->refunded_at->toDateString())->toBe('2026-09-14');
});

it('keeps a confirmed refund that cannot be booked, and books it once the books allow', function () {
    [$order, $payment] = ($this->paidOrder)();
    Carbon::setTestNow('2026-09-16 13:00:00');
    ($this->sslcommerz)($this->accepted, $this->refunded);

    AccountingPeriod::closeThrough(Carbon::parse('2026-09-16'), $this->owner, 'September review');

    $refund = ($this->request)($payment);

    expect($refund->status)->toBe(GatewayRefundStatus::Confirmed)
        ->and($refund->problem)->toStartWith('Refunded but not yet booked:')
        ->and(CustomerRefund::query()->count())->toBe(0)
        // Still reserved: SSLCommerz has sent it.
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('0.00');

    AccountingPeriod::current()->unlock($this->owner, 'Book the SSLCommerz refund');
    app(CheckGatewayRefund::class)->handle($refund);

    expect($refund->fresh()->status)->toBe(GatewayRefundStatus::Refunded)
        ->and($refund->fresh()->problem)->toBeNull()
        ->and(CustomerRefund::query()->count())->toBe(1);

    // A copy read while it was still confirmed (a second worker, a slow page)
    // books nothing more and leaves no false note behind.
    expect($refund->status)->toBe(GatewayRefundStatus::Confirmed);
    app(BookGatewayRefund::class)->handle($refund);

    expect(CustomerRefund::query()->count())->toBe(1)
        ->and($refund->fresh()->status)->toBe(GatewayRefundStatus::Refunded)
        ->and($refund->fresh()->problem)->toBeNull()
        ->and($refund->fresh()->refund_ref_id)->toBe('REF1');
});

it('refuses a payment not booked, one taken on the other store, and a refund without a reason', function () {
    [$order, $payment] = ($this->paidOrder)();
    ($this->sslcommerz)($this->accepted, $this->processing);

    expect(fn () => ($this->request)($payment, '100.00', '   '))
        ->toThrow(ValidationException::class, 'Say why the money is going back.');

    $this->gateway->update(['is_live' => true]);
    expect(fn () => ($this->request)($payment))
        ->toThrow(ValidationException::class, 'was paid on the SSLCommerz sandbox store, and the shop is now set to live');
    $this->gateway->update(['is_live' => false]);

    $payment->forceFill(['status' => GatewayPaymentStatus::Proven])->save();
    expect(fn () => ($this->request)($payment))
        ->toThrow(ValidationException::class, 'is not a booked payment');

    [, $noBankId] = ($this->paidOrder)('');
    expect(fn () => ($this->request)($noBankId))
        ->toThrow(ValidationException::class, 'gave no bank transaction id');

    Http::assertNothingSent();
    expect(GatewayRefund::query()->count())->toBe(0);
});

it('never books a gateway refund that SSLCommerz has not confirmed, nor one for another order', function () {
    [$order, $payment] = ($this->paidOrder)();
    [$other] = ($this->paidOrder)('BANK790');
    ($this->sslcommerz)($this->accepted, $this->processing);
    $refund = ($this->request)($payment);

    $book = fn (SalesOrder $for, string $amount = '1820.00') => app(RecordCustomerRefund::class)->forOrder(
        $for->fresh(), $this->gatewayAccount, $amount, $this->owner, '2026-09-14', throughGateway: $refund->fresh(),
    );

    // Processing, not confirmed: the gateway account pays nobody.
    expect(fn () => $book($order))->toThrow(ValidationException::class, 'holds money for somebody else');

    $refund->forceFill(['status' => GatewayRefundStatus::Confirmed])->save();
    expect(fn () => $book($other))->toThrow(ValidationException::class, 'holds money for somebody else')
        ->and(fn () => $book($order, '1000.00'))->toThrow(ValidationException::class, 'booked for exactly what SSLCommerz was asked to send back');

    expect(CustomerRefund::query()->count())->toBe(0);
});

it('is the Owner\'s alone, and only for a payment of the order named', function () {
    [$order, $payment] = ($this->paidOrder)();
    [$other] = ($this->paidOrder)('BANK790');
    ($this->sslcommerz)($this->accepted, $this->processing);

    $send = fn (User $as, SalesOrder $for) => $this->actingAs($as)->post("/sales/orders/{$for->id}/gateway-refunds", [
        'gateway_payment_id' => $payment->id, 'amount' => '1820.00', 'remarks' => 'Order cancelled',
    ]);

    $send($this->manager, $order)->assertForbidden();
    $send($this->owner, $other)->assertNotFound();
    expect(GatewayRefund::query()->count())->toBe(0);

    $send($this->owner, $order)->assertRedirect()->assertSessionHas('success');
    $refund = GatewayRefund::query()->sole();

    $this->actingAs($this->manager)->post("/sales/orders/{$order->id}/gateway-refunds/{$refund->id}/check")->assertForbidden();
    $this->actingAs($this->owner)->post("/sales/orders/{$other->id}/gateway-refunds/{$refund->id}/check")->assertNotFound();
    $this->actingAs($this->owner)->post("/sales/orders/{$order->id}/gateway-refunds/{$refund->id}/check")->assertRedirect();

    $ownerView = $this->actingAs($this->owner)->get('/sales/orders/'.$order->id)->original->getData()['page']['props'];
    $managerView = $this->actingAs($this->manager)->get('/sales/orders/'.$order->id)->original->getData()['page']['props'];

    expect($ownerView['can_gateway_refund'])->toBeTrue()
        ->and($managerView['can_gateway_refund'])->toBeFalse()
        ->and($ownerView['payments']['refunding'])->toBe('1820.00')
        ->and($ownerView['payments']['refund_owed'])->toBeNull()
        ->and($ownerView['payments']['online'][0]['refundable'])->toBe('0.00')
        ->and($ownerView['payments']['gateway_refunds'][0])->toMatchArray([
            'refund_trans_id' => $payment->tran_id.'-R1', 'status' => 'processing', 'in_flight' => true, 'refund_ref_id' => 'REF1',
        ]);
});
