<?php

/**
 * Purchases as ONE sidebar group of five sections (Joy, 15 Oct 2026).
 *
 * ⚠ Asserted in the SOURCE of the sections registry, the way the HRM, Assets
 * and Settings sections are: the gate of every address sits inside the same
 * `{ ... }` entry as the address, so nobody gains or loses a screen when a
 * link becomes a tab. A test that parsed TSX would be a second implementation
 * of the thing it checks.
 */
function purchasesSectionsSource(): string
{
    return file_get_contents(resource_path('js/components/purchasing/purchases-sections.ts'));
}

/** The address and the flag inside the SAME `{ ... }` entry of the registry, one line or several. */
function purchasesGateOf(string $url, string $flag): string
{
    return '/url: \''.preg_quote($url, '/').'\'[^}]*'.preg_quote($flag, '/').'/';
}

it('lists every Purchases screen as a tab with the gate its route has', function (string $url, string $flag) {
    $source = purchasesSectionsSource();

    expect($source)->toMatch(purchasesGateOf($url, $flag))
        ->and(substr_count($source, "'{$url}'"))->toBe(1);
})->with([
    // purchasing.reports.view, the read gate on every Purchasing list (routes/web.php).
    ['/purchasing/overview', 'canViewPurchasing'],
    ['/purchasing/purchase-orders', 'canViewPurchasing'],
    ['/purchasing/bills', 'canViewPurchasing'],
    ['/purchasing/payments', 'canViewPurchasing'],
    ['/purchasing/credit-notes', 'canViewPurchasing'],
    ['/purchasing/expenses', 'canViewPurchasing'],
    ['/purchasing/recurring-expenses', 'canViewPurchasing'],
    ['/purchasing/suppliers', 'canViewPurchasing'],
    // accounting.view: the two Accounts reports about suppliers (Accounting routes/web.php).
    ['/accounting/payables-ageing', 'canViewAccounting'],
    ['/accounting/supplier-statement', 'canViewAccounting'],
    // ⭐ D-F (18 Oct 2026): supplier = customer, read by whoever reads both sides.
    ['/accounting/set-offs', 'canViewParties'],
    ['/accounting/party-statement', 'canViewParties'],
    // purchasing.suppliers.manage: the gate on the withholding-rates route.
    ['/purchasing/withholding-rates', 'canManageSuppliers'],
    // ...and on the supplier-types routes (17 Oct 2026).
    ['/purchasing/supplier-types', 'canManageSuppliers'],
    // purchasing.landed_cost.view: the gate on the landed-costs routes (Phase 1, 17 Oct 2026).
    ['/purchasing/landed-costs', 'canViewLandedCosts'],
]);

it('puts Landed costs beside Purchase orders and finds it from the header search', function () {
    $source = purchasesSectionsSource();

    expect($source)->toMatch("/key: 'purchases-orders'[^\]]*url: '\/purchasing\/landed-costs'/s")
        ->and($source)->toMatch("/url: '\/purchasing\/landed-costs'[^}]*keywords: 'landed cost/");
});

it('finds Supplier types from the header search by "supplier type"', function () {
    expect(purchasesSectionsSource())->toMatch("/url: '\/purchasing\/supplier-types'[^}]*keywords: 'supplier type/");
});

it('groups the screens into the five sections of the design', function () {
    $source = purchasesSectionsSource();

    foreach (['purchases-overview', 'purchases-orders', 'purchases-bills', 'purchases-expenses', 'purchases-suppliers'] as $key) {
        expect($source)->toContain("key: '{$key}'");
    }
});

it('registers the Purchases sections with the shared registry and the sidebar', function () {
    // The layout draws the tab row from ALL_SECTIONS; a module not listed there has no tabs.
    expect(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('...PURCHASES_SECTIONS');

    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain('sectionNavItems(PURCHASES_SECTIONS')
        ->and($sidebar)->toContain("label: 'Purchases'")
        // The seven old links are gone: the module is its sections now.
        ->and($sidebar)->not->toContain("url: '/purchasing/suppliers'")
        ->and($sidebar)->not->toContain("url: '/purchasing/bills'")
        ->and($sidebar)->not->toContain("url: '/purchasing/recurring-expenses'");
});

it('keeps the two supplier reports in Accounts as well', function () {
    // Decision 15 Oct 2026: those links STAY, as tabs of Accounts > Reports and
    // Ledgers since round 3. Whoever may open the Purchases tab gets the
    // Purchases row (registered first); everybody else the Accounts row.
    $sections = file_get_contents(resource_path('js/components/accounting/accounts-sections.ts'));

    expect($sections)->toContain("url: '/accounting/payables-ageing'")
        ->and($sections)->toContain("url: '/accounting/supplier-statement'");
});

it('keeps the Tax rates page behind its own manage check, not only the tab gate', function () {
    expect(file_get_contents(resource_path('js/pages/purchasing/withholding-rates/index.tsx')))->toContain('canManageRates')
        ->and(file_get_contents(resource_path('js/pages/purchasing/supplier-types/index.tsx')))->toContain('canManageTypes');
});

it('says corrections are by reversal on the document lists (PUR-003)', function (string $page) {
    expect(file_get_contents(resource_path("js/pages/purchasing/{$page}/index.tsx")))->toContain('reversing');
})->with(['bills', 'payments', 'credit-notes', 'expenses']);

it('draws no section tabs and no duplicate module title of its own on the Purchases pages', function (string $page) {
    $source = file_get_contents(resource_path("js/pages/purchasing/{$page}.tsx"));

    expect($source)->not->toContain('SectionTabs')
        ->and($source)->not->toContain('<h1');
})->with(['overview', 'purchase-orders/index', 'bills/index', 'payments/index', 'credit-notes/index', 'suppliers/index', 'withholding-rates/index', 'supplier-types/index', 'landed-costs/index']);

it('writes no em dash into the Purchases screens', function () {
    $files = array_merge(
        glob(resource_path('js/pages/purchasing/*.tsx')),
        glob(resource_path('js/pages/purchasing/*/*.tsx')),
        [resource_path('js/components/purchasing/purchases-sections.ts')],
    );

    foreach ($files as $file) {
        expect(str_contains(file_get_contents($file), "\u{2014}"))->toBeFalse(basename($file).' holds an em dash');
    }
});
