import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import { useSidebarScrollMemory } from '@/hooks/use-sidebar-scroll-memory';
import { useTranslation } from '@/lib/i18n';
import { settingsNavItems } from '@/lib/settings-nav';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    BadgeDollarSign,
    Banknote,
    BellRing,
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
    Globe,
    Grid3x3,
    HandCoins,
    Landmark,
    LayoutDashboard,
    LayoutGrid,
    LifeBuoy,
    ListChecks,
    ListTree,
    LockKeyhole,
    Mail,
    Megaphone,
    MessageSquare,
    Network,
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
    ShieldCheck,
    ShoppingCart,
    SlidersHorizontal,
    Store,
    Tag,
    Tags,
    Target,
    Timer,
    TrendingDown,
    TrendingUp,
    Truck,
    Undo2,
    UserCircle,
    UserPlus,
    Users,
    UserSearch,
    Wallet,
    Warehouse,
    Waves,
    Wrench,
} from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { t } = useTranslation();
    // Clicking a link in the sidebar used to throw it back to the top,
    // because this app has no persistent layout and the whole tree remounts
    // on every visit. See the hook for why it is fixed there.
    const sidebarScroll = useSidebarScrollMemory<HTMLDivElement>();

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
        canViewOperatingReports,
        canViewInventory,
        canViewPurchasing,
        canViewSales,
        canViewStores,
        canSell,
        canRecordOrders,
        canManagePrices,
        canRecordOpeningStock,
        canManageBom,
        canViewCrm,
        canViewContacts,
        canSendCampaigns,
        canViewCalendar,
        canViewNotes,
        canViewFiles,
        canUseChat,
        canManageCrmSettings,
        canViewAssets,
        canViewAssetItems,
        hasEmployeeProfile,
        canManageUsers,
        canManageOrganization,
        canManageReasons,
        canManageFinancePolicy,
        canPostJournal,
    } = usePage<SharedData>().props;

    // ⚠ Inventory's write permissions, shared by the module itself. Staff were
    // shown Production, Stock requests and Purchase units and got 403 on each.
    const inventory = useInventoryAbilities();

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
                { title: 'Business health', url: '/health', icon: Activity },
            ],
        },
        // ⭐ 29 Sep 2026 (Joy: "organise it like a real ERP"): everyone's own
        // tools in one group, closed until one of its pages is open. Clock in/out,
        // My leave and My payslips stay tabs under My profile (23 Aug decision).
        // The calendar belongs to no module: it reads HR, CRM, Sales and
        // Purchasing and shows each reader only the sources they may see.
        {
            label: 'Workspace',
            defaultOpen: false,
            items: [
                ...(hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
                ...(hasEmployeeProfile ? [{ title: 'My team', url: '/hrm/my-team', icon: Network }] : []),
                ...(hasEmployeeProfile ? [{ title: 'Staff purchase', url: '/hrm/staff-purchase', icon: Store }] : []),
                { title: 'Org chart', url: '/hrm/org-chart', icon: Network },
                ...(canViewCalendar ? [{ title: 'Calendar', url: '/calendar', icon: CalendarDays }] : []),
                ...(canViewNotes ? [{ title: 'Notes', url: '/notes', icon: PenSquare }] : []),
                ...(canViewFiles ? [{ title: 'Documents', url: '/files', icon: FolderOpen }] : []),
                ...(canUseChat ? [{ title: 'Chat', url: '/chat', icon: MessageSquare }] : []),
            ],
        },
        // The four businesses side by side. "Switch business" is in the user
        // menu (bottom left), where every ERP keeps it.
        {
            label: 'Group',
            defaultOpen: false,
            items: [
                { title: 'Group cockpit', url: '/group/cockpit', icon: LayoutGrid },
                { title: 'Group partners', url: '/group/partners', icon: Network },
                { title: 'Group products', url: '/group/products', icon: Network },
            ],
        },
        // ⚠ The last two ungated groups, closed 24 Aug 2026: "open to the
        // business" was written before the zero-permission Self-service role
        // existed, and a self-service login could read the stock book and the
        // supplier bills. Every real role holds the view permissions.
        //
        // ⭐ 21 Sep 2026: each module is split in two, the day's work and the
        // setup behind it. One flat list of 20 Inventory and 16 Sales rows put
        // "Units of measure" between "Stock" and "Counter sale" for somebody
        // selling all day. The setup groups start closed and open themselves on
        // any of their pages, like Settings; every url and gate is unchanged.
        ...(canViewInventory
            ? [
                  {
                      label: 'Inventory',
                      items: [
                          { title: 'Inventory', url: '/inventory', icon: Boxes },
                          { title: 'Stock', url: '/inventory/stock', icon: ClipboardList },
                          // Stock on the road between warehouses, and where it is
                          // marked received. It had no menu row until 21 Sep 2026.
                          { title: 'Transfers', url: '/inventory/stock/transfers', icon: ArrowLeftRight },
                          // C-CNT: cycle counting - the recurring answer to
                          // "is the shelf what the book says". Route, screen
                          // and this line ship in one commit.
                          { title: 'Stock counts', url: '/inventory/stock-counts', icon: ClipboardCheck },
                          // The approver's queue: the index authorizes stock.approve.
                          ...(inventory.approveStock ? [{ title: 'Stock requests', url: '/inventory/stock-requests', icon: ClipboardList }] : []),
                          // DreamsPOS gaps INV-2/INV-3 (Joy, 24 Aug): what needs
                          // reordering, and which batches are past or near their date.
                          { title: 'Low stocks', url: '/inventory/low-stocks', icon: TrendingDown },
                          { title: 'Stock expiry', url: '/inventory/expiry', icon: Timer },
                          // C-BIN-4/6. Placement is floor work, gated like the
                          // other floor work; the pick list prints what this
                          // screen records.
                          ...(canRecordOpeningStock ? [{ title: 'Bin placement', url: '/inventory/bin-placement', icon: Grid3x3 }] : []),
                          // Manufacturing phase 1: the production run and the report that
                          // notices a drifting yield - shipped WITH their screens in one
                          // commit, the reachability rule this file records.
                          ...(inventory.manageProduction ? [{ title: 'Production', url: '/inventory/production-runs', icon: Factory }] : []),
                          // ⭐ 26 Sep 2026 (Q19): what the shelf is worth, for the Manager.
                          // The Finance & Accounts section lists it too, so once only.
                          ...(canViewOperatingReports && !canViewAccounting
                              ? [{ title: 'Stock value', url: '/accounting/inventory-valuation', icon: Coins }]
                              : []),
                      ],
                  },
                  {
                      label: 'Inventory setup',
                      defaultOpen: false,
                      items: [
                          { title: 'Products', url: '/inventory/products', icon: PackageSearch },
                          // ⭐ 27 Sep 2026 (Joy): raw materials kept separate from the
                          // finished goods above; same screen, filtered to raw.
                          { title: 'Raw materials', url: '/inventory/products?kind=raw', icon: Boxes },
                          // Import products and Barcode labels are buttons on Products (29 Sep 2026).
                          // ⚠⚠ The cutover's one irreversible document, and it had no
                          // link at all until 19 Aug. Gated, because the route is:
                          // opening stock sets the FIFO layers behind every margin.
                          ...(canRecordOpeningStock ? [{ title: 'Opening stock', url: '/inventory/opening-stock', icon: ClipboardCheck }] : []),
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
                          ...(inventory.manageCatalog ? [{ title: 'Purchase units', url: '/inventory/unit-conversions', icon: Scale }] : []),
                          // ⭐ Courier hub slice 3 (11 Oct 2026): fill every product's
                          // weight in one sheet. Route, screen and this line ship in one
                          // commit - the reachability rule.
                          ...(inventory.manageCatalog ? [{ title: 'Product weights', url: '/inventory/products/weights', icon: Scale }] : []),
                          // C-BOM-9. Route, screen and this line shipped in one commit.
                          // The recipe screen posts nothing - it only decides what a run
                          // suggests - but a template nobody can find saves nobody any
                          // typing.
                          ...(canManageBom ? [{ title: 'Recipes (BOM)', url: '/inventory/bills-of-material', icon: ListTree }] : []),
                          // 21 Sep 2026: the approval limit for corrections had a
                          // column for seven weeks and no screen. Route, screen and
                          // this line ship in one commit - the reachability rule.
                          ...(inventory.approveStock ? [{ title: 'Inventory settings', url: '/inventory/settings', icon: SlidersHorizontal }] : []),
                      ],
                  },
              ]
            : []),
        // ⚠⚠ SALES WAS DEPLOYED AND UNREACHABLE. Every page below was live in
        // production with no link to it anywhere in the application - not in this
        // sidebar and not from any other screen - so the entire revenue side of
        // the ERP could only be reached by typing a URL. Fixed here, and a
        // structural test now fails if any module ships without navigation.
        //
        // The order follows a sale: sell or take the order, deliver, bill,
        // collect, and what comes back.
        ...(canViewSales || canSell || canRecordOrders
            ? [
                  {
                      label: 'Sales',
                      items: [
                          ...(canSell ? [{ title: t('menu.counter_sale'), url: '/sales/counter', icon: Store }] : []),
                          ...(canSell ? [{ title: t('menu.cashier_shifts'), url: '/sales/shifts', icon: Store }] : []),
                          // ⚠ The order book: what is promised, to whom, and how
                          // much of it has left. Shipped WITH its screens in one
                          // commit - the reachability lesson this file records.
                          ...(canViewSales || canRecordOrders ? [{ title: t('menu.sales_orders'), url: '/sales/orders', icon: ClipboardList }] : []),
                          ...(canRecordOrders ? [{ title: 'Quotations', url: '/sales/quotations', icon: FileText }] : []),
                          ...(canViewSales ? [{ title: t('menu.deliveries'), url: '/sales/deliveries', icon: PackageCheck }] : []),
                          // Parcels out with couriers: where their answers are entered.
                          ...(canViewSales ? [{ title: 'Parcels', url: '/sales/consignments', icon: Truck }] : []),
                          ...(canViewSales ? [{ title: 'Invoices', url: '/sales/invoices', icon: FileText }] : []),
                          // ⭐ Money in, and the list of it (21 Sep 2026): before this a receipt
                          // could only be taken from one invoice's page, and never listed.
                          ...(canViewSales ? [{ title: 'Receipts', url: '/sales/receipts', icon: HandCoins }] : []),
                          ...(canViewSales ? [{ title: 'Courier payouts', url: '/sales/courier-statements', icon: Wallet }] : []),
                          // Goods coming back - shipped WITH its screens in one
                          // commit, same reachability rule as the order book.
                          ...(canViewSales ? [{ title: 'Returns', url: '/sales/returns', icon: Undo2 }] : []),
                          ...(canViewSales ? [{ title: 'Customers', url: '/sales/customers', icon: Contact }] : []),
                          // ⭐ 26 Sep 2026 (Q19): the Manager reads who owes us and one
                          // customer's account. Listed here only when the Finance &
                          // Accounts section (which lists them too) is not shown.
                          ...(canViewOperatingReports && !canViewAccounting
                              ? [
                                    { title: 'Receivables ageing', url: '/accounting/receivables-ageing', icon: HandCoins },
                                    { title: 'Customer statement', url: '/accounting/customer-statement', icon: Contact },
                                ]
                              : []),
                          // Sales by brand is a button on Invoices (29 Sep 2026).
                      ],
                  },
                  {
                      label: 'Sales setup',
                      defaultOpen: false,
                      items: [
                          ...(canViewSales || canManagePrices ? [{ title: 'Selling prices', url: '/sales/prices', icon: BadgeDollarSign }] : []),
                          // Who a price is for, and which groups pay MRP (21 Sep 2026).
                          ...(canManagePrices ? [{ title: 'Price groups', url: '/sales/price-groups', icon: BadgeDollarSign }] : []),
                          // Joy's own word for it, 23 Aug: a place to set a
                          // discount price on any product. It writes ordinary
                          // price rows, so nothing downstream learns a new idea.
                          ...(canManagePrices ? [{ title: 'Discounts', url: '/sales/discounts', icon: Percent }] : []),
                          ...(canManagePrices ? [{ title: 'Promotions', url: '/sales/promotions', icon: Percent }] : []),
                          ...(canManagePrices ? [{ title: 'Targets', url: '/sales/targets', icon: BadgeDollarSign }] : []),
                          // Credit limits and days, by file, applied after approval (26 Sep 2026).
                          ...(canViewSales ? [{ title: 'Credit terms', url: '/sales/credit-terms', icon: Contact }] : []),
                          // Import prices is a button on Selling prices (29 Sep 2026).
                      ],
                  },
              ]
            : []),
        // ⚠⚠ Website management, added WITH its routes and screens in one
        // commit, which is the reachability rule this file records. The Sales
        // module was deployed and unreachable for want of these four lines.
        ...(canViewStores
            ? [
                  {
                      label: 'Websites',
                      items: [
                          { title: 'Stores', url: '/commerce/stores', icon: Globe },
                          // ⚠ Pages get their own entry rather than living only
                          // inside a store, because the question an author has is
                          // "where is that article", not "which store was it on".
                          { title: 'Pages', url: '/commerce/pages', icon: FileText },
                          { title: 'Media', url: '/commerce/media', icon: FolderOpen },
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
                          { title: t('menu.purchase_orders'), url: '/purchasing/purchase-orders', icon: ShoppingCart },
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
                                    { title: 'Pipeline', url: '/crm/pipeline', icon: UserPlus },
                                    { title: 'Tickets', url: '/crm/tickets', icon: LifeBuoy },
                                    // ⚠ The work queue is the screen an agent lives in all
                                    // day. Without it, follow-ups exist and nobody sees them.
                                    { title: 'Follow-ups', url: '/crm/queue', icon: CalendarCheck },
                                    { title: 'CRM reports', url: '/crm/reports', icon: FileBarChart },
                                ]
                              : []),
                          ...(canManageCrmSettings ? [{ title: 'CRM settings', url: '/crm/settings', icon: Megaphone }] : []),
                          ...(canManageCrmSettings ? [{ title: 'Cadences', url: '/crm/cadences', icon: Repeat }] : []),
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
        // ⭐ 16 Sep 2026: the physical register joins the section. An Asset Keeper
        // sees the things and their tags; only a reader of the financial register
        // sees fixed assets and depreciation.
        ...(canViewAssets || canViewAssetItems
            ? [
                  {
                      label: 'Assets',
                      items: [
                          ...(canViewAssetItems
                              ? [
                                    { title: 'Things we keep', url: '/assets/items', icon: PackageSearch },
                                    { title: 'Hand-overs', url: '/assets/movements', icon: ArrowLeftRight },
                                    { title: 'Stock-takes', url: '/assets/counts', icon: ClipboardCheck },
                                    { title: 'Maintenance', url: '/assets/maintenance', icon: Wrench },
                                    { title: 'Asset categories', url: '/assets/categories', icon: Tags },
                                    { title: 'Outside parties', url: '/assets/parties', icon: Contact },
                                ]
                              : []),
                          ...(canViewAssets
                              ? [
                                    { title: 'Fixed assets', url: '/assets', icon: Landmark },
                                    { title: 'Depreciation', url: '/assets/depreciation', icon: Scale },
                                ]
                              : []),
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
                                    // The COA mapping behind the categories staff pick.
                                    { title: 'Expense categories', url: '/hrm/expense-categories', icon: Tags },
                                    ...(canAccessSalary ? [{ title: 'Staff purchases', url: '/hrm/staff-purchases', icon: Store }] : []),
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
        // ⭐ 29 Sep 2026 (Joy: "a real ERP does not have this huge menu"): the one
        // 42-link Finance list is split by what somebody opens it FOR - the
        // day's work, the reports, and the setup touched once a month. Same
        // links, same gates; setup and reports start closed.
        ...(canViewAccounting
            ? [
                  {
                      label: 'Accounts',
                      items: [
                          { title: 'Overview', url: '/accounting/overview', icon: ChartColumn },
                          { title: 'Payment vouchers', url: '/accounting/vouchers/payment', icon: Banknote },
                          { title: 'Receive vouchers', url: '/accounting/vouchers/receipt', icon: HandCoins },
                          { title: 'Contra vouchers', url: '/accounting/vouchers/contra', icon: ArrowLeftRight },
                          { title: 'Journal vouchers', url: '/accounting/vouchers/journal', icon: NotebookPen },
                          { title: 'Cheques', url: '/accounting/cheques', icon: ReceiptText },
                          { title: 'Other income', url: '/accounting/other-income', icon: HandCoins },
                          ...(canPostJournal ? [{ title: 'Adjustments', url: '/accounting/adjustments', icon: Scale }] : []),
                          ...(canPostJournal ? [{ title: 'Inter-business transfers', url: '/accounting/inter-business', icon: ArrowLeftRight }] : []),
                          { title: 'Bank statements', url: '/accounting/bank-statements', icon: Landmark },
                          { title: 'Bank & cash', url: '/accounting/money-accounts', icon: Landmark },
                          { title: 'VAT return', url: '/accounting/vat-return', icon: Percent },
                      ],
                  },
                  {
                      label: 'Financial reports',
                      defaultOpen: false,
                      items: [
                          { title: 'Profit & loss', url: '/accounting/income-statement', icon: TrendingUp },
                          { title: 'Balance sheet', url: '/accounting/balance-sheet', icon: FileBarChart },
                          { title: 'Trial balance', url: '/accounting/trial-balance', icon: Scale },
                          { title: 'Cash flow', url: '/accounting/cash-flow', icon: Waves },
                          { title: 'Cash forecast', url: '/accounting/cash-forecast', icon: TrendingUp },
                          { title: 'Receivables ageing', url: '/accounting/receivables-ageing', icon: HandCoins },
                          { title: 'Payables ageing', url: '/accounting/payables-ageing', icon: ReceiptText },
                          { title: 'Customer statement', url: '/accounting/customer-statement', icon: Contact },
                          { title: 'Supplier statement', url: '/accounting/supplier-statement', icon: Contact },
                          { title: 'Employee statement', url: '/accounting/employee-statement', icon: Contact },
                          { title: 'Cash & bank book', url: '/accounting/cash-and-bank', icon: Wallet },
                          { title: 'General ledger', url: '/accounting/general-ledger', icon: ListTree },
                          { title: 'Day book', url: '/accounting/day-book', icon: CalendarClock },
                          { title: 'Journals', url: '/accounting/journals', icon: NotebookPen },
                          { title: 'Inventory valuation', url: '/accounting/inventory-valuation', icon: Boxes },
                          { title: 'Cost of goods sold', url: '/accounting/cost-of-goods-sold', icon: Coins },
                          { title: 'Reconciliation', url: '/accounting/reconciliation', icon: ShieldCheck },
                          { title: 'Transfer history', url: '/accounting/money-transfers', icon: ArrowLeftRight },
                      ],
                  },
                  {
                      label: 'Accounts setup',
                      defaultOpen: false,
                      items: [
                          { title: 'Chart of accounts', url: '/accounting/accounts', icon: BookOpen },
                          ...(canPostJournal ? [{ title: 'Opening balances', url: '/accounting/opening-balances', icon: Landmark }] : []),
                          { title: 'Period close', url: '/accounting/period-close', icon: LockKeyhole },
                          { title: 'Budgets', url: '/accounting/budgets', icon: Target },
                          { title: 'Accruals & prepayments', url: '/accounting/amortizations', icon: CalendarClock },
                          { title: 'Payment reminders', url: '/accounting/dunning', icon: BellRing },
                          { title: 'VAT settings', url: '/accounting/vat-settings', icon: Percent },
                          ...(canManageFinancePolicy ? [{ title: 'Finance policy', url: '/accounting/finance-policy', icon: Percent }] : []),
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
                      label: 'HR reports',
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

            <SidebarContent ref={sidebarScroll.ref} onScroll={sidebarScroll.onScroll}>
                <NavGroups groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
