import { type ModuleSection, type SectionTab } from '@/lib/sections';
import { type SharedData } from '@/types';
import { Bot, Building2, CalendarClock, ListChecks, ServerCrash, Truck, UserCircle, Users } from 'lucide-react';

/**
 * ⭐ Settings in ONE sidebar link (Joy, 15 Oct 2026, after HRM). Twenty links
 * in a "Settings" group became one link to a searchable home of eight areas;
 * each screen that used to be a link is a TAB of its area, drawn by the layout
 * from the address (lib/sections). Every address and every gate is unchanged.
 *
 * ⚠⚠ THE GATES HERE ARE NOT DECORATION AND THEY WERE WRONG ONCE. The settings
 * nav of August 2026 showed "Reasons" to everybody, and the controller behind
 * it authorizes `inventory.catalog.manage`, so anybody without it followed a
 * link the interface offered them straight into a 403. A link nobody may open
 * is worse than a missing link: it reads as a broken system rather than as a
 * permission they do not hold.
 *
 * ⚠ Each flag must match the permission its controller actually authorizes,
 * not the permission that sounds right. They were read off the controllers
 * and the route middleware one at a time, and SettingsHomeTest opens every
 * screen with exactly that permission and with none to prove each pair.
 *
 * The flags, with the permission each one really is:
 *   canManageUsers           foundation.users.manage        Team, Roles
 *   canManageOrganization    foundation.organization.manage Business, Organization, Printers, AI providers
 *   canConfigureDashboards   dashboard.view_all             Dashboards
 *   canManageCourierSettings couriers.settings.manage       Courier accounts, Courier locations
 *   canProposeRateCards      couriers.ratecards.propose     Rate cards (Managers propose, the Owner approves)
 *   canManageSchedule        schedule.manage                Work schedule (Owner and Manager)
 *   canManagePopups          system.popups.manage           Popups, Sounds (route middleware)
 *   canManageRelax           relax.manage                   Relax zone (route middleware)
 *   canManageReasons         inventory.catalog.manage       Stock reasons
 *   canManageCrmSettings     crm.settings.manage            SMS gateway (route middleware)
 *   canManageJobs            system.jobs.manage             Failed jobs (route middleware)
 */

type Flags = SharedData;

/** A settings area: a section of the shared registry plus what the home page says about it. */
export type SettingsArea = ModuleSection & {
    /** One line under the title on the home card. */
    description: string;
    /** Words the search box matches besides the titles: what people call these things. */
    keywords: string;
    /** The home groups its cards; "you" is the one personal group. */
    group: SettingsGroupKey;
    /** Changes the ERP for this person only. Every other area is company-wide. */
    personal?: boolean;
};

export type SettingsGroupKey = 'you' | 'company' | 'operations' | 'connections' | 'system';

export const SETTINGS_GROUPS: { key: SettingsGroupKey; title: string; description: string }[] = [
    { key: 'you', title: 'Your account', description: 'Personal. Changes how the ERP works for you only.' },
    { key: 'company', title: 'Company', description: 'Company-wide. Changes here affect everybody.' },
    { key: 'operations', title: 'Daily operations', description: 'Company-wide. How the work of the day runs.' },
    { key: 'connections', title: 'Connections', description: 'Company-wide. Outside services the ERP signs in to.' },
    { key: 'system', title: 'System', description: 'Company-wide. Health of the ERP itself.' },
];

const everyone = () => true;

