import { ACCOUNTS_SECTIONS } from '@/components/accounting/accounts-sections';
import { ASSETS_SECTIONS } from '@/components/assets/assets-sections';
import { CRM_SECTIONS } from '@/components/crm/crm-sections';
import { HRM_SECTIONS } from '@/components/hrm/hrm-sections';
import { INVENTORY_SECTIONS } from '@/components/inventory/inventory-sections';
import { NavGroups, type NavGroup } from '@/components/nav-groups';
import { NavUser } from '@/components/nav-user';
import { PURCHASES_SECTIONS } from '@/components/purchasing/purchases-sections';
import { SALES_SECTIONS, SHOPS_SECTIONS } from '@/components/sales/sales-sections';
import { SETTINGS_SECTIONS } from '@/components/settings/settings-sections';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { GROUP_SECTIONS, HOME_SECTIONS, WORKSPACE_SECTIONS } from '@/components/workspace/workspace-sections';
import { useSidebarScrollMemory } from '@/hooks/use-sidebar-scroll-memory';
import { useTranslation } from '@/lib/i18n';
import { sectionNavItems, sectionSearchGroups, tabTitle, visibleTabs } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    Banknote,
    BellRing,
    BookOpen,
    Boxes,
    CalendarClock,
    CalendarDays,
    ChartColumn,
    CheckSquare,
    Coffee,
    Coins,
    Contact,
    FileBarChart,
    FolderOpen,
    HandCoins,
    Handshake,
    Landmark,
    LayoutGrid,
    ListChecks,
    ListTree,
    LockKeyhole,
    MessageSquare,
    Network,
    NotebookPen,
    PenSquare,
    Percent,
    PiggyBank,
    ReceiptText,
    Scale,
    Settings,
    ShieldCheck,
    Siren,
    Store,
    Target,
    TrendingUp,
    UserCircle,
    Wallet,
    Waves,
} from 'lucide-react';
import AppLogo from './app-logo';

/**
 * Every destination this user may open, grouped exactly as the sidebar shows
 * them. ONE definition, read by the sidebar and by the header search's "Menus"
 * group (Joy, 11 Oct 2026: "can find any sidebar menu from the search bar"),
 * so the two can never disagree about what a role may reach.
 */
