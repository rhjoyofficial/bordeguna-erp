<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM as ONE sidebar group of five sections (redesign round 3, 16 Oct 2026).
 *
 * ⚠ Asserted in the SOURCE of the sections registry, the way the HRM, Assets,
 * Inventory, Purchases and Sales sections are: the gate of every address sits
 * in the same `{ ... }` entry as the address, so nobody gains or loses a screen
 * when a link becomes a tab.
 */
function crmSectionsSource(): string
{
    return file_get_contents(resource_path('js/components/crm/crm-sections.ts'));
}

it('lists every CRM screen as a tab with the gate its route has', function (string $url, string $gate) {
    $source = crmSectionsSource();

    expect($source)->toMatch('/url: \''.preg_quote($url, '/').'\'[^}]*can: '.preg_quote($gate, '/').'/')
        ->and(substr_count($source, "'{$url}'"))->toBe(1);
})->with([
    // crm.leads.view (routes/web.php), the shared flag canViewCrm.
    ['/crm/overview', 'crm'],
    ['/crm/reports', 'crm'],
    ['/crm/queue', 'crm'],
    // Hidden since 24 Oct 2026 (Joy): crm.leads.view AND the crm_tickets switch.
    ['/crm/tickets', '(f) => !!f.canViewCrmTickets'],
    ['/crm/leads', 'crm'],
    ['/crm/pipeline', 'crm'],
    // crm.contacts.view.
    ['/crm/contacts', '(f) => f.canViewContacts'],
    // crm.campaigns.send.
    ['/crm/messages/sms', '(f) => f.canSendCampaigns'],
    ['/crm/messages/email', '(f) => f.canSendCampaigns'],
    // crm.settings.manage.
    ['/crm/settings', 'setup'],
    ['/crm/cadences', 'setup'],
]);

it('binds the two short gate names to the right flags', function () {
    expect(crmSectionsSource())->toContain('const crm = (f: Flags) => f.canViewCrm;')
        ->toContain('const setup = (f: Flags) => f.canManageCrmSettings;');
});

it('groups the screens into the five sections of the design', function () {
    $source = crmSectionsSource();

    foreach (['crm-overview', 'crm-followups', 'crm-leads', 'crm-messages', 'crm-setup'] as $key) {
        expect($source)->toContain("key: '{$key}'");
    }

    // A lead's own page lights Leads; the old link name stays findable.
    expect($source)->toContain("also: ['/crm/lead']")
        ->and($source)->toContain("'Lists & sources'")
        ->and($source)->toMatch("/'Lists & sources'[^}]*keywords: 'crm settings/");
});

it('carries the two badges on Follow-ups and Tickets, and both on the section', function () {
    $source = crmSectionsSource();

    expect($source)->toContain('badge: (p) => count((p.crmFollowUpsDueCount ?? 0) + (p.crmTicketsOverdueCount ?? 0))')
        ->and($source)->toMatch("/url: '\\/crm\\/queue'[^}]*badge: \\(p\\) => count\\(p.crmFollowUpsDueCount\\)/")
        ->and($source)->toMatch("/url: '\\/crm\\/tickets'[^}]*badge: \\(p\\) => count\\(p.crmTicketsOverdueCount\\)/");
});

it('registers the CRM sections with the shared registry and the sidebar', function () {
    expect(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('...CRM_SECTIONS');

    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain('sectionNavItems(CRM_SECTIONS, shared, t)')
        // Shown when ANY section is visible, not on two of the four flags.
        ->and($sidebar)->toContain("...(crmItems.length > 0 ? [{ label: 'CRM', items: crmItems }] : [])")
        ->and($sidebar)->not->toContain('shared.canViewCrm || shared.canViewContacts')
        // The ten old links are gone: the module is its sections now.
        ->and($sidebar)->not->toContain("url: '/crm/leads'")
        ->and($sidebar)->not->toContain("url: '/crm/messages/sms'")
        ->and($sidebar)->not->toContain("url: '/crm/cadences'");
});

it('roots every CRM breadcrumb at the Overview', function (string $page) {
    $source = file_get_contents(resource_path("js/pages/crm/{$page}.tsx"));

    expect($source)->toContain("{ title: 'CRM', href: '/crm/overview' }");
})->with(['overview', 'queue', 'reports', 'tickets', 'pipeline', 'cadences', 'leads/index', 'contacts/index', 'settings/index', 'messaging/index', 'timeline']);

it('draws no duplicate module title on the tabbed list pages', function (string $page) {
    expect(file_get_contents(resource_path("js/pages/crm/{$page}.tsx")))->not->toContain('<h1');
})->with(['overview', 'queue', 'tickets', 'pipeline', 'cadences', 'leads/index']);

it('keeps the strings the ticket and messaging tests read', function () {
    $tickets = file_get_contents(resource_path('js/pages/crm/tickets.tsx'));

    expect($tickets)->toContain('<TicketDialog')
        ->and($tickets)->toContain('<TicketCard key={ticket.id} ticket={ticket} users={users} />');
});

it('writes no em dash into the CRM screens', function () {
    $files = array_merge(
        glob(resource_path('js/pages/crm/*.tsx')),
        glob(resource_path('js/pages/crm/*/*.tsx')),
        glob(resource_path('js/components/crm/*.ts*')),
    );

    foreach ($files as $file) {
        expect(str_contains(file_get_contents($file), "\u{2014}"))->toBeFalse(basename($file).' holds an em dash');
    }
});

it('shares the CRM flags each role needs for its sections', function (string $role, array $expected) {
    $business = Business::create(['name' => 'Gate Co', 'slug' => 'gate-co']);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    setPermissionsTeamId($business->id);
    $user->assignRole($role);

    $this->withoutVite();
    $props = $this->actingAs($user)->withHeader('X-Business-Id', (string) $business->id)
        ->get('/crm/overview')->assertOk()->viewData('page')['props'];

    expect([
        'crm' => $props['canViewCrm'],
        'contacts' => $props['canViewContacts'],
        'send' => $props['canSendCampaigns'],
        'setup' => $props['canManageCrmSettings'],
    ])->toBe($expected)
        // Every role here may read leads, so the follow-ups badge is a count;
        // Tickets are hidden since 24 Oct 2026 (Joy), so theirs is null.
        ->and($props['crmFollowUpsDueCount'])->toBe(0)
        ->and($props['crmTicketsOverdueCount'])->toBeNull();
})->with([
    // Five sections: Overview, Follow-ups, Leads, Phone book & messages, Setup.
    'Owner' => ['Owner', ['crm' => true, 'contacts' => true, 'send' => true, 'setup' => true]],
    'Manager' => ['Manager', ['crm' => true, 'contacts' => true, 'send' => true, 'setup' => true]],
    // Three: Overview, Follow-ups, Leads.
    'Sales Lead' => ['Sales Lead', ['crm' => true, 'contacts' => false, 'send' => false, 'setup' => false]],
    'Sales Officer' => ['Sales Officer', ['crm' => true, 'contacts' => false, 'send' => false, 'setup' => false]],
    // Four: the three, plus the Phone book.
    'Staff' => ['Staff', ['crm' => true, 'contacts' => true, 'send' => false, 'setup' => false]],
]);
