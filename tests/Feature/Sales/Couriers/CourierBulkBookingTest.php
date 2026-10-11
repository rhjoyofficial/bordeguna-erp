<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Booking\BookParcelThroughCourier;
use App\Modules\Sales\Couriers\Jobs\BookParcelsInBulk;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierBulkRun;
use App\Modules\Sales\Couriers\Models\CourierBulkRunItem;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Parcels to book (Joy, 11 Oct 2026): approved orders, challans made and booked
 * in one queued run, every answer written down, labels to print. Most tests are
 * about what must NOT happen: stock moved for a parcel no courier can take, a
 * parcel booked twice, an order short of stock leaving half its goods.
 */
const CBB_CB = 'https://developers.carrybee.com';

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    config(['couriers.bulk_pace_ms' => 0]);

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg-bulk']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['owner' => 'Owner', 'manager' => 'Manager', 'staff' => 'Staff'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    $this->money = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier,
        'provider' => 'carrybee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    CourierLocation::create(['provider' => 'carrybee', 'level' => 'city', 'provider_location_id' => '14', 'name' => 'Dhaka', 'synced_at' => now()]);
    CourierLocation::create(['provider' => 'carrybee', 'level' => 'zone', 'provider_location_id' => '101', 'parent_provider_id' => '14', 'name' => 'Dhanmondi', 'synced_at' => now()]);
    CourierLocationMap::create([
        'provider' => 'carrybee', 'district' => 'Dhaka', 'thana' => 'Dhanmondi',
        'provider_city_id' => '14', 'provider_city_name' => 'Dhaka', 'provider_zone_id' => '101', 'provider_zone_name' => 'Dhanmondi',
        'status' => CourierLocationMap::APPLIED, 'source' => CourierLocationMap::MANUAL, 'confidence' => CourierLocationMap::HIGH,
    ]);

    $this->account = cbbAccount($this->business, $this->money);

    Http::preventStrayRequests();
    $this->withoutVite();
});

afterEach(fn () => Carbon::setTestNow());

function cbbAccount(Business $business, MoneyAccount $money): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee', 'label' => 'CarryBee LaLaDia', 'environment' => 'live', 'base_url' => CBB_CB,
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ]);
    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $business->id, 'provider_store_id' => '24869',
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $money->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

/** An approved order (the Owner's own) going to $thana. */
function cbbOrder(object $t, string $quantity = '2', string $thana = 'Dhanmondi', ?string $ref = null): SalesOrder
{
    $order = app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => $quantity]],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, shopReference: $ref, deliveryCharge: '60'),
    );
    $order->address()->create([
        'business_id' => $order->business_id, 'recipient_name' => 'Karim Uddin', 'recipient_phone' => '01711000000',
        'district' => 'Dhaka', 'thana' => $thana, 'address_line' => 'House 4, Road 2, Jigatola',
    ]);

    return $order->fresh();
}

function cbbOk(string $consignment): array
{
    return [CBB_CB.'/api/v2/orders' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['order' => [
        'consignment_id' => $consignment, 'delivery_fee' => '90', 'cod_fee' => 8.5,
    ]]], 201)];
}

it('lists approved orders waiting for a courier, and names the ones whose address the courier cannot take', function () {
    $ready = cbbOrder($this, ref: '#LLD-1');
    $mirpur = cbbOrder($this, thana: 'Mirpur');
    $waiting = app(CreateSalesOrder::class)->handle(customer: $this->customer, lines: [['product' => $this->product, 'quantity' => '1']], createdBy: $this->staff);

    $this->actingAs($this->manager)->get('/sales/parcels-to-book')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sales/parcels-to-book/index')
        ->has('orders', 2)
        ->where('orders.1.id', $ready->id)
        ->where('orders.1.shop_reference', '#LLD-1')
        ->where('orders.1.kind', 'challan')
        ->where('orders.1.cod', 1760)
        ->where('orders.1.weight_grams', 900)
        ->where('orders.1.default_account_id', $this->account->id)
        ->where("orders.1.destinations.{$this->account->id}", null)
        ->where('fix_addresses.0.id', $mirpur->id)
        ->where('fix_addresses.0.message', 'Mirpur, Dhaka has no CarryBee zone yet. Map it in Settings > Couriers > Locations.')
        ->where('accounts.0.is_default', true));

    expect($waiting->approvalRequest->isPending())->toBeTrue();
});

