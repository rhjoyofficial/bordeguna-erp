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
    flash: { success?: string | null; error?: string | null };
    pendingApprovalsCount: number;
    canReviewApprovals: boolean;
    canManageUsers: boolean;
    canManageOrganization: boolean;
    canManageEmployees: boolean;
    canManageScheduling: boolean;
    canManageAttendance: boolean;
    canManageLeave: boolean;
    canAccessSalary: boolean;
    canManagePayroll: boolean;
    canViewAccounting: boolean;
    canViewSales: boolean;
    canSell: boolean;
    canManagePrices: boolean;
    canRecordOpeningStock: boolean;
    canViewCrm: boolean;
    canManageCrmSettings: boolean;
    canViewAssets: boolean;
    canRunDepreciation: boolean;
    canManageChartOfAccounts: boolean;
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
