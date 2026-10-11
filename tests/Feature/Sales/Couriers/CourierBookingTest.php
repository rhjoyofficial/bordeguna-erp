<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
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
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Drivers\PathaoDriver;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierApiCall;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 4 (11 Oct 2026): a challan booked through the courier's API
 * from the order screen, followed by webhook and pull. Most tests are about
 * what must NOT happen: a parcel booked twice after a timeout, a guessed
 * destination, money posted because a courier said "delivered", a webhook
 * believed about which business it is for.
 */
const CBK_CB = 'https://developers.carrybee.com';
const CBK_PATHAO = 'https://api-hermes.pathao.com';
const CBK_PHONE = '01711000000';

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');

    $this->business = cbkBusiness('LaLaDia', 'laladia');

    foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $world = cbkWorld($this->business, $this->owner);
    $this->product = $world['product'];
    $this->courierMoney = $world['money'];
    $this->customer = $world['customer'];
    $this->warehouse = $world['warehouse'];

    cbkLocations();

    Http::preventStrayRequests();
    $this->withoutVite();
});

afterEach(fn () => Carbon::setTestNow());

function cbkBusiness(string $name, string $slug): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new ChartOfAccountsSeeder)->run();

    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    return $business;
}

/** @return array{product: Product, money: MoneyAccount, customer: Customer, warehouse: Warehouse} */
function cbkWorld(Business $business, User $owner): array
{
    $warehouse = Warehouse::create(['business_id' => $business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = Product::create([
        'business_id' => $business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $business->id, 'product_id' => $product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($product, $warehouse, '100', '500', $owner, occurredAt: '2026-10-01 09:00:00');

    return [
        'product' => $product,
        'warehouse' => $warehouse,
        'customer' => Customer::create(['business_id' => $business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]),
        'money' => MoneyAccount::create([
            'business_id' => $business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
            'provider' => 'carrybee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
        ]),
    ];
}

/** Dhaka / Dhanmondi mapped (applied) for both couriers; Dhaka / Mirpur mapped for neither. */
function cbkLocations(): void
{
    foreach ([['carrybee', '14', '101'], ['pathao', '1', '52']] as [$provider, $city, $zone]) {
        CourierLocation::create(['provider' => $provider, 'level' => 'city', 'provider_location_id' => $city, 'name' => 'Dhaka', 'synced_at' => now()]);
        CourierLocation::create(['provider' => $provider, 'level' => 'zone', 'provider_location_id' => $zone, 'parent_provider_id' => $city, 'name' => 'Dhanmondi', 'synced_at' => now()]);
        CourierLocationMap::create([
            'provider' => $provider, 'district' => 'Dhaka', 'thana' => 'Dhanmondi',
            'provider_city_id' => $city, 'provider_city_name' => 'Dhaka', 'provider_zone_id' => $zone, 'provider_zone_name' => 'Dhanmondi',
            'status' => CourierLocationMap::APPLIED, 'source' => CourierLocationMap::MANUAL, 'confidence' => CourierLocationMap::HIGH,
        ]);
    }
}

/** An account that passed a test, switched on, linked to the business with a store and its 1145 account. */
function cbkAccount(string $provider, Business $business, MoneyAccount $money, string $store = '24869'): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, $provider === 'carrybee' ? [
        'provider' => 'carrybee', 'label' => 'CB '.$business->slug, 'environment' => 'live', 'base_url' => CBK_CB,
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ] : [
        'provider' => 'pathao', 'label' => 'Pathao '.$business->slug, 'environment' => 'live', 'base_url' => CBK_PATHAO,
        'credentials' => ['client_id' => 'p-id', 'client_secret' => 'p-SECRET-1234', 'username' => 'ops@bionic.garden', 'password' => 'p-PASSWORD-99'],
    ]);

    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $business->id, 'provider_store_id' => $store,
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $money->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

/** An order for $quantity ghee to Dhanmondi (or another thana), and its challan. */
function cbkChallan(object $t, string $quantity = '2', string $thana = 'Dhanmondi', string $phone = '+880 1711-000000'): Delivery
{
    $order = app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => $quantity]],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal),
    );
    $order->address()->create([
        'business_id' => $order->business_id, 'recipient_name' => 'Karim Uddin', 'recipient_phone' => $phone,
        'district' => 'Dhaka', 'thana' => $thana, 'address_line' => 'House 4, Road 2, Jigatola',
    ]);

    return app(RecordDelivery::class)->handle(
        customer: $t->customer,
        warehouse: $t->warehouse,
        lines: [['product' => $t->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $t->owner,
        deliveredAt: '2026-10-11 09:00:00',
    );
}

function cbkBook(object $t, Delivery $delivery, CourierAccount $account, array $extra = [], ?User $as = null)
{
    return $t->actingAs($as ?? $t->manager)->post("/sales/deliveries/{$delivery->id}/courier-booking", $extra + [
        'courier_account_id' => $account->id,
        'cod_amount' => '1700',
    ]);
}

function cbkOk(array $data): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['error' => false, 'message' => 'ok', 'data' => $data], 201);
}

