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
        tabs: [{ title: 'Purchase orders', url: '/purchasing/purchase-orders', can: (f: SharedData) => f.canViewPurchasing }],
    },
    {
        key: 'purchases-bills',
        title: 'Bills & payments',
        icon: ReceiptText,
        tabs: [
            { title: 'Bills', url: '/purchasing/bills', can: (f: SharedData) => f.canViewPurchasing },
            { title: 'Payments', url: '/purchasing/payments', can: (f: SharedData) => f.canViewPurchasing },
            { title: 'Credit notes', url: '/purchasing/credit-notes', can: (f: SharedData) => f.canViewPurchasing },
            { title: 'Payables ageing', url: '/accounting/payables-ageing', can: (f: SharedData) => f.canViewAccounting },
            { title: 'Supplier statement', url: '/accounting/supplier-statement', can: (f: SharedData) => f.canViewAccounting },
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
