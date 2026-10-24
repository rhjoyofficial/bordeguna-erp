import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Building2, CalendarDays, FolderOpen, LayoutGrid, ListChecks, MessageSquare, Network, UserCircle } from 'lucide-react';

/**
 * ⭐ The top of the sidebar (redesign round 3, Joy, 16 Oct 2026): two top links
 * with no heading (Dashboard, All businesses) and ONE Workspace group of six
 * (My work, My profile, Team, Calendar, Documents, Chat). It was 18 links in
 * three groups (Overview, Workspace, Group); every screen keeps its address
 * and becomes a tab. A self-service login goes from 10 visible links to 5.
 *
 * The gates are the shared flags the old links used, one for one
 * (workspace-design/MAPPING.md §2, round3-VERIFY §3), so nobody gains or
 * loses a screen:
 * - everyone: Dashboard, Needs attention, Business health, My work,
 *   Notifications, Work schedule.
 * - canReviewApprovals: Approvals and Approval history (the history page lets
 *   the same people in, ApprovalInboxController@history).
 * - hasEmployeeProfile: every My profile tab and My team (the routes are
 *   self-scope; the flag is what the My profile link used).
 * - calendar.view, files.view, notes.view, chat.use: their own flags.
 * - ⚠ Hidden since 24 Oct 2026 (Joy): Calendar, Notes, Chat and Org chart.
 *   Their flags carry the config/features.php switch as well as the
 *   permission (canViewOrgChart is the switch alone), so the tabs come back
 *   by themselves the day a switch is turned on.
 * - All businesses: the three Group flags.
 *
 * Badges: the Dashboard row counts Needs attention; My work counts approvals
 * ONLY (unread notifications are the bell's, never added here).
 * Relax left the sidebar: the user menu has "Take a break" and the header
 * search still finds it (app-sidebar's last search group).
 */
const everyone = () => true;
const me = (f: SharedData) => f.hasEmployeeProfile;

export const HOME_SECTIONS: ModuleSection[] = [
    {
        key: 'home-dashboard',
        title: 'Dashboard',
        titleKey: 'ws.dashboard',
        icon: LayoutGrid,
        badge: (p) => p.attentionCount || undefined,
        tabs: [
            // /dashboard/{owner|finance|...} and /dashboard/classic light this tab by prefix.
            { title: 'Dashboard', titleKey: 'ws.dashboard', url: '/dashboard', can: everyone, keywords: 'home overview classic' },
            {
                title: 'Needs attention',
                titleKey: 'attention.title',
                url: '/attention',
                can: everyone,
                badge: (p) => p.attentionCount || undefined,
                keywords: 'attention alerts urgent',
            },
            { title: 'Business health', titleKey: 'ws.business_health', url: '/health', can: everyone, keywords: 'health checks signals' },
        ],
    },
];