function cbkPathaoToken(): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'tok-ABC', 'refresh_token' => 'r']);
}

function cbkJournalCount(): int
{
    return JournalLine::query()->withoutGlobalScopes()->count();
}

it('books a challan with CarryBee with every field mapped in its own units, and moves no money', function () {
    Event::fake([CourierParcelChanged::class]);
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    $journalsBefore = cbkJournalCount();

    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => [
        'consignment_id' => 'FA1110ABCD', 'merchant_order_id' => $delivery->delivery_number,
        'collectable_amount' => 1700, 'delivery_fee' => '90', 'cod_fee' => 8.5,
    ]])]);

    cbkBook($this, $delivery, $account, ['special_instruction' => 'Call before'])->assertSessionHasNoErrors()->assertSessionHas('success');

    Http::assertSent(function (Request $request) use ($delivery) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === CBK_CB.'/api/v2/orders'
            && $request->hasHeader('Client-ID', 'cb-id')
            && $body['store_id'] === '24869'
            && $body['merchant_order_id'] === $delivery->delivery_number
            && $body['delivery_type'] === 1 && $body['product_type'] === 1
            && $body['recipient_phone'] === CBK_PHONE
            && $body['recipient_name'] === 'Karim Uddin'
            && $body['recipient_address'] === 'House 4, Road 2, Jigatola, Dhanmondi, Dhaka'
            && $body['city_id'] === 14 && $body['zone_id'] === 101 && ! array_key_exists('area_id', $body)
            // Grams, a whole number: 2 x 450 g.
            && $body['item_weight'] === 900
            && $body['item_quantity'] === 2
            // Whole taka, an integer.
            && $body['collectable_amount'] === 1700
            && $body['product_description'] === '2 x Royal Essence Ghee 400g'
            && $body['special_instruction'] === 'Call before';
    });

    $consignment = Consignment::query()->where('delivery_id', $delivery->id)->sole();
    expect($consignment)
        ->consignment_number->toBe('FA1110ABCD')
        ->provider_consignment_id->toBe('FA1110ABCD')
        ->merchant_order_ref->toBe($delivery->delivery_number)
        ->booked_via->toBe('api')
        ->courier_account_id->toBe($account->id)
        ->money_account_id->toBe($this->courierMoney->id)
        ->courier_status->toBe(CourierStatus::Booked)
        ->status->toBe(ConsignmentStatus::Booked)
        ->declared_weight_grams->toBe(900)
        ->courier_city_id->toBe('14')->courier_zone_id->toBe('101')
        ->and($consignment->provider_fee_quoted)->toBe('90.0000')
        ->and($consignment->provider_cod_fee_quoted)->toBe('8.5000')
        ->and((string) $consignment->cod_amount)->toBe('1700.0000');

    expect(CourierBookingRequest::query()->sole())->status->toBe('succeeded')->consignment_id->toBe($consignment->id)
        ->and(CourierShipmentEvent::query()->where('consignment_id', $consignment->id)->sole())->source->toBe('booking')->applied->toBeTrue()
        // ⚠ Booking is not money: no journal, no invoice.
        ->and(cbkJournalCount())->toBe($journalsBefore)
        ->and(SalesInvoice::query()->count())->toBe(0);

    Event::assertDispatched(CourierParcelChanged::class, fn ($e) => $e->type === 'booked' && $e->consignmentId === $consignment->id);
});

it('books a challan with Pathao in kilograms rounded up, ids as numbers, and never sends Pathao to guess', function () {
    $account = cbkAccount('pathao', $this->business, $this->courierMoney, '393838');
    $delivery = cbkChallan($this);

    Http::fake([
        CBK_PATHAO.'/aladdin/api/v1/issue-token' => cbkPathaoToken(),
        CBK_PATHAO.'/aladdin/api/v1/orders' => Http::response(['type' => 'success', 'code' => 200, 'data' => [
            'consignment_id' => 'DT1110PATH', 'merchant_order_id' => $delivery->delivery_number, 'order_status' => 'Pending', 'delivery_fee' => 70,
        ]]),
    ]);

    cbkBook($this, $delivery, $account, ['declared_weight_grams' => 1201])->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request) use ($delivery) {
        if ($request->url() !== CBK_PATHAO.'/aladdin/api/v1/orders') {
            return false;
        }

        $body = $request->data();

        return $request->hasHeader('Authorization', 'Bearer tok-ABC')
            && $body['store_id'] === 393838
            && $body['merchant_order_id'] === $delivery->delivery_number
            && $body['recipient_phone'] === CBK_PHONE
            && $body['recipient_city'] === 1 && $body['recipient_zone'] === 52 && ! array_key_exists('recipient_area', $body)
            && $body['delivery_type'] === 48 && $body['item_type'] === 2
            && $body['item_quantity'] === 2
            // 1,201 g goes up to 1.3 kg, never down to 1.2.
            && $body['item_weight'] === 1.3
            && $body['amount_to_collect'] === 1700
            && $body['item_description'] === '2 x Royal Essence Ghee 400g';
    });

    expect(Consignment::query()->sole())->consignment_number->toBe('DT1110PATH')->declared_weight_grams->toBe(1201)
        ->and(Consignment::query()->sole()->provider_fee_quoted)->toBe('70.0000');
    expect(PathaoDriver::kilograms(300))->toBe(0.5)->and(PathaoDriver::kilograms(500))->toBe(0.5)
        ->and(PathaoDriver::kilograms(501))->toBe(0.6)->and(PathaoDriver::kilograms(1000))->toBe(1.0);
});

