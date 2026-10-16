<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The four report tabs of Sales › Overview open /hrm/reports/{key}, and the
 * report registry decides who may run each one, not the permission alone: a
 * Department Manager is held to their own department (ACL-02 / C10d), and a
 * sales report has no department to scope to, so it answers 404. The role walk
 * of round 3 (17 Oct 2026) found the Manager shown all four. The tabs now read
 * `runnableReports`, the keys ReportRegistry::canRun lets THIS user run.
 *
 * Run for real: the shared props a role's browser gets go through the section
 * registry (tests/js/section-navigation.harness.ts), like SectionNavigationTest.
 */
const SALES_REPORT_TABS = [
    '/hrm/reports/sales_by_channel',
    '/hrm/reports/sales_margin',
    '/hrm/reports/delivery_economics',
    '/hrm/reports/courier_settlement_ageing',
];

/** @param list<array<string, mixed>> $calls */
function salesReportNavigation(array $calls): array
{
    $root = dirname(__DIR__, 3);
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the navigation harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/section-navigation.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->business = Business::create(['name' => 'Report Co', 'slug' => 'report-co']);
    app(BusinessContext::class)->set($this->business->id);
    setPermissionsTeamId($this->business->id);

    $permissions = ['sales.view', 'sales.reports.view', 'sales.reports.costs'];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->as = function (string $roleName): array {
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web', 'business_id' => $this->business->id]);
        $role->givePermissionTo(['sales.view', 'sales.reports.view', 'sales.reports.costs']);
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($roleName);

        $props = $this->actingAs($user)->withSession(['current_business_id' => $this->business->id])
            ->get('/notifications')->assertOk()->original->getData()['page']['props'];

        return [$user, $props];
    };
});

it('shares the report keys this user may run, as the registry decides', function () {
    [, $owner] = ($this->as)('Owner');
    [, $manager] = ($this->as)('Manager');

    expect($owner['runnableReports'])->toEqualCanonicalizing(['sales_by_channel', 'sales_margin', 'delivery_economics', 'courier_settlement_ageing'])
        // Same permissions, but a Department Manager: none of them is scoped to a department.
        ->and($manager['runnableReports'])->toBe([]);
});

it('shows the Manager none of the four Sales report tabs, and the Owner and Finance all four', function () {
    [, $owner] = ($this->as)('Owner');
    [, $finance] = ($this->as)('Finance');
    [$managerUser, $manager] = ($this->as)('Manager');

    [$ownerRow, $financeRow, $managerRow] = salesReportNavigation([
        ['op' => 'row', 'path' => '/sales/overview', 'flags' => $owner, 'registry' => 'all'],
        ['op' => 'row', 'path' => '/sales/overview', 'flags' => $finance, 'registry' => 'all'],
        ['op' => 'row', 'path' => '/sales/overview', 'flags' => $manager, 'registry' => 'all'],
    ]);

    expect($ownerRow['tabs'])->toBe(['/sales/overview', ...SALES_REPORT_TABS])
        ->and($financeRow['tabs'])->toBe(['/sales/overview', ...SALES_REPORT_TABS])
        // Overview alone is a heading, not a row.
        ->and($managerRow)->toBeNull();

    // And what the Manager is no longer shown is exactly what the server refuses.
    foreach (SALES_REPORT_TABS as $url) {
        $this->actingAs($managerUser)->withSession(['current_business_id' => $this->business->id])->get($url)->assertNotFound();
    }
});
