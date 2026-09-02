import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { settingsNavItems } from '@/lib/settings-nav';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BadgeDollarSign,
    Banknote,
    BookOpen,
    BookOpenText,
    Boxes,
    CalendarCheck,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    ChartColumn,
    CheckSquare,
    ClipboardCheck,
    ClipboardList,
    Coins,
    Contact,
    Factory,
    FileBarChart,
    FileText,
    FileUp,
    FileWarning,
    Fingerprint,
    FolderOpen,
    Grid3x3,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    ListTree,
    Mail,
    Megaphone,
    MessageSquare,
    NotebookPen,
    PackageCheck,
    PackageSearch,
    Palmtree,
    PenSquare,
    Percent,
    ReceiptText,
    Repeat,
    Ruler,
    Scale,
    ScanBarcode,
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Store,
    Tag,
    Tags,
    Timer,
    TrendingDown,
    TrendingUp,
    Truck,
    Undo2,
    Upload,
    UserCircle,
    UserPlus,
    Users,
    UserSearch,
    Wallet,
    Warehouse,
    Waves,
    Network,
    ListChecks,
    LockKeyhole,
    Target,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const {
        pendingApprovalsCount,
        canReviewApprovals,
        canManageEmployees,
        canViewRecruitment,
        canManageScheduling,
        canManageAttendance,
        canManageLeave,
        canAccessSalary,
        canManagePayroll,
        canViewAccounting,
        canViewInventory,
        canViewPurchasing,
        canViewSales,
        canSell,
        canManagePrices,
        canRecordOpeningStock,
        canImportCatalogue,
        canManageBom,
        canImportPrices,
        canViewCrm,
        canViewContacts,
        canSendCampaigns,
        canViewCalendar,
        canViewNotes,
        canViewFiles,
        canUseChat,
        canManageCrmSettings,
        canViewAssets,
        hasEmployeeProfile,
        canManageUsers,
        canManageOrganization,
        canManageReasons,
    } = usePage<SharedData>().props;

    const settingsItems = settingsNavItems({
        canManageUsers,
        canManageOrganization,
        canManageCrmSettings,
        canManageReasons,
    });

    const navGroups: NavGroup[] = [
        {
            label: 'Overview',
            items: [
                { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                // Module 1, Slice 3: the day on one page.
                { title: 'My work', url: '/work', icon: ListChecks },
                ...(canReviewApprovals ? [{ title: 'Approvals', url: '/approvals', icon: CheckSquare, badge: pendingApprovalsCount }] : []),
                // ⚠ ONE self-service entry, on Joy's reorganisation (23 Aug
                // 2026): Clock in/out, My leave and My payslips live as tabs
                // under My profile now (SelfServiceTabs), not as sidebar rows.
                // Their routes are unchanged, so old bookmarks still land.
                ...(hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
                // Module 1, Slice 1: the reporting tree - the phone book's
                // shape, so it sits with the other everyone-features.
                { title: 'Org chart', url: '/hrm/org-chart', icon: Network },
                // ⚠ Top level rather than inside a module group, because the
                // calendar belongs to no module: it reads HR, CRM, Sales and
                // Purchasing and shows each reader only the sources they may
                // already see.
                ...(canViewCalendar ? [{ title: 'Calendar', url: '/calendar', icon: CalendarDays }] : []),
                ...(canViewNotes ? [{ title: 'Notes', url: '/notes', icon: PenSquare }] : []),
                ...(canViewFiles ? [{ title: 'Documents', url: '/files', icon: FolderOpen }] : []),
                ...(canUseChat ? [{ title: 'Chat', url: '/chat', icon: MessageSquare }] : []),
                { title: 'Switch business', url: '/businesses/switch', icon: Repeat },
            ],
        },
        // ⚠ The last two ungated groups, closed 24 Aug 2026: "open to the
        // business" was written before the zero-permission Self-service role
        // existed, and a self-service login could read the stock book and the
        // supplier bills. Every real role holds the view permissions.
        ...(canViewInventory
            ? [
                  {
                      label: 'Inventory',
                      items: [
                          { title: 'Inventory', url: '/inventory', icon: Boxes },
                          { title: 'Products', url: '/inventory/products', icon: PackageSearch },
                          { title: 'Stock', url: '/inventory/stock', icon: ClipboardList },
                          // DreamsPOS gaps INV-2/INV-3 (Joy, 24 Aug): what needs
                          // reordering, and which batches are past or near their date.
                          { title: 'Low stocks', url: '/inventory/low-stocks', icon: TrendingDown },
                          { title: 'Stock expiry', url: '/inventory/expiry', icon: Timer },
                          // Manufacturing phase 1: the production run and the report that
                          // notices a drifting yield - shipped WITH their screens in one
                          // commit, the reachability rule this file records.
                          { title: 'Production', url: '/inventory/production-runs', icon: Factory },
                          { title: 'Production yield', url: '/inventory/production-yield', icon: FileBarChart },
                          { title: 'Stock requests', url: '/inventory/stock-requests', icon: ClipboardList },
                          // C-CNT: cycle counting - the recurring answer to
                          // "is the shelf what the book says". Route, screen
                          // and this line ship in one commit.
                          { title: 'Stock counts', url: '/inventory/stock-counts', icon: ClipboardCheck },
                          // ⚠⚠ The cutover's one irreversible document, and it had no
                          // link at all until 19 Aug. Gated, because the route is:
                          // opening stock sets the FIFO layers behind every margin.
                          ...(canRecordOpeningStock ? [{ title: 'Opening stock', url: '/inventory/opening-stock', icon: ClipboardCheck }] : []),
                          // C-BIN-4/6. Placement is floor work, gated like the
                          // other floor work; the pick list prints what this
                          // screen records.
                          ...(canRecordOpeningStock ? [{ title: 'Bin placement', url: '/inventory/bin-placement', icon: Grid3x3 }] : []),
                          // C-IMP-7. The whole point of the import screen is that Joy
                          // stops sending files to be loaded for him, so it has to be
                          // findable without being told where it is.
                          ...(canImportCatalogue ? [{ title: 'Import products', url: '/inventory/products/import', icon: Upload }] : []),
                          // ⚠⚠ Products have carried a `barcode` column since
                          // 4 August with nothing to print it - data entered
                          // that had no way back out. Gated on the same
                          // permission as the catalogue it prints from.
                          ...(canImportCatalogue ? [{ title: 'Barcode labels', url: '/inventory/labels', icon: ScanBarcode }] : []),
                          // C-BOM-9. Route, screen and this line shipped in one commit.
                          // The recipe screen posts nothing - it only decides what a run
                          // suggests - but a template nobody can find saves nobody any
                          // typing.
                          ...(canManageBom ? [{ title: 'Recipes (BOM)', url: '/inventory/bills-of-material', icon: ListTree }] : []),
                          { title: 'Warehouses', url: '/inventory/warehouses', icon: Warehouse },
                          // WMS bins phase 1: route, screen and this line ship
                          // in one commit - the reachability rule.
                          { title: 'Bins', url: '/inventory/bins', icon: Grid3x3 },
                          { title: 'Categories', url: '/inventory/categories', icon: Tags },
                          // INV-4: the brands table existed with only a seeder writing
                          // to it - master data Joy could not enter himself.
                          { title: 'Brands', url: '/inventory/brands', icon: Tag },
                          { title: 'Units of measure', url: '/inventory/units', icon: Ruler },
                          // C-IMP-7 again. This one was built on 9 August and had no way
                          // in until 20 August.
                          { title: 'Purchase units', url: '/inventory/unit-conversions', icon: Scale },
                      ],
                  },
              ]
            : []),
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
                          // Joy's own word for it, 23 Aug: a place to set a
                          // discount price on any product. It writes ordinary
                          // price rows, so nothing downstream learns a new idea.
                          ...(canManagePrices ? [{ title: 'Discounts', url: '/sales/discounts', icon: Percent }] : []),
                          // C-IMP-7. Reachable, or it is the fifth screen built
                          // and never found.
                          ...(canImportPrices ? [{ title: 'Import prices', url: '/sales/prices/import', icon: Upload }] : []),
                          ...(canViewSales ? [{ title: 'Invoices', url: '/sales/invoices', icon: FileText }] : []),
                          ...(canViewSales ? [{ title: 'Deliveries', url: '/sales/deliveries', icon: PackageCheck }] : []),
                          // Goods coming back - shipped WITH its screens in one
                          // commit, same reachability rule as the order book.
                          ...(canViewSales ? [{ title: 'Returns', url: '/sales/returns', icon: Undo2 }] : []),
                      ],
                  },
              ]
            : []),
        ...(canViewPurchasing
            ? [
                  {
                      label: 'Purchases',
                      items: [
                          { title: 'Suppliers', url: '/purchasing/suppliers', icon: Truck },
                          { title: 'Purchase orders', url: '/purchasing/purchase-orders', icon: ShoppingCart },
                          { title: 'Bills', url: '/purchasing/bills', icon: ReceiptText },
                          { title: 'Payments', url: '/purchasing/payments', icon: Banknote },
                          { title: 'Credit notes', url: '/purchasing/credit-notes', icon: FileText },
                          // ⚠⚠ Same class of gap as Sales above: RecordExpenseVoucher was
                          // built and tested with no route and no screen, so rent, fuel and
                          // utilities had nowhere to be entered and most of the P&L had no
                          // source at all. Reading the list is open to the business; only
                          // purchasing.expense.record may add one.
                          { title: 'Expenses', url: '/purchasing/expenses', icon: ReceiptText },
                          // ⚠ Linked deliberately. A reminder nobody can reach
                          // is a note, and this is the screen that stops a
                          // hosting bill being remembered by hand.
                          { title: 'Recurring expenses', url: '/purchasing/recurring-expenses', icon: CalendarClock },
                      ],
                  },
              ]
            : []),
        ...(canViewCrm || canViewContacts
            ? [
                  {
                      label: 'CRM',
                      items: [
                          // ⚠⚠ Gated ITEM BY ITEM, not just as a group. The phone
                          // book has its own permission, so somebody may hold it
                          // without holding the lead permissions. Gating only the
                          // group would show that person four links they cannot
                          // open, and a 403 from the sidebar reads as a bug.
                          ...(canViewCrm
                              ? [
                                    { title: 'Leads', url: '/crm/leads', icon: UserPlus },
                                    // ⚠ The work queue is the screen an agent lives in all
                                    // day. Without it, follow-ups exist and nobody sees them.
                                    { title: 'Follow-ups', url: '/crm/queue', icon: CalendarCheck },
                                    { title: 'CRM reports', url: '/crm/reports', icon: FileBarChart },
                                ]
                              : []),
                          ...(canManageCrmSettings ? [{ title: 'Sources & campaigns', url: '/crm/settings', icon: Megaphone }] : []),
                          // ⚠ Linked in the same slice as the route and the
                          // screen. Seven modules in this project have shipped
                          // complete and unreachable because this line was left
                          // for later.
                          ...(canViewContacts ? [{ title: 'Phone book', url: '/crm/contacts', icon: Contact }] : []),
                          // ⚠ Two pages, one engine underneath. Linked in the
                          // same slice as the routes, like everything else here.
                          ...(canSendCampaigns
                              ? [
                                    { title: 'SMS', url: '/crm/messages/sms', icon: MessageSquare },
                                    { title: 'Email', url: '/crm/messages/email', icon: Mail },
                                ]
                              : []),
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
        // One HRM section in the Dreams POS taxonomy, replacing the four
        // one-and-three-item fragments (People / Scheduling / Attendance /
        // Leave) it grew as. Same items, same per-permission gating - only
        // the grouping changed, so a Staff user who can manage attendance
        // and nothing else sees an HRM section holding exactly those four.
        ...(canManageEmployees || canManageScheduling || canManageAttendance || canManageLeave || canViewRecruitment
            ? [
                  {
                      label: 'HRM',
                      items: [
                          ...(canManageEmployees ? [{ title: 'Employees', url: '/hrm/employees', icon: Users }] : []),
                          // ⚠ Its own permission, so a Manager running a hiring
                          // round is not handed the people file with it.
                          ...(canViewRecruitment ? [{ title: 'Recruitment', url: '/hrm/recruitment', icon: UserSearch }] : []),
                          ...(canManageScheduling
                              ? [
                                    // Merged into one section on Joy's instruction
                                    // (23 Aug 2026); Shifts is a tab inside it and
                                    // its route is unchanged.
                                    { title: 'Shift & schedule', url: '/hrm/schedules', icon: CalendarRange },
                                    { title: 'Calendars', url: '/hrm/calendars', icon: CalendarDays },
                                ]
                              : []),
                          ...(canManageAttendance
                              ? [
                                    { title: 'Daily attendance', url: '/hrm/attendance/daily', icon: CalendarCheck },
                                    { title: 'Attendance summary', url: '/hrm/attendance/summary', icon: ChartColumn },
                                    { title: 'Punch log', url: '/hrm/attendance', icon: Fingerprint },
                                    { title: 'Corrections', url: '/hrm/attendance/corrections', icon: PenSquare },
                                    { title: 'Attendance import', url: '/hrm/attendance/import', icon: FileUp },
                                    // Joy, 24 Aug 2026: the POL-03 ladders are
                                    // adjustable data now, not seeded constants.
                                    { title: 'Attendance policy', url: '/hrm/policies', icon: SlidersHorizontal },
                                    // The formal letter after a lateness review
                                    // (Joy, 24 Aug 2026); employees hold their
                                    // copies under My profile.
                                    { title: 'Show cause notices', url: '/hrm/notices', icon: FileWarning },
                                ]
                              : []),
                          ...(canManageLeave
                              ? [
                                    { title: 'Leave & movement', url: '/hrm/leave', icon: Palmtree },
                                    // ⚠ Linked deliberately. Five engines in this system
                                    // have shipped complete and unreachable; a route with
                                    // no menu entry is a feature nobody uses.
                                    { title: 'Holiday work', url: '/hrm/holiday-work', icon: CalendarCheck },
                                ]
                              : []),
                      ],
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
                                    // ⚠ Linked deliberately: a settlement screen nobody can
                                    // reach means employees are never paid back.
                                    { title: 'Expense claims', url: '/hrm/expense-claims', icon: HandCoins },
                                    { title: 'Payroll runs', url: '/hrm/payroll', icon: Banknote },
                                    // ⚠ Linked deliberately. The payroll screen shows ONE
                                    // month; the register is the only place the months add up.
                                    { title: 'Salary history', url: '/hrm/payroll/history', icon: BookOpenText },
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
                      label: 'Finance & Accounts',
                      items: [
                          // ⚠ First, because it is the one screen that answers
                          // "how are we doing" without being asked for a date
                          // range first.
                          { title: 'Overview', url: '/accounting/overview', icon: ChartColumn },
                          // ⚠ The two statements first, and the trial balance
                          // after them. A trial balance answers "do the books
                          // add up"; these answer "how did we do" and "what do
                          // we own" - which is what somebody opens the section
                          // to find out.
                          { title: 'Profit & loss', url: '/accounting/income-statement', icon: TrendingUp },
                          { title: 'Balance sheet', url: '/accounting/balance-sheet', icon: FileBarChart },
                          { title: 'Trial balance', url: '/accounting/trial-balance', icon: Scale },
                          // ⚠ The third primary statement, beside the two it
                          // completes. P&L says whether we made money; the
                          // balance sheet says what we hold; only this answers
                          // "profitable, so why is there none in the bank".
                          { title: 'Cash flow', url: '/accounting/cash-flow', icon: Waves },
                          { title: 'Cash forecast', url: '/accounting/cash-forecast', icon: TrendingUp },
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
                          // Module 2, Slice 1: the boundary the whole ledger obeys.
                          { title: 'Period close', url: '/accounting/period-close', icon: LockKeyhole },
                          { title: 'Budgets', url: '/accounting/budgets', icon: Target },
                          { title: 'Bank statements', url: '/accounting/bank-statements', icon: Landmark },
                          { title: 'Accruals & prepayments', url: '/accounting/amortizations', icon: CalendarClock },
                          { title: 'Payment reminders', url: '/accounting/dunning', icon: BellRing },
                          { title: 'Bank & cash', url: '/accounting/money-accounts', icon: Landmark },
                          // ⚠⚠ Directly under the screen that NAMES the accounts,
                          // because this is the only way to move money between
                          // them. Five accounts existed with no transfer document
                          // at all, so a bank-to-till withdrawal could only be a
                          // hand-written journal - which meant it went unrecorded.
                          { title: 'Money transfer', url: '/accounting/money-transfers', icon: ArrowLeftRight },
                      ],
                  },
              ]
            : []),
        // Reporting (W3). Shown to anyone who can reach at least one report;
        // the list itself is filtered per user server-side by ReportRegistry,
        // which also re-applies ACL-02 - so a Department Manager sees the
        // section, and no payroll report inside it.
        ...(canManageAttendance || canAccessSalary
            ? [
                  {
                      label: 'Reports',
                      items: [
                          // RPT-04. Same gate as Reports and for the same
                          // reason: the widget list is filtered per user
                          // server-side by DashboardRegistry, which re-applies
                          // ACL-02 - so a Department Manager sees the section
                          // and no payroll tile inside it.
                          { title: 'HR dashboard', url: '/hrm/dashboard', icon: LayoutDashboard },
                          { title: 'Reports', url: '/hrm/reports', icon: FileBarChart },
                      ],
                  },
              ]
            : []),
        // ⚠⚠ SETTINGS LIVES IN THE SIDEBAR (Joy, 30 Aug 2026), not in a second
        // navigation column inside the settings pages. One place to look for a
        // link, and the settings pages get the whole screen back.
        //
        // ⚠ Every item is gated by the permission its own controller
        // authorizes - see settings-nav.ts, where the gates are listed with the
        // permission each one really checks. An empty list renders no group at
        // all rather than an empty heading.
        ...(settingsItems.length > 0
            ? [
                  {
                      label: 'Settings',
                      defaultOpen: false,
                      items: settingsItems,
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