it('refuses a destination with no reviewed map, before anything is written or sent', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this, thana: 'Mirpur');
    Http::fake();

    cbkBook($this, $delivery, $account)->assertSessionHasErrors(['destination' => 'Mirpur, Dhaka has no CarryBee zone yet. Map it in Settings > Couriers > Locations.']);

    Http::assertNothingSent();
    expect(CourierBookingRequest::query()->count())->toBe(0)->and(Consignment::query()->count())->toBe(0);
});

it('refuses a phone that is not a mobile number, paisa in the amount, and more than the challan bills', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    Http::fake();

    cbkBook($this, cbkChallan($this, phone: '02-9110000'), $account)->assertSessionHasErrors('recipient_phone');

    $delivery = cbkChallan($this);
    cbkBook($this, $delivery, $account, ['cod_amount' => '1699.50'])
        ->assertSessionHasErrors(['cod_amount' => 'Couriers collect whole taka. Enter 1700 (rounded up) or another whole amount.']);
    cbkBook($this, $delivery, $account, ['cod_amount' => '1701'])->assertSessionHasErrors(['cod_amount' => 'That is more than this challan would bill (1700).']);

    Http::assertNothingSent();
});

it('writes the booking intent before the request leaves', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    $seen = null;

    Http::fake(function (Request $request) use (&$seen) {
        $seen = CourierBookingRequest::query()->withoutGlobalScopes()->get(['status', 'merchant_order_id'])->toArray();

        return cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]]);
    });

    cbkBook($this, $delivery, $account)->assertSessionHasNoErrors();

    expect($seen)->toBe([['status' => 'requested', 'merchant_order_id' => $delivery->delivery_number]]);
});

it('marks a timed-out CarryBee booking unknown, refuses to book again, and recovers it by looking our reference up', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    $posts = 0;
    $atCarryBee = false;

    Http::fake(function (Request $request) use (&$posts, &$atCarryBee, $delivery) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/api/v2/orders') {
            $posts++;
            $atCarryBee = true;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }

        if ($path === '/api/v2/orders/'.$delivery->delivery_number.'/details') {
            return $atCarryBee
                ? Http::response(['error' => false, 'data' => ['consignment_id' => 'FA1110LOST', 'merchant_order_id' => $delivery->delivery_number, 'transfer_status' => 'Pickup Requested', 'delivery_fee' => '90', 'cod_fee' => 8.5]])
                : Http::response(['error' => true, 'message' => 'Not found'], 404);
        }

        return Http::response([], 500);
    });

    cbkBook($this, $delivery, $account)->assertSessionHas('error');

    $intent = CourierBookingRequest::query()->sole();
    expect($intent->status)->toBe('unknown')->and(Consignment::query()->count())->toBe(0);

    // ⚠ The second press does not send it again.
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');
    expect($posts)->toBe(1);

    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/look-up")->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($posts)->toBe(1)
        ->and($intent->fresh())->status->toBe('succeeded')->resolution->toBe('found_by_lookup')
        ->and(Consignment::query()->sole())->consignment_number->toBe('FA1110LOST')->courier_status->toBe(CourierStatus::Booked)->booked_via->toBe('api');
});

it('lets a CarryBee booking be made again only once the lookup proves nothing was booked', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    $first = true;

    Http::fake(function (Request $request) use (&$first) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/api/v2/orders') {
            if ($first) {
                $first = false;

                return Http::response(['error' => true, 'message' => 'Bad gateway'], 502);
            }

            return cbkOk(['order' => ['consignment_id' => 'FA2', 'delivery_fee' => '60', 'cod_fee' => 0]]);
        }

        return Http::response(['error' => true, 'message' => 'Order not found'], 404);
    });

    cbkBook($this, $delivery, $account);
    $intent = CourierBookingRequest::query()->sole();
    expect($intent->status)->toBe('unknown');

    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/look-up")->assertSessionHasNoErrors();
    expect($intent->fresh())->status->toBe('failed')->resolution->toBe('not_at_courier');

    cbkBook($this, $delivery, $account)->assertSessionHasNoErrors();
    expect(Consignment::query()->sole()->consignment_number)->toBe('FA2');
});

