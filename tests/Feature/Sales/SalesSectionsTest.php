<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Sales in one sidebar group and Websites in one link (15 Oct 2026): every
 * screen that used to be a sidebar link is a tab in sales-sections.ts with the
 * gate its old link had, the sidebar reads that list, and /hrm/reports itself
 * stays HRM's tab. Source checks, like the HRM and Settings ones: a test that
 * parsed TSX would be a second implementation of the registry.
 */
function salesSectionsSource(): string
{
    return file_get_contents(resource_path('js/components/sales/sales-sections.ts'));
}

/** Every tab, with the gate expression its old sidebar link carried. */
dataset('sales tabs', [
    'Overview' => ["url: '/sales/overview'", 'f.canViewSales || f.canSell || f.canRecordOrders'],
    // And the report registry (round 3 role walk): see SalesReportTabsTest.
    'Sales by channel' => ["url: '/hrm/reports/sales_by_channel'", "f.canViewSalesReports && runs(f, 'sales_by_channel')"],
    'Margin' => ["url: '/hrm/reports/sales_margin'", "f.canViewCostReports && runs(f, 'sales_margin')"],
    'Delivery economics' => ["url: '/hrm/reports/delivery_economics'", "f.canViewCostReports && runs(f, 'delivery_economics')"],
    'Courier settlement ageing' => ["url: '/hrm/reports/courier_settlement_ageing'", "f.canViewSalesReports && runs(f, 'courier_settlement_ageing')"],
    'Counter sale' => ["url: '/sales/counter'", 'f.canSell'],
    'Cashier shifts' => ["url: '/sales/shifts'", 'f.canSell'],
    'Quotations' => ["url: '/sales/quotations'", 'f.canRecordOrders'],
    'Orders' => ["url: '/sales/orders'", 'f.canViewSales || f.canRecordOrders'],
    'Import' => ["url: '/sales/orders/import'", 'f.canRecordOrders'],
    'Challans' => ["url: '/sales/deliveries'", 'can: sales'],
    'Returns' => ["url: '/sales/returns'", 'can: sales'],
    'To book' => ["url: '/sales/parcels-to-book'", 'f.canBookParcels'],
    // ⚠ Bug hunt 16 Oct 2026: the parcels list's own gate (sales.parcels.see).
    'Parcels' => ["url: '/sales/consignments'", 'f.canSeeParcels'],
    'Courier fees' => ["url: '/sales/courier-fees'", 'f.canProposeRateCards'],
    'Invoices' => ["url: '/sales/invoices'", 'can: sales'],
    'Receipts' => ["url: '/sales/receipts'", 'can: sales'],
    'Courier payouts' => ["url: '/sales/courier-statements'", 'can: sales'],
    // And the Sales group gate (round 3): Payroll reads these under Accounts.
    'Receivables ageing' => ["url: '/accounting/receivables-ageing'", 'f.canViewOperatingReports && inSalesGroup(f)'],
    'Customer statement' => ["url: '/accounting/customer-statement'", 'f.canViewOperatingReports && inSalesGroup(f)'],
    'Customers' => ["url: '/sales/customers'", "also: ['/crm/customer'], can: sales"],
    'Credit terms' => ["url: '/sales/credit-terms'", 'can: sales'],
    'Selling prices' => ["url: '/sales/prices'", 'f.canViewSales || f.canManagePrices'],
    'Price groups' => ["url: '/sales/price-groups'", 'f.canManagePrices'],
    'Discounts' => ["url: '/sales/discounts'", 'f.canManagePrices'],
    'Promotions' => ["url: '/sales/promotions'", 'f.canManagePrices'],
    'Targets' => ["url: '/sales/targets'", 'f.canManagePrices'],
    'Stores' => ["url: '/commerce/stores'", 'f.canViewStores'],
    'Pages' => ["url: '/commerce/pages'", 'f.canViewStores'],
    'Media' => ["url: '/commerce/media'", 'f.canViewStores'],
]);