it('makes the challan and books every selected order, with each answer written down', function () {
    $a = cbbOrder($this, ref: '#LLD-A');
    $b = cbbOrder($this, quantity: '1', ref: '#LLD-B');

    $numbers = ['FA1', 'FA2'];
    Http::fake([CBB_CB.'/api/v2/orders' => function () use (&$numbers) {
        return Http::response(['error' => false, 'message' => 'ok', 'data' => ['order' => ['consignment_id' => array_shift($numbers), 'delivery_fee' => '90', 'cod_fee' => 8.5]]], 201);
    }]);

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$a->id, $b->id], 'warehouse_id' => $this->warehouse->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $run = CourierBulkRun::query()->sole();
    expect($run->status)->toBe(CourierBulkRun::FINISHED)
        ->and($run->total)->toBe(2)->and($run->done)->toBe(2)->and($run->booked)->toBe(2);

    $items = $run->items()->orderBy('id')->get();
    expect($items->pluck('status')->all())->toBe(['booked', 'booked'])
        ->and($items->pluck('consignment_number')->all())->toBe(['FA1', 'FA2'])
        ->and((float) $items[0]->fee_quoted)->toBe(90.0)
        ->and($items[0]->cod_amount)->toBe(1760)
        ->and($items[1]->cod_amount)->toBe(910);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->data()['merchant_order_id'] === Delivery::query()->find($items[0]->delivery_id)->delivery_number
        && $request->data()['collectable_amount'] === 1760);

    expect(Consignment::query()->count())->toBe(2)
        ->and($a->fresh()->status->value)->toBe('delivered');

    // The progress endpoint says the same, and the run page shows it.
    $this->actingAs($this->manager)->getJson("/sales/parcels-to-book/runs/{$run->id}/progress")->assertOk()
        ->assertJsonPath('booked', 2)->assertJsonPath('finished', true)->assertJsonPath('items.0.consignment_number', 'FA1');
    $this->actingAs($this->manager)->get("/sales/parcels-to-book/runs/{$run->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sales/parcels-to-book/run')->where('run.items.1.status', 'booked'));

    // Booked orders leave the list.
    $this->actingAs($this->manager)->get('/sales/parcels-to-book')->assertInertia(fn (Assert $page) => $page->has('orders', 0));
});

it('refuses an order short of stock by name, and moves nothing for it', function () {
    $short = cbbOrder($this, quantity: '50');
    Http::fake();

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$short->id], 'warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();

    $item = CourierBulkRunItem::query()->sole();
    expect($item->status)->toBe(CourierBulkRunItem::REFUSED)
        ->and($item->message)->toBe('Not enough Royal Essence Ghee 400g in Main: needs 50, has 10.')
        ->and(Delivery::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('sends a place the courier has no zone for to fix address, before any challan is made', function () {
    $order = cbbOrder($this, thana: 'Mirpur');
    Http::fake();

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();

    $item = CourierBulkRunItem::query()->sole();
    expect($item->status)->toBe(CourierBulkRunItem::FIX_ADDRESS)
        ->and($item->message)->toContain('Mirpur, Dhaka has no CarryBee zone yet')
        ->and(Delivery::query()->count())->toBe(0)
        ->and(CourierBulkRun::query()->sole()->fix_address)->toBe(1);

    Http::assertNothingSent();
});

it('never books twice: an order in an open run, a run started again, and a parcel whose answer was lost', function () {
    $order = cbbOrder($this);

    // An order already in a run that has not answered for it.
    $run = CourierBulkRun::create(['business_id' => $this->business->id, 'warehouse_id' => $this->warehouse->id, 'status' => CourierBulkRun::QUEUED, 'total' => 1, 'created_by' => $this->manager->id]);
    CourierBulkRunItem::create(['business_id' => $this->business->id, 'courier_bulk_run_id' => $run->id, 'sales_order_id' => $order->id, 'status' => CourierBulkRunItem::PENDING]);

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse->id])
        ->assertSessionHasErrors(['order_ids' => "Already being booked: {$order->order_number}. Wait for that run to finish."]);

    // The courier times out: the parcel may exist, so it is UNKNOWN and never re-sent.
    Http::fake([CBB_CB.'/api/v2/orders' => fn () => throw new ConnectionException('cURL error 28: timed out'), CBB_CB.'/*' => Http::response([], 500)]);
    (new BookParcelsInBulk($this->business->id, $run->id, $this->manager->id))->handle(app(RecordDelivery::class), app(BookParcelThroughCourier::class), app(ResolveSellingPrice::class));

    $item = CourierBulkRunItem::query()->sole();
    expect($item->status)->toBe(CourierBulkRunItem::UNKNOWN)
        ->and(CourierBookingRequest::query()->sole()->status)->toBe(CourierBookingRequest::UNKNOWN);

    // Started again: a finished run does nothing.
    $sent = count(Http::recorded());
    (new BookParcelsInBulk($this->business->id, $run->id, $this->manager->id))->handle(app(RecordDelivery::class), app(BookParcelThroughCourier::class), app(ResolveSellingPrice::class));
    expect(count(Http::recorded()))->toBe($sent);

    // A new run for the same order stops at the open booking.
    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();
    $again = CourierBulkRunItem::query()->latest('id')->first();
    expect($again->status)->toBe(CourierBulkRunItem::UNKNOWN)
        ->and($again->message)->toContain('Settle it on the order first')
        ->and(Delivery::query()->count())->toBe(1)
        ->and(count(Http::recorded()))->toBe($sent);
});

