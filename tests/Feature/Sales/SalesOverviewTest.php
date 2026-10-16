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
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use App\Support\Dashboards\Widgets\CourierParcelsByStatusWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Sales overview (module redesign, 15 October 2026): one page that reads
 * the role dashboards' own widgets, so every number is one the dashboards
 * already show, and sends each card only with the permission its widget
 * checks. A cashier with `sales.sell` alone still gets a page.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Overview Co', 'slug' => 'overview-co']);
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

    // The books, for the money cards: the Owner reads the ledger, nobody else here.
    foreach (['accounting.view', 'accounting.operations.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::findByName('Owner')->givePermissionTo(['accounting.view', 'accounting.operations.view']);
    // One permission each, to prove the page renders with any subset.
    Role::create(['name' => 'Cashier', 'guard_name' => 'web', 'business_id' => $this->business->id])->givePermissionTo('sales.sell');
    Role::create(['name' => 'Nobody', 'guard_name' => 'web', 'business_id' => $this->business->id]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->manager = ($this->user)('Manager');
    $this->staff = ($this->user)('Staff');
    $this->cashier = ($this->user)('Cashier');
    $this->nobody = ($this->user)('Nobody');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $this->order = fn (User $by, string $quantity = '2'): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $by,
    );

    $this->deliver = fn (SalesOrder $order, string $quantity): Delivery => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
        deliveredAt: '2026-10-15 09:00:00',
    );

    $this->book = function (Delivery $delivery, string $cod, ?CourierStatus $status = null): Consignment {
        $this->actingAs($this->owner)
            ->post("/sales/deliveries/{$delivery->id}/consignment", [
                'money_account_id' => $this->courier->id,
                'consignment_number' => 'C-'.$delivery->id,
                'cod_amount' => $cod,
            ])->assertSessionHasNoErrors();

        $parcel = Consignment::query()->where('delivery_id', $delivery->id)->sole();

        if ($status !== null) {
            $parcel->forceFill(['courier_status' => $status, 'courier_status_at' => now()])->save();
        }

        return $parcel;
    };

    $this->props = function (User $as, string $query = '') {
        // The widgets cache their payloads; this page reads them fresh.
        Cache::flush();

        return $this->actingAs($as)->get('/sales/overview'.$query)->assertOk()->original->getData()['page']['props'];
    };
});

afterEach(fn () => Carbon::setTestNow());

it('opens for whoever may read sales, sell or take an order, and for nobody else', function () {
    foreach ([$this->owner, $this->manager, $this->staff, $this->cashier] as $user) {
        $this->actingAs($user)->get('/sales/overview')->assertOk();
    }

    $this->actingAs($this->nobody)->get('/sales/overview')->assertForbidden();
});