export function useNavGroups(): NavGroup[] {
    const { t } = useTranslation();

    // ⚠ Round 3 (16 Oct 2026): every block reads `shared.x` instead of one big
    // destructuring, so the Workspace, CRM and Accounts builds each replace
    // only their own block.
    const shared = usePage<SharedData>().props;
    const { canViewSales, canSell, canRecordOrders } = shared;

    // Each HRM section links to the first of its tabs this person may open, and
    // lights up on any of them (lib/sections).
    const hrmItems = sectionNavItems(HRM_SECTIONS, shared, t);
    const assetsItems = sectionNavItems(ASSETS_SECTIONS, shared, t);
    const inventoryItems = sectionNavItems(INVENTORY_SECTIONS, shared, t);
    const purchasesItems = sectionNavItems(PURCHASES_SECTIONS, shared, t);
    // ⭐ Sales (15 Oct 2026): the group opens, as it always did, for whoever may
    // read sales, sell or take an order; each section links to the first tab
    // this person may open. Websites is one link of three tabs.
    const salesShown = canViewSales || canSell || canRecordOrders;
    const salesItems = canViewSales || canSell || canRecordOrders ? sectionNavItems(SALES_SECTIONS, shared, t) : [];
    const shopItems = sectionNavItems(SHOPS_SECTIONS, shared, t);
    const crmItems = sectionNavItems(CRM_SECTIONS, shared, t);

    // ⭐ Settings is ONE link, pinned at the foot of the sidebar (Joy, 15 Oct
    // 2026), not a group of twenty. Its screens stay in this list for the
    // header search only (`searchOnly`): "printer" still finds Printers, under
    // Settings, for exactly the people whose permission opens it.
    const settingsSearchItems = SETTINGS_SECTIONS.flatMap((section) =>
        visibleTabs(section, shared).map((tab) => ({ title: tabTitle(tab, t), url: tab.url, icon: section.icon, keywords: tab.keywords })),
    );

    // ⭐ Every TAB is searchable (round 3): one never-drawn group per section,
    // after every drawn group so the sidebar highlight is unchanged. A module's
    // tabs are offered exactly when its group is (Sales keeps its group gate).
    const tabSearchGroups = sectionSearchGroups(
        [
            ['Home', HOME_SECTIONS],
            ['Workspace', WORKSPACE_SECTIONS],
            ['All businesses', GROUP_SECTIONS],
            ['Inventory', INVENTORY_SECTIONS],
            ['Sales', salesShown ? SALES_SECTIONS : []],
            ['Websites', SHOPS_SECTIONS],
            ['Purchases', PURCHASES_SECTIONS],
            ['CRM', CRM_SECTIONS],
            ['Assets', ASSETS_SECTIONS],
            ['HRM', HRM_SECTIONS],
            ['Accounts', ACCOUNTS_SECTIONS],
        ],
        shared,
        t,
    );

    const navGroups: NavGroup[] = [
        {
            label: 'Overview',
            items: [
                { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                // Module 1, Slice 3: the day on one page.
                { title: 'My work', url: '/work', icon: ListChecks },
                ...(shared.canReviewApprovals
                    ? [{ title: 'Approvals', url: '/approvals', icon: CheckSquare, badge: shared.pendingApprovalsCount }]
                    : []),
                // Plan C.4: one ranked list of what is waiting on somebody.
                { title: t('attention.title'), url: '/attention', icon: Siren, badge: shared.attentionCount },
                // The work schedule, read-only, for everybody (14 Oct 2026).
                { title: t('schedule.nav'), url: '/schedule', icon: CalendarClock },
                { title: 'Business health', url: '/health', icon: Activity },
                // A short break, for everybody signed in (14 Oct 2026).
                { title: t('relax.nav'), url: '/relax', icon: Coffee },
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
                ...(shared.hasEmployeeProfile ? [{ title: 'My profile', url: '/hrm/my-profile', icon: UserCircle }] : []),
                ...(shared.hasEmployeeProfile ? [{ title: 'My team', url: '/hrm/my-team', icon: Network }] : []),
                ...(shared.hasEmployeeProfile ? [{ title: 'Staff purchase', url: '/hrm/staff-purchase', icon: Store }] : []),
                { title: 'Org chart', url: '/hrm/org-chart', icon: Network },
                ...(shared.canViewCalendar ? [{ title: 'Calendar', url: '/calendar', icon: CalendarDays }] : []),
                ...(shared.canViewNotes ? [{ title: 'Notes', url: '/notes', icon: PenSquare }] : []),
                ...(shared.canViewFiles ? [{ title: 'Documents', url: '/files', icon: FolderOpen }] : []),
                ...(shared.canUseChat ? [{ title: 'Chat', url: '/chat', icon: MessageSquare }] : []),
            ],
        },
        // The four businesses side by side. "Switch business" is in the user
        // menu (bottom left), where every ERP keeps it.
        {
            label: 'Group',
            defaultOpen: false,
            items: [
                // Slice 0, D3: the cockpit is every business's books side by side,
                // so it follows accounting.view held in at least one of them.
                ...(shared.canViewGroupCockpit ? [{ title: 'Group cockpit', url: '/group/cockpit', icon: LayoutGrid }] : []),
                ...(shared.canViewGroupPartners ? [{ title: 'Group partners', url: '/group/partners', icon: Network }] : []),
                ...(shared.canViewGroupProducts ? [{ title: 'Group products', url: '/group/products', icon: Network }] : []),
            ],
        },
        // ⚠⚠ The last two ungated groups, closed 24 Aug 2026: "open to the
        // business" was written before the zero-permission Self-service role
        // existed, and a self-service login could read the stock book and the
        // supplier bills. Every real role holds the view permissions.
        //
        // ⭐ ONE Inventory group (Joy, 15 Oct 2026): 22 links in two groups
        // (Inventory, Inventory setup) became five sections; their screens are
        // tabs drawn above the page by SectionTabs. INVENTORY_SECTIONS holds the
        // gates, the same flags as the old links, so nobody gains or loses a
        // screen.
        ...(inventoryItems.length > 0 ? [{ label: 'Inventory', items: inventoryItems }] : []),
        // ⚠⚠ SALES WAS DEPLOYED AND UNREACHABLE. Every page below was live in
        // production with no link to it anywhere in the application - not in this
        // sidebar and not from any other screen - so the entire revenue side of
        // the ERP could only be reached by typing a URL. Fixed here, and a
        // structural test now fails if any module ships without navigation.
        //
        // The order follows a sale: sell or take the order, deliver, bill,
        // collect, and what comes back.
        ...(salesItems.length > 0 ? [{ label: 'Sales', items: salesItems }] : []),
        // ONE link of three tabs since 15 Oct 2026 (SHOPS_SECTIONS, commerce.stores.view).
        ...(shopItems.length > 0 ? [{ label: 'Websites', items: shopItems }] : []),
        // ⭐ ONE Purchases group of five sections (Joy, 15 Oct 2026): Overview,
        // Purchase orders, Bills & payments, Expenses, Suppliers. The seven
        // links became tabs drawn above the page; PURCHASES_SECTIONS holds the
        // gates, the same flags as the old links (plus the two Accounts report
        // tabs on accounting.view and Tax rates on suppliers.manage), so
        // nobody gains or loses a screen. Expenses and Recurring stay linked
        // deliberately: a reminder nobody can reach is a note.
        ...(purchasesItems.length > 0 ? [{ label: 'Purchases', items: purchasesItems }] : []),
        // ⭐ ONE CRM group of five sections (round 3, 16 Oct 2026): Overview,
        // Follow-ups, Leads, Phone book & messages, Setup. The ten links became
        // tabs; CRM_SECTIONS holds the gates, the same flags as the old links.
        // Shown when ANY section is visible: somebody with only the send
        // permission used to get no CRM group and no way to SMS or Email.
        ...(crmItems.length > 0 ? [{ label: 'CRM', items: crmItems }] : []),
        // ⚠⚠ The Assets module loaded MIGRATIONS ONLY until 9 August: tables and
        // tested actions with no routes, no screens and no permissions. A
        // delivery van's only reachable path was Purchasing, which files it as
        // stock and expenses it as cost of goods sold - through one month's P&L
        // and off the balance sheet entirely.
        // ⭐ 15 Oct 2026: ONE Assets group of four sections (Overview, Things,
        // Accounts & logins, Fixed assets), after HRM. The ten links became
        // tabs drawn above the page; ASSETS_SECTIONS holds the gates, the same
        // flags as the old links, so nobody gains or loses a screen.
        ...(assetsItems.length > 0 ? [{ label: 'Assets', items: assetsItems }] : []),
        // ⭐ ONE HRM group (Joy, 15 Oct 2026): 26 links in three groups (HRM,
        // Payroll, HR reports) became seven sections; their screens are tabs
        // drawn above the page by HrmSectionTabs. HRM_SECTIONS holds the gates,
        // the same flags as the old links, so nobody gains or loses a screen.
        ...(hrmItems.length > 0 ? [{ label: 'HRM', items: hrmItems }] : []),
        // Accounting Phase 2. Gated on accounting.view, which is Owner, Finance
        // and Payroll by default - a general ledger eventually carries a line
        // per salary payment, so reading it is close to reading payroll.
        // ⭐ 29 Sep 2026 (Joy: "a real ERP does not have this huge menu"): the one
        // 42-link Finance list is split by what somebody opens it FOR - the
        // day's work, the reports, and the setup touched once a month. Same
        // links, same gates; setup and reports start closed.
        ...(shared.canViewAccounting
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
                          ...(shared.canPostJournal ? [{ title: 'Adjustments', url: '/accounting/adjustments', icon: Scale }] : []),
                          ...(shared.canPostJournal
                              ? [{ title: 'Inter-business transfers', url: '/accounting/inter-business', icon: ArrowLeftRight }]
                              : []),
                          // Loans plan v2 (14 Oct 2026): the lenders and borrowers every
                          // loan line names. Route, screen and this line in one commit.
                          ...(shared.canViewCounterparties
                              ? [{ title: t('counterparties.nav'), url: '/accounting/counterparties', icon: Handshake }]
                              : []),
                          // The Loan Register (loans plan v2, phase 2): route, screen,
                          // permission, workflow row and this line in one change.
                          ...(shared.canViewLoans ? [{ title: t('loans.nav'), url: '/accounting/loans', icon: PiggyBank }] : []),
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
                          // Reports pack (11 Oct 2026): cash apart from money that is not cash.
                          { title: t('menu.report_cash_position'), url: '/hrm/reports/cash_position', icon: Wallet },
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
                          { title: 'Journal register', url: '/accounting/journals', icon: NotebookPen },
                          { title: 'Inventory valuation', url: '/accounting/inventory-valuation', icon: Boxes },
                          { title: 'Cost of goods sold', url: '/accounting/cost-of-goods-sold', icon: Coins },
                          { title: 'Reconciliation', url: '/accounting/reconciliation', icon: ShieldCheck },
                          // ACC-005 (14 Oct 2026): the route is Owner-only (journal.post);
                          // Finance and Payroll were shown the link and met a 403.
                          ...(shared.canPostJournal ? [{ title: 'Transfer history', url: '/accounting/money-transfers', icon: ArrowLeftRight }] : []),
                      ],
                  },
                  {
                      label: 'Accounts setup',
                      defaultOpen: false,
                      items: [
                          { title: 'Chart of accounts', url: '/accounting/accounts', icon: BookOpen },
                          ...(shared.canPostJournal ? [{ title: 'Opening balances', url: '/accounting/opening-balances', icon: Landmark }] : []),
                          { title: 'Period close', url: '/accounting/period-close', icon: LockKeyhole },
                          { title: 'Budgets', url: '/accounting/budgets', icon: Target },
                          { title: 'Accruals & prepayments', url: '/accounting/amortizations', icon: CalendarClock },
                          { title: 'Payment reminders', url: '/accounting/dunning', icon: BellRing },
                          ...(shared.canManageVat ? [{ title: 'VAT settings', url: '/accounting/vat-settings', icon: Percent }] : []),
                          ...(shared.canManageFinancePolicy ? [{ title: 'Finance policy', url: '/accounting/finance-policy', icon: Percent }] : []),
                      ],
                  },
              ]
            : []),
        // ⭐ The Manager reads the counterparties without the ledger (14 Oct
        // 2026), so the link stands on its own when the Accounts group is not shown.
        ...((shared.canViewCounterparties || shared.canViewLoans) && !shared.canViewAccounting
            ? [
                  {
                      label: 'Accounts',
                      items: [
                          ...(shared.canViewCounterparties
                              ? [{ title: t('counterparties.nav'), url: '/accounting/counterparties', icon: Handshake }]
                              : []),
                          ...(shared.canViewLoans ? [{ title: t('loans.nav'), url: '/accounting/loans', icon: PiggyBank }] : []),
                      ],
                  },
              ]
            : []),
        // ⚠⚠ SETTINGS IS ONE LINK AT THE FOOT (Joy, 15 Oct 2026; before that a
        // 20-link group since 30 Aug 2026). The home at /settings lays every
        // area out as cards and each screen is a tab; the gates live in
        // settings-sections.ts beside the permission each one really checks.
        // This group is for the header search only and is never drawn.
        {
            label: 'Settings',
            searchOnly: true,
            items: [{ title: 'Settings', url: '/settings', icon: Settings }, ...settingsSearchItems],
        },
        // ⚠ Search only, and LAST: every tab of every module (round 3), then
        // the short break, which leaves the sidebar in round 3 and stays
        // findable by name.
        ...tabSearchGroups,
        {
            label: 'Take a break',
            searchOnly: true,
            items: [{ title: t('relax.nav'), url: '/relax', icon: Coffee, keywords: 'relax take a break rest' }],
        },
    ];

    return navGroups;
}

/**
 * The one Settings link, pinned above the user's name so it is in the same
 * place on every page and never scrolls away with the module groups. Lit on
 * every /settings address (the home and every tab under it).
 */
function SettingsLink() {
    const path = usePage().url.split('?')[0];
    const active = path === '/settings' || path.startsWith('/settings/');

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={active} tooltip={{ children: 'Settings' }}>
                    <Link href="/settings" prefetch>
                        <Settings />
                        <span>Settings</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

export function AppSidebar() {
    // Clicking a link in the sidebar used to throw it back to the top,
    // because this app has no persistent layout and the whole tree remounts
    // on every visit. See the hook for why it is fixed there.
    const sidebarScroll = useSidebarScrollMemory<HTMLDivElement>();
    const navGroups = useNavGroups();

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
                <SettingsLink />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
