<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The dashboard's Brand switcher (Joy, 11 Oct 2026): All brands, LaLaDia,
 * Bionic-Garden, in the URL beside the range. Orders and parcels follow it, the
 * ledger does not, a cached figure is never shared between brands, and a brand
 * of another business is ignored. Authorisation is unchanged.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bdg']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['dashboard.view_all', 'accounting.view', 'sales.reports.view', 'sales.parcels.see', 'sales.orders.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    Role::create(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['dashboard.view_all', 'accounting.view', 'sales.reports.view', 'sales.parcels.see']);
    (new DashboardAssignmentSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->courier = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier, 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true]);

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);
});

afterEach(fn () => Carbon::setTestNow());

function dbOrder(object $t, ?Brand $brand): SalesOrder
{
    return app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => '1']],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal),
        brandId: $brand?->id,
    );
}

/** A challan of the order and its parcel at the courier, straight to the tables. */
function dbParcel(object $t, SalesOrder $order, string $courierStatus): void
{
    $delivery = DB::table('deliveries')->insertGetId([
        'business_id' => $t->business->id, 'customer_id' => $t->customer->id, 'warehouse_id' => $t->warehouse->id,
        'delivery_number' => 'DC-'.Str::random(6), 'delivered_at' => now(), 'created_by' => $t->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('delivery_lines')->insert([
        'business_id' => $t->business->id, 'delivery_id' => $delivery, 'product_id' => $t->product->id,
        'sales_order_line_id' => $order->lines->first()->id, 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('consignments')->insert([
        'business_id' => $t->business->id, 'delivery_id' => $delivery, 'money_account_id' => $t->courier->id,
        'consignment_number' => 'C-'.Str::random(8), 'cod_amount' => 850, 'status' => 'booked',
        'courier_status' => $courierStatus, 'courier_status_at' => now()->subDay(),
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function dbWidget(object $t, string $key, ?int $brandId): array
{
    return app(WidgetRegistry::class)->find($key)->data(
        new WidgetContext($t->owner, $t->business->id, DateRange::fromQuery(['range' => 'this_month']), DashboardKey::Owner, $brandId),
    );
}

it('offers the brands beside the range and keeps the chosen one in every link', function () {
    $this->actingAs($this->owner)->get('/dashboard/owner')
        ->assertInertia(fn (Assert $page) => $page->where('brand.selected', null)->has('brand.options', 2)
            ->where('range.query', ['range' => 'this_month']));

    $this->actingAs($this->owner)->get('/dashboard/owner?brand='.$this->bnc->id)
        ->assertInertia(fn (Assert $page) => $page->where('brand.selected', $this->bnc->id)
            ->where('range.query', ['range' => 'this_month', 'brand' => (string) $this->bnc->id])
            ->where('widgets', fn ($widgets) => collect($widgets)->firstWhere('key', 'orders_by_state')['brand_aware'] === true
                && collect($widgets)->every(fn ($w) => array_key_exists('brand_aware', $w))));
});

it('ignores a brand of another business, showing all brands', function () {
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    $foreign = Brand::query()->withoutGlobalScopes()->create(['business_id' => $other->id, 'code' => 'XYZ', 'name' => 'Foreign', 'slug' => 'foreign']);
    dbOrder($this, $this->lld);

    $this->actingAs($this->owner)->get('/dashboard/owner?brand='.$foreign->id)
        ->assertInertia(fn (Assert $page) => $page->where('brand.selected', null)->where('range.query', ['range' => 'this_month']));

    $this->actingAs($this->owner)->getJson('/dashboard/widgets/orders_by_state?dashboard=owner&brand='.$foreign->id)
        ->assertOk()->assertJsonPath('data.watch.orders_today', 1);
});

it('counts orders of the chosen brand only, and never serves one brand\'s cached figure for another', function () {
    dbOrder($this, $this->lld);
    dbOrder($this, $this->lld);
    dbOrder($this, $this->bnc);
    dbOrder($this, null);

    $today = fn (string $query) => $this->actingAs($this->owner)
        ->getJson('/dashboard/widgets/orders_by_state?dashboard=owner'.$query)->assertOk()->json('data.watch.orders_today');

    // Asked in this order on purpose: each answer is cached for 15 seconds.
    expect($today(''))->toBe(4)
        ->and($today('&brand='.$this->lld->id))->toBe(2)
        ->and($today('&brand='.$this->bnc->id))->toBe(1)
        ->and($today(''))->toBe(4);
});

it('follows the brand on the parcel widgets, through the parcel\'s order', function () {
    dbParcel($this, dbOrder($this, $this->lld), 'in_transit');
    dbParcel($this, dbOrder($this, $this->lld), 'on_hold');
    dbParcel($this, dbOrder($this, $this->bnc), 'on_hold');

    $total = fn (?int $brand) => dbWidget($this, 'courier_parcels_by_status', $brand)['total'];
    $attention = fn (?int $brand) => dbWidget($this, 'courier_parcels_attention', $brand)['total_count'];

    expect($total(null))->toBe(3)->and($total($this->lld->id))->toBe(2)->and($total($this->bnc->id))->toBe(1)
        ->and($attention(null))->toBe(2)->and($attention($this->lld->id))->toBe(1)->and($attention($this->bnc->id))->toBe(1);

    $chain = collect(dbWidget($this, 'courier_cod_chain', $this->bnc->id)['items'])->firstWhere('label', 'Booked, awaiting delivery');
    expect($chain['value'])->toBe(850.0);
});

it('keeps ledger widgets whole-business and says so, and changes nobody\'s access', function () {
    $registry = app(WidgetRegistry::class);

    expect($registry->find('courier_balances')->brandAware())->toBeFalse()
        ->and($registry->find('orders_by_state')->brandAware())->toBeTrue()
        ->and($registry->find('courier_parcels_by_status')->brandAware())->toBeTrue();

    $context = fn (?int $brand) => new WidgetContext($this->owner, $this->business->id, DateRange::fromQuery([]), DashboardKey::Owner, $brand);

    // A whole-business widget has one cache entry whatever the brand.
    expect($registry->cacheKey($registry->find('courier_balances'), $context($this->lld->id)))
        ->toBe($registry->cacheKey($registry->find('courier_balances'), $context(null)))
        ->and($registry->cacheKey($registry->find('orders_by_state'), $context($this->lld->id)))
        ->not->toBe($registry->cacheKey($registry->find('orders_by_state'), $context(null)));

    // Somebody without the permission is still refused, brand or not.
    $plain = User::factory()->create();
    $plain->businesses()->attach($this->business->id);
    $this->actingAs($plain)->getJson('/dashboard/widgets/orders_by_state?dashboard=owner&brand='.$this->lld->id)->assertForbidden();
});
