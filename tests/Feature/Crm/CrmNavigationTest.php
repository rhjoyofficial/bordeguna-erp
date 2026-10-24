<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The CRM sections RUN, not read (round 3, 16 Oct 2026): the real
 * CRM_SECTIONS through the real registry functions, by the navigation harness
 * that SectionNavigationTest uses (tests/js, bundled by esbuild, run by node).
 * Only the harness's existing calls are used, against the full registry.
 */
function crmNavigation(array $calls): array
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

/** The shared flags of each role, as CrmPermissionsSeeder grants them. */
function crmFlags(string $role): array
{
    return match ($role) {
        'Owner', 'Manager' => ['canViewCrm' => true, 'canViewContacts' => true, 'canSendCampaigns' => true, 'canManageCrmSettings' => true],
        'Sales Lead', 'Sales Officer' => ['canViewCrm' => true],
        'Staff' => ['canViewCrm' => true, 'canViewContacts' => true],
        'Sender only' => ['canSendCampaigns' => true],
    };
}

it('gives each role its CRM sidebar rows and nobody a screen they cannot open', function () {
    $roles = ['Owner', 'Manager', 'Sales Lead', 'Sales Officer', 'Staff', 'Sender only'];
    $results = crmNavigation(array_map(fn (string $role) => ['op' => 'nav', 'flags' => crmFlags($role), 'registry' => 'all'], $roles));

    $rows = [];
    foreach ($roles as $index => $role) {
        $rows[$role] = collect($results[$index])
            // Settings' own Messaging & AI row also lives under /crm/settings/messaging.
            ->filter(fn (array $row) => str_starts_with($row['url'], '/crm/') && $row['url'] !== '/crm/settings/messaging')
            ->map(fn (array $row) => $row['title'].' '.$row['url'])
            ->values()
            ->all();
    }

    $all = ['Overview /crm/overview', 'Follow-ups /crm/queue', 'Leads /crm/leads', 'Phone book & messages /crm/contacts', 'Setup /crm/settings'];

    expect($rows['Owner'])->toBe($all)
        ->and($rows['Manager'])->toBe($all)
        ->and($rows['Sales Lead'])->toBe(['Overview /crm/overview', 'Follow-ups /crm/queue', 'Leads /crm/leads'])
        ->and($rows['Sales Officer'])->toBe(['Overview /crm/overview', 'Follow-ups /crm/queue', 'Leads /crm/leads'])
        ->and($rows['Staff'])->toBe(['Overview /crm/overview', 'Follow-ups /crm/queue', 'Leads /crm/leads', 'Phone book & messages /crm/contacts'])
        // ⚠ The gap this round closes: with only the send permission there
        // used to be no CRM group at all.
        ->and($rows['Sender only'])->toBe(['Phone book & messages /crm/messages/sms']);
});

it('counts follow-ups and late tickets on the Follow-ups row, and nothing when there is nothing', function () {
    [$busy, $quiet] = crmNavigation([
        ['op' => 'nav', 'flags' => crmFlags('Sales Officer') + ['crmFollowUpsDueCount' => 3, 'crmTicketsOverdueCount' => 2], 'registry' => 'all'],
        ['op' => 'nav', 'flags' => crmFlags('Sales Officer') + ['crmFollowUpsDueCount' => 0, 'crmTicketsOverdueCount' => null], 'registry' => 'all'],
    ]);

    $followUps = fn (array $rows) => collect($rows)->firstWhere('url', '/crm/queue');

    expect($followUps($busy)['badge'])->toBe(5)
        ->and($followUps($quiet)['badge'])->toBeNull();
});

it('lights Leads on a lead\'s own page, and leaves the SMS gateway to Settings', function () {
    [$lead, $leadRow, $gateway, $customer] = crmNavigation([
        ['op' => 'find', 'path' => '/crm/lead/12/timeline', 'flags' => crmFlags('Owner'), 'registry' => 'all'],
        ['op' => 'row', 'path' => '/crm/lead/12/timeline', 'flags' => crmFlags('Owner'), 'registry' => 'all'],
        ['op' => 'find', 'path' => '/crm/settings/messaging', 'flags' => crmFlags('Owner'), 'registry' => 'all'],
        ['op' => 'find', 'path' => '/crm/customer/4/timeline', 'flags' => crmFlags('Owner') + ['canViewSales' => true], 'registry' => 'all'],
    ]);

    expect($lead)->toBe(['section' => 'crm-leads', 'tab' => '/crm/leads'])
        ->and($leadRow)->toBe(['section' => 'crm-leads', 'tabs' => ['/crm/leads', '/crm/pipeline'], 'current' => '/crm/leads'])
        ->and($gateway['tab'])->toBe('/crm/settings/messaging')
        ->and($gateway['section'])->not->toStartWith('crm-')
        ->and($customer['section'])->not->toStartWith('crm-');
});

it('draws each section\'s tab row only with the tabs the person may open', function () {
    [$ownerMessages, $staffPhoneBook, $officerFollowUps, $ticketsHidden] = crmNavigation([
        ['op' => 'row', 'path' => '/crm/messages/email', 'flags' => crmFlags('Owner'), 'registry' => 'all'],
        // One tab is a heading, not a choice: no row.
        ['op' => 'row', 'path' => '/crm/contacts', 'flags' => crmFlags('Staff'), 'registry' => 'all'],
        // Tickets are hidden since 24 Oct 2026 (Joy): canViewCrmTickets carries the switch.
        ['op' => 'row', 'path' => '/crm/tickets', 'flags' => crmFlags('Sales Officer') + ['canViewCrmTickets' => true], 'registry' => 'all'],
        ['op' => 'row', 'path' => '/crm/queue', 'flags' => crmFlags('Sales Officer'), 'registry' => 'all'],
    ]);

    expect($ownerMessages)->toBe(['section' => 'crm-messages', 'tabs' => ['/crm/contacts', '/crm/messages/sms', '/crm/messages/email'], 'current' => '/crm/messages/email'])
        ->and($staffPhoneBook)->toBeNull()
        ->and($officerFollowUps)->toBe(['section' => 'crm-followups', 'tabs' => ['/crm/queue', '/crm/tickets'], 'current' => '/crm/tickets'])
        // Switched off, Follow-ups is the one screen left: a heading, no Tickets tab.
        ->and($ticketsHidden)->toBeNull();
});
