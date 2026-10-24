<?php

/**
 * Inventory in ONE sidebar group of five sections (Joy, 15 Oct 2026, after
 * HRM and Assets). The sidebar rows and the tab rows are both built from
 * inventory-sections.ts, so that list is where every screen must be, with
 * the gate its old link carried.
 *
 * ⚠ File-based, like EveryModuleIsReachableTest: the navigation is a React
 * component, and reading its source is the honest check.
 */
function inventorySectionsSource(): string
{
    return file_get_contents(resource_path('js/components/inventory/inventory-sections.ts'));
}

it('lists every inventory screen as a tab at its address', function (string $url) {
    expect(inventorySectionsSource())->toContain("url: '{$url}'");
})->with([
    'overview' => ['/inventory'],
    'low stock' => ['/inventory/low-stocks'],
    'expiry' => ['/inventory/expiry'],
    'stock value' => ['/accounting/inventory-valuation'],
    'current stock' => ['/inventory/stock'],
    'transfers' => ['/inventory/stock/transfers'],
    'requests' => ['/inventory/stock-requests'],
    'counts' => ['/inventory/stock-counts'],
    'bin placement' => ['/inventory/bin-placement'],
    'runs' => ['/inventory/production-runs'],
    'yield report' => ['/inventory/production-yield'],
    'recipes' => ['/inventory/bills-of-material'],
    'products' => ['/inventory/products'],
    'raw materials' => ['/inventory/raw-materials'],
    'product families' => ['/inventory/product-families'],
    'opening stock' => ['/inventory/opening-stock'],
    'warehouses' => ['/inventory/warehouses'],
    'bins' => ['/inventory/bins'],
    'categories' => ['/inventory/categories'],
    'brands' => ['/inventory/brands'],
    'units' => ['/inventory/units'],
    'purchase units' => ['/inventory/unit-conversions'],
    'product weights' => ['/inventory/products/weights'],
    'settings' => ['/inventory/settings'],
]);

it('keeps the gate each old sidebar link had', function () {
    $source = inventorySectionsSource();

    expect($source)
        ->toContain("url: '/inventory/stock-requests', can: approve")
        ->toContain("url: '/inventory/bin-placement', can: adjust")
        ->toContain("url: '/inventory/production-runs', can: production")
        ->toContain("url: '/inventory/bills-of-material', can: bom")
        ->toContain("url: '/inventory/opening-stock', can: adjust")
        ->toContain("url: '/inventory/unit-conversions', can: catalog")
        ->toContain("url: '/inventory/products/weights', can: catalog")
        ->toContain("url: '/inventory/settings', can: approve")
        // ⭐ 26 Sep 2026 (Q19): the Manager's stock value, listed here only when
        // Finance & Accounts (which lists it too) is not shown.
        ->toContain('f.canViewOperatingReports && !f.canViewAccounting');
});

it('is the one inventory group in the sidebar and the registry', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($sidebar)->toContain('sectionNavItems(INVENTORY_SECTIONS')
        ->and($sidebar)->not->toContain("label: 'Inventory setup'")
        ->and($sidebar)->not->toContain('?kind=raw')
        ->and(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('...INVENTORY_SECTIONS');
});

it('sends nothing to the old raw-materials address any more', function () {
    $sources = array_merge(
        glob(resource_path('js/pages/**/*.tsx')) ?: [],
        glob(resource_path('js/pages/**/**/*.tsx')) ?: [],
        glob(resource_path('js/components/**/*.tsx')) ?: [],
        glob(resource_path('js/components/*.tsx')) ?: [],
    );

    foreach ($sources as $file) {
        expect(file_get_contents($file))->not->toContain('products?kind=raw', basename($file));
    }
});
