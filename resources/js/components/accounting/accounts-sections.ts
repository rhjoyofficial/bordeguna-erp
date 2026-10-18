import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { FileBarChart, Landmark, LayoutDashboard, ListTree, LockKeyhole, NotebookPen, PiggyBank } from 'lucide-react';

/**
 * ⭐ Accounts in ONE sidebar group of seven sections (redesign round 3, 16 Oct
 * 2026). It was 41 links in three groups (Accounts, Financial reports,
 * Accounts setup) plus a separate two-link "Accounts" group for a Manager
 * without the ledger. Every screen keeps its address and becomes a tab inside
 * its section, drawn by SectionTabs above the page; the three loan screens
 * that were buttons inside Loans became tabs too (44 tabs). Nothing
 * disappears and nobody gains a screen.
 *
 * The gates are the shared permission flags the old links used, verified
 * against app/Modules/Accounting/routes/web.php (round3-VERIFY, Accounts):
 * - `canViewAccounting` (accounting.view): Owner, Finance and Payroll. The
 *   read gate of almost every screen; the narrower actions on a page keep
 *   their own checks (post, approve, file, close).
 * - `canPostJournal` (accounting.journal.post, the Owner): Adjustments,
 *   Between businesses, Transfers and Opening balances (ACC-005: Finance and
 *   Payroll met a 403 from the old links).
 * - `canManageVat` and `canManageFinancePolicy`: VAT settings and Finance
 *   policy, exactly as the route gates.
 * - `canViewLoans` and `canViewCounterparties`: the Manager holds these
 *   without accounting.view, so his Accounts group is the one Loans link.
 *
 * ⚠ Shared report addresses (decision D1 of 16 Oct, corrected by the
 * verifier): Receivables ageing and Customer statement belong to Sales too,
 * Payables ageing and Supplier statement to Purchases, Inventory valuation to
 * Inventory. Those modules are registered before Accounts, so whoever may
 * open their tab gets their row; everybody else gets the Accounts row. The
 * Accounts copies stay on accounting.view (D4) so the Manager's sidebar does
 * not grow a Reports link.
 *
 * ⚠ Each tab's url and gate stay on ONE line: AccountsSectionsTest reads
 * them that way. `keywords` keep the OLD link names findable in the header
 * search ("payment vouchers", "transfer history", "counterparties").
 */
const view = (f: SharedData) => f.canViewAccounting;
const post = (f: SharedData) => f.canPostJournal;
const loans = (f: SharedData) => f.canViewLoans;
const parties = (f: SharedData) => f.canViewCounterparties;
const policy = (f: SharedData) => f.canManageFinancePolicy;
// Edit, delete and correct, Phase 0 (17 Oct 2026): the monthly Corrections report (Owner, Finance).
const corrections = (f: SharedData) => !!f.canViewCorrections;
// Owner Personal Withdrawal (17 Oct 2026; renamed and restricted 18 Oct 2026, D-C): Owner, Finance, or a role explicitly given the permission.
const ownerWithdrawals = (f: SharedData) => !!f.canViewOwnerWithdrawals;

