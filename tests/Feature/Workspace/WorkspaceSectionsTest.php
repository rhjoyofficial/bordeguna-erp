<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The top of the sidebar (redesign round 3, Joy, 16 Oct 2026): Dashboard and
 * All businesses as top links, ONE Workspace group of six, every old link a
 * tab with its exact gate.
 *
 * Two halves: the registry SOURCE (each address beside its gate, the way the
 * other modules' sections tests read theirs), and the registry RUN with the
 * shared flags each role really receives (tests/js/workspace-navigation.harness.ts,
 * bundled by esbuild and run by node, as SectionNavigationTest does).
 */
function workspaceSectionsSource(): string
{
    return file_get_contents(resource_path('js/components/workspace/workspace-sections.ts'));
}

/** The address and the gate inside the SAME `{ ... }` entry, one line or several. */
function workspaceGateOf(string $url, string $gate): string
{
    return '/url: \''.preg_quote($url, '/').'\'[^}]*can: '.preg_quote($gate, '/').'/';
}

/** @param list<array<string, mixed>> $calls */
function workspaceNavigation(array $calls): array
{
    $root = base_path();
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the navigation harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/workspace-navigation.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/** The flags a role's login really gets: the shared props of /work. */
function workspaceFlags(object $test, User $user): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $props = null;

    $test->actingAs($user->fresh())->withSession(['current_business_id' => $test->business->id])
        ->get('/work')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // Only the flags and counts: the sidebar reads nothing else.
    return array_filter($props, fn ($value) => is_bool($value) || is_int($value));
}

/** @param array<int, array{label: string, items: list<array{title: string, url: string}>}> $sidebar */
function workspaceLinks(array $sidebar): array
{
    return collect($sidebar)->flatMap(fn ($group) => collect($group['items'])->map(fn ($item) => $item['title'].' '.$item['url']))->all();
}

const WS_ROLES = [
    'Owner' => [
        'dashboard.view_all', 'inventory.stock.approve', 'calendar.view', 'notes.view', 'files.view', 'chat.use',
        'accounting.view', 'sales.reports.view', 'inventory.reports.view',
    ],
    'Finance' => ['accounting.view', 'calendar.view', 'notes.view', 'files.view', 'chat.use'],
    'Manager' => ['inventory.stock.approve', 'inventory.reports.view', 'calendar.view', 'files.view', 'chat.use'],
    'Sales Officer' => ['sales.sell', 'sales.orders.record', 'chat.use'],
    'Self-service' => [],
];

beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Workspace Co', 'slug' => 'workspace-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (WS_ROLES as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::create(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    $this->member = function (string $role, bool $employee = false): User {
        $user = User::factory()->create();
        $user->businesses()->syncWithoutDetaching([$this->business->id]);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        if ($employee) {
            $record = Employee::create([
                'employee_code' => 'BDG-'.random_int(100000, 999999), 'user_id' => $user->id, 'first_name' => 'E', 'last_name' => $role,
                'employment_type' => 'permanent', 'status' => 'active',
            ]);
            DB::table('employee_assignments')->insert([
                'business_id' => $this->business->id, 'employee_id' => $record->id, 'effective_from' => '2026-01-01', 'is_primary' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    };
});

it('lists every Workspace, Dashboard and All businesses screen as a tab with the gate its link had', function (string $url, string $gate) {
    $source = workspaceSectionsSource();

    expect($source)->toMatch(workspaceGateOf($url, $gate))
        ->and(substr_count($source, "url: '{$url}'"))->toBe(1);
})->with([
    ['/dashboard', 'everyone'],
    ['/attention', 'everyone'],
    ['/health', 'everyone'],
    ['/work', 'everyone'],
    ['/approvals', '(f) => f.canReviewApprovals'],
    ['/approvals/history', '(f) => f.canReviewApprovals'],
    ['/notifications', 'everyone'],
    ['/hrm/my-profile', 'me'],
    ['/hrm/clock', 'me'],
    ['/hrm/my-leave', 'me'],
    ['/hrm/my-payslips', 'me'],
    ['/hrm/my-expenses', 'me'],
    ['/hrm/my-notices', 'me'],
    ['/hrm/my-assets', 'me'],
    ['/hrm/my-accounts', 'me'],
    ['/hrm/staff-purchase', 'me'],
    ['/hrm/my-team', 'me'],
    ['/hrm/org-chart', 'everyone'],
    ['/calendar', '(f) => f.canViewCalendar'],
    ['/schedule', 'everyone'],
    ['/files', '(f) => f.canViewFiles'],
    ['/notes', '(f) => f.canViewNotes'],
    ['/chat', '(f) => f.canUseChat'],
    ['/group/cockpit', '(f) => f.canViewGroupCockpit'],
    ['/group/partners', '(f) => f.canViewGroupPartners'],
    ['/group/products', '(f) => f.canViewGroupProducts'],
]);

it('replaces the Overview, Workspace and Group blocks of the sidebar with the registry', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect(file_get_contents(resource_path('js/lib/sections.ts')))
        ->toContain('...HOME_SECTIONS')->toContain('...WORKSPACE_SECTIONS')->toContain('...GROUP_SECTIONS')
        ->and($sidebar)->toContain('sectionNavItems(WORKSPACE_SECTIONS')
        ->and($sidebar)->toContain('sectionNavItems(HOME_SECTIONS')
        ->and($sidebar)->toContain('hideLabel: true')
        ->and($sidebar)->not->toContain("label: 'Overview'")
        ->and($sidebar)->not->toContain("label: 'Group'")
        ->and($sidebar)->not->toContain("url: '/group/cockpit'")
        ->and($sidebar)->not->toContain("url: '/hrm/my-profile'")
        // Still findable by name from the header search, and in the user menu.
        ->and($sidebar)->toContain("url: '/relax'")
        ->and(file_get_contents(resource_path('js/components/user-menu-content.tsx')))->toContain('href="/relax"')
        // The classic dashboard stays reachable for a month, from the More menu.
        ->and(file_get_contents(resource_path('js/pages/dashboards/show.tsx')))->toContain('/dashboard/classic');

    // The self-service pages draw no tab row of their own any more: the registry does.
    expect(file_exists(resource_path('js/components/self-service-tabs.tsx')))->toBeFalse();
});

it('draws for each role exactly the links its old gates gave, and five for a self-service login', function () {
    $links = function (string $role, bool $employee = false) {
        [$sidebar] = workspaceNavigation([['op' => 'sidebar', 'flags' => workspaceFlags($this, ($this->member)($role, $employee))]]);

        return workspaceLinks($sidebar);
    };

    // Was 10 visible links (Dashboard, My work, Needs attention, Schedule,
    // Business health, Relax, My profile, My team, Staff purchase, Org chart).
    expect($links('Self-service', true))->toBe([
        'Dashboard /dashboard', 'My work /work', 'My profile /hrm/my-profile', 'Team /hrm/my-team', 'Calendar /schedule',
    ]);

    // No employee record: no My profile, and Team opens on the org chart.
    expect($links('Sales Officer'))->toBe([
        'Dashboard /dashboard', 'My work /work', 'Team /hrm/org-chart', 'Calendar /schedule', 'Chat /chat',
    ]);

    // inventory.reports.view opens one of the three All businesses tabs: the link goes there.
    expect($links('Manager'))->toBe([
        'Dashboard /dashboard', 'All businesses /group/products', 'My work /work', 'Team /hrm/org-chart', 'Calendar /calendar', 'Documents /files', 'Chat /chat',
    ]);

    // accounting.view opens the All businesses overview.
    expect($links('Finance'))->toBe([
        'Dashboard /dashboard', 'All businesses /group/cockpit', 'My work /work', 'Team /hrm/org-chart', 'Calendar /calendar', 'Documents /files', 'Chat /chat',
    ]);

    expect($links('Owner', true))->toBe([
        'Dashboard /dashboard', 'All businesses /group/cockpit', 'My work /work', 'My profile /hrm/my-profile', 'Team /hrm/my-team',
        'Calendar /calendar', 'Documents /files', 'Chat /chat',
    ]);
});

it('puts the old links in the tab rows, each tab only for whoever its gate opens', function () {
    $owner = workspaceFlags($this, ($this->member)('Owner', true));
    $finance = workspaceFlags($this, ($this->member)('Finance'));
    $self = workspaceFlags($this, ($this->member)('Self-service', true));

    [$myWork, $history, $selfWork, $clock, $dashboard, $classic, $group, $financeGroup, $schedule, $documents] = workspaceNavigation([
        ['op' => 'row', 'path' => '/work', 'flags' => $owner],
        ['op' => 'row', 'path' => '/approvals/history', 'flags' => $owner],
        ['op' => 'row', 'path' => '/work', 'flags' => $self],
        ['op' => 'row', 'path' => '/hrm/clock', 'flags' => $self],
        ['op' => 'row', 'path' => '/dashboard/owner', 'flags' => $owner],
        ['op' => 'row', 'path' => '/dashboard/classic', 'flags' => $owner],
        ['op' => 'row', 'path' => '/group/partners', 'flags' => $owner],
        ['op' => 'row', 'path' => '/group/cockpit', 'flags' => $finance],
        ['op' => 'row', 'path' => '/schedule', 'flags' => $self],
        ['op' => 'row', 'path' => '/notes', 'flags' => $owner],
    ]);

    expect($myWork['tabs'])->toBe(['My work', 'Approvals', 'Approval history', 'Notifications'])
        ->and($history['current'])->toBe('/approvals/history')
        // No approvals to review: My work and Notifications only.
        ->and($selfWork['tabs'])->toBe(['My work', 'Notifications'])
        ->and($clock['tabs'])->toBe(['Profile', 'Clock in/out', 'Leave', 'Payslips', 'Expense claims', 'Notices', 'Assets', 'Accounts', 'Staff purchase'])
        ->and($clock['current'])->toBe('/hrm/clock')
        ->and($dashboard['tabs'])->toBe(['Dashboard', 'Needs attention', 'Business health'])
        ->and($dashboard['current'])->toBe('/dashboard')
        ->and($classic['current'])->toBe('/dashboard')
        ->and($group['tabs'])->toBe(['Overview', 'Customers & suppliers', 'Products'])
        ->and($group['current'])->toBe('/group/partners')
        // Finance opens one of the three: a heading, not a tab row.
        ->and($financeGroup)->toBeNull()
        // Self-service: the work schedule alone, no calendar permission.
        ->and($schedule)->toBeNull()
        ->and($documents['tabs'])->toBe(['Documents', 'Notes']);
});

it('counts approvals only on My work and Needs attention on Dashboard, never the unread notifications', function () {
    [$sidebar] = workspaceNavigation([['op' => 'sidebar', 'flags' => [
        'canReviewApprovals' => true, 'pendingApprovalsCount' => 3, 'unreadNotificationsCount' => 7, 'attentionCount' => 2,
    ]]]);

    $badges = collect($sidebar)->flatMap(fn ($group) => $group['items'])->pluck('badge', 'title')->all();

    expect($badges['My work'])->toBe(3)
        ->and($badges['Dashboard'])->toBe(2);
});

it('says every Workspace title in Bangla through its dictionary key', function () {
    [$keys, $sidebar, $row] = workspaceNavigation([
        ['op' => 'titleKeys'],
        ['op' => 'sidebar', 'flags' => ['hasEmployeeProfile' => true], 'translate' => true],
        ['op' => 'row', 'path' => '/hrm/my-leave', 'flags' => ['hasEmployeeProfile' => true], 'translate' => true],
    ]);

    $dictionary = implode("\n", array_map('file_get_contents', [resource_path('js/lib/i18n.ts'), ...glob(resource_path('js/lib/i18n/*.ts'))]));

    // Every My profile tab and every Workspace section carries one.
    expect($keys)->toContain('ws.my_profile', 'ws.tab.clock', 'ws.tab.leave', 'ws.tab.payslips', 'ws.tab.staff_purchase', 'attention.title', 'ws.work_schedule');

    foreach (array_unique($keys) as $key) {
        expect($dictionary)->toMatch("/'".preg_quote($key, '/')."': \\{ en: '[^']+', bn: '[^']+' \\}/u");
    }

    expect(workspaceLinks($sidebar))->toContain('«ws.my_profile» /hrm/my-profile')
        ->and($row['tabs'])->toContain('«ws.tab.leave»');
});

it('finds the old link names from the header search', function (string $q, string $url) {
    $flags = workspaceFlags($this, ($this->member)('Owner', true));
    [$found] = workspaceNavigation([['op' => 'search', 'q' => $q, 'flags' => $flags]]);

    expect(collect($found)->pluck('url')->all())->toContain($url);
})->with([
    ['group cockpit', '/group/cockpit'],
    ['group partners', '/group/partners'],
    ['approvals', '/approvals'],
    ['approval history', '/approvals/history'],
    ['needs attention', '/attention'],
    ['org chart', '/hrm/org-chart'],
    ['schedule', '/schedule'],
    ['notes', '/notes'],
    ['payslips', '/hrm/my-payslips'],
    ['notifications', '/notifications'],
]);
