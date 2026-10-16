<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Enums\PageType;
use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Page;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\RecordOrderPayment;
use App\Modules\Sales\Actions\SendPaidOrderForApproval;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Attention\Sources\GatewayPaymentsUnbookedSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * SSLCommerz slice P2c: paying for a shop order online.
 *
 * ⭐ Joy, 13 September: cash on delivery or full online payment; an unpaid online
 * order is hidden from approvers until the payment is proven, and cancelled after
 * 24 hours.
 */
const ONLINE_SECRET = 'shopsandbox@ssl';
const ONLINE_PAGE = 'https://sandbox.sslcommerz.com/EasyCheckOut/testshop0001';

/**
 * A callback carrying SSLCommerz's signature for this store's password.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function onlineSigned(array $fields): array
{
    $signed = $fields;
    $signed['store_passwd'] = md5(ONLINE_SECRET);
    ksort($signed);

    $pairs = [];

    foreach ($signed as $key => $value) {
        $pairs[] = $key.'='.$value;
    }

    return $fields + ['verify_key' => implode(',', array_keys($fields)), 'verify_sign' => md5(implode('&', $pairs))];
}

function onlineToken($response): string
{
    return $response->getCookie(CurrentCart::cookieName(test()->store))->getValue();
}

function onlineBrowser(string $token)
{
    return test()->withCookie(CurrentCart::cookieName(test()->store), $token);
}

function onlineBasket(): string
{
    $token = onlineToken(test()->post('http://shop.test/cart/lines', ['product' => test()->product->id, 'quantity' => 1]));
    onlineBrowser($token)->post('http://shop.test/cart/delivery', ['district' => 'Bagerhat']);

    return $token;
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Carbon::setTestNow('2026-09-14 10:00:00');

    // ⛔ Never the real gateway from a test, not even its sandbox.
    Http::preventStrayRequests();

    $retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-500', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    ProductPrice::create(['product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    $this->store = Store::create([
        'key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC',
        'theme_key' => 'bionic-classic', 'price_group_id' => $retail->id,
    ]);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'shop.test', 'is_primary' => true]);
    $this->store->forceFill(['status' => 'live'])->save();
    $this->store->products()->create(['product_id' => $this->product->id, 'slug' => 'sundarban-honey'])->publish();

    Page::create(['store_id' => $this->store->id, 'type' => PageType::Home, 'slug' => 'home', 'title' => 'Welcome'])
        ->forceFill(['published_at' => now()])->save();

    $inside = ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Inside Dhaka', 'charge' => '60']);
    ShippingZone::create(['store_id' => $this->store->id, 'name' => 'Outside Dhaka', 'charge' => '120'])->makeDefault();
    DistrictShippingZone::create(['store_id' => $this->store->id, 'district' => 'Dhaka', 'shipping_zone_id' => $inside->id]);

    $this->gatewayAccount = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);
    $this->gateway = StorePaymentGateway::create([
        'store_id' => $this->store->id, 'provider' => StorePaymentGateway::SSLCOMMERZ, 'is_enabled' => true,
        'merchant_id' => 'bioni0test', 'merchant_secret' => ONLINE_SECRET, 'money_account_id' => $this->gatewayAccount->id,
    ]);

    // 850 of honey and 120 delivery to Bagerhat.
    $this->address = [
        'name' => 'Karim Uddin', 'phone' => '01711000000', 'thana' => 'Fakirhat', 'address_line' => 'House 4, Road 2',
    ];

    $this->fakeGateway = function (string $tranId, array $overrides = []) {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => ONLINE_PAGE]),
            'sandbox.sslcommerz.com/validator/*' => Http::response(array_merge([
                'status' => 'VALID', 'tran_id' => $tranId, 'val_id' => 'VAL900', 'amount' => '970.00', 'store_amount' => '945.75',
                'currency' => 'BDT', 'bank_tran_id' => 'BKASH55', 'card_type' => 'BKASH-BKash', 'risk_level' => '0',
            ], $overrides)),
        ]);
    };

    $this->placeOnline = function (): SalesOrder {
        $token = onlineBasket();
        onlineBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'payment_method' => 'sslcommerz']);

        return SalesOrder::query()->latest('id')->firstOrFail();
    };

    // Place an online order, then fake the Validation API for its first attempt.
    $this->placeOnlineThenFake = function (): SalesOrder {
        Http::fake(['sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => ONLINE_PAGE])]);
        $order = ($this->placeOnline)();
        Http::fake(['sandbox.sslcommerz.com/validator/*' => Http::response([
            'status' => 'VALID', 'tran_id' => $order->shop_reference.'-1', 'val_id' => 'VAL900', 'amount' => '970.00',
            'store_amount' => '945.75', 'currency' => 'BDT', 'bank_tran_id' => 'BKASH55',
        ])]);

        return $order;
    };

    $this->paid = fn (SalesOrder $order, string $path = 'success') => test()->post('http://shop.test/payments/sslcommerz/'.$path, onlineSigned([
        'tran_id' => $order->shop_reference.'-1', 'val_id' => 'VAL900', 'amount' => '970.00', 'status' => 'VALID', 'currency' => 'BDT',
    ]));
});

afterEach(fn () => Carbon::setTestNow());

it('offers paying online at checkout only while the shop can record it', function () {
    $token = onlineBasket();

    onlineBrowser($token)->get('http://shop.test/checkout')->assertOk()->assertSee('value="sslcommerz"', false);

    $this->gatewayAccount->update(['is_active' => false]);

    onlineBrowser($token)->get('http://shop.test/checkout')->assertOk()->assertDontSee('value="sslcommerz"', false);
});

it('takes a customer who chooses to pay online straight to SSLCommerz, and asks nobody to confirm an unpaid order', function () {
    ($this->fakeGateway)('BNC-X-1');
    $token = onlineBasket();

    $response = onlineBrowser($token)->post('http://shop.test/checkout', [...$this->address, 'payment_method' => 'sslcommerz']);

    $order = SalesOrder::query()->sole();
    $attempt = GatewayPayment::query()->sole();

    $response->assertRedirect(ONLINE_PAGE);

    expect($order->payment_method)->toBe('sslcommerz')
        ->and($order->created_by)->toBe(SystemActor::user()->id)
        // ⭐ Not in the approval queue until it is paid.
        ->and($order->approvalRequest)->toBeNull()
        ->and($attempt->sales_order_id)->toBe($order->id)
        ->and($attempt->amountMoney()->toTaka())->toBe('970.00')
        ->and($attempt->status)->toBe(GatewayPaymentStatus::Initiated);

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'gwprocess')
        && $request['success_url'] === 'http://shop.test/payments/sslcommerz/success'
        && $request['ipn_url'] === 'http://shop.test/payments/sslcommerz/ipn');
});

it('keeps the order and offers to try again when the payment page cannot open', function () {
    Http::fake(['sandbox.sslcommerz.com/gwprocess/*' => Http::sequence()
        ->push(['status' => 'FAILED', 'failedreason' => 'Store is De-active'])
        ->push(['status' => 'SUCCESS', 'GatewayPageURL' => ONLINE_PAGE])]);

    $order = ($this->placeOnline)();

    $page = $this->get('http://shop.test/orders/'.$order->shop_reference);

    $page->assertOk()->assertSee('Your order is saved')->assertSee('Pay BDT 970.00 now');

    $this->post('http://shop.test/orders/'.$order->shop_reference.'/pay')->assertRedirect(ONLINE_PAGE);

    expect(GatewayPayment::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['failed', 'initiated']);
});

it('books the payment when SSLCommerz comes back without any cookie, then puts the order in front of approvers', function () {
    $order = ($this->placeOnlineThenFake)();

    // A fresh request with no session and no basket cookie, as a cross-site POST is.
    ($this->paid)($order)->assertRedirect('/orders/'.$order->shop_reference);

    $order = $order->fresh('approvalRequest');

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and(CustomerReceipt::query()->sole()->money_account_id)->toBe($this->gatewayAccount->id)
        ->and($order->approvalRequest->status)->toBe(ApprovalStatus::Pending);

    $this->get('http://shop.test/orders/'.$order->shop_reference)
        ->assertOk()->assertSee('Paid online: BDT 970.00')->assertDontSee('Pay BDT 970.00 now');
});

it('answers the server notification with a plain OK and books a payment once', function () {
    $order = ($this->placeOnlineThenFake)();

    ($this->paid)($order, 'ipn')->assertOk()->assertSeeText('OK');
    ($this->paid)($order, 'ipn')->assertOk();
    ($this->paid)($order, 'success');

    expect(CustomerReceipt::query()->count())->toBe(1);
});

it('leaves an order waiting after a failed payment, and says what happened', function () {
    $order = ($this->placeOnlineThenFake)();

    $this->post('http://shop.test/payments/sslcommerz/fail', onlineSigned(['tran_id' => $order->shop_reference.'-1', 'status' => 'FAILED']))
        ->assertRedirect('/orders/'.$order->shop_reference);

    $this->get('http://shop.test/orders/'.$order->shop_reference)
        ->assertOk()->assertSee('Last try: payment failed')->assertSee('Pay BDT 970.00 now');

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Open)
        ->and($order->fresh()->approvalRequest)->toBeNull();
});

it('refuses to confirm an order that is still waiting for its payment', function () {
    $order = ($this->placeOnlineThenFake)();

    expect(fn () => app(ApproveSalesOrder::class)->handle($order, SystemActor::user()))
        ->toThrow(RuntimeException::class, "Sales order {$order->order_number} is waiting for its online payment and is not ready to confirm.");
});

it('exempts only the gateway callbacks from CSRF checks', function () {
    // ⚠ The framework skips CSRF while tests run, so a missing exemption would pass
    // every other test and fail every real payment. The list is asserted instead.
    $csrf = app(ValidateCsrfToken::class);
    $excepted = new ReflectionMethod($csrf, 'inExceptArray');

    expect($excepted->invoke($csrf, Request::create('/payments/sslcommerz/ipn', 'POST')))->toBeTrue()
        ->and($excepted->invoke($csrf, Request::create('/payments/sslcommerz/success', 'POST')))->toBeTrue()
        ->and($excepted->invoke($csrf, Request::create('/checkout', 'POST')))->toBeFalse()
        ->and($excepted->invoke($csrf, Request::create('/orders/BNC-20260914-ABCDEFGH/pay', 'POST')))->toBeFalse();
});

it('cancels an online order left unpaid for a day, and never one that was paid', function () {
    // One faked SSLCommerz for the whole test: it opens every page, and the
    // Validation API confirms only the second order's payment.
    $paidTranId = null;
    Http::fake(function (ClientRequest $request) use (&$paidTranId) {
        return str_contains($request->url(), 'gwprocess')
            ? Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => ONLINE_PAGE])
            : Http::response(['status' => 'VALID', 'tran_id' => $paidTranId, 'val_id' => 'VAL901', 'amount' => '970.00', 'store_amount' => '945.75', 'currency' => 'BDT']);
    });

    $unpaid = ($this->placeOnline)();
    $paid = ($this->placeOnline)();
    $paidTranId = $paid->shop_reference.'-1';

    test()->post('http://shop.test/payments/sslcommerz/success', onlineSigned([
        'tran_id' => $paid->shop_reference.'-1', 'val_id' => 'VAL901', 'amount' => '970.00', 'status' => 'VALID',
    ]));

    expect(GatewayPayment::query()->where('sales_order_id', $paid->id)->sole()->status)->toBe(GatewayPaymentStatus::Paid);

    Carbon::setTestNow('2026-09-15 09:59:00');
    $this->artisan('commerce:online-payments')->assertSuccessful();
    expect($unpaid->fresh()->status)->toBe(SalesOrderStatus::Open);

    Carbon::setTestNow('2026-09-15 10:01:00');
    $this->artisan('commerce:online-payments')->assertSuccessful();
    // The command leaves the tenant when it is done, as every business-iterating
    // command here does; this test reads as the business again.
    app(BusinessContext::class)->set($this->business->id);

    expect($unpaid->fresh()->status)->toBe(SalesOrderStatus::Cancelled)
        ->and(GatewayPayment::query()->where('sales_order_id', $unpaid->id)->sole()->status)->toBe(GatewayPaymentStatus::Expired)
        ->and($paid->fresh()->status)->toBe(SalesOrderStatus::Open);

    $this->get('http://shop.test/orders/'.$unpaid->shop_reference)->assertOk()->assertSee('This order was cancelled');
});

it('books on the next run a payment the gateway proved that could not be booked at the time', function () {
    $order = ($this->placeOnlineThenFake)();

    $this->gatewayAccount->update(['account_id' => null]);
    ($this->paid)($order);

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Proven);

    // ⛔ A day later, still not bookable: the customer HAS paid, so the order is
    // never cancelled as unpaid, whatever the clock says.
    Carbon::setTestNow('2026-09-15 11:00:00');
    $this->artisan('commerce:online-payments')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Open)
        ->and(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Proven);

    $this->gatewayAccount->update(['account_id' => Account::bySlug('gateway_receivable')->id]);
    $this->artisan('commerce:online-payments')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and(CustomerReceipt::query()->count())->toBe(1);
});

it('never expires an online order the customer paid another way, recorded by hand', function () {
    // Found on the walk: the customer pays by bKash transfer instead of the page,
    // and somebody records it on the order - what the order screen's payment form
    // does (the controller wiring is tested in OrderPaymentTest).
    $order = ($this->placeOnlineThenFake)();

    $bkash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'bKash merchant', 'type' => MoneyAccountType::Mfs,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    app(RecordOrderPayment::class)->handle($order, $bkash, '2026-09-14', '970', SystemActor::user(), 'TRXHAND1');
    app(SendPaidOrderForApproval::class)->handle($order->fresh(), SystemActor::user());

    expect($order->fresh('approvalRequest')->approvalRequest?->status)->toBe(ApprovalStatus::Pending);

    // Even an order that somehow missed the queue is not expired while it holds the customer's money.
    $order->fresh('approvalRequest')->approvalRequest->delete();

    Carbon::setTestNow('2026-09-16 10:00:00');
    $this->artisan('commerce:online-payments')->assertSuccessful();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Open);
});

it('runs the online payment housekeeping every ten minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'commerce:online-payments'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');
});

// ⚠ Bug hunt 16 Oct 2026: a payment proven on a day whose month was later
// closed was retried every ten minutes for ever, and nobody was told.
it('stops retrying a proven payment whose month was closed, and lists it in Needs attention', function () {
    $order = ($this->placeOnlineThenFake)();
    $this->gatewayAccount->update(['account_id' => null]);
    ($this->paid)($order);

    Carbon::setTestNow('2026-10-05 10:00:00');
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), User::factory()->create());
    $this->gatewayAccount->update(['account_id' => Account::bySlug('gateway_receivable')->id]);

    $this->artisan('commerce:online-payments')->assertSuccessful();
    app(BusinessContext::class)->set($this->business->id);

    $payment = GatewayPayment::query()->sole();
    expect($payment->status)->toBe(GatewayPaymentStatus::Proven)
        ->and($payment->problem)->toContain('closed month');

    $finance = User::factory()->create();
    $items = (new GatewayPaymentsUnbookedSource)->items($finance, $this->business->id);

    expect($items)->toHaveCount(1)
        ->and($items[0]->reason)->toContain($payment->tran_id);
});