export const ACCOUNTS_SECTIONS: ModuleSection[] = [
    {
        key: 'accounts-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [
            { title: 'Overview', url: '/accounting/overview', can: view, keywords: 'finance overview finance dashboard accounts' },
            { title: 'Cash forecast', url: '/accounting/cash-forecast', can: view, keywords: 'financial reports' },
        ],
    },
    {
        key: 'accounts-vouchers',
        title: 'Vouchers',
        icon: NotebookPen,
        tabs: [
            // A voucher's own page (/accounting/vouchers/doc/{id}, its edit and
            // print) names its type tab with the `sectionTab` prop; this prefix
            // only finds the section for it.
            { title: 'Payment', url: '/accounting/vouchers/payment', can: view, also: ['/accounting/vouchers/doc'], keywords: 'payment vouchers' },
            { title: 'Receipt', url: '/accounting/vouchers/receipt', can: view, keywords: 'receive vouchers receipt vouchers money coming in' },
            { title: 'Contra', url: '/accounting/vouchers/contra', can: view, keywords: 'contra vouchers move money between own accounts' },
            { title: 'Journal', url: '/accounting/vouchers/journal', can: view, keywords: 'journal vouchers correction' },
            { title: 'Other income', url: '/accounting/other-income', can: view, keywords: 'income vouchers rent interest scrap' },
            // prettier-ignore
            { title: 'Owner Personal Withdrawal', titleKey: 'owner_withdrawal.nav', url: '/accounting/owner-withdrawals', can: ownerWithdrawals, keywords: 'drawings proprietor goods taken owner stock personal use' },
            { title: 'Adjustments', url: '/accounting/adjustments', can: post, keywords: 'party adjustments write off' },
            { title: 'Between businesses', url: '/accounting/inter-business', can: post, keywords: 'inter-business transfers intercompany' },
        ],
    },
    {
        key: 'accounts-money',
        title: 'Bank & cash',
        icon: Landmark,
        tabs: [
            { title: 'Money accounts', url: '/accounting/money-accounts', can: view, keywords: 'bank & cash bank accounts cash boxes wallets' },
            { title: 'Cash & bank book', url: '/accounting/cash-and-bank', can: view, keywords: 'cash book bank book financial reports' },
            // F34: its own Accounts address; the shared registry address still finds this tab.
            // prettier-ignore
            { title: 'Cash position', titleKey: 'menu.report_cash_position', url: '/accounting/reports/cash-position', can: view, keywords: 'not cash',
                also: ['/hrm/reports/cash_position'] },
            { title: 'Bank statements', url: '/accounting/bank-statements', can: view, keywords: 'bank reconciliation import statement match' },
            { title: 'Cheques', url: '/accounting/cheques', can: view, keywords: 'cheque register clear bounce' },
            { title: 'Transfers', url: '/accounting/money-transfers', can: post, keywords: 'transfer history money transfers' },
        ],
    },
    {
        key: 'accounts-ledgers',
        title: 'Ledgers',
        icon: ListTree,
        tabs: [
            { title: 'General ledger', url: '/accounting/general-ledger', can: view, keywords: 'financial reports ledger' },
            { title: 'Day book', url: '/accounting/day-book', can: view, keywords: 'financial reports daybook' },
            { title: 'Journal register', url: '/accounting/journals', can: view, keywords: 'financial reports journals manual journal' },
            { title: 'Customer statement', url: '/accounting/customer-statement', can: view, keywords: 'financial reports customer ledger' },
            { title: 'Supplier statement', url: '/accounting/supplier-statement', can: view, keywords: 'financial reports supplier ledger' },
            // ⭐ 18 Oct 2026 (A1): one supplier across payable, GRNI and advances.
            { title: 'Supplier ledger', url: '/accounting/supplier-ledger', can: view, keywords: 'supplier ledger payable advances grni exposure' },
            { title: 'Employee statement', url: '/accounting/employee-statement', can: view, keywords: 'financial reports staff ledger' },
            { title: 'Chart of accounts', url: '/accounting/accounts', can: view, keywords: 'accounts setup ledger heads coa' },
        ],
    },
    {
        key: 'accounts-reports',
        title: 'Reports',
        icon: FileBarChart,
        tabs: [
            { title: 'Profit & loss', url: '/accounting/income-statement', can: view, keywords: 'financial reports income statement p&l' },
            { title: 'Balance sheet', url: '/accounting/balance-sheet', can: view, keywords: 'financial reports' },
            { title: 'Trial balance', url: '/accounting/trial-balance', can: view, keywords: 'financial reports tb' },
            { title: 'Cash flow', url: '/accounting/cash-flow', can: view, keywords: 'financial reports' },
            { title: 'Receivables ageing', url: '/accounting/receivables-ageing', can: view, keywords: 'financial reports debtors who owes us' },
            { title: 'Payables ageing', url: '/accounting/payables-ageing', can: view, keywords: 'financial reports creditors what we owe' },
            { title: 'GRNI not tagged', url: '/accounting/grni-untagged', can: view, keywords: 'goods received not invoiced untagged' },
            { title: 'Inventory valuation', url: '/accounting/inventory-valuation', can: view, keywords: 'financial reports stock value' },
            { title: 'Cost of goods sold', url: '/accounting/cost-of-goods-sold', can: view, keywords: 'financial reports cogs' },
            { title: 'VAT return', url: '/accounting/vat-return', can: view, keywords: 'vat mushak' },
        ],
    },
    {
        key: 'accounts-loans',
        title: 'Loans',
        titleKey: 'loans.nav',
        icon: PiggyBank,
        tabs: [
            { title: 'Loans', titleKey: 'loans.nav', url: '/accounting/loans', can: loans, keywords: 'loan register' },
            { title: 'Loan reports', url: '/accounting/loans/reports', can: loans, keywords: 'loans overdue' },
            { title: 'Accrue interest', url: '/accounting/loans/accruals', can: loans, keywords: 'loan interest accruals' },
            { title: 'TDS on interest', url: '/accounting/loans/tds-rules', can: loans, keywords: 'loan tds rules withholding' },
            { title: 'Lenders & borrowers', url: '/accounting/counterparties', can: parties, keywords: 'counterparties' },
        ],
    },
    {
        key: 'accounts-setup',
        title: 'Month end & setup',
        icon: LockKeyhole,
        tabs: [
            { title: 'Period close', url: '/accounting/period-close', can: view, keywords: 'accounts setup lock month end' },
            { title: 'Corrections', url: '/accounting/corrections', can: corrections, keywords: 'corrections reversed corrected' },
            { title: 'Reconciliation', url: '/accounting/reconciliation', can: view, keywords: 'financial reports subledger checks' },
            { title: 'Accruals & prepayments', url: '/accounting/amortizations', can: view, keywords: 'accounts setup amortization' },
            { title: 'Budgets', url: '/accounting/budgets', can: view, keywords: 'accounts setup' },
            { title: 'Payment reminders', url: '/accounting/dunning', can: view, keywords: 'accounts setup dunning' },
            { title: 'Opening balances', url: '/accounting/opening-balances', can: post, keywords: 'accounts setup' },
            { title: 'VAT settings', url: '/accounting/vat-settings', can: (f: SharedData) => f.canManageVat, keywords: 'accounts setup vat regime' },
            { title: 'Finance policy', url: '/accounting/finance-policy', can: policy, keywords: 'accounts setup approval limit discount caps' },
        ],
    },
];