it('renders for a cashier who may only sell: the till, and every other card absent rather than zero', function () {
    DB::table('pos_shifts')->insert([
        'business_id' => $this->business->id, 'money_account_id' => $this->courier->id, 'opened_by' => $this->cashier->id,
        'opened_at' => '2026-10-15 09:05:00', 'opening_float' => 0, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $props = ($this->props)($this->cashier);

    expect($props['can']['sell'])->toBeTrue()
        ->and($props['can']['sales'])->toBeFalse()
        ->and($props['today_strip']['till'])->toHaveCount(1)
        ->and($props['today_strip']['till'][0]['opened_at'])->toBe('09:05')
        ->and($props['today_strip']['invoiced'])->toBeNull()
        ->and($props['today_strip']['orders_in'])->toBeNull()
        ->and($props['today_strip']['delivered'])->toBeNull()
        ->and($props['today_strip']['received'])->toBeNull()
        ->and($props['flow'])->toBeNull()
        ->and($props['invoiced'])->toBeNull()
        ->and($props['attention'])->toBeNull()
        ->and($props['parcels'])->toBeNull()
        ->and($props['orders'])->toBeNull()
        ->and($props['top_products'])->toBeNull()
        ->and($props['statements'])->toBeNull()
        ->and($props['connections'])->toBeNull()
        ->and($props['money']['receivables'])->toBeNull()
        ->and($props['money']['in_transit'])->toBeNull()
        ->and($props['money']['courier_balances'])->toBeNull();
});

it('reads the flow strip and the orders card from the orders and cash-on-delivery widgets', function () {
    // One order waiting for approval (Staff), one approved and part delivered
    // with its parcel in transit, one delivered and invoiced today.
    ($this->order)($this->staff);
    $partial = ($this->order)($this->owner, '4');
    ($this->book)(($this->deliver)($partial, '2'), '1700', CourierStatus::InTransit);
    $done = ($this->order)($this->owner, '2');
    $parcel = ($this->book)(($this->deliver)($done, '2'), '1700', CourierStatus::Delivered);
    $this->actingAs($this->owner)->post("/sales/consignments/{$parcel->id}/outcome", [
        'outcome' => 'delivered', 'outcome_on' => '2026-10-15', 'collected_amount' => '1700', 'lines' => [],
    ])->assertSessionHasNoErrors();

    $props = ($this->props)($this->owner);
    $flow = collect($props['flow'])->keyBy('key');

    expect($flow['approval']['value'])->toBe(1)
        ->and($flow['dispatch']['value'])->toBe(1)
        ->and($flow['awaiting']['value'])->toBe(1)
        ->and($flow['awaiting']['note'])->toBe('COD to collect 1,700')
        ->and($flow['recorded']['value'])->toBe(1)
        ->and($flow['overdue']['value'])->toBe(0)
        ->and($flow['overdue']['tone'])->toBeNull()
        ->and($props['orders']['kind'])->toBe('breakdown')
        ->and(collect($props['orders']['items'])->firstWhere('label', 'Awaiting approval')['value'])->toBe(1)
        ->and($props['orders']['drill_down'])->toBe('/sales/orders')
        ->and($props['today_strip']['orders_in'])->toBe(3)
        ->and($props['today_strip']['delivered'])->toBe(1)
        ->and($props['today_strip']['came_back'])->toBe(0)
        ->and(collect($props['parcels']['items'])->firstWhere('label', 'In transit')['value'])->toBe(1);
});

it('says what was invoiced today by channel with the Sales by channel report\'s own arithmetic', function () {
    $done = ($this->order)($this->owner, '2');
    $parcel = ($this->book)(($this->deliver)($done, '2'), '1700', CourierStatus::Delivered);
    $this->actingAs($this->owner)->post("/sales/consignments/{$parcel->id}/outcome", [
        'outcome' => 'delivered', 'outcome_on' => '2026-10-15', 'collected_amount' => '1700', 'lines' => [],
    ])->assertSessionHasNoErrors();

    $props = ($this->props)($this->owner);
    $today = $props['today_strip']['invoiced'];

    expect($today['invoices'])->toBe(1)
        ->and($today['net'])->toBe(1700.0)
        ->and($today['channels'][0]['key'])->toBe('internal')
        ->and($today['channels'][0]['net'])->toBe(1700.0)
        ->and($props['invoiced']['net'])->toBe(1700.0)
        ->and($props['invoiced']['brands'][0]['label'])->toBe('No brand')
        // Yesterday's range holds none of it.
        ->and(($this->props)($this->owner, '?range=yesterday')['invoiced']['invoices'])->toBe(0);
});

it('groups what needs attention by reason, from the same sources the attention page reads', function () {
    $order = ($this->order)($this->owner, '2');
    ($this->book)(($this->deliver)($order, '2'), '1700', CourierStatus::DeliveryFailed);

    $attention = ($this->props)($this->owner)['attention'];
    $row = collect($attention['rows'])->firstWhere('key', 'parcel_problem');

    expect($attention['total'])->toBeGreaterThanOrEqual(1)
        ->and($row['count'])->toBe(1)
        ->and($row['tone'])->toBe('info')
        ->and($row['url'])->toBe('/sales/consignments')
        ->and($row['hint'])->toContain('Delivery failed')
        ->and($attention['failed'])->toBe([]);
});

it('sends each money card only with the permission its widget checks', function () {
    $owner = ($this->props)($this->owner);
    $manager = ($this->props)($this->manager);

    expect($owner['money']['receivables']['kind'])->toBe('breakdown')
        ->and($owner['money']['in_transit']['kind'])->toBe('stat')
        ->and($owner['today_strip']['received'])->toBe(0.0)
        ->and($owner['statements']['kind'])->toBe('list')
        // A Manager reads sales, not the ledger: the ledger cards are absent, the sales ones present.
        ->and($manager['money']['receivables'])->toBeNull()
        ->and($manager['money']['in_transit'])->toBeNull()
        ->and($manager['money']['courier_balances'])->toBeNull()
        ->and($manager['today_strip']['received'])->toBeNull()
        ->and($manager['flow'])->not->toBeNull()
        ->and($manager['top_products']['kind'])->toBe('breakdown')
        ->and($manager['connections']['kind'])->toBe('empty');
});

it('keeps a bad custom range from breaking the page', function () {
    $props = ($this->props)($this->owner, '?range=custom&from=2026-13-01&to=2026-10-15');

    expect($props['range_error'])->not->toBeNull()
        ->and($props['range']['preset'])->toBe('this_month');
});

// ⚠ Bug hunt 16 Oct 2026: "a widget that throws arrives as its error state,
// never as zeros" - the today strip read the parcels widget's error as 0.
it('shows no parcel counts in the today strip when the parcels widget fails, never zeros', function () {
    app(WidgetRegistry::class)->register(new class extends CourierParcelsByStatusWidget
    {
        public function data(WidgetContext $context): array
        {
            throw new RuntimeException('Courier tables unreadable');
        }
    });

    $props = ($this->props)($this->owner);

    expect($props['today_strip']['delivered'])->toBeNull()
        ->and($props['today_strip']['came_back'])->toBeNull();
});