it('never retries a timed-out Pathao booking: a person says what the Pathao panel shows', function () {
    $account = cbkAccount('pathao', $this->business, $this->courierMoney, '393838');
    $delivery = cbkChallan($this);
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts, $delivery) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/aladdin/api/v1/issue-token' => cbkPathaoToken(),
            $path === '/aladdin/api/v1/orders' => (function () use (&$posts) {
                $posts++;

                throw new ConnectionException('cURL error 28: timed out');
            })(),
            $path === '/aladdin/api/v1/orders/DT-OTHER/info' => Http::response(['data' => ['consignment_id' => 'DT-OTHER', 'merchant_order_id' => 'SOMEONE-ELSE', 'order_status_slug' => 'Pending']]),
            $path === '/aladdin/api/v1/orders/DT1110SEEN/info' => Http::response(['data' => ['consignment_id' => 'DT1110SEEN', 'merchant_order_id' => $delivery->delivery_number, 'order_status_slug' => 'Pickup_Requested', 'updated_at' => '2026-10-11 10:05:00']]),
            default => Http::response([], 404),
        };
    });

    cbkBook($this, $delivery, $account);
    $intent = CourierBookingRequest::query()->sole();
    expect($intent->status)->toBe('unknown');

    // No lookup by our reference at Pathao, and no second booking.
    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/look-up")->assertSessionHasErrors('booking');
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');
    expect($posts)->toBe(1);

    // A number that belongs to another order is refused.
    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/booked", ['consignment_number' => 'DT-OTHER'])
        ->assertSessionHasErrors('consignment_number');

    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/booked", ['consignment_number' => 'DT1110SEEN'])
        ->assertSessionHasNoErrors();

    expect($posts)->toBe(1)
        ->and($intent->fresh())->status->toBe('succeeded')->resolution->toBe('person_says_booked')->resolved_by->toBe($this->manager->id)
        ->and(Consignment::query()->sole())->consignment_number->toBe('DT1110SEEN');
});

it('books a Pathao parcel again only after a person says it was not booked', function () {
    $account = cbkAccount('pathao', $this->business, $this->courierMoney, '393838');
    $delivery = cbkChallan($this);
    $posts = 0;

    Http::fake(function (Request $request) use (&$posts) {
        if (str_ends_with($request->url(), '/issue-token')) {
            return cbkPathaoToken();
        }

        $posts++;

        return $posts === 1
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response(['data' => ['consignment_id' => 'DT2', 'delivery_fee' => 70]]);
    });

    cbkBook($this, $delivery, $account);
    $intent = CourierBookingRequest::query()->sole();

    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/not-booked")->assertSessionHasNoErrors();
    expect($intent->fresh())->status->toBe('failed')->resolution->toBe('person_says_not_booked');

    cbkBook($this, $delivery, $account)->assertSessionHasNoErrors();
    expect($posts)->toBe(2)->and(Consignment::query()->sole()->consignment_number)->toBe('DT2');
});

it('books once however many times the button is pressed', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]])]);

    // A press still in flight holds the challan's lock: the second is refused.
    $lock = Cache::lock('couriers:book:delivery:'.$delivery->id, 90);
    $lock->get();
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');
    $lock->release();
    Http::assertNothingSent();

    cbkBook($this, $delivery, $account)->assertSessionHasNoErrors();
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');

    Http::assertSentCount(1);
    expect(Consignment::query()->count())->toBe(1)->and(CourierBookingRequest::query()->count())->toBe(1);
});

it('keeps a courier number unique per account, and leaves the booking open when the ERP cannot record it', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA-SAME', 'delivery_fee' => '60', 'cod_fee' => 0]])]);

    cbkBook($this, cbkChallan($this), $account)->assertSessionHasNoErrors();
    $second = cbkChallan($this);
    cbkBook($this, $second, $account);

    $intent = CourierBookingRequest::query()->where('delivery_id', $second->id)->sole();
    expect(Consignment::query()->count())->toBe(1)
        ->and($intent)->status->toBe('unknown')->provider_consignment_id->toBe('FA-SAME')
        ->and($intent->error)->toContain('Booked at the courier as FA-SAME')->toContain('already recorded');

    // Nobody can mark it "not booked" while the courier holds a number for it.
    $this->actingAs($this->manager)->post("/sales/courier-bookings/{$intent->id}/not-booked")->assertSessionHasErrors('booking');
});

it('cancels a CarryBee parcel before pickup, and asks for the goods to be recorded as returned', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);

    Http::fake([
        CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]]),
        CBK_CB.'/api/v2/orders/FA1/cancel' => Http::response(['error' => false, 'message' => 'Accepted'], 202),
    ]);

    cbkBook($this, $delivery, $account);
    $consignment = Consignment::query()->sole();

    $this->actingAs($this->staff)->post("/sales/consignments/{$consignment->id}/cancel", ['reason' => 'Customer changed mind'])->assertForbidden();
    $this->actingAs($this->manager)->post("/sales/consignments/{$consignment->id}/cancel", ['reason' => 'Customer changed mind'])->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === CBK_CB.'/api/v2/orders/FA1/cancel' && $r['cancellation_reason'] === 'Customer changed mind');
    expect($consignment->fresh())->courier_status->toBe(CourierStatus::Cancelled)->status->toBe(ConsignmentStatus::Booked);

    $challan = $this->actingAs($this->manager)->get('/sales/orders/'.$delivery->lines->first()->orderLine->sales_order_id)
        ->original->getData()['page']['props']['challans'][0];
    expect($challan['consignment']['courier_info']['prompt'])->outcome->toBe('returned')
        ->and($challan['consignment']['courier_info']['can_cancel'])->toBeFalse();
});

