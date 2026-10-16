import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { ClipboardList, Contact, Globe, LayoutDashboard, Settings2, Store, Truck, Wallet } from 'lucide-react';

/**
 * ⭐ Sales in ONE sidebar group of seven sections, and Websites in one link
 * (Joy, 15 Oct 2026, after HRM, Assets and Settings). It was 30 links in
 * four groups (Sales, Sales reports, Sales setup, Websites). Every screen
 * keeps its address and becomes a TAB of its section, drawn by SectionTabs
 * above the page; a tab shows only to somebody who may open it.
 *
 * The gates are the same shared permission flags the old links used, one for
 * one (verified against app/Modules/Sales/routes/web.php and
 * app/Modules/Commerce/routes/web.php):
 *
 *   canViewSales          sales.reports.view        Challans, Returns, Parcels (sales.parcels.see),
 *                                                   Invoices, Receipts, Courier payouts, Customers,
 *                                                   Credit terms, Selling prices
 *   canSell               sales.sell                Counter sale, Cashier shifts
 *   canRecordOrders       sales.orders.record       Quotations, Import; Orders with canViewSales (sales.orders.see)
 *   canBookParcels        sales.sell + couriers.parcels.book   To book
 *   canProposeRateCards   couriers.ratecards.propose          Courier fees
 *   canViewSalesReports   sales.reports.view        Sales by channel, Courier settlement ageing
 *   canViewCostReports    sales.reports.costs       Margin, Delivery economics
 *   canViewOperatingReports accounting.operations.view  Receivables ageing, Customer statement
 *                                                   (and the Sales group gate, see inSalesGroup)
 *   canManagePrices       sales.prices.manage       Price groups, Discounts, Promotions, Targets
 *   canViewStores         commerce.stores.view      Stores, Pages, Media
 *
 * ⚠ The whole Sales group is shown, as before, only to somebody holding
 * canViewSales, canSell or canRecordOrders (app-sidebar); a tab's own gate
 * decides whether the tab is drawn.
 *
 * ⚠ `/hrm/reports` itself stays HRM's Reports tab. Only the four report
 * addresses are listed here; the registry matches by the LONGEST prefix, so
 * /hrm/reports/sales_margin lights Sales › Overview and /hrm/reports lights HRM.
 *
 * ⚠ Receivables ageing and Customer statement keep their /accounting
 * addresses; Accounts lists them too. On those addresses this tab row is
 * drawn for whoever may open the tab here, Accounts' row for everyone else
 * (lib/sections: Sales comes before Accounts). Decided, not an accident.
 *
 * ⚠ The old "Couriers" shortcut of Sales setup is gone: Settings › Couriers is
 * that area, and the Parcels pages link to it from their header.
 */

type Flags = SharedData;

const sales = (f: Flags) => f.canViewSales;

/**
 * The Sales group's own gate (app-sidebar). ⚠ Folded into the two Accounts
 * report tabs below (round 3, 16 Oct 2026): accounting.operations.view
 * follows accounting.view, so Payroll holds it with no sales permission, and
 * on those shared addresses would have met a Sales tab row with no Sales group
 * in its sidebar. Without the group they read Accounts' row instead.
 */
const inSalesGroup = (f: Flags) => f.canViewSales || f.canSell || f.canRecordOrders;

/**
 * ⚠ A report tab also asks the report registry (round 3 role walk, 17 Oct
 * 2026): the permission alone showed a Department Manager four tabs that
 * answered 404. `runnableReports` is ReportRegistry::canRun for this user.
 */
const runs = (f: SharedData, key: string) => (f.runnableReports ?? []).includes(key);

