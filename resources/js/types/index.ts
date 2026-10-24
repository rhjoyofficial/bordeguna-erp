import { type InventoryAbilities } from '@/hooks/use-inventory-abilities';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
    /** Further path prefixes that light this item up (an HRM section's tabs). */
    match?: string[];
    /** Words the header search matches besides the title (old link names). */
    keywords?: string;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

/** One line of a popup: plain text, rendered as text. */
export interface PopupLine {
    kind: 'arabic' | 'heading' | 'body' | 'note';
    text: string;
}

/** A popup as the engine receives it (PopupFeed::item). */
export interface PopupItem {
    id: number;
    key: string | null;
    title: string;
    lines: PopupLine[];
    style: 'calm' | 'bismillah' | 'notice' | 'warning';
    on_login: boolean;
    idle_minutes: number | null;
    frequency: 'every' | 'daily' | 'once';
    /** The URL to play when it opens; null for silence (a video popup is always null). */
    sound: string | null;
    /** Null = stays until closed (a video popup is always null). */
    close_after_seconds: number | null;
    priority: number;
    media: { type: 'youtube'; id: string } | { type: 'video' | 'image'; url: string } | null;
}

export interface PopupFeed {
    enabled: boolean;
    sound: boolean;
    login: string | null;
    quiet_hours: { from: string; to: string } | null;
    /** The URL each event plays; null = the built-in file (dashboards) or silence (popup). */
    sounds: { success: string | null; attention: string | null; error: string | null; popup: string | null; schedule: string | null };
    items: PopupItem[];
}

export type ScheduleKind = 'focus' | 'break' | 'meal' | 'prayer' | 'wrap';

/** One slot of the working day (WorkScheduleSlot::toFeed). Times are Asia/Dhaka 'HH:MM'. */
export interface ScheduleSlot {
    id: number;
    starts_at: string;
    ends_at: string;
    title: string;
    subtitle: string | null;
    kind: ScheduleKind;
    /** 0 = Sunday .. 6 = Saturday. */
    weekdays: number[];
}

/** The work schedule alert engine's feed (ScheduleFeed::share, 14 Oct 2026). */
export interface ScheduleFeed {
    /** The master switch AND the person's own switch. */
    enabled: boolean;
    /** The person's own switch, and the managers' master switch. */
    own_enabled: boolean;
    global_enabled: boolean;
    sound: boolean;
    alert_seconds: number;
    /** ISO 8601 with the +06:00 offset. */
    server_now: string;
    sound_url: string | null;
    slots: ScheduleSlot[];
}