export const WORKSPACE_SECTIONS: ModuleSection[] = [
    {
        key: 'ws-my-work',
        title: 'My work',
        titleKey: 'ws.my_work',
        icon: ListChecks,
        badge: (p) => p.pendingApprovalsCount || undefined,
        tabs: [
            { title: 'My work', titleKey: 'ws.my_work', url: '/work', can: everyone, keywords: 'today to-dos tasks' },
            {
                title: 'Approvals',
                titleKey: 'ws.approvals',
                url: '/approvals',
                can: (f) => f.canReviewApprovals,
                badge: (p) => p.pendingApprovalsCount || undefined,
                keywords: 'approve inbox decide',
            },
            {
                title: 'Approval history',
                titleKey: 'ws.approval_history',
                url: '/approvals/history',
                can: (f) => f.canReviewApprovals,
                keywords: 'approved rejected sent back decided',
            },
            {
                title: 'Notifications',
                titleKey: 'ws.notifications',
                url: '/notifications',
                can: everyone,
                badge: (p) => p.unreadNotificationsCount || undefined,
                keywords: 'bell alerts inbox',
            },
        ],
    },
    {
        key: 'ws-profile',
        title: 'My profile',
        titleKey: 'ws.my_profile',
        icon: UserCircle,
        tabs: [
            { title: 'Profile', titleKey: 'ws.tab.profile', url: '/hrm/my-profile', can: me, keywords: 'me my profile' },
            { title: 'Clock in/out', titleKey: 'ws.tab.clock', url: '/hrm/clock', can: me, keywords: 'clock in out attendance me' },
            { title: 'Leave', titleKey: 'ws.tab.leave', url: '/hrm/my-leave', can: me, keywords: 'my leave holiday me' },
            { title: 'Payslips', titleKey: 'ws.tab.payslips', url: '/hrm/my-payslips', can: me, keywords: 'my payslips salary me' },
            { title: 'Expense claims', titleKey: 'ws.tab.expense_claims', url: '/hrm/my-expenses', can: me, keywords: 'my expenses claim me' },
            { title: 'Notices', titleKey: 'ws.tab.notices', url: '/hrm/my-notices', can: me, keywords: 'my notices show cause me' },
            { title: 'Assets', titleKey: 'ws.tab.assets', url: '/hrm/my-assets', can: me, keywords: 'my assets things me' },
            { title: 'Accounts', titleKey: 'ws.tab.accounts', url: '/hrm/my-accounts', can: me, keywords: 'my accounts logins me' },
            { title: 'Staff purchase', titleKey: 'ws.tab.staff_purchase', url: '/hrm/staff-purchase', can: me, keywords: 'staff purchase buy me' },
        ],
    },
    {
        key: 'ws-team',
        title: 'Team',
        titleKey: 'ws.team',
        icon: Network,
        tabs: [
            { title: 'My team', titleKey: 'ws.my_team', url: '/hrm/my-team', can: me, keywords: 'team reports colleagues' },
            {
                title: 'Org chart',
                titleKey: 'ws.org_chart',
                url: '/hrm/org-chart',
                can: (f) => !!f.canViewOrgChart,
                keywords: 'organisation chart who reports to whom',
            },
        ],
    },
    {
        key: 'ws-calendar',
        title: 'Calendar',
        titleKey: 'ws.calendar',
        icon: CalendarDays,
        tabs: [
            { title: 'Calendar', titleKey: 'ws.calendar', url: '/calendar', can: (f) => f.canViewCalendar, keywords: 'dates events' },
            { title: 'Work schedule', titleKey: 'ws.work_schedule', url: '/schedule', can: everyone, keywords: "schedule rhythm today's rhythm" },
        ],
    },
    {
        key: 'ws-documents',
        title: 'Documents',
        titleKey: 'ws.documents',
        icon: FolderOpen,
        tabs: [
            { title: 'Documents', titleKey: 'ws.documents', url: '/files', can: (f) => f.canViewFiles, keywords: 'files archive' },
            { title: 'Notes', titleKey: 'ws.notes', url: '/notes', can: (f) => f.canViewNotes, keywords: 'notes' },
        ],
    },
    {
        key: 'ws-chat',
        title: 'Chat',
        titleKey: 'ws.chat',
        icon: MessageSquare,
        tabs: [{ title: 'Chat', titleKey: 'ws.chat', url: '/chat', can: (f) => f.canUseChat, keywords: 'messages' }],
    },
];

export const GROUP_SECTIONS: ModuleSection[] = [
    {
        key: 'group-businesses',
        title: 'All businesses',
        titleKey: 'ws.all_businesses',
        icon: Building2,
        tabs: [
            { title: 'Overview', url: '/group/cockpit', can: (f) => f.canViewGroupCockpit, keywords: 'group cockpit all businesses' },
            { title: 'Customers & suppliers', url: '/group/partners', can: (f) => f.canViewGroupPartners, keywords: 'group partners' },
            { title: 'Products', url: '/group/products', can: (f) => f.canViewGroupProducts, keywords: 'group products' },
        ],
    },
];