export const SALES_SECTIONS: ModuleSection[] = [
    {
        key: 'sales-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [
            { title: 'Overview', url: '/sales/overview', can: (f) => f.canViewSales || f.canSell || f.canRecordOrders },
            { title: 'Sales by channel', url: '/hrm/reports/sales_by_channel', can: (f) => f.canViewSalesReports && runs(f, 'sales_by_channel') },
            // Cost is shown on these two: sales.reports.costs, not Staff.
            { title: 'Margin', url: '/hrm/reports/sales_margin', can: (f) => f.canViewCostReports && runs(f, 'sales_margin') },
            {
                title: 'Delivery economics',
                url: '/hrm/reports/delivery_economics',
                can: (f) => f.canViewCostReports && runs(f, 'delivery_economics'),
            },
            {
                title: 'Courier settlement ageing',
                url: '/hrm/reports/courier_settlement_ageing',
                can: (f) => f.canViewSalesReports && runs(f, 'courier_settlement_ageing'),
            },
        ],
    },
    {
        key: 'sales-sell',
        title: 'Sell',
        icon: Store,
        tabs: [
            { title: 'Counter sale', url: '/sales/counter', can: (f) => f.canSell },
            { title: 'Cashier shifts', url: '/sales/shifts', can: (f) => f.canSell },
            { title: 'Quotations', url: '/sales/quotations', can: (f) => f.canRecordOrders },
        ],
    },
    {
        key: 'sales-orders',
        title: 'Orders',
        icon: ClipboardList,
        tabs: [
            { title: 'Orders', url: '/sales/orders', can: (f) => f.canViewSales || f.canRecordOrders },
            { title: 'Import', url: '/sales/orders/import', can: (f) => f.canRecordOrders },
            { title: 'Challans', url: '/sales/deliveries', can: sales },
            { title: 'Returns', url: '/sales/returns', can: sales },
        ],
    },
    {
        key: 'sales-parcels',
        title: 'Parcels',
        icon: Truck,
        tabs: [
            { title: 'To book', url: '/sales/parcels-to-book', can: (f) => f.canBookParcels },
            { title: 'Parcels', url: '/sales/consignments', can: (f) => f.canSeeParcels },
            { title: 'Courier fees', url: '/sales/courier-fees', can: (f) => f.canProposeRateCards },
        ],
    },
    {
        key: 'sales-money',
        title: 'Money',
        icon: Wallet,
        tabs: [
            { title: 'Invoices', url: '/sales/invoices', can: sales },
            { title: 'Receipts', url: '/sales/receipts', can: sales },
            { title: 'Courier payouts', url: '/sales/courier-statements', can: sales },
            { title: 'Receivables ageing', url: '/accounting/receivables-ageing', can: (f) => f.canViewOperatingReports && inSalesGroup(f) },
            { title: 'Customer statement', url: '/accounting/customer-statement', can: (f) => f.canViewOperatingReports && inSalesGroup(f) },
        ],
    },
    {
        key: 'sales-customers',
        title: 'Customers',
        icon: Contact,
        tabs: [
            // A customer's CRM history (/crm/customer/{id}/timeline) reads under Customers.
            { title: 'Customers', url: '/sales/customers', also: ['/crm/customer'], can: sales },
            { title: 'Credit terms', url: '/sales/credit-terms', can: sales },
        ],
    },
    {
        key: 'sales-setup',
        title: 'Sales setup',
        icon: Settings2,
        tabs: [
            { title: 'Selling prices', url: '/sales/prices', can: (f) => f.canViewSales || f.canManagePrices },
            { title: 'Price groups', url: '/sales/price-groups', can: (f) => f.canManagePrices },
            { title: 'Discounts', url: '/sales/discounts', can: (f) => f.canManagePrices },
            { title: 'Promotions', url: '/sales/promotions', can: (f) => f.canManagePrices },
            { title: 'Targets', url: '/sales/targets', can: (f) => f.canManagePrices },
        ],
    },
];

/**
 * Websites stay their own group on purpose: the gate is commerce.stores.view,
 * held by content staff who may not sell, and nothing here is a sale (shop
 * orders already land in Sales › Orders). Three links become one link of
 * three tabs.
 */
export const SHOPS_SECTIONS: ModuleSection[] = [
    {
        key: 'shops',
        title: 'Online shops',
        icon: Globe,
        tabs: [
            { title: 'Stores', url: '/commerce/stores', can: (f) => f.canViewStores },
            // ⚠ Pages get their own tab rather than living only inside a store,
            // because the question an author has is "where is that article",
            // not "which store was it on".
            { title: 'Pages', url: '/commerce/pages', can: (f) => f.canViewStores },
            { title: 'Media', url: '/commerce/media', can: (f) => f.canViewStores },
        ],
    },
];