it('says to cancel in the Pathao panel when Pathao does not take the cancel, and changes nothing', function () {
    $account = cbkAccount('pathao', $this->business, $this->courierMoney, '393838');
    $delivery = cbkChallan($this);

    Http::fake([
        CBK_PATHAO.'/aladdin/api/v1/issue-token' => cbkPathaoToken(),
        CBK_PATHAO.'/aladdin/api/v1/orders' => Http::response(['data' => ['consignment_id' => 'DT1', 'delivery_fee' => 70]]),
        CBK_PATHAO.'/aladdin/api/v1/orders/DT1/cancel' => Http::response(['message' => 'Not Found'], 404),
    ]);

    cbkBook($this, $delivery, $account);
    $consignment = Consignment::query()->sole();

    $this->actingAs($this->manager)->post("/sales/consignments/{$consignment->id}/cancel", ['reason' => 'Duplicate'])
        ->assertSessionHasErrors(['cancel']);

    expect(session('errors')->first('cancel'))->toContain('Cancel it in the Pathao merchant panel')
        ->and($consignment->fresh()->courier_status)->toBe(CourierStatus::Booked);
});

it('checks the webhook secret, answers the CarryBee handshake with the header echoed, and logs unknown parcels', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $secret = $account->webhookSecretValue();
    $url = '/webhooks/couriers/'.$account->uuid;

    $this->postJson($url, ['event' => 'webhook.integration'])->assertStatus(401);
    $this->postJson($url, ['event' => 'webhook.integration'], ['X-CB-Webhook-Integration-Header' => 'wrong'])->assertStatus(401);
    $this->postJson('/webhooks/couriers/'.fake()->uuid(), ['event' => 'webhook.integration'], ['X-CB-Webhook-Integration-Header' => $secret])->assertNotFound();

    $this->postJson($url, ['event' => 'webhook.integration'], ['X-CB-Webhook-Integration-Header' => $secret])
        ->assertStatus(202)
        ->assertHeader('X-CB-Webhook-Integration-Header', $secret);

    $this->postJson($url, ['event' => 'order.delivered', 'consignment_id' => 'NOT-OURS'], ['X-CB-Webhook-Integration-Header' => $secret])
        ->assertStatus(202);
    expect(CourierShipmentEvent::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('records a delivered webhook once, ignores a late word, and never posts money from it', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]])]);
    cbkBook($this, $delivery, $account);
    $consignment = Consignment::query()->sole();
    $journals = cbkJournalCount();
    $headers = ['X-CB-Webhook-Integration-Header' => $account->webhookSecretValue()];
    $url = '/webhooks/couriers/'.$account->uuid;

    $delivered = ['event' => 'order.delivered', 'store_id' => '24869', 'consignment_id' => 'FA1', 'merchant_order_id' => $delivery->delivery_number,
        'timestamptz' => '2026-10-12T15:00:00+06:00', 'collected_amount' => 1700, 'attempt' => 1];

    $this->postJson($url, $delivered, $headers)->assertStatus(202);
    $this->postJson($url, $delivered, $headers)->assertStatus(202);
    // Older than what is already applied, and after a final state.
    $this->postJson($url, ['event' => 'order.in-transit', 'consignment_id' => 'FA1', 'timestamptz' => '2026-10-12T09:00:00+06:00'], $headers)->assertStatus(202);
    $this->postJson($url, ['event' => 'order.pickup-requested', 'consignment_id' => 'FA1', 'timestamptz' => '2026-10-12T16:00:00+06:00'], $headers)->assertStatus(202);

    $fresh = $consignment->fresh();
    expect($fresh)->courier_status->toBe(CourierStatus::Delivered)->courier_status_raw->toBe('order.delivered')
        ->and($fresh->courier_collected_amount)->toBe('1700.0000')
        // ⚠ The money side has not moved: still booked, no invoice, no journal.
        ->and($fresh->status)->toBe(ConsignmentStatus::Booked)
        ->and($fresh->sales_invoice_id)->toBeNull()
        ->and(cbkJournalCount())->toBe($journals);

    $events = CourierShipmentEvent::query()->where('source', 'webhook')->orderBy('id')->get();
    expect($events)->toHaveCount(3)
        ->and($events->pluck('applied')->all())->toBe([true, false, false]);
});

