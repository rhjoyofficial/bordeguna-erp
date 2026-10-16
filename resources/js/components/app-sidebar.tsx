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
import { Coffee, Settings } from 'lucide-react';
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
    const accountsItems = sectionNavItems(ACCOUNTS_SECTIONS, shared, t);
    // ⭐ Sales (15 Oct 2026): the group opens, as it always did, for whoever may
    // read sales, sell or take an order; each section links to the first tab
    // this person may open. Websites is one link of three tabs.
    // A courier-desk role that may only follow or book parcels opens it too (bug hunt 16 Oct 2026).
    const salesShown = canViewSales || canSell || canRecordOrders || shared.canSeeParcels || shared.canBookParcels;
    const salesItems = salesShown ? sectionNavItems(SALES_SECTIONS, shared, t) : [];
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
        // ⭐ Round 3 (Joy, 16 Oct 2026): the top of the sidebar is two links with
        // no heading (Dashboard, All businesses) and ONE Workspace group of six.
        // The old Overview (7), Workspace (8) and Group (3) links are tabs now
        // (workspace-sections.ts holds every gate); Relax is "Take a break" in
        // the user menu and still in the header search below.
        { label: 'Home', hideLabel: true, items: [...sectionNavItems(HOME_SECTIONS, shared, t), ...sectionNavItems(GROUP_SECTIONS, shared, t)] },
        // Open by default: for a self-service login it is the whole sidebar.
        { label: 'Workspace', items: sectionNavItems(WORKSPACE_SECTIONS, shared, t) },
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
        // ⭐ ONE Accounts group of seven sections (redesign round 3, 16 Oct
        // 2026): Overview, Vouchers, Bank & cash, Ledgers, Reports, Loans,
        // Month end & setup. The 41 links of Accounts, Financial reports and
        // Accounts setup (and the Manager's separate two-link group) became
        // tabs drawn above the page; ACCOUNTS_SECTIONS holds the gates, the
        // same flags as the old links, so nobody gains or loses a screen. The
        // Manager (loans and counterparties, no ledger) sees the one Loans link.
        ...(accountsItems.length > 0 ? [{ label: 'Accounts', items: accountsItems }] : []),
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
