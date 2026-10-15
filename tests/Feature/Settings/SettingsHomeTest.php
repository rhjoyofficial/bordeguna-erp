<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Settings home (Joy, 15 Oct 2026): twenty sidebar links become ONE link
 * to a searchable page of grouped cards, and every settings screen becomes a
 * tab of its area. Every screen keeps its address and its gate.
 *
 * ⚠ The cards are built in the browser from the shared permission flags, the
 * same way the old sidebar was. So the honest checks are: (1) the flag each
 * tab reads is true exactly when the screen behind it answers 200, and false
 * exactly when it would answer 403, for every tab; (2) the registry source
 * lists every screen with the flag its controller really checks. A test that
 * scraped the rendered cards would pass while the gate underneath was wrong.
 */
beforeEach(function () {
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Home Co', 'slug' => 'home-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Role::create(['name' => 'Nobody', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $this->plain = shMember($this->business, 'Nobody');
});

function shMember(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->syncWithoutDetaching([$business->id]);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** A member holding exactly ONE permission, and nothing else. */
function shHolderOf(Business $business, string $permission): User
{
    Permission::findOrCreate($permission, 'web');
    $role = Role::firstOrCreate(['name' => 'Holds '.$permission, 'business_id' => $business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    return shMember($business, $role->name);
}

function shAs(object $t, User $user)
{
    // ⚠ Like production: no business is resolved until the middleware does
    // it (FND-001, 14 Oct 2026).
    app(BusinessContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $t->actingAs($user->fresh())
        ->withSession(['current_business_id' => $t->business->id])
        ->withHeader('X-Business-Id', (string) $t->business->id);
}

function shSectionsSource(): string
{
    return file_get_contents(resource_path('js/components/settings/settings-sections.ts'));
}

/** The address and the flag inside the SAME `{ ... }` entry of the registry, one line or several. */
function shGateOf(string $url, string $flag): string
{
    return '/url: \''.preg_quote($url, '/').'\'[^}]*'.preg_quote($flag, '/').'/';
}

it('opens the settings home at /settings instead of jumping to the profile', function () {
    shAs($this, $this->plain)->get('/settings')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('settings/home'));
});

it('still answers the old address of every personal screen for everybody', function (string $url) {
    shAs($this, $this->plain)->get($url)->assertOk();
})->with(['/settings/profile', '/settings/password', '/settings/appearance']);

/**
 * ⚠⚠ One row per company-wide tab: the flag the card reads, and the permission
 * the screen behind it really checks. A person holding ONLY that permission
 * sees the flag true and the screen open; a person with nothing sees the flag
 * false and the screen refused. The two halves are asserted together so a
 * card can never offer a link into a 403, which is the bug the old settings
 * nav had (SettingsNavigationTest).
 */
it('shows a tab exactly to the people whose screen would open', function (string $url, string $permission, string $flag) {
    $holder = shHolderOf($this->business, $permission);

    shAs($this, $holder)->get('/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where($flag, true));
    shAs($this, $holder)->get($url)->assertOk();

    shAs($this, $this->plain)->get('/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where($flag, false));
    shAs($this, $this->plain)->get($url)->assertForbidden();

    // And the registry names that very flag beside that very address.
    expect(shSectionsSource())->toMatch(shGateOf($url, $flag));
})->with([
    'business' => ['/settings/business', 'foundation.organization.manage', 'canManageOrganization'],
    'organization' => ['/settings/organization', 'foundation.organization.manage', 'canManageOrganization'],
    'printers' => ['/settings/printers', 'foundation.organization.manage', 'canManageOrganization'],
    'team' => ['/settings/team', 'foundation.users.manage', 'canManageUsers'],
    'roles' => ['/settings/roles', 'foundation.users.manage', 'canManageUsers'],
    'dashboards' => ['/settings/dashboards', 'dashboard.view_all', 'canConfigureDashboards'],
    'courier accounts' => ['/settings/couriers', 'couriers.settings.manage', 'canManageCourierSettings'],
    'courier locations' => ['/settings/couriers/locations', 'couriers.settings.manage', 'canManageCourierSettings'],
    'courier rate cards' => ['/settings/couriers/rate-cards', 'couriers.ratecards.propose', 'canProposeRateCards'],
    'work schedule' => ['/settings/work-schedule', 'schedule.manage', 'canManageSchedule'],
    'popups' => ['/settings/popups', 'system.popups.manage', 'canManagePopups'],
    'sounds' => ['/settings/sounds', 'system.popups.manage', 'canManagePopups'],
    'relax zone' => ['/settings/relax', 'relax.manage', 'canManageRelax'],
    'stock reasons' => ['/settings/reasons', 'inventory.catalog.manage', 'canManageReasons'],
    'sms gateway' => ['/crm/settings/messaging', 'crm.settings.manage', 'canManageCrmSettings'],
    'ai providers' => ['/settings/ai-providers', 'foundation.organization.manage', 'canManageOrganization'],
    'failed jobs' => ['/settings/failed-jobs', 'system.jobs.manage', 'canManageJobs'],
]);

it('lists the module shortcuts with the gate their own screen checks', function (string $url, string $flag) {
    expect(shSectionsSource())->toMatch(shGateOf($url, $flag));
})->with([
    'crm settings' => ['/crm/settings', 'canManageCrmSettings'],
    'cadences' => ['/crm/cadences', 'canManageCrmSettings'],
    'inventory settings' => ['/inventory/settings', 'inventoryAbilities?.approveStock'],
    'vat settings' => ['/accounting/vat-settings', 'canManageVat'],
    'finance policy' => ['/accounting/finance-policy', 'canManageFinancePolicy'],
]);

it('gives every area the words the search box matches on', function () {
    $source = shSectionsSource();

    // Eight areas, each with its own keywords line; "My account" is the one personal area.
    expect(substr_count($source, 'keywords:'))->toBeGreaterThanOrEqual(8 + 5)
        ->and($source)->toContain('personal: true')
        ->and(substr_count($source, 'personal: true'))->toBe(1);
});

it('registers the settings areas with the shared sections registry', function () {
    // The layout draws the tab row from ALL_SECTIONS; an area not listed there has no tabs.
    expect(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('SETTINGS_SECTIONS');
});

it('puts one Settings link in the sidebar and retires the twenty', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain('href="/settings"')
        ->and($sidebar)->not->toContain('settings-nav')
        ->and(file_exists(resource_path('js/lib/settings-nav.ts')))->toBeFalse()
        // Sales repeats NO courier link (Sales redesign, 15 Oct 2026): the
        // Settings › Couriers area is the one place, and the Parcels pages link
        // to it from their header. Before that day Sales kept one shortcut.
        ->and(substr_count($sidebar, "title: 'Couriers'"))->toBe(0)
        ->and($sidebar)->not->toContain("title: 'Courier settings'")
        ->and($sidebar)->not->toContain("title: 'Courier locations'")
        ->and($sidebar)->not->toContain("title: 'Courier rate cards'")
        ->and($sidebar)->not->toContain("'/settings/couriers/locations'");
});

it('hands the failed-jobs count only to somebody who may open that screen', function () {
    DB::table('failed_jobs')->insert([
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()],
        ['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()],
    ]);

    $owner = shHolderOf($this->business, 'system.jobs.manage');

    shAs($this, $owner)->get('/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('failedJobsCount', 2));

    // ⚠ Null, not 0: a count that is withheld is not a count of nothing.
    shAs($this, $this->plain)->get('/settings')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('failedJobsCount', null));
});