it('offers a person the delivered outcome prefilled, and the existing outcome still posts the invoice and the receipt', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]])]);
    cbkBook($this, $delivery, $account);
    $consignment = Consignment::query()->sole();
    $orderId = $delivery->lines->first()->orderLine->sales_order_id;

    $this->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'order.delivered', 'consignment_id' => 'FA1', 'collected_amount' => 1700],
        ['X-CB-Webhook-Integration-Header' => $account->webhookSecretValue()])->assertStatus(202);

    $info = $this->actingAs($this->manager)->get('/sales/orders/'.$orderId)->original->getData()['page']['props']['challans'][0]['consignment']['courier_info'];
    expect($info['prompt'])->toBe(['outcome' => 'delivered', 'collected' => '1700.00', 'message' => 'Courier reports delivered: confirm.'])
        ->and($info['courier_status'])->toBe('delivered')
        ->and($info['events'])->not->toBeEmpty();

    $this->actingAs($this->manager)->post("/sales/consignments/{$consignment->id}/outcome", [
        'outcome' => 'delivered', 'outcome_on' => '2026-10-11', 'collected_amount' => '1700',
    ])->assertSessionHasNoErrors();

    $receivable = (int) JournalLine::query()->where('account_id', Account::bySlug('courier_receivable')->id)->sum('amount_minor');
    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->fresh()->sales_invoice_id)->not->toBeNull()
        ->and($receivable)->toBe(170000);
});

it('takes the business from our own record, never from the webhook body', function () {
    $accountA = cbkAccount('carrybee', $this->business, $this->courierMoney);
    // A second business booking through the same courier account.
    $bionic = cbkBusiness('Bionic', 'bionic');
    $bionicOwner = User::factory()->create();
    $bionicOwner->businesses()->attach($bionic->id);
    $bionicOwner->assignRole('Owner');
    $world = cbkWorld($bionic, $bionicOwner);
    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $accountA->id, 'business_id' => $bionic->id, 'provider_store_id' => '26837',
        'provider_store_name' => 'Bionic', 'money_account_id' => $world['money']->id,
    ]);
    $t = (object) ($world + ['owner' => $bionicOwner]);
    $delivery = cbkChallan($t);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA-BIONIC', 'delivery_fee' => '60', 'cod_fee' => 0]])]);
    cbkBook($this, $delivery, $accountA, as: $bionicOwner)->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $r) => $r['store_id'] === '26837');

    // Back in LaLaDia's context, a webhook that claims LaLaDia's store.
    app(BusinessContext::class)->set($this->business->id);
    $this->postJson('/webhooks/couriers/'.$accountA->uuid, ['event' => 'order.picked', 'consignment_id' => 'FA-BIONIC', 'store_id' => '24869', 'business_id' => $this->business->id],
        ['X-CB-Webhook-Integration-Header' => $accountA->webhookSecretValue()])->assertStatus(202);

    $event = CourierShipmentEvent::query()->withoutGlobalScopes()->where('event', 'order.picked')->sole();
    expect($event->business_id)->toBe($bionic->id)
        ->and(Consignment::query()->withoutGlobalScopes()->where('consignment_number', 'FA-BIONIC')->sole()->courier_status)->toBe(CourierStatus::PickedUp)
        // The tenant the test was in is restored.
        ->and(app(BusinessContext::class)->id())->toBe($this->business->id);
});

it('checks the Pathao signature and echoes the saved integration value', function () {
    $account = cbkAccount('pathao', $this->business, $this->courierMoney, '393838');
    $this->actingAs($this->owner)->put("/settings/couriers/{$account->id}", [
        'label' => $account->label, 'environment' => 'live', 'base_url' => CBK_PATHAO, 'integration_secret' => 'f3992ecc-59da-4cbe-a049-a13da2018d51',
    ])->assertSessionHasNoErrors();
    $account->refresh();

    $this->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'webhook_integration'], ['X-PATHAO-Signature' => 'nope'])->assertStatus(401);
    $this->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'webhook_integration'], ['X-PATHAO-Signature' => $account->webhookSecretValue()])
        ->assertStatus(202)
        ->assertHeader('X-Pathao-Merchant-Webhook-Integration-Secret', 'f3992ecc-59da-4cbe-a049-a13da2018d51');

    // Still active (the echo value is not a credential), never shown back.
    $props = $this->actingAs($this->owner)->get('/settings/couriers')->original->getData()['page']['props'];
    $row = collect($props['accounts'])->firstWhere('id', $account->id);
    expect($row['integration_secret_set'])->toBeTrue()->and($row['is_active'])->toBeTrue()
        ->and(json_encode($props))->not->toContain('f3992ecc');
});

