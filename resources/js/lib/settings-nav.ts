import { type NavItem } from '@/types';
import {
    Bot,
    Building2,
    KeyRound,
    LayoutDashboard,
    ListChecks,
    MapPin,
    Network,
    Palette,
    Printer,
    Send,
    ShieldCheck,
    Truck,
    User,
    Users,
} from 'lucide-react';

/**
 * Every settings destination, in one list, gated one by one.
 *
 * Joy, 30 August 2026: the settings links move into the sidebar, the pages use
 * the whole screen, and what somebody sees depends on what they may do.
 *
 * ⚠⚠ THE GATES HERE ARE NOT DECORATION AND THEY WERE WRONG BEFORE. The old
 * in-page nav showed "Reasons" to everybody, and the controller behind it
 * authorizes `inventory.catalog.manage` - so anybody without it followed a link
 * the interface offered them straight into a 403. A link nobody is allowed to
 * open is worse than a missing link: it reads as a broken system rather than as
 * a permission they do not hold.
 *
 * ⚠ Each flag below must match the permission its controller actually
 * authorizes, not the permission that sounds right. They were read off the
 * controllers, one at a time.
 */
export interface SettingsAccess {
    /** foundation.users.manage */
    canManageUsers?: boolean;
    /** foundation.organization.manage */
    canManageOrganization?: boolean;
    /** crm.settings.manage */
    canManageCrmSettings?: boolean;
    /** inventory.catalog.manage - what ReasonController really asks for. */
    canManageReasons?: boolean;
    /** couriers.settings.manage - CourierSettingsController. */
    canManageCourierSettings?: boolean;
    /** dashboard.view_all - DashboardAssignmentController. */
    canConfigureDashboards?: boolean;
}

export interface SettingsSection {
    label: string;
    description: string;
    items: NavItem[];
}

/**
 * Grouped, for a page that wants to lay the whole of settings out.
 *
 * ⚠ A section with no items is dropped rather than rendered empty. A heading
 * over nothing tells somebody they are missing something without saying what.
 */
export function settingsSections(access: SettingsAccess): SettingsSection[] {
    const sections: SettingsSection[] = [
        {
            label: 'You',
            description: 'Your own account, and how the system looks to you.',
            items: [
                { title: 'Profile', url: '/settings/profile', icon: User },
                { title: 'Password', url: '/settings/password', icon: KeyRound },
                { title: 'Appearance', url: '/settings/appearance', icon: Palette },
            ],
        },
        {
            label: 'People',
            description: 'Who can log in, and what each of them is allowed to do.',
            items: access.canManageUsers
                ? [
                      { title: 'Team', url: '/settings/team', icon: Users },
                      { title: 'Roles', url: '/settings/roles', icon: ShieldCheck },
                  ]
                : [],
        },
        {
            label: 'Dashboards',
            description: 'Which dashboard each role lands on.',
            items: access.canConfigureDashboards ? [{ title: 'Dashboards', url: '/settings/dashboards', icon: LayoutDashboard }] : [],
        },
        {
            label: 'Business',
            description: 'The company itself: its details, its departments and its offices.',
            items: access.canManageOrganization
                ? [
                      { title: 'Business', url: '/settings/business', icon: Building2 },
                      { title: 'Organization', url: '/settings/organization', icon: Network },
                      // ⚠ Same gate as the two above, and that is deliberate:
                      // PrinterProfileController authorizes
                      // foundation.organization.manage, not a permission of its
                      // own. Which printer stands at which counter is company
                      // configuration in the way an office is.
                      { title: 'Printers', url: '/settings/printers', icon: Printer },
                  ]
                : [],
        },
        {
            label: 'How it works',
            description: 'The lists and connections the rest of the system draws on.',
            items: [
                ...(access.canManageReasons ? [{ title: 'Reasons', url: '/settings/reasons', icon: ListChecks }] : []),
                // ⚠ Lived only under CRM before, so the one screen that holds
                // the SMS account credentials was not in settings at all.
                ...(access.canManageCrmSettings ? [{ title: 'Messaging', url: '/crm/settings/messaging', icon: Send }] : []),
                ...(access.canManageOrganization ? [{ title: 'AI providers', url: '/settings/ai-providers', icon: Bot }] : []),
                // Courier accounts, their credentials and webhooks (11 Oct 2026).
                ...(access.canManageCourierSettings ? [{ title: 'Couriers', url: '/settings/couriers', icon: Truck }] : []),
                // Which courier zone each thana is, and the courier price bands (slice 2).
                ...(access.canManageCourierSettings ? [{ title: 'Courier locations', url: '/settings/couriers/locations', icon: MapPin }] : []),
            ],
        },
    ];

    return sections.filter((section) => section.items.length > 0);
}

/** The same list flattened, for the sidebar. */
export function settingsNavItems(access: SettingsAccess): NavItem[] {
    return settingsSections(access).flatMap((section) => section.items);
}