export const SETTINGS_SECTIONS: SettingsArea[] = [
    {
        key: 'account',
        title: 'My account',
        icon: UserCircle,
        group: 'you',
        personal: true,
        description: 'Your name and photo, your password, language, greeting, alert sounds and colours.',
        keywords: 'profile photo picture password language bangla english greeting bismillah sound alerts theme dark light colour accent appearance',
        tabs: [
            { title: 'Profile', url: '/settings/profile', can: everyone },
            { title: 'Password', url: '/settings/password', can: everyone },
            { title: 'Appearance', url: '/settings/appearance', can: everyone },
        ],
    },
    {
        key: 'company',
        title: 'Company profile',
        icon: Building2,
        group: 'company',
        description:
            'Name, logo, address, licence, TIN and signatory on documents; offices, departments and designations; receipt paper per counter.',
        keywords:
            'business logo address trade licence tin signatory letterhead font organization office geofence department section designation printer receipt paper',
        tabs: [
            { title: 'Business', url: '/settings/business', can: (f) => f.canManageOrganization },
            { title: 'Organization', url: '/settings/organization', can: (f) => f.canManageOrganization },
            // ⚠ Same gate as the two above, and that is deliberate:
            // PrinterProfileController authorizes foundation.organization.manage,
            // not a permission of its own. Which printer stands at which counter
            // is company configuration in the way an office is.
            { title: 'Printers', url: '/settings/printers', can: (f) => f.canManageOrganization },
        ],
    },
    {
        key: 'people',
        title: 'People & access',
        icon: Users,
        group: 'company',
        description: 'Who can sign in, what each role may do, and which dashboard each role lands on.',
        keywords: 'team members users staff login password link deactivate roles permissions dashboards landing',
        tabs: [
            { title: 'Team', url: '/settings/team', can: (f) => f.canManageUsers },
            { title: 'Roles', url: '/settings/roles', can: (f) => f.canManageUsers },
            { title: 'Dashboards', url: '/settings/dashboards', can: (f) => f.canConfigureDashboards },
        ],
    },
    {
        key: 'couriers',
        title: 'Couriers',
        icon: Truck,
        group: 'operations',
        description: 'Courier accounts, pickup stores and webhooks; which zone each thana is; what each courier charges.',
        keywords: 'courier pathao carrybee account credentials webhook pickup store cod locations thana zone bands rate cards price',
        tabs: [
            // Courier accounts, their credentials and webhooks (11 Oct 2026).
            { title: 'Accounts', url: '/settings/couriers', can: (f) => f.canManageCourierSettings },
            // Which courier zone each thana is, and the courier price bands (slice 2).
            { title: 'Locations', url: '/settings/couriers/locations', can: (f) => f.canManageCourierSettings },
            // What each courier charges, by version (slice 5). Managers propose, the Owner approves.
            { title: 'Rate cards', url: '/settings/couriers/rate-cards', can: (f) => f.canProposeRateCards },
        ],
    },
    {
        key: 'workday',
        title: 'Workday & alerts',
        icon: CalendarClock,
        group: 'operations',
        description: 'The slots of the working day, what pops up at sign-in, which sound each event plays, and the Relax zone.',
        keywords: 'work schedule slots alerts popups sign in sounds library events relax zone break',
        tabs: [
            // The slots of the working day and their alerts (14 Oct 2026). Owner and Manager.
            { title: 'Work schedule', url: '/settings/work-schedule', can: (f) => !!f.canManageSchedule },
            // The popup engine and the sound library (14 Oct 2026). Group-wide, Owner only.
            { title: 'Popups', url: '/settings/popups', can: (f) => !!f.canManagePopups },
            { title: 'Sounds', url: '/settings/sounds', can: (f) => !!f.canManagePopups },
            // What the Relax zone plays (14 Oct 2026). Owner and Manager.
            { title: 'Relax zone', url: '/settings/relax', can: (f) => !!f.canManageRelax },
        ],
    },
    {
        key: 'reasons',
        title: 'Stock reasons',
        icon: ListChecks,
        group: 'operations',
        description: 'The pick-lists people choose from when they adjust or transfer stock.',
        keywords: 'reasons stock adjustment transfer inventory pick list',
        tabs: [{ title: 'Reasons', url: '/settings/reasons', can: (f) => f.canManageReasons }],
    },
    {
        key: 'messaging',
        title: 'Messaging & AI',
        icon: Bot,
        group: 'connections',
        description: 'The SMS gateway the ERP sends from, with a test send; the AI assistant, its name, picture, provider keys and monthly limits.',
        keywords: 'sms gateway messaging bangla test send balance ai assistant provider keys model limits',
        tabs: [
            // ⚠ Lived only under CRM before 30 Aug 2026, so the one screen that
            // holds the SMS account credentials was not in settings at all.
            { title: 'SMS gateway', url: '/crm/settings/messaging', can: (f) => f.canManageCrmSettings },
            { title: 'AI providers', url: '/settings/ai-providers', can: (f) => f.canManageOrganization },
        ],
    },
    {
        key: 'jobs',
        title: 'Failed jobs',
        icon: ServerCrash,
        group: 'system',
        description: 'Background jobs that failed and will not run again on their own. Retry or forget each one.',
        keywords: 'failed jobs queue retry forget background errors',
        tabs: [
            // Background jobs that failed (11 Oct 2026). System-wide, Owner only.
            // The count is shared only with people holding the permission (failedJobsCount).
            { title: 'Failed jobs', url: '/settings/failed-jobs', can: (f) => !!f.canManageJobs, badge: (p) => p.failedJobsCount ?? undefined },
        ],
    },
];

/**
 * Settings that stay in their own module (the CRM, Inventory and Accounts
 * groups keep their links) and are listed on the home only so the search box
 * finds them. Each carries the gate its own screen checks.
 */
export type SettingsShortcut = SectionTab & {
    /** The module group it lives in, shown after the title. */
    module: string;
    keywords: string;
};

export const SETTINGS_SHORTCUTS: SettingsShortcut[] = [
    { title: 'CRM settings', url: '/crm/settings', module: 'CRM', keywords: 'lead sources campaigns crm lists', can: (f) => f.canManageCrmSettings },
    { title: 'Cadences', url: '/crm/cadences', module: 'CRM', keywords: 'cadences follow up crm', can: (f) => f.canManageCrmSettings },
    // inventory.stock.approve, shared by the Inventory module itself (use-inventory-abilities).
    {
        title: 'Inventory settings',
        url: '/inventory/settings',
        module: 'Inventory',
        keywords: 'inventory approval limit corrections stock',
        can: (f) => !!f.inventoryAbilities?.approveStock,
    },
    { title: 'VAT settings', url: '/accounting/vat-settings', module: 'Accounts', keywords: 'vat tax', can: (f) => f.canManageVat },
    {
        title: 'Finance policy',
        url: '/accounting/finance-policy',
        module: 'Accounts',
        keywords: 'finance policy discount caps counter',
        can: (f) => f.canManageFinancePolicy,
    },
];

/** The shortcuts this person may open. */
export function visibleShortcuts(flags: Flags): SettingsShortcut[] {
    return SETTINGS_SHORTCUTS.filter((shortcut) => shortcut.can(flags));
}
