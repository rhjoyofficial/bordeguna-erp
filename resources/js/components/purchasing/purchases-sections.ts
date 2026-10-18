import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { BarChart3, PackageCheck, ReceiptText, Settings2, ShoppingCart, Truck, Wallet } from 'lucide-react';

/**
 * ⭐ Purchases in ONE sidebar group (Joy, 15 Oct 2026), laid out as Tally's
 * Gateway since the purchase redesign PR6 (18 Oct 2026, research A13): the
 * daily entry first (Transactions), then the master (Suppliers), then Reports,
 * then Setup. Every screen keeps its address and is a tab inside its section,
 * drawn by SectionTabs above the page; the pages that had no menu entry (a
 * bill without an order, a new payment, advance or credit note, the expense
 * import) are tabs now, each behind the permission its route checks.
 *
 * The gates are the shared permission flags, verified against
 * app/Modules/Purchasing/routes/web.php and app/Modules/Accounting/routes/web.php:
 * - Lists and reads (Purchase orders, Receipts, Returns, Bills, Payments,
 *   Advances, Credit notes, Expenses, Recurring, Suppliers, Overview,
 *   Outstanding orders, Bills due): `purchasing.reports.view`
 *   (canViewPurchasing), the read gate on each route.
 * - The "New ..." pages: the record permission of their route
 *   (po.create, quick.purchase, bill.record, payment.record, advance.record,
 *   credit_note.record, expense.record).
 * - Payables ageing, Supplier statement, Supplier ledger, Sundry Creditors and
 *   GRNI not tagged: `accounting.view` (canViewAccounting). Decision 15 Oct
 *   2026: the Accounts copies STAY; on those addresses this module's row is
 *   drawn for whoever may open it (registered before Accounts), as intended.
 * - Set-offs and Party statement (18 Oct 2026, decision D-F): `canViewParties`.
 * - Tax rates (TDS/VDS) and Supplier types: `purchasing.suppliers.manage`
 *   (canManageSuppliers). Approval limits: the Finance policy page
 *   (canManageFinancePolicy); its address carries `#approvals`, so the page
 *   itself still draws the Accounts > Month end & setup row.
 */

