<?php

use App\Events\Dashboards\DashboardBroadcast;
use App\Events\Dashboards\DashboardEventType;
use App\Events\Dashboards\DashboardSignal;
use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\Customer;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier widgets on the role dashboards (realtime everywhere, 11 Oct 2026):
 * who may see them, the cash on delivery chain stage by stage, each courier's
 * money kept on its own row, overdue by the finance policy, and the signals a
 * parcel change sends.
 */
const CW_GRANTS = [
    'Owner' => ['dashboard.view_all', 'accounting.view', 'sales.reports.view'],
    'Finance' => ['accounting.view'],
    'Manager' => ['sales.reports.view'],
    'Staff' => ['sales.sell'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Courier Co', 'slug' => 'courier-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (CW_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::create(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }
    (new DashboardAssignmentSeeder)->run();
    (new ChartOfAccountsSeeder)->run();

    $this->owner = cwMember($this->business, 'Owner');
    $receivable = Account::bySlug('courier_receivable')->id;
    $this->cb = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'CarryBee', 'type' => MoneyAccountType::Courier, 'account_id' => $receivable, 'is_active' => true]);
    $this->pa = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier, 'account_id' => $receivable, 'is_active' => true]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
});

afterEach(fn () => Carbon::setTestNow());

function cwMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A challan and its parcel, written straight to the tables. */
function cwParcel(object $t, MoneyAccount $courier, array $attributes): int
{
    $delivery = DB::table('deliveries')->insertGetId([
        'business_id' => $t->business->id, 'customer_id' => $t->customer->id, 'warehouse_id' => $t->warehouse->id,
        'delivery_number' => 'DC-'.Str::random(6), 'delivered_at' => now(), 'created_by' => $t->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('consignments')->insertGetId($attributes + [
        'business_id' => $t->business->id, 'delivery_id' => $delivery, 'money_account_id' => $courier->id,
        'consignment_number' => 'C-'.Str::random(8), 'cod_amount' => 0, 'status' => 'booked',
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cwLine(object $t, string $slug, ?MoneyAccount $courier, int $minor): void
{
    static $n = 0;
    $n++;
    $journal = DB::table('journals')->insertGetId([
        'business_id' => $t->business->id, 'journal_number' => "JV-CW-{$n}", 'journal_type' => 'manual',
        'journal_date' => today()->toDateString(), 'memo' => 'Courier', 'posted_by' => $t->owner->id,
        'posted_at' => now(), 'created_at' => now(),
    ]);
    DB::table('journal_lines')->insert([
        'journal_id' => $journal, 'business_id' => $t->business->id, 'line_no' => 1,
        'account_id' => Account::bySlug($slug)->id, 'amount_minor' => $minor,
        'money_account_id' => $courier?->id, 'created_at' => now(),
    ]);
}

function cwData(object $t, string $key, DashboardKey $dashboard = DashboardKey::Owner): array
{
    return app(WidgetRegistry::class)->find($key)->data(
        new WidgetContext($t->owner, $t->business->id, DateRange::fromQuery(['range' => 'this_month']), $dashboard),
    );
}

/** The world the chain and attention tests read. */
function cwWorld(object $t): array
{
    $ids = [];
    $ids['transit'] = cwParcel($t, $t->cb, ['cod_amount' => 1000, 'courier_status' => 'in_transit', 'courier_status_at' => now()->subDay()]);
    $ids['reported'] = cwParcel($t, $t->cb, ['cod_amount' => 900, 'courier_status' => 'delivered', 'courier_status_at' => now(), 'courier_collected_amount' => 800]);
    $ids['old'] = cwParcel($t, $t->cb, ['status' => 'delivered', 'cod_amount' => 500, 'collected_amount' => 500, 'outcome_on' => today()->subDays(6)->toDateString()]);
    $ids['recent'] = cwParcel($t, $t->pa, ['status' => 'delivered', 'cod_amount' => 300, 'collected_amount' => 300, 'outcome_on' => today()->subDays(2)->toDateString()]);
    $ids['settled'] = cwParcel($t, $t->pa, ['status' => 'delivered', 'cod_amount' => 700, 'collected_amount' => 700, 'outcome_on' => today()->subDays(10)->toDateString()]);
    $ids['hold'] = cwParcel($t, $t->pa, ['cod_amount' => 200, 'courier_status' => 'on_hold', 'courier_status_at' => now()->subDay()]);
    $ids['stuck'] = cwParcel($t, $t->cb, ['cod_amount' => 400, 'courier_status' => 'picked_up', 'courier_status_at' => now()->subDays(5)]);

    $statement = DB::table('courier_statements')->insertGetId([
        'business_id' => $t->business->id, 'money_account_id' => $t->pa->id, 'statement_reference' => 'PA-1',
        'statement_date' => today()->toDateString(), 'status' => 'posted', 'created_by' => $t->owner->id,
        'posted_by' => $t->owner->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $money = ['collectable_amount' => 700, 'collected_amount' => 700, 'delivery_fee' => 60, 'discount' => 0, 'cod_fee' => 7, 'additional_charge' => 0, 'final_fee' => 67, 'payout' => 633];
    DB::table('courier_statement_lines')->insert($money + [
        'business_id' => $t->business->id, 'courier_statement_id' => $statement, 'row_number' => 1,
        'consignment_number' => DB::table('consignments')->where('id', $ids['settled'])->value('consignment_number'),
        'consignment_id' => $ids['settled'], 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ids;
}

it('shows the courier widgets only to people allowed to see them', function () {
    $registry = app(WidgetRegistry::class);
    $manager = cwMember($this->business, 'Manager');
    $finance = cwMember($this->business, 'Finance');
    $staff = cwMember($this->business, 'Staff');

    $keys = fn (DashboardKey $d, User $u) => $registry->forDashboard($d, $u)->keys()->filter(fn ($k) => str_starts_with($k, 'courier_'))->values()->all();

    expect($keys(DashboardKey::Owner, $this->owner))->toEqualCanonicalizing([
        'courier_parcels_by_status', 'courier_parcels_attention', 'courier_cod_chain', 'courier_balances',
        'courier_statements_pending', 'courier_integration_health',
    ])
        // Sales reporting without the books: parcels and the chain, never the ledger balances.
        ->and($keys(DashboardKey::Manager, $manager))->toEqualCanonicalizing(['courier_parcels_by_status', 'courier_parcels_attention', 'courier_cod_chain', 'courier_integration_health'])
        ->and($keys(DashboardKey::Finance, $finance))->toEqualCanonicalizing(['courier_cod_chain', 'courier_balances'])
        ->and($keys(DashboardKey::Sales, $staff))->toBe([]);

    $visit = fn (User $u, string $url) => $this->actingAs($u->fresh())->withSession(['current_business_id' => $this->business->id])->get($url);
    $visit($this->owner, '/dashboard/widgets/courier_balances?dashboard=owner')->assertOk()->assertJsonPath('status', 'ok');
    $visit($manager, '/dashboard/widgets/courier_balances?dashboard=manager')->assertForbidden();
    $visit($manager, '/dashboard/widgets/courier_cod_chain?dashboard=manager')->assertOk()->assertJsonPath('data.kind', 'breakdown');
});

it('walks cash on delivery stage by stage, overdue by the finance policy days', function () {
    cwWorld($this);
    FinancePolicy::create(['business_id' => $this->business->id, 'courier_settlement_alert_days' => 5]);

    $items = collect(cwData($this, 'courier_cod_chain')['items'])->keyBy(fn ($i) => explode(' (', $i['label'])[0]);

    expect($items['Booked, awaiting delivery']['value'])->toBe(1600.0)     // transit 1000 + hold 200 + stuck 400
        ->and($items['Courier says collected']['value'])->toBe(800.0)
        ->and($items['Recorded, not settled']['value'])->toBe(800.0)        // 500 + 300, the settled 700 left out
        ->and($items['Settled']['value'])->toBe(700.0)
        ->and($items['Overdue']['value'])->toBe(500.0)                      // 6 days > 5
        ->and($items['Overdue']['label'])->toContain('5 days');

    // The policy decides: at 7 days nothing is overdue yet.
    FinancePolicy::query()->withoutGlobalScopes()->where('business_id', $this->business->id)->update(['courier_settlement_alert_days' => 7]);
    $items = collect(cwData($this, 'courier_cod_chain')['items'])->keyBy(fn ($i) => explode(' (', $i['label'])[0]);
    expect($items['Overdue']['value'])->toBe(0.0)->and($items['Overdue']['label'])->toContain('7 days');
});

it('keeps each courier on its own row for money with them and owed to them', function () {
    cwLine($this, 'courier_receivable', $this->cb, 150000);
    cwLine($this, 'courier_receivable', $this->cb, -20000);
    cwLine($this, 'courier_receivable', $this->pa, 90000);
    cwLine($this, 'courier_payable', $this->pa, -15000);
    cwLine($this, 'courier_receivable', null, 5000);

    $rows = collect(cwData($this, 'courier_balances')['rows'])->keyBy(fn ($r) => $r['cells']['courier']);

    expect($rows['CarryBee']['cells'])->toMatchArray(['with_courier' => 1300.0, 'payable' => 0.0])
        ->and($rows['Pathao']['cells'])->toMatchArray(['with_courier' => 900.0, 'payable' => 150.0])
        ->and($rows['No courier named on the line']['cells']['with_courier'])->toBe(50.0)
        ->and($rows)->toHaveCount(3);
});

it('lists parcels needing attention and counts parcels by courier status', function () {
    cwWorld($this);

    $attention = cwData($this, 'courier_parcels_attention');
    $problems = collect($attention['rows'])->pluck('cells.problem')->all();
    expect($attention['total_count'])->toBe(2)
        ->and($problems)->toContain('On hold', 'Stuck: picked up');

    $byStatus = collect(cwData($this, 'courier_parcels_by_status')['items'])->pluck('value', 'label');
    expect($byStatus['In transit'])->toBe(1)
        ->and($byStatus['Delivered (range)'])->toBe(1)
        ->and($byStatus['On hold'])->toBe(1);

    $pending = cwData($this, 'courier_statements_pending');
    expect($pending['total_count'])->toBe(0);
});

it('signals parcel changes with the sound each deserves, and refreshes the courier widgets', function () {
    Event::fake([DashboardSignal::class]);
    $id = $this->business->id;

    CourierParcelChanged::dispatch(CourierParcelChanged::STATUS, $id, 11, 21, 'delivered');
    CourierParcelChanged::dispatch(CourierParcelChanged::STATUS, $id, 12, 22, 'delivery_failed');
    CourierParcelChanged::dispatch(CourierParcelChanged::STATUS, $id, 13, 23, 'picked_up');
    CourierParcelChanged::dispatch(CourierParcelChanged::BOOKING_FAILED, $id, 14);
    CourierParcelChanged::dispatch(CourierParcelChanged::BOOKING_UNKNOWN, $id, 15);

    $sent = fn (string $type, int $delivery) => Event::dispatched(DashboardSignal::class, fn ($e) => $e->type === $type && ($e->ids['delivery'] ?? null) === $delivery)->first()[0] ?? null;

    expect($sent(DashboardEventType::PARCEL_STATUS, 11)->broadcastWith()['sound'])->toBe('success')
        ->and($sent(DashboardEventType::PARCEL_STATUS, 11)->ids)->toBe(['delivery' => 11, 'parcel' => 21])
        ->and($sent(DashboardEventType::PARCEL_STATUS, 12)->broadcastWith()['sound'])->toBe('attention')
        ->and($sent(DashboardEventType::PARCEL_STATUS, 13)->broadcastWith()['sound'])->toBe('none')
        ->and($sent(DashboardEventType::BOOKING_FAILED, 14)->broadcastWith()['sound'])->toBe('error')
        ->and($sent(DashboardEventType::BOOKING_UNKNOWN, 15)->broadcastWith()['sound'])->toBe('attention');

    expect(DashboardBroadcast::widgetsFor(DashboardEventType::PARCEL_STATUS))
        ->toContain('courier_parcels_by_status', 'courier_parcels_attention', 'courier_cod_chain', 'courier_integration_health');
});

it('signals a courier statement imported and then posted', function () {
    Event::fake([DashboardSignal::class]);

    $statement = CourierStatement::forceCreate([
        'business_id' => $this->business->id, 'money_account_id' => $this->cb->id, 'statement_reference' => 'CB-9',
        'statement_date' => today()->toDateString(), 'status' => CourierStatement::DRAFT, 'created_by' => $this->owner->id,
    ]);
    Event::assertDispatched(DashboardSignal::class, fn ($e) => $e->type === DashboardEventType::STATEMENT_IMPORTED && $e->ids === ['statement' => $statement->id]);

    expect(cwData($this, 'courier_statements_pending')['total_count'])->toBe(1);

    $statement->forceFill(['status' => CourierStatement::POSTED, 'posted_at' => now()])->save();
    Event::assertDispatched(DashboardSignal::class, fn ($e) => $e->type === DashboardEventType::STATEMENT_POSTED);
});
