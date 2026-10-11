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
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
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
    /** The channels this tab listens on (realtime everywhere, 11 Oct 2026). */
    realtime?: { enabled: boolean; business_channel: string | null; user_channel: string | null };
    /** The record kinds this page shows; a `record.changed` of one of them refreshes it. */
    live?: string[];
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
    canManageOrganization: boolean;
    canManageEmployees: boolean;
    canManageScheduling: boolean;
    canManageAttendance: boolean;
    canManageLeave: boolean;
    canAccessSalary: boolean;
    canManagePayroll: boolean;
    canViewAccounting: boolean;
    canViewGroupCockpit: boolean;
    canViewOperatingReports: boolean;
    canViewInventory: boolean;
    canViewPurchasing: boolean;
    canViewSales: boolean;
    canSell: boolean;
    canRecordOrders: boolean;
    canRecordReceipts: boolean;
    canManagePrices: boolean;
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
    /** dashboard.view_all: Settings > Dashboards. */
    canConfigureDashboards: boolean;
    canManageReasons: boolean;
    canUseAssistant: boolean;
    assistantSuggestions: string[];
    assistantLanguages: { code: string; label: string; native: string }[];
    assistantIdentity: { name: string; avatar: string | null; charLimit: number };
    canViewAssets: boolean;
    canViewAssetItems: boolean;
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