// Shape of a Laravel LengthAwarePaginator serialized to Inertia (via ->through()).
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: PaginationLink[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success?: string | null; error?: string | null; warning?: string | null };
    pendingApprovalsCount: number;
    canReviewApprovals: boolean;
    /** Needs attention's sidebar badge: at most a minute old. */
    attentionCount?: number;
    /** system.jobs.manage: Settings > Failed jobs. */
    canManageJobs?: boolean;
    /** How many jobs have failed: the badge on the Failed jobs card. Null unless canManageJobs. */
    failedJobsCount?: number | null;
    /** CRM Follow-ups badge: open follow-ups due today or overdue, own scope. Null without crm.leads.view. */
    crmFollowUpsDueCount?: number | null;
    /** CRM Tickets badge: tickets open past their resolve-by. Null without crm.leads.view. */
    crmTicketsOverdueCount?: number | null;
    /** Inventory's write flags, shared by its own module (use-inventory-abilities has the full shape). */
    inventoryAbilities?: Partial<InventoryAbilities>;
    /** The channels this tab listens on (realtime everywhere, 11 Oct 2026). */
    realtime?: { enabled: boolean; business_channel: string | null; user_channel: string | null };
    /** The record kinds this page shows; a `record.changed` of one of them refreshes it. */
    live?: string[];
    /** The Bismillah greeting: the person's own switch and the sign-in nonce. Null on guest pages. */
    bismillah?: { enabled: boolean; login: string | null } | null;
    /** system.popups.manage: Settings > Sounds and Settings > Popups. */
    canManagePopups?: boolean;
    /** relax.manage: Settings > Relax zone. */
    canManageRelax?: boolean;
    /** The popup engine's feed (14 Oct 2026). Null on guest pages. */
    popups?: PopupFeed | null;
    /** schedule.manage: Settings > Work schedule. */
    canManageSchedule?: boolean;
    /** The work schedule alerts (14 Oct 2026). Null on guest pages. */
    schedule?: ScheduleFeed | null;
    /** Stored, addressed to this user, and unread. In-app only since 23 Aug. */
    unreadNotificationsCount: number;
    recentNotifications: {
        id: string;
        summary: string;
        url: string | null;
        kind: string;
        at: string | null;
    }[];
    canManageChart: boolean;
    canManageUsers: boolean;
    canManageFinancePolicy: boolean;
    /** VAT settings (accounting.vat.manage): the menu link follows the route gate. */
    canManageVat: boolean;
    canManageOrganization: boolean;
    canManageEmployees: boolean;
    canManageScheduling: boolean;
    canManageAttendance: boolean;
    canManageLeave: boolean;
    canAccessSalary: boolean;
    canManagePayroll: boolean;
    canViewAccounting: boolean;
    canViewGroupCockpit: boolean;
    canViewGroupPartners: boolean;
    canViewGroupProducts: boolean;
    canViewOperatingReports: boolean;
    /** accounting.counterparties.view: Accounting > Counterparties (Owner, Finance, Manager). */
    canViewCounterparties: boolean;
    /** accounting.counterparties.manage: add, change, switch off; the "New counterparty" dialog. */
    canManageCounterparties: boolean;
    /** corrections.report.view: the monthly Corrections report (Owner, Finance). */
    canViewCorrections?: boolean;
    /** accounting.owner_withdrawal.view: Accounts > Vouchers > Owner Personal Withdrawal (Owner, Finance, or an explicit grant). */
    canViewOwnerWithdrawals?: boolean;
    // Supplier = customer (18 Oct 2026): set-offs and the combined party statement.
    canViewParties?: boolean;
    canManageSetOffs?: boolean;
    /** recycle_bin.manage and the Owner role: the Recycle bin (Owner only, restore only). */
    canManageRecycleBin?: boolean;
    /** A "Possible duplicate" warning flashed by the server, with the ack token for "Save anyway". */
    duplicateWarning?: {
        candidates: {
            number: string | null;
            summary: string;
            saved_by: string | null;
            saved_ago: string | null;
            status: string | null;
            url: string | null;
        }[];
        ack_token: string;
    } | null;
    /** accounting.loans.view: Accounting > Loans, the Loan Register (Owner, Finance, Manager). */
    canViewLoans: boolean;
    /** accounting.loans.manage: draft loans, record their money, accrue interest. */
    canManageLoans: boolean;
    canViewSalesReports: boolean;
    canViewCostReports: boolean;
    /** The sidebar-linked report keys ReportRegistry::canRun lets this user run (a Department Manager runs none of Sales'). */
    runnableReports?: string[];
    canViewInventory: boolean;
    canViewPurchasing: boolean;
    /** purchasing.suppliers.manage: the Tax rates (TDS/VDS) tab, the gate on its route. */
    canManageSuppliers: boolean;
    /** purchasing.landed_cost.view: the Landed costs tab under Purchase orders (17 Oct 2026). */
    canViewLandedCosts: boolean;
    /** purchasing.quick.purchase: the Buy and receive tab under Purchase orders (18 Oct 2026). */
    canQuickPurchase?: boolean;
    /** purchasing.po.create: the New purchase order page and its shortcuts (purchase redesign PR1, 18 Oct 2026). */
    canCreatePurchaseOrder?: boolean;
    /** Purchase redesign PR6 (18 Oct 2026): the New bill, New advance, New credit note and Import expenses tabs, the gates on their routes. */
    canRecordSupplierPayment?: boolean;
    canRecordSupplierBill?: boolean;
    canRecordSupplierAdvance?: boolean;
    canRecordCreditNote?: boolean;
    canRecordExpense?: boolean;
    canViewSales: boolean;
    /** commerce.stores.view: Websites (Online shops) and its three tabs. */
    canViewStores: boolean;
    canSell: boolean;
    canRecordOrders: boolean;
    canRecordReceipts: boolean;
    canManagePrices: boolean;
    /** sales.sellers.manage: Retail sellers (initial plan B21). */
    canManageSellers?: boolean;
    /** This login is a retail seller: he sees his own statement. */
    isRetailSeller?: boolean;
    canRecordOpeningStock: boolean;
    canImportCatalogue: boolean;
    canImportPrices: boolean;
    canManageBom: boolean;
    canViewCrm: boolean;
    canViewContacts: boolean;
    canSendCampaigns: boolean;
    canViewCalendar: boolean;
    canViewNotes: boolean;
    canViewFiles: boolean;
    canViewRecruitment: boolean;
    canUseChat: boolean;
    canManageCrmSettings: boolean;
    canManageCourierSettings: boolean;
    canProposeRateCards: boolean;
    canSeeParcels: boolean;
    canBookParcels: boolean;
    /** dashboard.view_all: Settings > Dashboards. */
    canConfigureDashboards: boolean;
    canManageReasons: boolean;
    canUseAssistant: boolean;
    assistantSuggestions: string[];
    assistantLanguages: { code: string; label: string; native: string }[];
    assistantIdentity: { name: string; avatar: string | null; charLimit: number };
    canViewAssets: boolean;
    canViewAssetItems: boolean;
    /** assets.accounts.view: the accounts hub. */
    canViewAccounts: boolean;
    /** assets.accounts.manage: the leaver check sits with the hub's managers. */
    canManageAccounts: boolean;
    canRunDepreciation: boolean;
    canManageChartOfAccounts: boolean;
    canCreateLedgerHeads: boolean;
    canUpdateLedgerHeads: boolean;
    canDeleteLedgerHeads: boolean;
    canManageChartStructure: boolean;
    canManageMoneyAccounts: boolean;
    canPrepareVouchers: boolean;
    canPostVouchers: boolean;
    canPostJournal: boolean;
    hasEmployeeProfile: boolean;
    /**
     * A PAGE prop, not a shared one: the tab URL this page lights when its
     * address cannot say (a voucher's own page lights its type's tab). Honoured
     * only when it is a tab of the section found from the address.
     */
    sectionTab?: string;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