it('books an order\'s existing challan as it is, and writes down the courier\'s refusal', function () {
    $order = cbbOrder($this);
    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '2', 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
    );

    Http::fake([CBB_CB.'/api/v2/orders' => Http::response(['error' => true, 'message' => 'The recipient phone is blocked'], 422)]);

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id]])->assertSessionHasNoErrors();

    $item = CourierBulkRunItem::query()->sole();
    expect($item->delivery_id)->toBe($delivery->id)
        ->and($item->status)->toBe(CourierBulkRunItem::REFUSED)
        ->and($item->message)->toContain('CarryBee LaLaDia refused')
        ->and(Delivery::query()->count())->toBe(1);
});

it('prints A4 labels for the booked parcels with the consignment as QR and barcode', function () {
    $order = cbbOrder($this, ref: '#LLD-LBL');
    Http::fake(cbbOk('FA1110ABCD'));

    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse->id]);
    $run = CourierBulkRun::query()->sole();

    $this->actingAs($this->manager)->get("/sales/parcels-to-book/labels?run={$run->id}&per_page=2")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/parcels-to-book/labels')
            ->where('per_page', 2)
            ->has('labels', 1)
            ->where('labels.0.number', 'FA1110ABCD')
            ->where('labels.0.recipient', 'Karim Uddin')
            ->where('labels.0.phone', '01711000000')
            ->where('labels.0.cod', '1,760.00')
            ->where('labels.0.shop_reference', '#LLD-LBL')
            ->where('labels.0.address', 'House 4, Road 2, Jigatola, Dhanmondi, Dhaka')
            ->where('labels.0.qr', fn (string $svg) => str_starts_with($svg, '<svg'))
            ->where('labels.0.barcode', fn (string $svg) => str_contains($svg, '<svg')));
});

it('lets only those who may book use parcels to book', function () {
    $order = cbbOrder($this);

    // Staff sell and follow parcels, but do not book through the API.
    $this->actingAs($this->staff)->get('/sales/parcels-to-book')->assertForbidden();
    $this->actingAs($this->staff)->post('/sales/parcels-to-book', ['order_ids' => [$order->id]])->assertForbidden();
    $this->actingAs($this->staff)->get('/sales/parcels-to-book/labels')->assertForbidden();

    $this->actingAs($this->manager)->get('/sales/parcels-to-book')->assertOk();
    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'courier_account_id' => 999999])
        ->assertSessionHasErrors('courier_account_id');
});

it('books with the courier account of the order\'s brand once orders and courier links carry one', function () {
    Schema::table('sales_orders', fn (Blueprint $table) => $table->unsignedBigInteger('brand_id')->nullable());
    Schema::table('courier_account_business', fn (Blueprint $table) => $table->unsignedBigInteger('brand_id')->nullable());

    $bionic = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden', 'is_active' => true]);
    $second = app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee', 'label' => 'CarryBee Bionic', 'environment' => 'live', 'base_url' => CBB_CB,
        'credentials' => ['client_id' => 'cb-id-2', 'client_secret' => 'cb-SECRET-2222', 'client_context' => 'cb-CONTEXT-2222'],
    ]);
    $second->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $second->currentFingerprint()])->save();
    $second->forceFill(['is_active' => true, 'activated_at' => now()])->save();
    $link = CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $second->id, 'business_id' => $this->business->id, 'provider_store_id' => '777',
        'provider_store_name' => 'Bionic', 'money_account_id' => $this->money->id, 'is_default' => false,
    ]);
    DB::table('courier_account_business')->where('id', $link->id)->update(['brand_id' => $bionic->id]);

    $order = cbbOrder($this);
    DB::table('sales_orders')->where('id', $order->id)->update(['brand_id' => $bionic->id]);
    $plain = cbbOrder($this);

    $this->actingAs($this->manager)->get('/sales/parcels-to-book')->assertInertia(fn (Assert $page) => $page
        ->where('orders.0.id', $plain->id)->where('orders.0.default_account_id', $this->account->id)
        ->where('orders.1.id', $order->id)->where('orders.1.default_account_id', $second->id)
        ->where('orders.1.brand', 'Bionic-Garden'));

    Http::fake(cbbOk('FA-BNC'));
    $this->actingAs($this->manager)->post('/sales/parcels-to-book', ['order_ids' => [$order->id], 'warehouse_id' => $this->warehouse->id])->assertSessionHasNoErrors();

    expect(CourierBulkRunItem::query()->sole()->courier_account_id)->toBe($second->id);
    Http::assertSent(fn (Request $request) => $request->data()['store_id'] === '777');
});