it('pulls open parcels every 15 minutes, records the courier word once, and settles a lost CarryBee booking', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    config(['couriers.parcel_sync_delay_ms' => 0]);
    $delivery = cbkChallan($this);
    $lost = cbkChallan($this);

    Http::fake(function (Request $request) use ($delivery, $lost) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->method() === 'POST' && $request['merchant_order_id'] === $delivery->delivery_number => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]]),
            $request->method() === 'POST' => Http::response(['error' => true, 'message' => 'Gateway Timeout'], 504),
            $path === '/api/v2/orders/FA1/details' => Http::response(['error' => false, 'data' => [
                'consignment_id' => 'FA1', 'transfer_status' => 'In Transit', 'updated_at' => '2026-10-11T12:00:00+06:00', 'delivery_fee' => '60', 'cod_fee' => 0,
            ]]),
            $path === '/api/v2/orders/'.$lost->delivery_number.'/details' => Http::response(['error' => false, 'data' => [
                'consignment_id' => 'FA-LATE', 'merchant_order_id' => $lost->delivery_number, 'transfer_status' => 'Pending', 'delivery_fee' => '60', 'cod_fee' => 0,
            ]]),
            default => Http::response(['error' => true, 'message' => 'Not found'], 404),
        };
    });

    cbkBook($this, $delivery, $account);
    cbkBook($this, $lost, $account);
    expect(CourierBookingRequest::query()->where('delivery_id', $lost->id)->sole()->status)->toBe('unknown');

    // The lost answer is settled only once it is old enough not to be running.
    Carbon::setTestNow('2026-10-11 10:05:00');
    $this->artisan('couriers:sync-parcels')->assertSuccessful();
    $this->artisan('couriers:sync-parcels')->assertSuccessful();

    $parcel = Consignment::query()->where('consignment_number', 'FA1')->sole();
    expect($parcel)->courier_status->toBe(CourierStatus::InTransit)->status_synced_at->not->toBeNull()
        ->and(CourierShipmentEvent::query()->where('consignment_id', $parcel->id)->where('source', 'poll')->count())->toBe(1)
        ->and(CourierBookingRequest::query()->where('delivery_id', $lost->id)->sole()->status)->toBe('succeeded')
        ->and(Consignment::query()->where('delivery_id', $lost->id)->sole()->consignment_number)->toBe('FA-LATE');
});

it('refreshes one parcel from the order screen for whoever follows parcels', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake([
        CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]]),
        CBK_CB.'/api/v2/orders/FA1/details' => Http::response(['error' => false, 'data' => ['consignment_id' => 'FA1', 'transfer_status' => 'Assigned For Delivery', 'updated_at' => '2026-10-11T11:00:00+06:00']]),
    ]);
    cbkBook($this, $delivery, $account);
    $consignment = Consignment::query()->sole();

    $this->actingAs($this->staff)->post("/sales/consignments/{$consignment->id}/refresh")->assertSessionHas('success');
    expect($consignment->fresh()->courier_status)->toBe(CourierStatus::OutForDelivery);

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    $this->actingAs($outsider)->post("/sales/consignments/{$consignment->id}/refresh")->assertForbidden();
});

it('lets only those who may book use the courier API, and keeps the typed-number path', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake();

    // Staff sell, but booking through the API is couriers.parcels.book.
    cbkBook($this, $delivery, $account, as: $this->staff)->assertForbidden();
    Http::assertNothingSent();

    $orderId = $delivery->lines->first()->orderLine->sales_order_id;
    $props = $this->actingAs($this->staff)->get('/sales/orders/'.$orderId)->original->getData()['page']['props'];
    expect($props['can_book_courier'])->toBeFalse()->and($props['courier_accounts'])->toBe([]);

    $props = $this->actingAs($this->manager)->get('/sales/orders/'.$orderId)->original->getData()['page']['props'];
    expect($props['can_book_courier'])->toBeTrue()
        ->and($props['courier_accounts'][0])->id->toBe($account->id)->provider->toBe('carrybee')
        ->and($props['challans'][0]['courier_draft']['destinations'][$account->id])->resolved->toBeTrue()->where->toBe('Dhaka / Dhanmondi')
        ->and($props['challans'][0]['courier_draft']['cod_whole'])->toBe(1700);

    // The fallback still works for staff.
    $this->actingAs($this->staff)->post("/sales/deliveries/{$delivery->id}/consignment", [
        'money_account_id' => $this->courierMoney->id, 'consignment_number' => 'TYPED-1', 'cod_amount' => '1700',
    ])->assertSessionHasNoErrors();
    expect(Consignment::query()->sole())->booked_via->toBe('manual')->courier_account_id->toBeNull();
});

it('refuses an account that is switched off or does not serve this business', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);
    Http::fake();

    $account->forceFill(['is_active' => false])->save();
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');

    $account->forceFill(['is_active' => true])->save();
    CourierAccountBusiness::query()->delete();
    cbkBook($this, $delivery, $account)->assertSessionHasErrors('courier_account_id');

    Http::assertNothingSent();
});

it('keeps names, phones and addresses out of every log and summary', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    $delivery = cbkChallan($this);

    Http::fake([CBK_CB.'/api/v2/orders' => Http::response(['error' => true, 'message' => 'The recipient phone '.CBK_PHONE.' is blocked', 'causes' => ['recipient_phone' => [['type' => 'invalid']]]], 422)]);

    cbkBook($this, $delivery, $account)->assertSessionHas('error');

    $intent = CourierBookingRequest::query()->sole();
    $stored = json_encode([$intent->toArray(), CourierApiCall::query()->get()->toArray()]);

    expect($intent->status)->toBe('failed')
        ->and($intent->response_summary['result']['fields'])->toBe(['recipient_phone'])
        ->and($stored)->not->toContain(CBK_PHONE)->not->toContain('Karim')->not->toContain('Road 2')
        ->and($stored)->toContain('[phone]');
});

