import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { LayoutDashboard, ReceiptText, ShoppingCart, Truck, Wallet } from 'lucide-react';

/**
 * ⭐ Purchases in ONE sidebar group of five sections (Joy, 15 Oct 2026, after
 * HRM, Assets and Settings). It was seven links; every screen keeps its
 * address and becomes a tab inside its section, drawn by SectionTabs above
 * the page.
 *
 * The gates are the shared permission flags the links used, verified against
 * app/Modules/Purchasing/routes/web.php and app/Modules/Accounting/routes/web.php:
 * - Overview (new), Purchase orders, Bills, Payments, Credit notes, Expenses,
 *   Recurring, Suppliers: `purchasing.reports.view` (canViewPurchasing), the
 *   read gate on each route.
 * - Payables ageing and Supplier statement: `accounting.view`
 *   (canViewAccounting). Decision 15 Oct 2026: the Accounts > Financial
 *   reports links to those two pages STAY; the pages show this module's tab
 *   row by longest prefix, which is intended.
 * - Set-offs and Party statement (18 Oct 2026, decision D-F): `canViewParties`,
 *   whoever reads both suppliers and customers, the gate on their routes.
 * - Tax rates (TDS/VDS) and Supplier types (17 Oct 2026):
 *   `purchasing.suppliers.manage` (canManageSuppliers), the gate on their
 *   routes; each page keeps its own check as well.
 */

export const PURCHASES_SECTIONS: ModuleSection[] = [
    {
        key: 'purchases-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [{ title: 'Overview', url: '/purchasing/overview', can: (f: SharedData) => f.canViewPurchasing }],
    },
    {
        key: 'purchases-orders',
        title: 'Purchase orders',
        icon: ShoppingCart,
        tabs: [
            { title: 'Purchase orders', url: '/purchasing/purchase-orders', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ Buy and receive (ruling P4, 18 Oct 2026): order, receipt, bill
            // and payment on one screen. purchasing.quick.purchase, the gate on its route.
            {
                title: 'Buy and receive',
                titleKey: 'quick.title',
                url: '/purchasing/buy-and-receive/new',
                can: (f: SharedData) => !!f.canQuickPurchase,
                keywords: 'quick purchase buy and receive cash purchase one screen order receipt bill pay',
            },
            // ⭐ Landed costs (Phase 1, 17 Oct 2026): costs beside or after a
            // goods receipt. purchasing.landed_cost.view, the gate on its route.
            {
                title: 'Landed costs',
                titleKey: 'landed.title',
                url: '/purchasing/landed-costs',
                can: (f: SharedData) => f.canViewLandedCosts,
                keywords: 'landed cost packaging freight carrying labour bank charge receipt',
            },
        ],
    },
    {
        key: 'purchases-bills',
        title: 'Bills & payments',
        icon: ReceiptText,
        tabs: [
            { title: 'Bills', url: '/purchasing/bills', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ Ruling P6 (18 Oct 2026): bills overdue and falling due soon, by
            // due date. purchasing.reports.view, the gate on its route.
            {
                title: 'Bills due',
                titleKey: 'due.title',
                url: '/purchasing/bills-due',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'due overdue payable soon payment terms supplier bills',
            },
            { title: 'Payments', url: '/purchasing/payments', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ Supplier advances (ruling P2, 18 Oct 2026): paid before the bill.
            {
                title: 'Advances',
                url: '/purchasing/advances',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'supplier advance prepayment paid before bill refund on account 1160',
            },
            { title: 'Credit notes', url: '/purchasing/credit-notes', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ Purchasing approvals (ruling P7, 18 Oct 2026): bills and payments
            // waiting for a second person. Read gate as the route.
            {
                title: 'Waiting approval',
                titleKey: 'purchase_approval.title',
                url: '/purchasing/approvals',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'approval second person pending bill payment advance refund threshold exception',
            },
            { title: 'Payables ageing', url: '/accounting/payables-ageing', can: (f: SharedData) => f.canViewAccounting },
            { title: 'Supplier statement', url: '/accounting/supplier-statement', can: (f: SharedData) => f.canViewAccounting },
            // ⭐ D-F (18 Oct 2026): a supplier that is also our customer. Read by
            // whoever reads both sides (canViewParties = purchasing.reports.view
            // AND sales.reports.view), the gate on both routes.
            {
                title: 'Set-offs',
                titleKey: 'set_off.title',
                url: '/accounting/set-offs',
                can: (f: SharedData) => !!f.canViewParties,
                keywords: 'set off netting contra supplier customer same party',
            },
            {
                title: 'Party statement',
                titleKey: 'party_statement.title',
                url: '/accounting/party-statement',
                can: (f: SharedData) => !!f.canViewParties,
                keywords: 'combined statement supplier customer net both sides',
            },
        ],
    },
    {
        key: 'purchases-expenses',
        title: 'Expenses',
        icon: Wallet,
        tabs: [
            // The importer lives under the Expenses address, so it lights this tab.
            { title: 'Expenses', url: '/purchasing/expenses', can: (f: SharedData) => f.canViewPurchasing },
            { title: 'Recurring', url: '/purchasing/recurring-expenses', can: (f: SharedData) => f.canViewPurchasing },
        ],
    },
    {
        key: 'purchases-suppliers',
        title: 'Suppliers',
        icon: Truck,
        tabs: [
            { title: 'Suppliers', url: '/purchasing/suppliers', can: (f: SharedData) => f.canViewPurchasing },
            { title: 'Tax rates (TDS/VDS)', url: '/purchasing/withholding-rates', can: (f: SharedData) => f.canManageSuppliers },
            {
                title: 'Supplier types',
                url: '/purchasing/supplier-types',
                can: (f: SharedData) => f.canManageSuppliers,
                keywords: 'supplier type kinds categories tds vds',
            },
        ],
    },
];