it('lists every Sales and Websites screen as a tab with the gate its old link had', function (string $url, string $gate) {
    $lines = explode("\n", salesSectionsSource());
    $at = collect($lines)->search(fn (string $line) => str_contains($line, $url));
    // A tab Prettier wraps puts its gate on the line after the url.
    $line = $at === false ? null : implode(' ', array_slice($lines, $at, 2));

    expect($line)->not->toBeNull()
        ->and($line)->toContain($gate);
})->with('sales tabs');

it('leaves /hrm/reports itself to HRM and lists only the four report addresses', function () {
    $source = salesSectionsSource();

    expect($source)->not->toContain("url: '/hrm/reports'")
        ->and(substr_count($source, "url: '/hrm/reports/"))->toBe(4)
        // The Couriers settings area is not repeated as a Sales tab.
        ->and($source)->not->toContain("url: '/settings/couriers")
        ->and(file_get_contents(resource_path('js/components/hrm/hrm-sections.ts')))->toContain("url: '/hrm/reports'");
});

it('registers the Sales and Websites sections with the shared registry and the sidebar reads them', function () {
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    // The registry list is one entry per line once it grew past Prettier's width,
    // so each spread is asserted on its own.
    expect(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('...SALES_SECTIONS')
        ->and(file_get_contents(resource_path('js/lib/sections.ts')))->toContain('...SHOPS_SECTIONS')
        ->and($sidebar)->toContain('sectionNavItems(SALES_SECTIONS')
        ->and($sidebar)->toContain('sectionNavItems(SHOPS_SECTIONS')
        // The group opens, as before, for whoever may read sales, sell or take an order.
        ->and($sidebar)->toContain('canViewSales || canSell || canRecordOrders || shared.canSeeParcels || shared.canBookParcels')
        // The four old groups are gone.
        ->and($sidebar)->not->toContain("label: t('menu.sales_reports')")
        ->and($sidebar)->not->toContain("label: 'Sales setup'")
        ->and($sidebar)->not->toContain("url: '/sales/counter'")
        ->and($sidebar)->not->toContain("url: '/commerce/stores'");
});

it('draws no page title of its own on the redesigned Sales and Websites screens', function () {
    // The tab row names the screen; a page that said its name again said it twice.
    foreach ([
        'sales/overview.tsx', 'sales/orders/index.tsx', 'sales/orders/import.tsx', 'sales/quotations/index.tsx', 'sales/shifts.tsx',
        'sales/targets.tsx', 'sales/promotions.tsx', 'sales/prices/index.tsx', 'sales/receipts/index.tsx', 'sales/parcels-to-book/index.tsx',
        'sales/discounts.tsx', 'sales/customers/index.tsx', 'sales/invoices/index.tsx', 'sales/deliveries/index.tsx',
        'sales/consignments/index.tsx', 'sales/courier-fees/index.tsx', 'sales/courier-statements/index.tsx', 'sales/credit-terms/index.tsx',
        'sales/price-groups/index.tsx', 'accounting/ageing/index.tsx',
    ] as $page) {
        $source = file_get_contents(resource_path('js/pages/'.$page));

        expect($source)->not->toContain('<h1', $page)
            ->and($source)->not->toContain('<ReportHeading', $page)
            ->and($source)->not->toContain('SectionTabs', $page);
    }
});

// ⚠ Bug hunt 16 Oct 2026 (03 L4): a role with couriers.parcels.view alone may
// open /sales/consignments (sales.parcels.see) but never saw it in the menu.
it('shares canSeeParcels from the same gate as the parcels route', function () {
    $business = Business::create(['name' => 'Desk Co', 'slug' => 'desk-co']);
    app(BusinessContext::class)->set($business->id);
    setPermissionsTeamId($business->id);
    Permission::findOrCreate('couriers.parcels.view', 'web');
    Role::create(['name' => 'Courier desk', 'guard_name' => 'web', 'business_id' => $business->id])
        ->givePermissionTo('couriers.parcels.view');
    $desk = User::factory()->create();
    $desk->businesses()->attach($business->id);
    $desk->assignRole('Courier desk');

    $props = $this->actingAs($desk)->withSession(['current_business_id' => $business->id])
        ->get('/sales/consignments')->assertOk()->original->getData()['page']['props'];

    expect($props['canSeeParcels'])->toBeTrue();
});
