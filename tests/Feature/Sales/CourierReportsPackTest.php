<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\AccountingPermissionsSeeder;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Reporting\Definitions\CourierSettlementAgeingReport;
use App\Modules\Sales\Reporting\Definitions\DeliveryEconomicsReport;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reports pack (11 Oct 2026): delivery economics and courier settlement ageing.
 *
 *   C1  LaLaDia, Pathao     charge 120 billed; posted statement: net 80 + COD 15;
 *                           delivered 3 Oct, collected 1820, paid out on a statement posted 4 Oct
 *   C2  Bionic, Steadfast   charge 60 NOT billed (came back); courier quoted 70
 *   C3  LaLaDia, Pathao     charge 100 billed; estimate 75 + 10, band DHK-SUB;
 *                           delivered 9 Oct, collected 1100, not paid out
 *   C4  no brand, Steadfast charge 50 billed; a DRAFT statement: net 40 + COD 5;
 *                           delivered 5 Oct, collected 900, not paid out (draft pays nothing)
 *   C5a/b LaLaDia, Pathao   one order in two parcels, charge 80 billed once;
 *                           C5a part delivered 20 Sep, collected 850; C5b still booked
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = crBusiness('Bor de Guna', 'bdg-cr');
    $this->owner = crUser($this->business, 'Owner');
    FinancePolicy::query()->create(['business_id' => $this->business->id, 'courier_settlement_alert_days' => 5]);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'sku' => 'GHEE', 'name' => 'Ghee', 'type' => ProductType::FinishedGood]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '50', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Buyer', 'type' => CustomerType::Retail]);

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic', 'slug' => 'bionic']);

    $this->pathao = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Pathao', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);
    $this->steadfast = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Steadfast', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);

    $o1 = crOrder($this, $this->lld, '2', '120');
    $d1 = crDeliver($this, $o1, '2', '2026-10-02', bill: true);
    $this->c1 = crParcel($this, $d1, $this->pathao, 'C1', 'delivered', '2026-10-03', 1820);
    $posted = crStatement($this, $this->pathao, 'ST-1', 'posted', '2026-10-04 15:00:00');
    crLine($this, $posted, $this->c1, 'C1', fee: 90, discount: 10, net: 80, cod: 15);

    $o2 = crOrder($this, $this->bnc, '1', '60');
    $d2 = crDeliver($this, $o2, '1', '2026-10-04', bill: false);
    $this->c2 = crParcel($this, $d2, $this->steadfast, 'C2', 'returned', '2026-10-06', 0, quote: [70, 0]);

    $o3 = crOrder($this, $this->lld, '1', '100');
    $d3 = crDeliver($this, $o3, '1', '2026-10-05', bill: true);
    $this->c3 = crParcel($this, $d3, $this->pathao, 'C3', 'delivered', '2026-10-09', 1100);
    DB::table('courier_fee_estimates')->insert([
        'business_id' => $this->business->id, 'delivery_id' => $d3->id, 'consignment_id' => $this->c3,
        'provider' => 'pathao', 'method' => 'rate_card', 'confidence' => 'high', 'basis' => 'Rate card',
        'to_band' => 'DHK-SUB', 'delivery_fee' => 75, 'cod_fee' => 10, 'total' => 85, 'created_at' => now(),
    ]);

    $o4 = crOrder($this, null, '1', '50');
    $d4 = crDeliver($this, $o4, '1', '2026-10-05', bill: true);
    $this->c4 = crParcel($this, $d4, $this->steadfast, 'C4', 'delivered', '2026-10-05', 900);
    $draft = crStatement($this, $this->steadfast, 'ST-2', 'draft', null);
    crLine($this, $draft, $this->c4, 'C4', fee: 40, discount: 0, net: null, cod: 5);

    $o5 = crOrder($this, $this->lld, '2', '80');
    $d5a = crDeliver($this, $o5, '1', '2026-09-19', bill: true);
    $d5b = crDeliver($this, $o5->fresh('lines'), '1', '2026-10-06', bill: true);
    $this->c5a = crParcel($this, $d5a, $this->pathao, 'C5A', 'partially_delivered', '2026-09-20', 850);
    $this->c5b = crParcel($this, $d5b, $this->pathao, 'C5B', 'booked', null, null);
});

