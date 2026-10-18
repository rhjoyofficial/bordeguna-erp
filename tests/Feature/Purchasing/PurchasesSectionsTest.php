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
    // Bills due by due date (ruling P6, 18 Oct 2026).
    ['/purchasing/bills-due', 'canViewPurchasing'],
    ['/purchasing/payments', 'canViewPurchasing'],
    // Supplier advances (ruling P2, 18 Oct 2026).
    ['/purchasing/advances', 'canViewPurchasing'],
    ['/purchasing/credit-notes', 'canViewPurchasing'],
    // Purchasing approvals (ruling P7, 18 Oct 2026).
    ['/purchasing/approvals', 'canViewPurchasing'],
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
    // purchasing.quick.purchase: the gate on the Buy and receive routes (ruling P4, 18 Oct 2026).
    ['/purchasing/buy-and-receive/new', 'canQuickPurchase'],
    // Receipt Notes, returns and outstanding orders (PR2, 18 Oct 2026): purchasing.reports.view.
    ['/purchasing/receipts', 'canViewPurchasing'],
    ['/purchasing/returns', 'canViewPurchasing'],
    ['/purchasing/outstanding-orders', 'canViewPurchasing'],
    // ⭐ PR6 (18 Oct 2026): the pages that had no menu entry, each behind its route's record permission.
    ['/purchasing/purchase-orders/new', 'canCreatePurchaseOrder'],
    ['/purchasing/bills/new', 'canRecordSupplierBill'],
    ['/purchasing/payments/new', 'canRecordSupplierPayment'],
    ['/purchasing/advances/new', 'canRecordSupplierAdvance'],
    ['/purchasing/credit-notes/new', 'canRecordCreditNote'],
    ['/purchasing/expenses/import', 'canRecordExpense'],
    // ...and the supplier reports of Accounts, on accounting.view like their routes.
    ['/accounting/supplier-ledger', 'canViewAccounting'],
    ['/accounting/sundry-creditors', 'canViewAccounting'],
    ['/accounting/grni-untagged', 'canViewAccounting'],
    // The approval limits live on the Finance policy page (finance_policy.manage).
    ['/accounting/finance-policy#approvals', 'canManageFinancePolicy'],
]);

it('puts Landed costs beside the receipts and finds it from the header search', function () {
    $source = purchasesSectionsSource();

    expect($source)->toMatch("/key: 'purchases-receipts'[^\]]*url: '\/purchasing\/landed-costs'/s")
        ->and($source)->toMatch("/url: '\/purchasing\/landed-costs'[^}]*keywords: 'landed cost/");
});

it('finds Supplier types from the header search by "supplier type"', function () {
    expect(purchasesSectionsSource())->toMatch("/url: '\/purchasing\/supplier-types'[^}]*keywords: 'supplier type/");
});

it('lays the sections out as Tally\'s Gateway: transactions, then the supplier master, then reports, then setup', function () {
    // PR6 (18 Oct 2026, research A13). The order in the file is the order in the sidebar.
    preg_match_all("/^        key: '([a-z-]+)'/m", purchasesSectionsSource(), $keys);

    expect($keys[1])->toBe([
        'purchases-orders', 'purchases-receipts', 'purchases-bills', 'purchases-expenses',
        'purchases-suppliers',
        'purchases-reports',
        'purchases-setup',
    ]);
});

it('gates the header Create entries by the create permission of each page', function () {
    $menu = file_get_contents(resource_path('js/components/header-quick-create.tsx'));

    foreach ([
        'canCreatePurchaseOrder ? [{ title: t(\'gw.c_po\'), url: \'/purchasing/purchase-orders/new\'',
        'shared.canQuickPurchase ? [{ title: t(\'gw.c_buy\'), url: \'/purchasing/buy-and-receive/new\'',
        'shared.canPrepareVouchers',
        "url: '/accounting/vouchers/payment/create'",
        "url: '/accounting/vouchers/receipt/create'",
        "url: '/accounting/vouchers/journal/create'",
        'shared.inventoryAbilities?.manageProduction',
        "url: '/inventory/production-runs/new'",
        'canSell ? [{ title: t(\'gw.c_sale\'), url: \'/sales/counter\'',
    ] as $needle) {
        expect($menu)->toContain($needle);
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
})->with(['overview', 'purchase-orders/index', 'bills/index', 'payments/index', 'credit-notes/index', 'suppliers/index', 'withholding-rates/index', 'supplier-types/index', 'landed-costs/index', 'receipts/index', 'receipts/show', 'returns/index', 'returns/show', 'outstanding-orders/index']);

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