export const PURCHASES_SECTIONS: ModuleSection[] = [
    // ── Transactions ─────────────────────────────────────────────────────
    {
        key: 'purchases-orders',
        title: 'Purchase orders',
        icon: ShoppingCart,
        tabs: [
            { title: 'Purchase orders', url: '/purchasing/purchase-orders', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ Purchase redesign PR1 (18 Oct 2026): the full order page.
            // purchasing.po.create, the gate on its route.
            {
                title: 'New purchase order',
                titleKey: 'pof.title_new',
                url: '/purchasing/purchase-orders/new',
                can: (f: SharedData) => !!f.canCreatePurchaseOrder,
                keywords: 'new purchase order create po buy order supplier discount vat freight advance',
            },
            // ⭐ Buy and receive (ruling P4, 18 Oct 2026): order, receipt, bill
            // and payment on one screen. purchasing.quick.purchase, the gate on its route.
            {
                title: 'Buy and receive',
                titleKey: 'quick.title',
                url: '/purchasing/buy-and-receive/new',
                can: (f: SharedData) => !!f.canQuickPurchase,
                keywords: 'quick purchase buy and receive cash purchase one screen order receipt bill pay',
            },
        ],
    },
    {
        key: 'purchases-receipts',
        title: 'Receipts & returns',
        titleKey: 'gw.receipts_returns',
        icon: PackageCheck,
        tabs: [
            // ⭐ Receipt Notes and returns (purchase redesign PR2, 18 Oct 2026;
            // Tally's Receipt Note and Rejection Out). purchasing.reports.view.
            {
                title: 'Receipts',
                titleKey: 'receipts.title',
                url: '/purchasing/receipts',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'goods receipt grn receipt note delivery challan received batch',
            },
            {
                title: 'Returns',
                titleKey: 'returns.title',
                url: '/purchasing/returns',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'purchase return rejection out goods back to supplier debit',
            },
            // ⭐ Landed costs (Phase 1, 17 Oct 2026): costs beside or after a
            // goods receipt. purchasing.landed_cost.view, the gate on its route.
            {
                title: 'Landed costs',
                titleKey: 'landed.title',
                url: '/purchasing/landed-costs',
                can: (f: SharedData) => f.canViewLandedCosts,
                keywords: 'landed cost packaging freight carrying labour insurance duty bank charge receipt',
            },
        ],
    },
    {
        key: 'purchases-bills',
        title: 'Bills & payments',
        icon: ReceiptText,
        tabs: [
            { title: 'Bills', url: '/purchasing/bills', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ PR6: the bill for a supplier without an order (ruling P5).
            // purchasing.bill.record, the gate on its route.
            {
                title: 'New bill',
                titleKey: 'gw.new_bill',
                url: '/purchasing/bills/new',
                can: (f: SharedData) => !!f.canRecordSupplierBill,
                keywords: 'new bill without order non po bill service carriage supplier invoice purchase voucher',
            },
            { title: 'Payments', url: '/purchasing/payments', can: (f: SharedData) => f.canViewPurchasing },
            {
                title: 'New payment',
                titleKey: 'gw.new_payment',
                url: '/purchasing/payments/new',
                can: (f: SharedData) => !!f.canRecordSupplierPayment,
                keywords: 'pay supplier new payment bills tds vds',
            },
            // ⭐ Supplier advances (ruling P2, 18 Oct 2026): paid before the bill.
            {
                title: 'Advances',
                url: '/purchasing/advances',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'supplier advance prepayment paid before bill refund on account 1160',
            },
            {
                title: 'New advance',
                titleKey: 'gw.new_advance',
                url: '/purchasing/advances/new',
                can: (f: SharedData) => !!f.canRecordSupplierAdvance,
                keywords: 'new advance pay before bill prepayment supplier',
            },
            { title: 'Credit notes', url: '/purchasing/credit-notes', can: (f: SharedData) => f.canViewPurchasing },
            {
                title: 'New credit note',
                titleKey: 'gw.new_credit_note',
                url: '/purchasing/credit-notes/new',
                can: (f: SharedData) => !!f.canRecordCreditNote,
                keywords: 'new credit note debit note claim supplier late return',
            },
            // ⭐ Purchasing approvals (ruling P7, 18 Oct 2026): bills and payments
            // waiting for a second person. Read gate as the route.
            {
                title: 'Waiting approval',
                titleKey: 'purchase_approval.title',
                url: '/purchasing/approvals',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'approval second person pending bill payment advance refund threshold exception',
            },
        ],
    },
    {
        key: 'purchases-expenses',
        title: 'Expenses',
        icon: Wallet,
        tabs: [
            { title: 'Expenses', url: '/purchasing/expenses', can: (f: SharedData) => f.canViewPurchasing },
            // ⭐ PR6: the importer, behind purchasing.expense.record like its route.
            {
                title: 'Import expenses',
                titleKey: 'gw.import_expenses',
                url: '/purchasing/expenses/import',
                can: (f: SharedData) => !!f.canRecordExpense,
                keywords: 'import expenses excel csv file upload many',
            },
            { title: 'Recurring', url: '/purchasing/recurring-expenses', can: (f: SharedData) => f.canViewPurchasing },
        ],
    },
    // ── Masters ──────────────────────────────────────────────────────────
    {
        key: 'purchases-suppliers',
        title: 'Suppliers',
        icon: Truck,
        tabs: [{ title: 'Suppliers', url: '/purchasing/suppliers', can: (f: SharedData) => f.canViewPurchasing }],
    },
    // ── Reports ──────────────────────────────────────────────────────────
    {
        key: 'purchases-reports',
        title: 'Reports',
        titleKey: 'gw.reports',
        icon: BarChart3,
        tabs: [
            { title: 'Overview', url: '/purchasing/overview', can: (f: SharedData) => f.canViewPurchasing },
            {
                title: 'Outstanding orders',
                titleKey: 'outstanding.title',
                url: '/purchasing/outstanding-orders',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'outstanding pending orders still to come overdue expected due not received',
            },
            // ⭐ Ruling P6 (18 Oct 2026): bills overdue and falling due soon.
            {
                title: 'Bills due',
                titleKey: 'due.title',
                url: '/purchasing/bills-due',
                can: (f: SharedData) => f.canViewPurchasing,
                keywords: 'due overdue payable soon payment terms supplier bills',
            },
            { title: 'Payables ageing', url: '/accounting/payables-ageing', can: (f: SharedData) => f.canViewAccounting },
            { title: 'Supplier statement', url: '/accounting/supplier-statement', can: (f: SharedData) => f.canViewAccounting },
            {
                title: 'Supplier ledger',
                url: '/accounting/supplier-ledger',
                can: (f: SharedData) => f.canViewAccounting,
                keywords: 'supplier ledger payable advances grni exposure',
            },
            {
                title: 'Sundry Creditors',
                url: '/accounting/sundry-creditors',
                can: (f: SharedData) => f.canViewAccounting,
                keywords: 'sundry creditors suppliers payable group tally',
            },
            {
                title: 'GRNI not tagged',
                url: '/accounting/grni-untagged',
                can: (f: SharedData) => f.canViewAccounting,
                keywords: 'goods received not invoiced untagged',
            },
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
    // ── Setup ────────────────────────────────────────────────────────────
    {
        key: 'purchases-setup',
        title: 'Setup',
        titleKey: 'gw.setup',
        icon: Settings2,
        tabs: [
            { title: 'Tax rates (TDS/VDS)', url: '/purchasing/withholding-rates', can: (f: SharedData) => f.canManageSuppliers },
            {
                title: 'Supplier types',
                url: '/purchasing/supplier-types',
                can: (f: SharedData) => f.canManageSuppliers,
                keywords: 'supplier type kinds categories tds vds',
            },
            {
                title: 'Approval limits',
                titleKey: 'gw.approval_limits',
                url: '/accounting/finance-policy#approvals',
                can: (f: SharedData) => f.canManageFinancePolicy,
                keywords: 'approval limit 200000 second person purchasing approvals owner manager',
            },
        ],
    },
];
