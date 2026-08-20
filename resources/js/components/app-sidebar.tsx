import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BadgeDollarSign,
    Banknote,
    BookOpen,
    Boxes,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    CheckSquare,
    ClipboardList,
    Coins,
    Contact,
    Factory,
    FileBarChart,
    FileText,
    FileUp,
    Fingerprint,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    ListTree,
    Megaphone,
    NotebookPen,
    PackageCheck,
    PackageSearch,
    Palmtree,
    PenSquare,
    ReceiptText,
    Repeat,
    Ruler,
    Scale,
    ShieldCheck,
    ShoppingCart,
    Store,
    Tags,
    TrendingUp,
    Truck,
    Upload,
    Undo2,
    UserCircle,
    UserPlus,
    Users,
    Wallet,
    Warehouse,
    ClipboardCheck,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const {
        pendingApprovalsCount,
        canReviewApprovals,
        canManageEmployees,
        canManageScheduling,
        canManageAttendance,
        canManageLeave,
        canAccessSalary,
        canManagePayroll,
        canViewAccounting,
        canViewSales,
        canSell,
        canManagePrices,
        canRecordOpeningStock,
        canImportCatalogue,
        canViewCrm,
        canManageCrmSettings,
        canViewAssets,
        hasEmployeeProfile,
    } = usePage<SharedData>().props;

    const navGroups: NavGroup[] = [
        {
            label: 'Overview',
            items: [
                { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                ...(canReviewApprovals ? [{ title: 'Approvals', url: '/approvals', icon: CheckSquare, badge: pendingApprovalsCount }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
                ...(hasEmployeeProfile ? [{ title: 'Clock in/out', url: '/hrm/clock', icon: Fingerprint }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My leave', url: '/hrm/my-leave', icon: Palmtree }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My payslips', url: '/hrm/my-payslips', icon: ReceiptText }] : []),
                { title: 'Switch business', url: '/businesses/switch', icon: Repeat },
            ],
        },
        {
            label: 'Inventory',
            items: [
                { title: 'Inventory', url: '/inventory', icon: Boxes },
                { title: 'Products', url: '/inventory/products', icon: PackageSearch },
                { title: 'Stock', url: '/inventory/stock', icon: ClipboardList },
                // Manufacturing phase 1: the production run and the report that
                // notices a drifting yield - shipped WITH their screens in one
                // commit, the reachability rule this file records.
                { title: 'Production', url: '/inventory/production-runs', icon: Factory },
                { title: 'Production yield', url: '/inventory/production-yield', icon: FileBarChart },
                { title: 'Stock requests', url: '/inventory/stock-requests', icon: ClipboardList },
                // ⚠⚠ The cutover's one irreversible document, and it had no
                // link at all until 19 Aug. Gated, because the route is:
                // opening stock sets the FIFO layers behind every margin.
                ...(canRecordOpeningStock
                    ? [{ title: 'Opening stock', url: '/inventory/opening-stock', icon: ClipboardCheck }]
                    : []),
                // C-IMP-7. The whole point of the import screen is that Joy
                // stops sending files to be loaded for him, so it has to be
                // findable without being told where it is.
                ...(canImportCatalogue
                    ? [{ title: 'Import products', url: '/inventory/products/import', icon: Upload }]
                    : []),
                { title: 'Warehouses', url: '/inventory/warehouses', icon: Warehouse },
                { title: 'Categories', url: '/inventory/categories', icon: Tags },
                { title: 'Units of measure', url: '/inventory/units', icon: Ruler },
            ],
        },
        {
            label: 'Purchasing',
            items: [
                { title: 'Suppliers', url: '/purchasing/suppliers', icon: Truck },
                { title: 'Purchase orders', url: '/purchasing/purchase-orders', icon: ShoppingCart },
                { title: 'Bills', url: '/purchasing/bills', icon: ReceiptText },
                { title: 'Payments', url: '/purchasing/payments', icon: Banknote },
                { title: 'Credit notes', url: '/purchasing/credit-notes', icon: FileText },
                // ⚠⚠ Same class of gap as Sales below: RecordExpenseVoucher was
                // built and tested with no route and no screen, so rent, fuel and
                // utilities had nowhere to be entered and most of the P&L had no
                // source at all. Reading the list is open to the business; only
                // purchasing.expense.record may add one.
                { title: 'Expenses', url: '/purchasing/expenses', icon: ReceiptText },
            ],
        },
        // ⚠⚠ SALES WAS DEPLOYED AND UNREACHABLE. Every page below was live in
        // production with no link to it anywhere in the application - not in this
        // sidebar and not from any other screen - so the entire revenue side of
        // the ERP could only be reached by typing a URL. Fixed here, and a
        // structural test now fails if any module ships without navigation.
        ...(canViewSales || canSell
            ? [
                  {
                      label: 'Sales',
                      items: [
                          ...(canSell ? [{ title: 'Counter sale', url: '/sales/counter', icon: Store }] : []),
                          // ⚠ The order book: what is promised, to whom, and how
                          // much of it has left. Shipped WITH its screens in one
                          // commit - the reachability lesson this file records.
                          ...(canViewSales ? [{ title: 'Sales orders', url: '/sales/orders', icon: ClipboardList }] : []),
                          ...(canViewSales ? [{ title: 'Customers', url: '/sales/customers', icon: Contact }] : []),
                          ...(canViewSales || canManagePrices ? [{ title: 'Selling prices', url: '/sales/prices', icon: BadgeDollarSign }] : []),
                          ...(canViewSales ? [{ title: 'Invoices', url: '/sales/invoices', icon: FileText }] : []),
                          ...(canViewSales ? [{ title: 'Deliveries', url: '/sales/deliveries', icon: PackageCheck }] : []),
                          // Goods coming back - shipped WITH its screens in one
                          // commit, same reachability rule as the order book.
                          ...(canViewSales ? [{ title: 'Returns', url: '/sales/returns', icon: Undo2 }] : []),
                      ],
                  },
              ]
            : []),
        ...(canViewCrm
            ? [
                  {
                      label: 'CRM',
                      items: [
                          { title: 'Leads', url: '/crm/leads', icon: UserPlus },
                          // ⚠ The work queue is the screen an agent lives in all
                          // day. Without it, follow-ups exist and nobody sees them.
                          { title: 'Follow-ups', url: '/crm/queue', icon: CalendarCheck },
                          { title: 'CRM reports', url: '/crm/reports', icon: FileBarChart },
                          ...(canManageCrmSettings ? [{ title: 'Sources & campaigns', url: '/crm/settings', icon: Megaphone }] : []),
                      ],
                  },
              ]
            : []),
        // ⚠⚠ The Assets module loaded MIGRATIONS ONLY until 9 August: tables and
        // tested actions with no routes, no screens and no permissions. A
        // delivery van's only reachable path was Purchasing, which files it as
        // stock and expenses it as cost of goods sold - through one month's P&L
        // and off the balance sheet entirely.
        ...(canViewAssets
            ? [
                  {
                      label: 'Assets',
                      items: [
                          { title: 'Fixed assets', url: '/assets', icon: Landmark },
                          { title: 'Depreciation', url: '/assets/depreciation', icon: Scale },
                      ],
                  },
              ]
            : []),
        ...(canManageEmployees
            ? [
                  {
                      label: 'People',
                      items: [{ title: 'Employees', url: '/hrm/employees', icon: Users }],
                  },
              ]
            : []),
        ...(canManageScheduling
            ? [
                  {
                      label: 'Scheduling',
                      items: [
                          { title: 'Schedules', url: '/hrm/schedules', icon: CalendarRange },
                          { title: 'Shifts', url: '/hrm/shifts', icon: CalendarClock },
                          { title: 'Calendars', url: '/hrm/calendars', icon: CalendarDays },
                      ],
                  },
              ]
            : []),
        ...(canManageAttendance
            ? [
                  {
                      label: 'Attendance',
                      items: [
                          { title: 'Daily attendance', url: '/hrm/attendance/daily', icon: CalendarCheck },
                          { title: 'Punch log', url: '/hrm/attendance', icon: Fingerprint },
                          { title: 'Corrections', url: '/hrm/attendance/corrections', icon: PenSquare },
                          { title: 'Import', url: '/hrm/attendance/import', icon: FileUp },
                      ],
                  },
              ]
            : []),
        ...(canManageLeave
            ? [
                  {
                      label: 'Leave',
                      items: [{ title: 'Leave & movement', url: '/hrm/leave', icon: Palmtree }],
                  },
              ]
            : []),
        ...(canAccessSalary
            ? [
                  {
                      label: 'Payroll',
                      items: [
                          { title: 'Salary structures', url: '/hrm/salary', icon: Wallet },
                          { title: 'Increments', url: '/hrm/salary-revisions', icon: TrendingUp },
                          ...(canManagePayroll
                              ? [
                                    { title: 'Payroll inputs', url: '/hrm/payroll-inputs', icon: Coins },
                                    { title: 'Loans & advances', url: '/hrm/loans', icon: HandCoins },
                                    { title: 'Payroll runs', url: '/hrm/payroll', icon: Banknote },
                                    // The liability for leave earned and not
                                    // taken - shipped WITH its screens, the
                                    // reachability rule this file records.
                                    { title: 'Leave provision', url: '/hrm/leave-provision', icon: Palmtree },
                                ]
                              : []),
                      ],
                  },
              ]
            : []),
        // Accounting Phase 2. Gated on accounting.view, which is Owner, Finance
        // and Payroll by default - a general ledger eventually carries a line
        // per salary payment, so reading it is close to reading payroll.
        ...(canViewAccounting
            ? [
                  {
                      label: 'Accounting',
                      items: [
                          // ⚠ The two statements first, and the trial balance
                          // after them. A trial balance answers "do the books
                          // add up"; these answer "how did we do" and "what do
                          // we own" - which is what somebody opens the section
                          // to find out.
                          { title: 'Profit & loss', url: '/accounting/income-statement', icon: TrendingUp },
                          { title: 'Balance sheet', url: '/accounting/balance-sheet', icon: FileBarChart },
                          { title: 'Trial balance', url: '/accounting/trial-balance', icon: Scale },
                          // ⚠ The two ageings sit next to the statements rather
                          // than under Sales and Purchasing, because they are
                          // read against the balance sheet they must agree
                          // with, and the screen states that agreement.
                          { title: 'Receivables ageing', url: '/accounting/receivables-ageing', icon: HandCoins },
                          // The follow-up the ageing raises: which invoices,
                          // which payments, in what order, for ONE customer.
                          { title: 'Customer statement', url: '/accounting/customer-statement', icon: Contact },
                          { title: 'Payables ageing', url: '/accounting/payables-ageing', icon: ReceiptText },
                          // ⚠ "Cash & bank book" is the REPORT; "Bank & cash"
                          // below is the setup screen that names the accounts.
                          // Its figures are the trial balance's own (C-CBB-2).
                          { title: 'Cash & bank book', url: '/accounting/cash-and-bank', icon: Wallet },
                          // ⚠ Beside the ageings for the same reason: both tie
                          // out to a control account on screen, and stock value
                          // is read against the balance sheet it must agree
                          // with. A route with no link here is this project's
                          // recurring unreachable-screen defect.
                          { title: 'Inventory valuation', url: '/accounting/inventory-valuation', icon: Boxes },
                          { title: 'Cost of goods sold', url: '/accounting/cost-of-goods-sold', icon: Coins },
                          { title: 'General ledger', url: '/accounting/general-ledger', icon: ListTree },
                          // accounting:reconcile without the SSH: do the books
                          // agree with the documents, right now.
                          { title: 'Reconciliation', url: '/accounting/reconciliation', icon: ShieldCheck },
                          { title: 'Journals', url: '/accounting/journals', icon: NotebookPen },
                          { title: 'Chart of accounts', url: '/accounting/accounts', icon: BookOpen },
                          { title: 'Bank & cash', url: '/accounting/money-accounts', icon: Landmark },
                      ],
                  },
              ]
            : []),
        // Reporting (W3). Shown to anyone who can reach at least one report;
        // the list itself is filtered per user server-side by ReportRegistry,
        // which also re-applies ACL-02 — so a Department Manager sees the
        // section, and no payroll report inside it.
        ...(canManageAttendance || canAccessSalary
            ? [
                  {
                      label: 'Reporting',
                      items: [
                          // RPT-04. Same gate as Reports and for the same
                          // reason: the widget list is filtered per user
                          // server-side by DashboardRegistry, which re-applies
                          // ACL-02 — so a Department Manager sees the section
                          // and no payroll tile inside it.
                          { title: 'HR dashboard', url: '/hrm/dashboard', icon: LayoutDashboard },
                          { title: 'Reports', url: '/hrm/reports', icon: FileBarChart },
                      ],
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavGroups groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
