<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Models\Customer;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\GettingStarted;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dashboard UX pass (11 Oct 2026): the Owner's Getting started checklist read
 * from the real tables, its per-person dismiss, and the webhook pairing column
 * on Courier connections.
 */
const UX_GRANTS = [
    'Owner' => ['dashboard.view_all', 'accounting.view', 'sales.reports.view', 'sales.parcels.see'],
    'Manager' => ['sales.reports.view', 'sales.parcels.see'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = uxBusiness('ux-alpha');
    (new DashboardAssignmentSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = uxMember($this->business, 'Owner');
    $this->courierMoney = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'CarryBee LaLaDia', 'type' => MoneyAccountType::Courier,
        'provider' => 'CarryBee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $this->visit = function (User $user, Business $business, string $url = '/dashboard/owner') {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        GettingStarted::forget($business->id);

        return $this->actingAs($user->fresh())->withSession(['current_business_id' => $business->id])->get($url);
    };
});

afterEach(fn () => Carbon::setTestNow());

function uxBusiness(string $slug): Business
{
    $business = Business::create(['name' => ucfirst($slug), 'slug' => $slug]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (UX_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::create(['name' => $role, 'business_id' => $business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    return $business;
}

function uxMember(Business $business, string $role, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId(app(BusinessContext::class)->id() ?? $business->id);

    return $user;
}

/** A switched-on CarryBee account linked to the business, as the pairing guard tests make one. */
function uxCourier(object $t, string $label = 'Bionic'): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, [
        'provider' => 'carrybee', 'label' => $label, 'environment' => 'live', 'base_url' => 'https://developers.carrybee.com',
        'credentials' => ['client_id' => 'cb-id', 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ]);
    $account->forceFill(['last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint()])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $t->business->id, 'provider_store_id' => '24869',
        'provider_store_name' => 'Adabor 2', 'money_account_id' => $t->courierMoney->id, 'is_default' => true,
    ]);

    return $account->fresh(['provider']);
}

/** @return array<string, bool> step key => done */
function uxDone(int $businessId): array
{
    GettingStarted::forget($businessId);

    return collect(GettingStarted::steps($businessId))->mapWithKeys(fn ($s) => [$s['key'] => $s['done']])->all();
}

it('shows an empty business every Getting started step as still to do, Owner only', function () {
    ($this->visit)($this->owner, $this->business)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboards/show')
            ->where('getting_started.done', 0)
            ->where('getting_started.total', 6)
            ->where('getting_started.steps.0.key', 'opening_balances')
            ->where('getting_started.steps.5.key', 'courier')
            ->where('getting_started.steps.5.url', '/settings/couriers'));

    $manager = uxMember($this->business, 'Manager');
    ($this->visit)($manager, $this->business, '/dashboard/manager')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('getting_started', null));

    // Joy on another dashboard: the checklist belongs to the Owner one.
    ($this->visit)($this->owner, $this->business, '/dashboard/finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('getting_started', null));
});

it('reads each step from the real tables, for this business only', function () {
    $id = $this->business->id;
    $other = uxBusiness('ux-beta');
    $now = now();

    // Another business's data never ticks a box here.
    DB::table('suppliers')->insert(['business_id' => $other->id, 'name' => 'Elsewhere', 'created_at' => $now, 'updated_at' => $now]);
    expect(uxDone($id))->each->toBeFalse();

    // A manual journal is trading, not the opening balances.
    $journal = fn (string $type, string $number) => DB::table('journals')->insert([
        'business_id' => $id, 'journal_number' => $number, 'journal_type' => $type, 'journal_date' => '2026-09-30',
        'memo' => 'Opening', 'posted_by' => $this->owner->id, 'posted_at' => $now, 'created_at' => $now,
    ]);
    $journal('manual', 'JV-1');
    expect(uxDone($id)['opening_balances'])->toBeFalse();
    $journal('opening_balance', 'JV-2');
    expect(uxDone($id)['opening_balances'])->toBeTrue();

    // Products: every stocked active product needs a costed batch; a service needs none.
    $unit = DB::table('units_of_measure')->insertGetId(['business_id' => $id, 'name' => 'Piece', 'abbreviation' => 'pc', 'created_at' => $now, 'updated_at' => $now]);
    $product = fn (string $sku, string $type) => DB::table('products')->insertGetId([
        'business_id' => $id, 'unit_of_measure_id' => $unit, 'sku' => $sku, 'name' => $sku, 'type' => $type,
        'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $honey = $product('HONEY', 'finished_good');
    $product('DELIVERY', 'service');
    $warehouse = Warehouse::create(['business_id' => $id, 'name' => 'Main', 'code' => 'MAIN']);
    $batch = fn (int $productId, string $cost) => DB::table('stock_batches')->insert([
        'business_id' => $id, 'product_id' => $productId, 'warehouse_id' => $warehouse->id, 'unit_cost' => $cost,
        'initial_quantity' => 5, 'current_quantity' => 5, 'status' => 'active', 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $batch($honey, '0');
    expect(uxDone($id)['product_costs'])->toBeFalse();
    $batch($honey, '420.5');
    expect(uxDone($id)['product_costs'])->toBeTrue();
    $steps = collect(GettingStarted::steps($id))->keyBy('key');
    expect($steps['product_costs']['count'])->toBe(1)
        ->and($steps['product_costs']['of'])->toBe(1);

    DB::table('opening_stocks')->insert(['business_id' => $id, 'warehouse_id' => $warehouse->id, 'as_at' => '2026-09-30', 'created_by' => $this->owner->id, 'created_at' => $now, 'updated_at' => $now]);
    expect(uxDone($id)['opening_stock'])->toBeTrue();

    // The seeded walk-in counter row is not a customer anybody added.
    Customer::create(['business_id' => $id, 'name' => 'Walk-in', 'code' => Customer::WALK_IN_CODE]);
    expect(uxDone($id)['customers'])->toBeFalse();
    Customer::create(['business_id' => $id, 'name' => 'Rahima Khatun']);
    expect(uxDone($id)['customers'])->toBeTrue();

    DB::table('suppliers')->insert(['business_id' => $id, 'name' => 'Sundarban Apiary', 'created_at' => $now, 'updated_at' => $now]);
    expect(uxDone($id)['suppliers'])->toBeTrue();

    // Connected is not enough: the webhook must be paired (or already talking).
    $account = uxCourier($this);
    expect(uxDone($id)['courier'])->toBeFalse();
    $account->recordHandshake(false, CourierAccount::PAIRING_MISSING_ECHO);
    expect(uxDone($id)['courier'])->toBeFalse();
    $account->recordHandshake(true);
    expect(uxDone($id))->each->toBeTrue();

    // All done: the card leaves on its own.
    ($this->visit)($this->owner, $this->business)
        ->assertInertia(fn (Assert $page) => $page->where('getting_started', null));
});

it('lets a person hide the card for themselves, in this business only', function () {
    $second = uxMember($this->business, 'Owner');

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->putJson('/dashboard/getting-started/dismiss')
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect($this->owner->fresh()->preferences[GettingStarted::PREFERENCE])->toBe([$this->business->id]);

    ($this->visit)($this->owner, $this->business)
        ->assertInertia(fn (Assert $page) => $page->where('getting_started', null));
    ($this->visit)($second, $this->business)
        ->assertInertia(fn (Assert $page) => $page->where('getting_started.done', 0));

    // Joy in another business still sees that business's checklist.
    $beta = uxBusiness('ux-gamma');
    uxMember($beta, 'Owner', $this->owner);
    ($this->visit)($this->owner, $beta)
        ->assertInertia(fn (Assert $page) => $page->where('getting_started.total', 6));
});

it('shows on Courier connections whether each webhook is paired, and the last update it pushed', function () {
    $never = uxCourier($this, 'Never');
    $missing = uxCourier($this, 'Missing');
    $paired = uxCourier($this, 'Paired');

    $missing->recordHandshake(false, CourierAccount::PAIRING_MISSING_ECHO);
    Carbon::setTestNow('2026-10-09 08:00:00');
    $paired->recordHandshake(true);
    Carbon::setTestNow('2026-10-11 09:00:00');
    $paired->recordWebhookEvent();
    Carbon::setTestNow('2026-10-11 10:00:00');

    $data = app(WidgetRegistry::class)->find('courier_integration_health')->data(
        new WidgetContext($this->owner, $this->business->id, DateRange::fromQuery(['range' => 'this_month']), DashboardKey::Owner),
    );
    $rows = collect($data['rows'])->keyBy('id');

    expect(collect($data['columns'])->pluck('key')->all())->toContain('webhook', 'last_webhook')
        ->and($rows[$never->id]['cells']['webhook'])->toBe('Never tried')
        ->and($rows[$never->id]['cells']['last_webhook'])->toBe('Never')
        ->and($rows[$never->id]['cell_tones'])->toBe([])
        ->and($rows[$missing->id]['cells']['webhook'])->toBe('Reply value missing')
        ->and($rows[$missing->id]['cell_tones'])->toBe(['webhook' => 'bad'])
        ->and($rows[$paired->id]['cells']['webhook'])->toBe('Paired 9 Oct')
        ->and($rows[$paired->id]['cells']['last_webhook'])->toBe('1 hour ago');

    // An authentic update after a failed attempt means it paired after all.
    $missing->recordWebhookEvent();
    $data = app(WidgetRegistry::class)->find('courier_integration_health')->data(
        new WidgetContext($this->owner, $this->business->id, DateRange::fromQuery(['range' => 'this_month']), DashboardKey::Owner),
    );
    expect(collect($data['rows'])->keyBy('id')[$missing->id]['cells']['webhook'])->toBe('Paired 11 Oct');
});