it('stores the automatic posting switch off, refuses to turn it on, and lets the Owner switch it off', function () {
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);

    expect($account->auto_post_outcomes)->toBeFalse();

    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/auto-post", ['on' => true])
        ->assertSessionHasErrors(['auto_post_outcomes' => 'Automatic posting is available after the evidence period. Until then a person confirms every parcel.']);
    $this->actingAs($this->manager)->post("/settings/couriers/{$account->id}/auto-post", ['on' => false])->assertForbidden();
    $this->actingAs($this->owner)->post("/settings/couriers/{$account->id}/auto-post", ['on' => false])->assertSessionHasNoErrors();

    expect($account->fresh())->auto_post_outcomes->toBeFalse()->auto_post_switched_off_at->not->toBeNull();
});

it('reads every documented courier word, and keeps an unknown one raw without moving the parcel', function () {
    $cb = app(\App\Modules\Sales\Couriers\Providers\CourierProviderRegistry::class)->get('carrybee');
    $pathao = app(\App\Modules\Sales\Couriers\Providers\CourierProviderRegistry::class)->get('pathao');

    $carrybee = [
        'order.created' => 'booked', 'order.pickup-requested' => 'booked', 'order.assigned-for-pickup' => 'pickup_assigned',
        'order.picked' => 'picked_up', 'order.pickup-failed' => 'on_hold', 'order.pickup-cancelled' => 'cancelled',
        'order.at-the-sorting-hub' => 'in_transit', 'order.on-the-way-to-central-warehouse' => 'in_transit',
        'order.at-central-warehouse' => 'in_transit', 'order.in-transit' => 'in_transit',
        'order.received-at-last-mile-hub' => 'at_delivery_hub', 'order.assigned-for-delivery' => 'out_for_delivery',
        'order.delivery-on-hold' => 'on_hold', 'order.delivered' => 'delivered', 'order.partial-delivery' => 'partially_delivered',
        'order.delivery-failed' => 'delivery_failed', 'order.returned' => 'returning', 'order.paid-return' => 'paid_return',
        'order.exchange' => 'exchanged', 'order.returned-at-sorting' => 'returning', 'order.returned-in-transit' => 'returning',
        'order.returned-to-merchant' => 'returned_to_merchant',
    ];

    foreach ($carrybee as $word => $status) {
        expect($cb->readStatus($word)->status?->value)->toBe($status, $word);
    }

    expect($cb->readStatus('order.paid')->kind)->toBe('payment')
        ->and($cb->readStatus('order.updated')->kind)->toBe('ignore')
        ->and($cb->readStatus('order.create-failed')->kind)->toBe('ignore');

    $slugs = [
        'Pending' => 'booked', 'Pickup_Requested' => 'booked', 'Assigned_for_Pickup' => 'pickup_assigned', 'Picked' => 'picked_up',
        'In_Transit' => 'in_transit', 'At_the_Sorting_HUB' => 'in_transit', 'Received_at_Last_Mile_HUB' => 'at_delivery_hub',
        'Assigned_for_Delivery' => 'out_for_delivery', 'Delivered' => 'delivered', 'Partial_Delivery' => 'partially_delivered',
        'Return' => 'returning', 'Delivery_Failed' => 'delivery_failed', 'On_Hold' => 'on_hold', 'Paid_Return' => 'paid_return',
        'Exchange' => 'exchanged', 'Returned_To_Merchant' => 'returned_to_merchant',
    ];

    foreach ($slugs as $word => $status) {
        expect($pathao->readStatus($word)->status?->value)->toBe($status, $word);
    }

    expect($pathao->readStatus('Payment_Invoice')->kind)->toBe('payment')
        ->and($pathao->readStatus('Teleported')->known())->toBeFalse();

    // An unknown word through the webhook: kept on its row, the parcel unmoved.
    $account = cbkAccount('carrybee', $this->business, $this->courierMoney);
    Http::fake([CBK_CB.'/api/v2/orders' => cbkOk(['order' => ['consignment_id' => 'FA1', 'delivery_fee' => '60', 'cod_fee' => 0]])]);
    cbkBook($this, cbkChallan($this), $account);

    $this->postJson('/webhooks/couriers/'.$account->uuid, ['event' => 'order.teleported', 'consignment_id' => 'FA1'],
        ['X-CB-Webhook-Integration-Header' => $account->webhookSecretValue()])->assertStatus(202);

    expect(CourierShipmentEvent::query()->where('event', 'order.teleported')->sole())->raw_status->toBe('order.teleported')->applied->toBeFalse()->canonical_status->toBeNull()
        ->and(Consignment::query()->sole()->courier_status)->toBe(CourierStatus::Booked);
});
