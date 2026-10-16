<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The shared navigation of redesign round 3 (16 Oct 2026), RUN rather than
 * read: which tab row a path gets, what the sidebar rows carry, and what the
 * header search finds. tests/js/section-navigation.harness.ts is bundled with
 * the real resources/js modules by esbuild (installed with Vite) and run by
 * node, so these assertions hit the same functions the browser runs.
 *
 * ⚠ The rules, as decided (round3-VERIFY X1-X7):
 * - The LONGEST matching prefix wins. On an exact tie the first section in
 *   registry order whose tab the person may open wins; if nobody's may be
 *   opened, the first one is found and no tab row is drawn. A SHORTER prefix
 *   never wins instead.
 * - Payroll holds accounting.operations.view but no sales permission, so the
 *   two Sales tabs on Accounts addresses carry the Sales group gate too.
 * - Every tab is searchable by its title and its keywords.
 */
function navigationRoot(): string
{
    return dirname(__DIR__, 3);
}

/** @param list<array<string, mixed>> $calls */
function navigation(array $calls): array
{
    $root = navigationRoot();
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the navigation harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/section-navigation.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function navigationSource(string $path): string
{
    return file_get_contents(navigationRoot().'/resources/js/'.$path);
}

it('lights the tab of the longest matching prefix', function () {
    [$history, $overview] = navigation([
        ['op' => 'find', 'path' => '/inv/requests/history/3', 'flags' => ['inv' => true, 'approve' => true], 'registry' => 'synthetic'],
        ['op' => 'find', 'path' => '/inv/other', 'flags' => ['inv' => true], 'registry' => 'synthetic'],
    ]);

    expect($history)->toBe(['section' => 'inv-requests', 'tab' => '/inv/requests/history'])
        ->and($overview)->toBe(['section' => 'inv-overview', 'tab' => '/inv']);
});

it('never falls back to a shorter prefix when the longest tab may not be opened', function () {
    [$found, $row, $realFound, $realRow] = navigation([
        // No `approve`: the Requests tab is closed to this person, and still
        // nothing of the module overview (/inv) lights instead.
        ['op' => 'find', 'path' => '/inv/requests/5', 'flags' => ['inv' => true], 'registry' => 'synthetic'],
        ['op' => 'row', 'path' => '/inv/requests/5', 'flags' => ['inv' => true], 'registry' => 'synthetic'],
        // The real registry: a stock request for somebody who may not approve.
        ['op' => 'find', 'path' => '/inventory/stock-requests/5', 'flags' => ['canViewInventory' => true], 'registry' => 'all'],
        ['op' => 'row', 'path' => '/inventory/stock-requests/5', 'flags' => ['canViewInventory' => true], 'registry' => 'all'],
    ]);

    expect($found)->toBe(['section' => 'inv-requests', 'tab' => '/inv/requests'])
        ->and($row)->toBeNull()
        ->and($realFound['tab'])->toBe('/inventory/stock-requests')
        ->and($realRow)->toBeNull();
});

it('gives a shared address to the first section in registry order whose tab the person may open', function () {
    [$both, $booksOnly, $nobody, $noFlags, $valuationBooks, $valuationBoth, $rowBooks, $rowNobody] = navigation([
        ['op' => 'find', 'path' => '/books/ageing', 'flags' => ['sales' => true, 'books' => true], 'registry' => 'synthetic'],
        ['op' => 'find', 'path' => '/books/ageing', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'find', 'path' => '/books/ageing', 'flags' => [], 'registry' => 'synthetic'],
        ['op' => 'find', 'path' => '/books/ageing', 'flags' => null, 'registry' => 'synthetic'],
        // A tie inside a prefix another tab of the first module also owns.
        ['op' => 'find', 'path' => '/inv/valuation', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'find', 'path' => '/inv/valuation', 'flags' => ['inv' => true, 'books' => true], 'registry' => 'synthetic'],
        ['op' => 'row', 'path' => '/books/ageing', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'row', 'path' => '/books/ageing', 'flags' => [], 'registry' => 'synthetic'],
    ]);

    expect($both)->toBe(['section' => 'sales-money', 'tab' => '/books/ageing'])
        ->and($booksOnly)->toBe(['section' => 'books-reports', 'tab' => '/books/ageing'])
        ->and($nobody)->toBe(['section' => 'sales-money', 'tab' => '/books/ageing'])
        ->and($noFlags)->toBe(['section' => 'sales-money', 'tab' => '/books/ageing'])
        ->and($valuationBooks['section'])->toBe('books-reports')
        ->and($valuationBoth['section'])->toBe('inv-overview')
        ->and($rowBooks)->toBe([
            'section' => 'books-reports',
            'tabs' => ['/books/ageing', '/books/trial-balance', '/inv/valuation'],
            'current' => '/books/ageing',
        ])
        ->and($rowNobody)->toBeNull();
});

it('shows Payroll no Sales tab row on the Accounts report addresses Sales also lists', function () {
    // Payroll: accounting.view, hence accounting.operations.view, and no sales permission.
    $payroll = ['canViewAccounting' => true, 'canViewOperatingReports' => true];
    $owner = [...$payroll, 'canViewSales' => true, 'canSell' => true, 'canRecordOrders' => true];

    [$payrollAgeing, $payrollStatement, $ownerAgeing] = navigation([
        ['op' => 'row', 'path' => '/accounting/receivables-ageing', 'flags' => $payroll, 'registry' => 'all'],
        ['op' => 'row', 'path' => '/accounting/customer-statement', 'flags' => $payroll, 'registry' => 'all'],
        ['op' => 'row', 'path' => '/accounting/receivables-ageing', 'flags' => $owner, 'registry' => 'all'],
    ]);

    // Nothing, or Accounts' own row once Accounts lists these addresses: never Sales'.
    expect($payrollAgeing['section'] ?? null)->not->toBe('sales-money')
        ->and($payrollStatement['section'] ?? null)->not->toBe('sales-money')
        ->and($ownerAgeing['section'])->toBe('sales-money')
        ->and($ownerAgeing['current'])->toBe('/accounting/receivables-ageing');

    expect(navigationSource('components/sales/sales-sections.ts'))
        ->toContain('const inSalesGroup = (f: Flags) => f.canViewSales || f.canSell || f.canRecordOrders;');
});

it('lights the tab a page names when its address cannot say', function () {
    $books = ['books' => true];

    [$byAddress, $declared, $otherSection, $notATab] = navigation([
        ['op' => 'row', 'path' => '/books/vouchers/doc/7', 'flags' => $books, 'registry' => 'synthetic'],
        ['op' => 'row', 'path' => '/books/vouchers/doc/7', 'flags' => [...$books, 'sectionTab' => '/books/vouchers/receipt'], 'registry' => 'synthetic'],
        // A tab of another section is ignored: the address found Vouchers.
        ['op' => 'row', 'path' => '/books/vouchers/doc/7', 'flags' => [...$books, 'sectionTab' => '/books/ageing'], 'registry' => 'synthetic'],
        ['op' => 'row', 'path' => '/books/vouchers/doc/7', 'flags' => [...$books, 'sectionTab' => '/nowhere'], 'registry' => 'synthetic'],
    ]);

    expect($byAddress['current'])->toBe('/books/vouchers/payment')
        ->and($declared['section'])->toBe('books-vouchers')
        ->and($declared['current'])->toBe('/books/vouchers/receipt')
        ->and($otherSection['current'])->toBe('/books/vouchers/payment')
        ->and($notATab['current'])->toBe('/books/vouchers/payment');
});

it('carries a section badge onto its sidebar row', function () {
    [$counted, $none] = navigation([
        ['op' => 'nav', 'flags' => ['sales' => true, 'salesBadge' => 3], 'registry' => 'synthetic'],
        ['op' => 'nav', 'flags' => ['sales' => true, 'salesBadge' => 0], 'registry' => 'synthetic'],
    ]);

    expect($counted)->toBe([['title' => 'Money', 'url' => '/sales/invoices', 'badge' => 3]])
        ->and($none)->toBe([['title' => 'Money', 'url' => '/sales/invoices', 'badge' => null]]);
});

it('draws section and tab titles in the reader language when they have a key', function () {
    [$translated, $english, $tabTranslated, $tabEnglish] = navigation([
        ['op' => 'nav', 'flags' => ['books' => true], 'registry' => 'synthetic', 'translate' => true],
        ['op' => 'nav', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'search', 'q' => 'tabs.my_leave', 'flags' => ['books' => true], 'registry' => 'synthetic', 'translate' => true],
        ['op' => 'search', 'q' => 'my leave', 'flags' => ['books' => true], 'registry' => 'synthetic'],
    ]);

    expect(array_column($translated, 'title'))->toBe(['«menu.report_cash_position»', 'Vouchers'])
        ->and(array_column($english, 'title'))->toBe(['Reports', 'Vouchers'])
        ->and($tabTranslated)->toBe([['label' => '«tabs.my_leave»', 'sub' => 'Module › Vouchers', 'url' => '/books/leave']])
        ->and($tabEnglish)->toBe([['label' => 'My leave', 'sub' => 'Module › Vouchers', 'url' => '/books/leave']]);

    // The browser passes the real translator: the tab row, the sidebar rows and the search groups.
    expect(navigationSource('components/section-tabs.tsx'))->toContain('{tabTitle(tab, t)}')
        ->and(navigationSource('components/section-tabs.tsx'))->toContain('sectionTitle(row.section, t)')
        ->and(navigationSource('components/app-sidebar.tsx'))->toContain('sectionNavItems(HRM_SECTIONS, shared, t)')
        ->and(navigationSource('components/app-sidebar.tsx'))->toContain('const tabSearchGroups = sectionSearchGroups(');
});

it('finds every tab the person may open by its title, including tabs the sidebar folds away', function () {
    $sales = ['canViewSales' => true, 'canSell' => true, 'canRecordOrders' => true, 'canViewInventory' => true];

    [$quotations, $lowStock, $notAllowed] = navigation([
        ['op' => 'search', 'q' => 'quotations', 'flags' => $sales, 'registry' => 'all'],
        ['op' => 'search', 'q' => 'low stock', 'flags' => $sales, 'registry' => 'all'],
        ['op' => 'search', 'q' => 'quotations', 'flags' => ['canViewSales' => true], 'registry' => 'all'],
    ]);

    expect($quotations)->toBe([['label' => 'Quotations', 'sub' => 'Sales › Sell', 'url' => '/sales/quotations']])
        ->and($lowStock)->toBe([['label' => 'Low stock', 'sub' => 'Inventory › Overview', 'url' => '/inventory/low-stocks']])
        ->and($notAllowed)->toBe([]);
});

it('finds a tab by its keywords, and the drawn sidebar row before its tabs', function () {
    [$keyword, $otherModule, $short, $section] = navigation([
        ['op' => 'search', 'q' => 'who owes', 'flags' => ['sales' => true], 'registry' => 'synthetic'],
        // The keyword belongs to the Sales tab, not to the Accounts one at the same address.
        ['op' => 'search', 'q' => 'who owes', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'search', 'q' => 'tb', 'flags' => ['books' => true], 'registry' => 'synthetic'],
        ['op' => 'search', 'q' => 'money', 'flags' => ['sales' => true], 'registry' => 'synthetic'],
    ]);

    expect($keyword)->toBe([['label' => 'Ageing', 'sub' => 'Module › Money', 'url' => '/books/ageing']])
        ->and($otherModule)->toBe([])
        ->and($short)->toBe([['label' => 'Trial balance', 'sub' => 'Module › Reports', 'url' => '/books/trial-balance']])
        // The section row comes first and keeps its URL; its first tab is not listed twice.
        ->and($section)->toBe([
            ['label' => 'Money', 'sub' => 'Module', 'url' => '/sales/invoices'],
            ['label' => 'Ageing', 'sub' => 'Module › Money', 'url' => '/books/ageing'],
        ]);

    // The header search reads the keywords, through the same function.
    expect(navigationSource('components/header-search.tsx'))->toContain('matchMenus(navGroups, q)')
        ->and(navigationSource('lib/menu-search.ts'))->toContain('${item.title} ${group.label} ${item.keywords ?? \'\'}');
});

it('keeps the never-drawn search groups after every drawn group, and section keys unique', function () {
    $sidebar = navigationSource('components/app-sidebar.tsx');

    // The sidebar highlight reads every group in order; on an equal prefix the
    // drawn row must win, so the tab groups come after the last drawn group.
    expect(strpos($sidebar, '...tabSearchGroups'))->toBeGreaterThan(strpos($sidebar, "label: 'Settings',"))
        ->and(strpos($sidebar, "label: 'Settings',"))->toBeGreaterThan(strpos($sidebar, "label: 'Accounts'"));

    [$keys] = navigation([['op' => 'keys', 'registry' => 'all']]);

    expect($keys)->toBe(array_values(array_unique($keys)));
});

it('lets a sidebar group drop its heading', function () {
    $groups = navigationSource('components/nav-groups.tsx');

    expect($groups)->toContain('hideLabel?: boolean;')
        ->and($groups)->toContain('group.hideLabel ? (')
        ->and($groups)->toContain('<HeadlessNavGroup');
});