afterEach(fn () => Carbon::setTestNow());

function crBusiness(string $name, string $slug): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff', 'Clerk'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new AccountingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    return $business;
}

function crUser(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    $user->assignRole($role);

    return $user;
}

function crOrder(object $t, ?Brand $brand, string $quantity, string $delivery): SalesOrder
{
    return app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => $quantity]],
        createdBy: $t->owner,
        orderedAt: '2026-09-15',
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $delivery),
        brandId: $brand?->id,
    );
}

function crDeliver(object $t, SalesOrder $order, string $quantity, string $on, bool $bill): Delivery
{
    $delivery = app(RecordDelivery::class)->handle(
        customer: $t->customer,
        warehouse: $t->warehouse,
        lines: [['product' => $t->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $t->owner,
        deliveredAt: $on.' 09:00:00',
    )->fresh(['lines.product', 'lines.orderLine', 'customer']);

    if ($bill) {
        app(RecordSalesInvoice::class)->handle(
            customer: $t->customer,
            lines: DeliveryBilling::lines($delivery, DeliveryBilling::everythingUninvoiced($delivery), app(ResolveSellingPrice::class), Carbon::parse($on)),
            createdBy: $t->owner,
            invoiceDate: $on,
        );
    }

    return $delivery;
}

function crParcel(object $t, Delivery $delivery, MoneyAccount $courier, string $number, string $status, ?string $outcomeOn, ?float $collected, ?array $quote = null): int
{
    return DB::table('consignments')->insertGetId([
        'business_id' => $t->business->id, 'delivery_id' => $delivery->id, 'money_account_id' => $courier->id,
        'consignment_number' => $number, 'cod_amount' => $collected ?? 0, 'status' => $status,
        'outcome_on' => $outcomeOn, 'collected_amount' => $collected,
        'provider_fee_quoted' => $quote[0] ?? null, 'provider_cod_fee_quoted' => $quote[1] ?? null,
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function crStatement(object $t, MoneyAccount $courier, string $reference, string $status, ?string $postedAt): int
{
    return DB::table('courier_statements')->insertGetId([
        'business_id' => $t->business->id, 'money_account_id' => $courier->id, 'statement_reference' => $reference,
        'statement_date' => '2026-10-04', 'status' => $status, 'posted_at' => $postedAt,
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function crLine(object $t, int $statement, int $consignment, string $number, float $fee, float $discount, ?float $net, float $cod): void
{
    DB::table('courier_statement_lines')->insert([
        'business_id' => $t->business->id, 'courier_statement_id' => $statement, 'row_number' => 1,
        'consignment_number' => $number, 'collectable_amount' => 0, 'collected_amount' => 0,
        'delivery_fee' => $fee, 'discount' => $discount, 'net_fee' => $net, 'cod_fee' => $cod,
        'additional_charge' => 7, 'final_fee' => $fee - $discount + $cod, 'payout' => 0,
        'consignment_id' => $consignment, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function crRows(string $class, array $parameters): Illuminate\Support\Collection
{
    return app($class)->rows(new ReportParameters($parameters))->collect();
}

// ── Delivery economics ────────────────────────────────────────────────────

it('sets each parcel\'s billed charge against the courier\'s cost, labelled by where the cost came from', function () {
    $rows = crRows(DeliveryEconomicsReport::class, [])->keyBy('consignment');

    expect($rows)->toHaveCount(6)
        ->and($rows['C1'])->toMatchArray(['charge_billed' => 120.0, 'courier_cost' => 95.0, 'cod_fee' => 15.0, 'margin' => 25.0, 'cost_source' => 'Statement', 'brand' => 'LaLaDia', 'courier' => 'Pathao'])
        ->and($rows['C1']['cod_share'])->toBe(15.79)
        ->and($rows['C2'])->toMatchArray(['charge_billed' => 0.0, 'courier_cost' => 70.0, 'margin' => -70.0, 'cost_source' => 'Courier quote', 'brand' => 'Bionic'])
        ->and($rows['C3'])->toMatchArray(['charge_billed' => 100.0, 'courier_cost' => 85.0, 'cost_source' => 'Estimate', 'band' => 'DHK-SUB'])
        // A draft statement line: the courier's figure, said to be unposted; no net_fee
        // column on it, so fee less discount. The extra charge is not parcel cost.
        ->and($rows['C4'])->toMatchArray(['charge_billed' => 50.0, 'courier_cost' => 45.0, 'cost_source' => 'Statement, not posted', 'brand' => 'No brand'])
        // One order, two parcels: the charge counts once, on the first.
        ->and($rows['C5A']['charge_billed'])->toBe(80.0)
        ->and($rows['C5B']['charge_billed'])->toBe(0.0)
        ->and($rows['C5B']['cost_source'])->toBe('No figure');
});

it('ties the charges billed to Delivery Charges Income, counted once', function () {
    $rows = crRows(DeliveryEconomicsReport::class, []);
    $income = (int) DB::table('journal_lines')
        ->where('business_id', $this->business->id)
        ->where('account_id', AccountMapping::resolve('sales.delivery_income', Carbon::today())->id)
        ->sum('amount_minor');

    expect((int) round($rows->sum('charge_billed') * 100))->toBe(-1 * $income)
        ->and($rows->sum('charge_billed'))->toBe(350.0);
});

it('totals by courier, brand and band, and filters by brand, courier and challan date', function () {
    $byCourier = crRows(DeliveryEconomicsReport::class, ['group_by' => 'courier'])->keyBy('label');
    $byBrand = crRows(DeliveryEconomicsReport::class, ['group_by' => 'brand'])->keyBy('label');
    $byBand = crRows(DeliveryEconomicsReport::class, ['group_by' => 'band'])->keyBy('label');

    expect($byCourier['Pathao'])->toMatchArray(['parcels' => 4, 'on_actual' => 1, 'charge_billed' => 300.0, 'courier_cost' => 180.0])
        ->and($byCourier['Steadfast'])->toMatchArray(['parcels' => 2, 'on_actual' => 0, 'charge_billed' => 50.0, 'courier_cost' => 115.0, 'margin' => -65.0])
        ->and($byBrand['LaLaDia']['parcels'])->toBe(4)
        ->and($byBrand['No brand']['parcels'])->toBe(1)
        ->and($byBand['DHK-SUB']['parcels'])->toBe(1)
        ->and($byBand['Not estimated']['parcels'])->toBe(5)
        ->and(crRows(DeliveryEconomicsReport::class, ['brand' => (string) $this->bnc->id])->pluck('consignment')->all())->toBe(['C2'])
        ->and(crRows(DeliveryEconomicsReport::class, ['brand' => OrderBrand::NONE])->pluck('consignment')->all())->toBe(['C4'])
        ->and(crRows(DeliveryEconomicsReport::class, ['courier' => (string) $this->steadfast->id])->pluck('consignment')->sort()->values()->all())->toBe(['C2', 'C4'])
        ->and(crRows(DeliveryEconomicsReport::class, ['dispatched_from' => '2026-10-01', 'dispatched_to' => '2026-10-04'])->pluck('consignment')->all())->toBe(['C1', 'C2']);
});

// ── Courier settlement ageing ─────────────────────────────────────────────

it('ages collected cash not paid out on a posted statement, overdue by the finance policy', function () {
    $rows = crRows(CourierSettlementAgeingReport::class, ['as_at' => '2026-10-11'])->keyBy('courier');

    // Pathao: C3 (2 days) and C5A (21 days); C1 was paid out; C5B is still booked.
    expect($rows['Pathao'])->toMatchArray([
        'parcels' => 2, 'collected' => 1950.0,
        'days_0_3' => 1100.0, 'days_4_7' => 0.0, 'days_8_14' => 0.0, 'days_15_plus' => 850.0,
        'overdue_parcels' => 1, 'overdue_amount' => 850.0,
    ])
        // Steadfast: C4, 6 days, on a statement that is only a draft; 5 days is the policy.
        ->and($rows['Steadfast'])->toMatchArray(['parcels' => 1, 'days_4_7' => 900.0, 'overdue_parcels' => 1]);
});

it('reads both halves as at the day: the outcome recorded by then, and only statements posted by then', function () {
    $rows = crRows(CourierSettlementAgeingReport::class, ['as_at' => '2026-10-03', 'group_by' => 'parcel'])->keyBy('consignment');

    expect($rows->keys()->sort()->values()->all())->toBe(['C1', 'C5A'])
        ->and($rows['C1']['days'])->toBe(0)
        ->and($rows['C1']['bucket'])->toBe('0-3 days')
        ->and($rows['C5A']['days'])->toBe(13)
        ->and($rows['C5A']['bucket'])->toBe('8-14 days')
        ->and($rows['C5A']['overdue'])->toBeTrue()
        ->and($rows['C5A']['brand'])->toBe('LaLaDia');
});

it('filters by brand and keeps another business out', function () {
    $lld = crRows(CourierSettlementAgeingReport::class, ['as_at' => '2026-10-11', 'brand' => (string) $this->lld->id, 'group_by' => 'parcel']);

    $other = crBusiness('Other', 'other-cr');
    $otherCourier = MoneyAccount::create(['business_id' => $other->id, 'name' => 'Pathao', 'type' => 'courier', 'account_id' => Account::bySlug('courier_receivable')->id]);
    $otherOwner = crUser($other, 'Owner');
    $wh = Warehouse::create(['business_id' => $other->id, 'name' => 'W', 'code' => 'W']);
    $delivery = DB::table('deliveries')->insertGetId([
        'business_id' => $other->id, 'customer_id' => Customer::create(['business_id' => $other->id, 'name' => 'X', 'type' => CustomerType::Retail])->id,
        'warehouse_id' => $wh->id, 'delivery_number' => 'DN-X', 'delivered_at' => '2026-10-05 09:00:00',
        'created_by' => $otherOwner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('consignments')->insert([
        'business_id' => $other->id, 'delivery_id' => $delivery, 'money_account_id' => $otherCourier->id, 'consignment_number' => 'X1',
        'cod_amount' => 5000, 'status' => 'delivered', 'outcome_on' => '2026-10-05', 'collected_amount' => 5000,
        'created_by' => $otherOwner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    expect($lld->pluck('consignment')->sort()->values()->all())->toBe(['C3', 'C5A'])
        ->and(crRows(CourierSettlementAgeingReport::class, ['as_at' => '2026-10-11'])->sum('collected'))->toBe(2850.0)
        ->and(crRows(DeliveryEconomicsReport::class, [])->pluck('consignment')->all())->not->toContain('X1');
});

it('registers both under Sales (delivery economics behind the cost permission), 404 for a role without it, and serves the screen', function () {
    $clerk = crUser($this->business, 'Clerk');
    $registry = app(ReportRegistry::class);

    foreach (['delivery_economics' => 'sales.reports.costs', 'courier_settlement_ageing' => 'sales.reports.view'] as $key => $permission) {
        expect($registry->find($key)->permission())->toBe($permission)
            ->and($registry->availableTo($clerk)->has($key))->toBeFalse();

        $this->actingAs($clerk)->get(route('hrm.reports.preview', $key))->assertNotFound();
    }

    $json = $this->actingAs($this->owner)
        ->getJson(route('hrm.reports.preview', 'courier_settlement_ageing').'?as_at=2026-10-11')
        ->assertOk();

    expect($json->json('totals.collected.value'))->toEqual(2850)
        ->and($json->json('chart.type'))->toBe('stacked_bar')
        ->and($json->json('meta.note'))->toContain('5 days or more');

    $this->actingAs($this->owner)
        ->get(route('hrm.reports.run', 'delivery_economics').'?group_by=courier&format=xlsx')
        ->assertOk()
        ->assertDownload();
});
