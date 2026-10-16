<?php

use App\Models\User;
use App\Modules\Foundation\database\seeders\DashboardAssignmentSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The calm, role-aware dashboard (redesign round 3, Joy, 16 Oct 2026): the
 * widgets each role's login really receives, placed by the page's own rules
 * (components/dashboards/arrangement.ts, run by node through esbuild). Every
 * widget the server sends is drawn somewhere, each role's "Needs you today"
 * and Key numbers read only its own widgets, and the page source keeps the
 * blocks and drops the gradient hero band.
 */
const ARRANGE_GRANTS = [
    'Owner' => [
        'dashboard.view_all', 'accounting.view', 'accounting.operations.view', 'sales.reports.view', 'sales.parcels.see',
        'inventory.reports.view', 'hrm.attendance.manage', 'hrm.payroll.manage',
    ],
    'Finance' => ['accounting.view', 'accounting.operations.view', 'hrm.payroll.manage'],
    'Manager' => ['sales.reports.view', 'sales.parcels.see', 'inventory.reports.view', 'inventory.stock.approve'],
    'Staff' => ['sales.sell', 'sales.orders.record'],
    'Self-service' => [],
];

/** @param list<array<string, mixed>> $calls */
function arrangement(array $calls): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir(base_path('node_modules/esbuild'))) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the arrangement harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/dashboard-arrangement.harness.ts'], base_path(), null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Arrange Co', 'slug' => 'arrange-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (ARRANGE_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::create(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    (new DashboardAssignmentSeeder)->run();

    /** @return array{dashboard: string, widgets: list<array{key: string, category: string}>} */
    $this->sent = function (string $role): array {
        $user = User::factory()->create();
        $user->businesses()->syncWithoutDetaching([$this->business->id]);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $props = null;
        $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return [
            'dashboard' => $props['dashboard']['key'],
            'widgets' => collect($props['widgets'])->map(fn ($w) => ['key' => $w['key'], 'category' => $w['category']])->all(),
        ];
    };
});

it('draws every widget a role receives somewhere, and each block only from that role\'s widgets', function () {
    $cases = collect(['Owner', 'Finance', 'Manager', 'Staff', 'Self-service'])->mapWithKeys(fn ($role) => [$role => ($this->sent)($role)]);
    $results = array_combine($cases->keys()->all(), arrangement($cases->values()->all()));

    foreach ($cases as $role => $case) {
        $sent = collect($case['widgets'])->pluck('key')->sort()->values()->all();

        // Nothing the server sends is lost, and nothing is drawn that it did not send.
        expect(collect($results[$role]['placed'])->sort()->values()->all())->toBe($sent, "{$role}: placed");
        expect(array_diff($results[$role]['needs'], $sent))->toBe([], "{$role}: needs")
            ->and(array_diff($results[$role]['key'], $sent))->toBe([], "{$role}: key numbers");
    }

    expect($cases['Owner']['dashboard'])->toBe('owner')
        // Decision 4: the Owner's fourth key number is Gross profit.
        ->and($results['Owner']['key'])->toBe(['cash_position', 'income_trend', 'money_in_transit', 'gross_profit'])
        ->and($results['Owner']['needs'])->toContain('needs_attention')
        // The six courier widgets the Owner may see are ONE area of three cards.
        ->and(collect($results['Owner']['groups'])->pluck('group')->all())->toContain('Couriers')
        ->and(collect($results['Owner']['groups'])->pluck('group')->filter(fn ($g) => $g === 'Couriers')->count())->toBe(1);

    expect($cases['Finance']['dashboard'])->toBe('finance')
        ->and($results['Finance']['key'])->toBe(['cash_position', 'receivables_ageing', 'payables_ageing', 'money_in_transit']);

    expect($cases['Manager']['dashboard'])->toBe('manager')
        ->and($results['Manager']['needs'])->toContain('needs_attention');

    expect($cases['Staff']['dashboard'])->toBe('sales')
        ->and($results['Staff']['needs'])->toContain('orders_needing_action')
        ->and($results['Staff']['needs'])->not->toContain('needs_attention');

    expect($cases['Self-service']['dashboard'])->toBe('employee')
        ->and($results['Self-service']['needs'])->toBe(['my_tasks'])
        ->and($results['Self-service']['key'])->toBe(['my_attendance', 'my_leave', 'my_payslip', 'my_claims']);
});

it('keeps the blocks of the design on the page, calm, with Sounds, Classic and settings in the More menu', function () {
    $page = file_get_contents(resource_path('js/pages/dashboards/show.tsx'));

    expect($page)->toContain('<NeedsToday')
        ->toContain('<KeyNumbers')
        ->toContain('<Shortcuts')
        ->toContain('<GettingStartedCard')
        ->toContain('function MoreMenu')
        ->toContain('/dashboard/classic')
        ->toContain('/settings/dashboards')
        ->toContain('SoundSettingsPanel')
        ->toContain('RevealAllButton')
        ->not->toContain('HeroBand')
        ->not->toContain('bg-gradient');

    expect(file_exists(resource_path('js/components/dashboards/hero-band.tsx')))->toBeFalse();

    foreach (['key-numbers', 'needs-today', 'shortcuts', 'courier-cards', 'getting-started'] as $component) {
        expect(file_get_contents(resource_path("js/components/dashboards/{$component}.tsx")))->not->toContain('bg-gradient');
    }
});
