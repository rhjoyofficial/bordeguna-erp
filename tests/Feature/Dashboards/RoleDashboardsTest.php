<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\DashboardLayout;
use App\Modules\Foundation\Models\DashboardRoleAssignment;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\DateRange;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role dashboards (Joy, 11 Oct 2026): highest dashboard per business, an
 * audited see-all for the Owner, a widget endpoint that re-authorises every
 * call, strict self-scope, layout and sound preferences.
 */
const ROLE_GRANTS = [
    'Owner' => [
        'dashboard.view_all', 'foundation.organization.manage', 'accounting.view', 'accounting.operations.view',
        'sales.reports.view', 'inventory.reports.view', 'hrm.attendance.manage', 'hrm.payroll.manage', 'crm.leads.view',
    ],
    'Finance' => ['accounting.view', 'accounting.operations.view', 'hrm.payroll.manage'],
    'Manager' => ['sales.reports.view', 'inventory.reports.view', 'inventory.stock.approve'],
    'Staff' => ['sales.sell', 'sales.orders.record'],
    'Self-service' => [],
];

function dashboardBusiness(string $slug): Business
{
    $business = Business::create(['name' => ucfirst($slug), 'slug' => $slug]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (ROLE_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::create(['name' => $role, 'business_id' => $business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    return $business;
}

function dashboardMember(Business $business, string $role, ?User $user = null): User
{
    $user ??= User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

beforeEach(function () {
    // public/build is copied in and cannot be rebuilt here; the new pages are not in it.
    $this->withoutVite();
    $this->a = dashboardBusiness('alpha');
    $this->b = dashboardBusiness('beta');
    (new DashboardAssignmentSeeder)->run();
    app(BusinessContext::class)->set($this->a->id);
    setPermissionsTeamId($this->a->id);

    $this->visit = function (User $user, Business $business, string $url) {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs($user->fresh())->withSession(['current_business_id' => $business->id])->get($url);
    };
});

it('lands each person on the highest dashboard their roles give them in THIS business', function () {
    $joy = dashboardMember($this->a, 'Owner');
    dashboardMember($this->b, 'Staff', $joy);

    ($this->visit)($joy, $this->a, '/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboards/show')->where('dashboard.key', 'owner'));

    ($this->visit)($joy, $this->b, '/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'sales')
            ->where('switcher', fn ($s) => collect($s)->pluck('key')->all() === ['sales', 'employee']));
});

it('ranks Finance over Manager whatever order the roles were given in', function () {
    $user = dashboardMember($this->a, 'Manager');
    dashboardMember($this->a, 'Finance', $user);

    ($this->visit)($user, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'finance'));
});

it('never lets the switcher be bypassed by typing the URL', function () {
    $staff = dashboardMember($this->a, 'Staff');

    foreach (['owner', 'finance', 'manager'] as $key) {
        ($this->visit)($staff, $this->a, "/dashboard/{$key}")->assertForbidden();
    }

    ($this->visit)($staff, $this->a, '/dashboard/employee')->assertOk();
    // Not a dashboard key: the route does not match and the app's fallback sends them home.
    ($this->visit)($staff, $this->a, '/dashboard/nonsense')->assertRedirect(route('dashboard'));

    $self = dashboardMember($this->a, 'Self-service');
    ($this->visit)($self, $this->a, '/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'employee')
            ->where('switcher', fn ($s) => count($s) === 1)
            ->where('offers_classic', false));
});

it('logs a dashboard opened only through see-all access, and not one the role gives anyway', function () {
    $joy = dashboardMember($this->a, 'Owner');

    ($this->visit)($joy, $this->a, '/dashboard/owner')->assertOk();
    expect(DB::table('activity_log')->where('log_name', 'dashboard')->count())->toBe(0);

    ($this->visit)($joy, $this->a, '/dashboard/finance')->assertOk();

    $entry = DB::table('activity_log')->where('log_name', 'dashboard')->first();
    expect($entry)->not->toBeNull()
        ->and((int) $entry->causer_id)->toBe($joy->id)
        ->and(json_decode($entry->properties, true))->toMatchArray(['dashboard' => 'finance', 'via' => 'dashboard.view_all']);
});

it('renders every dashboard for its role with every widget answering, none failing', function () {
    $cases = [
        ['Owner', 'owner'], ['Finance', 'finance'], ['Manager', 'manager'], ['Staff', 'sales'], ['Self-service', 'employee'],
    ];

    foreach ($cases as [$role, $key]) {
        $user = dashboardMember($this->a, $role);

        ($this->visit)($user, $this->a, '/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboards/show')
                ->where('dashboard.key', $key)
                ->where('auto_refresh', false)
                ->where('widgets', function ($widgets) use ($role) {
                    $failed = collect($widgets)->filter(fn ($w) => $w['result']['status'] !== 'ok')->pluck('key')->all();
                    expect($failed)->toBe([], "{$role}: widgets failed");

                    return count($widgets) > 0;
                }));
    }

    // Joy sees every one of the five.
    $joy = dashboardMember($this->a, 'Owner');
    foreach (DashboardKey::cases() as $key) {
        ($this->visit)($joy, $this->a, "/dashboard/{$key->value}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('widgets', fn ($widgets) => collect($widgets)->every(fn ($w) => $w['result']['status'] === 'ok')));
    }
});

it('never sends a widget the person may not see', function () {
    $staff = dashboardMember($this->a, 'Staff');

    ($this->visit)($staff, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where(
        'widgets',
        fn ($widgets) => collect($widgets)->pluck('key')->intersect(['cash_position', 'money_accounts', 'receivables_ageing', 'gross_profit'])->isEmpty(),
    ));
});

it('refuses unknown and unauthorised widget keys on the endpoint', function () {
    $staff = dashboardMember($this->a, 'Staff');

    ($this->visit)($staff, $this->a, '/dashboard/widgets/nope?dashboard=sales')->assertNotFound();
    // On a dashboard they cannot open.
    ($this->visit)($staff, $this->a, '/dashboard/widgets/cash_position?dashboard=owner')->assertForbidden();
    // A widget that is not on the dashboard named.
    ($this->visit)($staff, $this->a, '/dashboard/widgets/cash_position?dashboard=sales')->assertForbidden();
    // A widget on their dashboard.
    ($this->visit)($staff, $this->a, '/dashboard/widgets/my_orders?dashboard=sales')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertHeader('Cache-Control', 'no-store, private');

    $joy = dashboardMember($this->a, 'Owner');
    ($this->visit)($joy, $this->a, '/dashboard/widgets/cash_position?dashboard=owner')
        ->assertOk()
        ->assertJsonPath('data.kind', 'stat')
        ->assertJsonPath('data.basis', 'ledger');
});

it('never returns another employee\'s records, whatever the query string says', function () {
    $mine = dashboardMember($this->a, 'Self-service');
    $theirs = dashboardMember($this->a, 'Self-service');

    $employee = fn (User $user, string $code) => Employee::create([
        'employee_code' => $code, 'user_id' => $user->id, 'first_name' => 'E', 'last_name' => $code,
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $me = $employee($mine, 'BDG-900001');
    $other = $employee($theirs, 'BDG-900002');

    foreach ([[$me, 'EC-MINE'], [$other, 'EC-THEIRS']] as [$owner, $number]) {
        DB::table('expense_claims')->insert([
            'business_id' => $this->a->id, 'employee_id' => $owner->id, 'claim_number' => $number,
            'spent_on' => '2026-10-01', 'amount' => 500, 'purpose' => 'Taxi', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $response = ($this->visit)($mine, $this->a, "/dashboard/widgets/my_claims?dashboard=employee&employee_id={$other->id}&user_id={$theirs->id}")
        ->assertOk();

    $numbers = collect($response->json('data.rows'))->pluck('cells.number')->all();
    expect($numbers)->toBe(['EC-MINE']);
});

it('keeps cache keys apart per business, per person for self widgets, and per range', function () {
    $registry = app(WidgetRegistry::class);
    $one = User::factory()->create();
    $two = User::factory()->create();
    $range = DateRange::fromQuery(['range' => 'this_month']);
    $other = DateRange::fromQuery(['range' => 'last_month']);

    $ctx = fn (User $u, Business $b, DateRange $r) => new WidgetContext($u, $b->id, $r, DashboardKey::Owner);

    $business = $registry->find('income_trend');
    $self = $registry->find('approvals_waiting');
    $position = $registry->find('cash_position');

    // A business widget is shared by its business, never across businesses or ranges.
    expect($registry->cacheKey($business, $ctx($one, $this->a, $range)))->toBe($registry->cacheKey($business, $ctx($two, $this->a, $range)))
        ->and($registry->cacheKey($business, $ctx($one, $this->a, $range)))->not->toBe($registry->cacheKey($business, $ctx($one, $this->b, $range)))
        ->and($registry->cacheKey($business, $ctx($one, $this->a, $range)))->not->toBe($registry->cacheKey($business, $ctx($one, $this->a, $other)));

    // A self widget is never shared between two people.
    expect($registry->cacheKey($self, $ctx($one, $this->a, $range)))->not->toBe($registry->cacheKey($self, $ctx($two, $this->a, $range)));

    // A position "as at now" ignores the range, so a range change reuses it.
    expect($registry->cacheKey($position, $ctx($one, $this->a, $range)))->toBe($registry->cacheKey($position, $ctx($one, $this->a, $other)));
});

it('reads every preset in Asia/Dhaka, with the previous period beside it', function () {
    // 00:30 in Dhaka on 11 Oct is still 10 Oct in UTC: the Dhaka date wins.
    $now = CarbonImmutable::parse('2026-10-10 18:30:00', 'UTC');
    $range = fn (array $q) => DateRange::fromQuery($q, $now);

    expect($range(['range' => 'today'])->toArray())->toMatchArray(['from' => '2026-10-11', 'to' => '2026-10-11', 'previous' => ['from' => '2026-10-10', 'to' => '2026-10-10']])
        ->and($range(['range' => 'yesterday'])->toArray())->toMatchArray(['from' => '2026-10-10', 'to' => '2026-10-10'])
        ->and($range(['range' => 'last_7_days'])->toArray())->toMatchArray(['from' => '2026-10-05', 'to' => '2026-10-11', 'previous' => ['from' => '2026-09-28', 'to' => '2026-10-04']])
        ->and($range(['range' => 'this_month'])->toArray())->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-11', 'previous' => ['from' => '2026-09-01', 'to' => '2026-09-11']])
        ->and($range(['range' => 'last_month'])->toArray())->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-30', 'previous' => ['from' => '2026-08-01', 'to' => '2026-08-31']])
        ->and($range(['range' => 'this_quarter'])->toArray())->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-11', 'previous' => ['from' => '2026-07-01', 'to' => '2026-07-11']])
        ->and($range(['range' => 'this_year'])->toArray())->toMatchArray(['from' => '2026-01-01', 'to' => '2026-10-11', 'previous' => ['from' => '2025-01-01', 'to' => '2025-10-11']])
        ->and($range(['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-05'])->toArray())->toMatchArray(['previous' => ['from' => '2026-09-26', 'to' => '2026-09-30']])
        ->and($range(['range' => 'what'])->preset)->toBe('this_month');

    // The 31 March edge: a month back from 31 Mar does not overflow into March.
    $march = DateRange::fromQuery(['range' => 'this_month'], CarbonImmutable::parse('2026-03-31 12:00', 'Asia/Dhaka'));
    expect($march->previous()->toArray())->toMatchArray(['from' => '2026-02-01', 'to' => '2026-02-28']);

    expect(fn () => $range(['range' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-01']))->toThrow(ValidationException::class)
        ->and(fn () => $range(['range' => 'custom', 'from' => '2025-01-01', 'to' => '2026-10-01']))->toThrow(ValidationException::class)
        ->and(fn () => $range(['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01']))->toThrow(ValidationException::class);
});

it('saves a layout that hides, and refuses a widget the person cannot see', function () {
    $joy = dashboardMember($this->a, 'Owner');
    $put = fn (array $body) => $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->putJson('/dashboard/owner/layout', $body);

    $put(['hidden' => ['cash_position']])->assertOk();

    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($w) => ! collect($w)->pluck('key')->contains('cash_position'))
        ->where('hidden_widgets', fn ($h) => collect($h)->pluck('key')->all() === ['cash_position']));

    // Hiding changes nothing about access: the endpoint still answers.
    ($this->visit)($joy, $this->a, '/dashboard/widgets/cash_position?dashboard=owner')->assertOk();

    $staff = dashboardMember($this->a, 'Staff');
    $this->actingAs($staff->fresh())->withSession(['current_business_id' => $this->a->id])
        ->putJson('/dashboard/sales/layout', ['hidden' => ['cash_position']])->assertUnprocessable();
    $this->actingAs($staff->fresh())->withSession(['current_business_id' => $this->a->id])
        ->putJson('/dashboard/owner/layout', ['hidden' => []])->assertForbidden();
});

// 14 Oct 2026 (Joy: "i broke the design layout, reset it"): only hide and show remain.
it('ignores a stored custom order and any order or size sent, keeping only the hidden list', function () {
    $joy = dashboardMember($this->a, 'Owner');
    $designed = app(WidgetRegistry::class)->forDashboard(DashboardKey::Owner, $joy->fresh())->keys()->values()->all();
    $reversed = array_reverse($designed);

    // The layout as the old reorder arrows left it.
    DashboardLayout::query()->create([
        'business_id' => $this->a->id, 'user_id' => $joy->id, 'dashboard' => 'owner',
        'widget_order' => $reversed, 'hidden' => ['cash_position'],
    ]);

    $expected = array_values(array_diff($designed, ['cash_position']));
    $keys = fn (Assert $page) => $page->where('widgets', fn ($w) => collect($w)->pluck('key')->all() === $expected)
        ->where('hidden_widgets', fn ($h) => collect($h)->pluck('key')->all() === ['cash_position'])
        ->where('widgets.0.size', app(WidgetRegistry::class)->find($expected[0])->size());

    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia($keys);

    // A stale tab still sending order and sizes: saved, but only the hidden list is kept.
    $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->putJson('/dashboard/owner/layout', ['order' => $reversed, 'size' => ['cash_position' => 'lg'], 'span' => 4, 'hidden' => ['cash_position']])
        ->assertOk();

    $layout = DashboardLayout::query()->where('user_id', $joy->id)->sole();
    expect($layout->widget_order)->toBeNull()->and($layout->hidden)->toBe(['cash_position']);
    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia($keys);
});

it('resets one person\'s layout to the designed default, hidden list included, and leaves others alone', function () {
    $joy = dashboardMember($this->a, 'Owner');
    $other = dashboardMember($this->a, 'Owner');

    foreach ([$joy, $other] as $person) {
        $this->actingAs($person->fresh())->withSession(['current_business_id' => $this->a->id])
            ->putJson('/dashboard/owner/layout', ['hidden' => ['cash_position']])->assertOk();
    }

    $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->deleteJson('/dashboard/owner/layout')->assertOk();

    expect(DashboardLayout::query()->where('user_id', $joy->id)->exists())->toBeFalse()
        ->and(DashboardLayout::query()->where('user_id', $other->id)->exists())->toBeTrue();

    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($w) => collect($w)->pluck('key')->contains('cash_position'))
        ->where('hidden_widgets', []));

    // Reset is gated like the dashboard itself.
    $staff = dashboardMember($this->a, 'Staff');
    $this->actingAs($staff->fresh())->withSession(['current_business_id' => $this->a->id])
        ->deleteJson('/dashboard/owner/layout')->assertForbidden();
});

it('flags every money widget as sensitive and leaves counts readable', function () {
    $registry = app(WidgetRegistry::class);

    foreach (['cash_position', 'income_trend', 'money_in_transit', 'gross_profit', 'payables_ageing', 'receivables_ageing', 'my_payslip'] as $key) {
        expect($registry->find($key)?->sensitive())->toBeTrue("{$key} should be sensitive");
    }

    foreach (['needs_attention', 'approvals_waiting', 'my_tasks', 'my_attendance'] as $key) {
        expect($registry->find($key)?->sensitive())->toBeFalse("{$key} should not be sensitive");
    }

    $joy = dashboardMember($this->a, 'Owner');
    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('widgets', fn ($w) => collect($w)->firstWhere('key', 'cash_position')['sensitive'] === true));
});

it('turns sound on by default only for holders of see-all access, and keeps a saved choice', function () {
    $joy = dashboardMember($this->a, 'Owner');
    $staff = dashboardMember($this->a, 'Staff');

    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('sound.enabled', true)->where('sound.customised', false));
    ($this->visit)($staff, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('sound.enabled', false));

    $this->actingAs($staff->fresh())->withSession(['current_business_id' => $this->a->id])->putJson('/dashboard/sound', [
        'enabled' => true, 'volume' => 0.4,
        'categories' => ['success' => true, 'attention' => false, 'error' => true],
        'quiet_hours' => ['enabled' => true, 'from' => '21:00', 'to' => '09:00'],
    ])->assertOk()->assertJsonPath('sound.enabled', true);

    ($this->visit)($staff, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('sound.enabled', true)
        ->where('sound.volume', 0.4)
        ->where('sound.categories.attention', false)
        ->where('sound.quiet_hours.from', '21:00'));

    $this->actingAs($staff->fresh())->withSession(['current_business_id' => $this->a->id])
        ->putJson('/dashboard/sound', ['enabled' => true, 'volume' => 3])->assertUnprocessable();
});

it('lets only the Owner map roles to dashboards, and the mapping decides the landing', function () {
    $joy = dashboardMember($this->a, 'Owner');
    $staff = dashboardMember($this->a, 'Staff');
    $staffRole = Role::where('business_id', $this->a->id)->where('name', 'Staff')->first();

    ($this->visit)($staff, $this->a, '/settings/dashboards')->assertForbidden();
    ($this->visit)($joy, $this->a, '/settings/dashboards')->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/dashboards'));

    $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->put('/settings/dashboards', ['assignments' => [['role_id' => $staffRole->id, 'dashboard' => 'manager']]])
        ->assertSessionHasNoErrors();

    ($this->visit)($staff, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'manager'));

    // A role of ANOTHER business cannot be mapped from here.
    $foreign = Role::where('business_id', $this->b->id)->where('name', 'Staff')->first();
    $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->put('/settings/dashboards', ['assignments' => [['role_id' => $foreign->id, 'dashboard' => 'owner']]])
        ->assertSessionHasErrors();

    // Automatic again: back to the permission fallback (sales.sell -> sales).
    $this->actingAs($joy->fresh())->withSession(['current_business_id' => $this->a->id])
        ->put('/settings/dashboards', ['assignments' => [['role_id' => $staffRole->id, 'dashboard' => null]]]);
    expect(DashboardRoleAssignment::withoutGlobalScopes()->where('role_id', $staffRole->id)->exists())->toBeFalse();
    ($this->visit)($staff, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'sales'));
});

it('gives an unmapped custom role a dashboard from its permissions', function () {
    $role = Role::create(['name' => 'Bookkeeper', 'business_id' => $this->a->id, 'guard_name' => 'web'])->givePermissionTo('accounting.view');
    $user = dashboardMember($this->a, $role->name);

    ($this->visit)($user, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.key', 'finance'));
});

it('keeps the classic dashboard, nothing lost', function () {
    $joy = dashboardMember($this->a, 'Owner');

    ($this->visit)($joy, $this->a, '/dashboard/classic')->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard')->has('trade_chart'));
    ($this->visit)($joy, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page->where('offers_classic', true));
});

it('reads money from posted ledger lines: cash apart from courier money, income by day', function () {
    (new ChartOfAccountsSeeder)->run();
    $joy = dashboardMember($this->a, 'Owner');

    $cash = Account::bySlug('cash_in_hand');
    $courier = Account::bySlug('courier_receivable');
    $revenue = Account::query()->where('code', 'like', '4%')->where('is_postable', true)->orderBy('code')->first();

    $post = function (string $number, array $lines) use ($joy) {
        $journal = DB::table('journals')->insertGetId([
            'business_id' => $this->a->id, 'journal_number' => $number, 'journal_date' => now()->toDateString(),
            'posted_by' => $joy->id, 'posted_at' => now(), 'created_at' => now(),
        ]);

        foreach ($lines as $i => [$account, $minor]) {
            DB::table('journal_lines')->insert([
                'journal_id' => $journal, 'business_id' => $this->a->id, 'line_no' => $i + 1,
                'account_id' => $account->id, 'amount_minor' => $minor, 'created_at' => now(),
            ]);
        }
    };

    // A counter sale paid in cash, and a courier parcel delivered but not paid out.
    $post('JV-T-1', [[$cash, 100000], [$revenue, -100000]]);
    $post('JV-T-2', [[$courier, 50000], [$revenue, -50000]]);
    // Another business's cash never shows here.
    DB::table('journals')->insert(['business_id' => $this->b->id, 'journal_number' => 'JV-B-1', 'journal_date' => now()->toDateString(), 'posted_by' => $joy->id, 'posted_at' => now(), 'created_at' => now()]);

    $get = fn (string $key) => ($this->visit)($joy, $this->a, "/dashboard/widgets/{$key}?dashboard=owner&range=today")->assertOk()->json('data');

    expect($get('cash_position')['value'])->toEqual(1000)
        ->and($get('money_in_transit')['value'])->toEqual(500)
        ->and($get('income_trend')['total'])->toEqual(1500)
        ->and(collect($get('income_trend')['points'])->last()['income'])->toEqual(1500);
});

// ── Round 3 (16 Oct 2026): the Shortcuts row, built and gated on the server ──

/** The shortcut keys a person is sent on a dashboard. */
function dashboardShortcuts(object $test, User $user, string $url = '/dashboard'): array
{
    $keys = null;

    ($test->visit)($user, $test->a, $url)->assertOk()->assertInertia(function (Assert $page) use (&$keys) {
        $page->has('shortcuts');
        $keys = collect($page->toArray()['props']['shortcuts'])->pluck('key')->all();
    });

    return $keys;
}

it('sends each role only the shortcuts its permissions open', function () {
    // The Owner of this suite holds none of the create permissions: nothing to draw.
    expect(dashboardShortcuts($this, dashboardMember($this->a, 'Owner')))->toBe([]);

    // Finance: the two screens it may open (no voucher.prepare here).
    expect(dashboardShortcuts($this, dashboardMember($this->a, 'Finance')))->toBe(['bank_statement', 'pay_claims']);

    // Manager: stock, and approvals through the legacy approve permission.
    expect(dashboardShortcuts($this, dashboardMember($this->a, 'Manager')))->toBe(['stock', 'approvals']);

    // Sales staff: the counter and an order (sales.orders.see follows orders.record).
    expect(dashboardShortcuts($this, dashboardMember($this->a, 'Staff')))->toBe(['sale', 'sales_order']);

    // Self-service without an employee record here: none of the Me screens.
    expect(dashboardShortcuts($this, dashboardMember($this->a, 'Self-service')))->toBe([]);
});

it('needs the action AND the page it lands on, and journal vouchers only voucher.prepare', function () {
    foreach (['purchasing.po.create', 'purchasing.expense.record', 'accounting.voucher.prepare', 'purchasing.reports.view', 'sales.sell', 'sales.customers.manage'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    // May create a purchase order and an expense but not read the lists: no shortcut, no 403.
    Role::create(['name' => 'Buyer', 'business_id' => $this->a->id, 'guard_name' => 'web'])
        ->givePermissionTo(['purchasing.po.create', 'purchasing.expense.record', 'accounting.voucher.prepare', 'dashboard.view_all']);
    $buyer = dashboardMember($this->a, 'Buyer');
    expect(dashboardShortcuts($this, $buyer, '/dashboard/owner'))->toBe(['payment_voucher', 'receipt_voucher', 'journal_voucher']);

    Role::findByName('Buyer')->givePermissionTo('purchasing.reports.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(dashboardShortcuts($this, $buyer, '/dashboard/owner'))
        ->toBe(['purchase_order', 'expense', 'payment_voucher', 'receipt_voucher', 'journal_voucher']);

    // Every shortcut lands on a list or a create page, never a 403.
    ($this->visit)($buyer, $this->a, '/dashboard/owner')->assertInertia(fn (Assert $page) => $page
        ->where('shortcuts.0.url', '/purchasing/purchase-orders/new')
        ->where('shortcuts.1.url', '/purchasing/expenses')
        ->where('shortcuts.4.url', '/accounting/vouchers/journal/create'));
});

it('gives the Me shortcuts to a person employed in this business only', function () {
    $self = dashboardMember($this->a, 'Self-service');
    $employee = Employee::create([
        'employee_code' => 'BDG-900010', 'user_id' => $self->id, 'first_name' => 'E', 'last_name' => 'Me',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    DB::table('employee_assignments')->insert([
        'business_id' => $this->a->id, 'employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'is_primary' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(dashboardShortcuts($this, $self))->toBe(['clock', 'leave', 'expense_claim', 'staff_purchase', 'payslips']);

    // In another business the same person has no employee record: none.
    dashboardMember($this->b, 'Self-service', $self);
    $keys = null;
    ($this->visit)($self, $this->b, '/dashboard')->assertInertia(function (Assert $page) use (&$keys) {
        $keys = collect($page->toArray()['props']['shortcuts'])->pluck('key')->all();
    });
    expect($keys)->toBe([]);
});

it('still sends each dashboard exactly its own widgets (the redesign changes no payload)', function () {
    $expected = [
        'Owner' => ['owner', ['needs_attention', 'cash_position', 'money_in_transit', 'gross_profit', 'income_trend', 'receipts_vs_payments', 'receivables_ageing', 'payables_ageing', 'money_accounts']],
        'Finance' => ['finance', ['cash_position', 'money_in_transit', 'gross_profit', 'bank_unmatched', 'period_lock', 'income_trend', 'receipts_vs_payments', 'receivables_ageing', 'payables_ageing', 'money_accounts', 'finance_approvals', 'claims_to_pay']],
        'Staff' => ['sales', ['sales_vs_target', 'my_orders', 'orders_needing_action']],
        'Self-service' => ['employee', ['my_attendance', 'my_tasks', 'my_payslip', 'my_leave', 'my_claims']],
    ];

    foreach ($expected as $role => [$key, $mustHave]) {
        $user = dashboardMember($this->a, $role);
        ($this->visit)($user, $this->a, '/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.key', $key)
            ->where('widgets', function ($widgets) use ($mustHave, $role) {
                $keys = collect($widgets)->pluck('key');
                expect($keys->intersect($mustHave)->values()->all())->toEqualCanonicalizing($mustHave, $role);

                return true;
            }));
    }
});
