<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Commerce\Actions\BookGatewayPayment;
use App\Modules\Commerce\Actions\CloseGatewayPayment;
use App\Modules\Commerce\Actions\ConfirmGatewayPayment;
use App\Modules\Commerce\Actions\StartGatewayPayment;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Exceptions\GatewayUnavailable;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SSLCommerz slice P2b: proving an online payment and booking it.
 *
 * ⛔ Nothing a callback says is believed until its signature verifies and the
 * Validation API - asked by the ERP - agrees. Money the gateway took is never lost:
 * the proof is kept before the booking, and the booking can be retried.
 */
const SSL_SECRET = 'sandboxpass@ssl';
const SSL_PAGE = 'https://sandbox.sslcommerz.com/EasyCheckOut/testcde0001';

/**
 * A callback carrying SSLCommerz's signature for this store's password.
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function sslSigned(array $fields, string $secret = SSL_SECRET): array
{
    $keys = array_keys($fields);
    $signed = $fields;
    $signed['store_passwd'] = md5($secret);
    ksort($signed);

    $pairs = [];

    foreach ($signed as $key => $value) {
        $pairs[] = $key.'='.$value;
    }

    return $fields + ['verify_key' => implode(',', $keys), 'verify_sign' => md5(implode('&', $pairs))];
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'Bionic Co', 'slug' => 'bionic-co']);
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

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Online buyer', 'type' => CustomerType::Retail, 'phone' => '01711000000']);

    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);

    $this->gatewayAccount = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'provider' => 'SSLCommerz', 'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);

    $this->gateway = StorePaymentGateway::create([
        'store_id' => $this->store->id, 'provider' => StorePaymentGateway::SSLCOMMERZ, 'is_enabled' => true,
        'merchant_id' => 'bioni0test', 'merchant_secret' => SSL_SECRET, 'is_live' => false,
        'money_account_id' => $this->gatewayAccount->id,
    ]);

    // 2 x 850 of goods plus 120 delivery = 1,820, as a shop order.
    $this->order = function (): SalesOrder {
        $order = app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => '2']],
            createdBy: $this->owner,
            origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: '120'),
        );

        $order->forceFill([
            'store_id' => $this->store->id, 'channel' => SalesChannel::Website,
            'shop_reference' => 'BNC-20260914-TESTAB'.SalesOrder::count(), 'payment_method' => 'sslcommerz',
        ])->save();

        return $order->fresh();
    };

    $this->urls = [
        'success' => 'https://bionic.garden/payments/sslcommerz/success',
        'fail' => 'https://bionic.garden/payments/sslcommerz/fail',
        'cancel' => 'https://bionic.garden/payments/sslcommerz/cancel',
        'ipn' => 'https://bionic.garden/payments/sslcommerz/ipn',
    ];

    $this->start = fn (SalesOrder $order) => app(StartGatewayPayment::class)->handle($order, $this->store->fresh(), $this->urls);

    // What the Validation API answers about a val_id, by default a clean payment
    // of 1,820 of which SSLCommerz settles 1,774.50 (a 45.50 fee).
    $this->validation = fn (string $tranId, array $overrides = []) => array_merge([
        'status' => 'VALID', 'tran_id' => $tranId, 'val_id' => 'VAL123', 'amount' => '1820.00',
        'store_amount' => '1774.50', 'currency' => 'BDT', 'bank_tran_id' => 'BANK789', 'card_type' => 'BKASH-BKash',
        'risk_level' => '0', 'risk_title' => 'Safe',
    ], $overrides);

    $this->fakeGateway = function (array $validation) {
        Http::fake([
            'sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => SSL_PAGE, 'sessionkey' => 'S1']),
            'sandbox.sslcommerz.com/validator/*' => Http::response($validation),
        ]);
    };

    $this->callback = fn (string $tranId, string $status = 'VALID', string $valId = 'VAL123') => sslSigned([
        'tran_id' => $tranId, 'val_id' => $valId, 'amount' => '1820.00', 'status' => $status, 'currency' => 'BDT',
    ]);

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->receivableProblems = fn () => array_values(array_filter(
        SubledgerReconciliation::reconcileCurrentBusiness()['problems'],
        fn (string $problem) => str_contains($problem, 'Receivable'),
    ));
});

afterEach(fn () => Carbon::setTestNow());

it('opens a payment page for what the order still owes, a new transaction id per attempt', function () {
    ($this->fakeGateway)([]);
    $order = ($this->order)();

    $first = ($this->start)($order);
    $second = ($this->start)($order);

    expect($first['url'])->toBe(SSL_PAGE)
        ->and($first['payment']->tran_id)->toBe($order->shop_reference.'-1')
        ->and($second['payment']->tran_id)->toBe($order->shop_reference.'-2')
        ->and($first['payment']->amountMoney()->toTaka())->toBe('1820.00')
        ->and($first['payment']->status)->toBe(GatewayPaymentStatus::Initiated)
        ->and($first['payment']->business_id)->toBe($this->business->id);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/gwprocess/v4/api.php')
        && $request['total_amount'] === '1820.00'
        && $request['tran_id'] === $order->shop_reference.'-1'
        && $request['store_passwd'] === SSL_SECRET
        && $request['ipn_url'] === 'https://bionic.garden/payments/sslcommerz/ipn'
        // The live gateway refuses a session without the shipping block.
        && $request['shipping_method'] === 'Courier' && $request['ship_country'] === 'Bangladesh');
});

it('refuses to open a page for a shop not taking payment online or an order that cannot be paid', function () {
    ($this->fakeGateway)([]);
    $order = ($this->order)();

    $this->gateway->update(['is_enabled' => false]);
    expect(fn () => ($this->start)($order))->toThrow(GatewayUnavailable::class, 'This shop is not taking payment online right now.');

    $this->gateway->update(['is_enabled' => true]);
    app(CancelSalesOrder::class)->handle($order);
    expect(fn () => ($this->start)($order->fresh()))->toThrow(GatewayUnavailable::class, "Order {$order->shop_reference} was cancelled, so it cannot be paid.");

    Http::assertNothingSent();
    expect(GatewayPayment::query()->count())->toBe(0);
});

it('closes an attempt whose page never opened, and says why', function () {
    Http::fake(['sandbox.sslcommerz.com/gwprocess/*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active'])]);
    $order = ($this->order)();

    expect(fn () => ($this->start)($order))->toThrow(GatewayUnavailable::class, 'SSLCommerz refused the payment: Store Credential Error Or Store is De-active');

    expect(GatewayPayment::query()->sole()->status)->toBe(GatewayPaymentStatus::Failed);
});

it('books a proven payment as credit on the order and the gateway fee as an expense', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1'));
    $payment = ($this->start)($order)['payment'];

    $confirmed = app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    $receipt = CustomerReceipt::query()->sole();

    expect($confirmed->status)->toBe(GatewayPaymentStatus::Paid)
        ->and($confirmed->val_id)->toBe('VAL123')
        ->and($confirmed->bank_tran_id)->toBe('BANK789')
        ->and($confirmed->fee()->toTaka())->toBe('45.50')
        ->and($confirmed->customer_receipt_id)->toBe($receipt->id)
        ->and($receipt->money_account_id)->toBe($this->gatewayAccount->id)
        ->and($receipt->sales_order_id)->toBe($order->id)
        ->and($receipt->reference)->toBe('BANK789')
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('1820.00')
        // What the gateway will deposit, what it kept, and what the customer paid.
        ->and(($this->posted)('gateway_receivable'))->toBe(177450)
        ->and(($this->posted)('payment_gateway_charge'))->toBe(4550)
        ->and(($this->posted)('accounts_receivable'))->toBe(-182000)
        ->and(($this->receivableProblems)())->toBe([]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'validationserverAPI.php') && $request['val_id'] === 'VAL123');
});

it('books a payment once when the server notification and the browser both arrive', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1'));
    $payment = ($this->start)($order)['payment'];

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));
    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    expect(CustomerReceipt::query()->count())->toBe(1)
        ->and(($this->posted)('payment_gateway_charge'))->toBe(4550);
});

it('believes nothing an unsigned or wrongly signed callback says, and never asks the Validation API about it', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1'));
    $payment = ($this->start)($order)['payment'];

    $unsigned = ['tran_id' => $payment->tran_id, 'val_id' => 'VAL123', 'status' => 'VALID'];
    $forged = sslSigned(['tran_id' => $payment->tran_id, 'val_id' => 'VAL123', 'status' => 'VALID'], 'guessed-password');

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), $unsigned);
    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), $forged);

    expect($payment->fresh()->status)->toBe(GatewayPaymentStatus::Initiated)
        ->and($payment->fresh()->problem)->toBe('A payment callback without a valid SSLCommerz signature was ignored.')
        ->and(CustomerReceipt::query()->count())->toBe(0);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'validationserverAPI.php'));
});

it('proves nothing the Validation API does not confirm for this attempt, in full, in BDT', function (array $answer, string $problem) {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1', $answer));
    $payment = ($this->start)($order)['payment'];

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    expect($payment->fresh()->status)->toBe(GatewayPaymentStatus::Initiated)
        ->and($payment->fresh()->problem)->toBe($problem)
        ->and(CustomerReceipt::query()->count())->toBe(0);
})->with([
    'not valid' => [['status' => 'INVALID_TRANSACTION'], 'SSLCommerz did not confirm the payment (INVALID_TRANSACTION).'],
    'another transaction' => [['tran_id' => 'BNC-20260914-OTHER-1'], 'SSLCommerz confirmed a different transaction.'],
    'less than asked' => [['amount' => '1819.99'], 'SSLCommerz confirmed less than the order asked for.'],
    'another currency' => [['currency' => 'USD'], 'SSLCommerz confirmed a payment in a currency other than BDT.'],
]);

it('keeps more than was asked on the customer account rather than refusing money already taken', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1', ['amount' => '1900.00', 'store_amount' => '1852.50']));
    $payment = ($this->start)($order)['payment'];

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    expect($payment->fresh()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and($order->fresh()->advanceHeld()->toTaka())->toBe('1900.00')
        ->and($order->fresh()->stillToPay()->toTaka())->toBe('0.00');
});

it('still books money paid for an order that was cancelled while the customer paid, and owes it back', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1'));
    $payment = ($this->start)($order)['payment'];

    app(CancelSalesOrder::class)->handle($order->fresh());
    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    $order = $order->fresh();

    expect($payment->fresh()->status)->toBe(GatewayPaymentStatus::Paid)
        ->and($order->advanceHeld()->toTaka())->toBe('1820.00')
        ->and($order->isFinishedForBilling())->toBeTrue();
});

it('keeps the proof when booking fails, and books it when run again', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1'));
    $payment = ($this->start)($order)['payment'];

    // Somebody removes the fee mapping between checkout and the callback.
    $mapping = AccountMapping::query()->where('key', 'sales.gateway_fees')->sole();
    $accountId = $mapping->account_id;
    $mapping->delete();

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    $payment = $payment->fresh();

    expect($payment->status)->toBe(GatewayPaymentStatus::Proven)
        ->and($payment->val_id)->toBe('VAL123')
        ->and($payment->problem)->toStartWith('Paid but not yet booked: ')
        // All or nothing: no receipt without its fee.
        ->and(CustomerReceipt::query()->count())->toBe(0)
        ->and(($this->posted)('gateway_receivable'))->toBe(0);

    AccountMapping::assign('sales.gateway_fees', Account::query()->findOrFail($accountId), Carbon::parse('2000-01-01'), 'Restored');

    $booked = app(BookGatewayPayment::class)->handle($payment);

    expect($booked->status)->toBe(GatewayPaymentStatus::Paid)
        ->and($booked->problem)->toBeNull()
        ->and(CustomerReceipt::query()->count())->toBe(1);
});

it('closes an attempt only on a signed failure, and never moves a paid one back', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-2'));
    $first = ($this->start)($order)['payment'];
    $second = ($this->start)($order)['payment'];

    app(CloseGatewayPayment::class)->handle($this->store->fresh(), ['tran_id' => $first->tran_id, 'status' => 'FAILED'], GatewayPaymentStatus::Failed);
    expect($first->fresh()->status)->toBe(GatewayPaymentStatus::Initiated);

    app(CloseGatewayPayment::class)->handle($this->store->fresh(), sslSigned(['tran_id' => $first->tran_id, 'status' => 'FAILED', 'error' => 'Declined by bank']), GatewayPaymentStatus::Failed);
    expect($first->fresh()->status)->toBe(GatewayPaymentStatus::Failed)
        ->and($first->fresh()->problem)->toBe('Declined by bank');

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($second->tran_id));
    app(CloseGatewayPayment::class)->handle($this->store->fresh(), sslSigned(['tran_id' => $second->tran_id, 'status' => 'CANCELLED']), GatewayPaymentStatus::Cancelled);

    expect($second->fresh()->status)->toBe(GatewayPaymentStatus::Paid);
});

it('keeps what the gateway says about a risky payment with the attempt', function () {
    $order = ($this->order)();
    ($this->fakeGateway)(($this->validation)($order->shop_reference.'-1', ['risk_level' => '1', 'risk_title' => 'Card is blacklisted']));
    $payment = ($this->start)($order)['payment'];

    app(ConfirmGatewayPayment::class)->handle($this->store->fresh(), ($this->callback)($payment->tran_id));

    expect($payment->fresh()->isRisky())->toBeTrue()
        ->and($payment->fresh()->risk_title)->toBe('Card is blacklisted')
        ->and($payment->fresh()->status)->toBe(GatewayPaymentStatus::Paid);
});
