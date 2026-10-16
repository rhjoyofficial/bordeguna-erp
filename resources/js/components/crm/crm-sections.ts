import { type ModuleSection } from '@/lib/sections';
import { type SharedData } from '@/types';
import { CalendarCheck, Contact, LayoutDashboard, SlidersHorizontal, UserPlus } from 'lucide-react';

/**
 * ⭐ CRM in ONE sidebar group of five sections (redesign round 3, 16 Oct
 * 2026). It was ten links; every screen keeps its address and becomes a TAB of
 * its section, drawn by SectionTabs above the page, shown only to somebody who
 * may open it.
 *
 * The gates are the shared permission flags the old links used, one for one
 * (verified against app/Modules/Crm/routes/web.php):
 *
 *   canViewCrm            crm.leads.view        Overview, Reports, Follow-ups, Tickets, Leads, Pipeline
 *   canViewContacts       crm.contacts.view     Phone book
 *   canSendCampaigns      crm.campaigns.send    SMS, Email
 *   canManageCrmSettings  crm.settings.manage   Lists & sources (was "CRM settings"), Cadences
 *
 * ⚠ The group shows when ANY section is visible (app-sidebar), not on
 * canViewCrm || canViewContacts as before: somebody holding only
 * crm.campaigns.send had no CRM group at all and could not reach SMS or Email.
 *
 * ⚠ A lead's own page (/crm/lead/{id}/timeline) lights Leads. A customer's
 * history (/crm/customer/...) belongs to Sales › Customers, where it is opened.
 * /crm/settings/messaging stays Settings › SMS gateway: the longer prefix wins.
 *
 * ⚠ Badges (the Failed jobs pattern): follow-ups due today or overdue in the
 * reader's own scope, and tickets open past their time. The Follow-ups row in
 * the sidebar carries both.
 */

type Flags = SharedData;

const crm = (f: Flags) => f.canViewCrm;
const setup = (f: Flags) => f.canManageCrmSettings;

const count = (value: number | null | undefined) => (value ? value : undefined);

export const CRM_SECTIONS: ModuleSection[] = [
    {
        key: 'crm-overview',
        title: 'Overview',
        icon: LayoutDashboard,
        tabs: [
            { title: 'Overview', url: '/crm/overview', can: crm, keywords: 'crm home dashboard' },
            { title: 'Reports', url: '/crm/reports', can: crm, keywords: 'crm reports funnel sources demand' },
        ],
    },
    {
        key: 'crm-followups',
        title: 'Follow-ups',
        icon: CalendarCheck,
        badge: (p) => count((p.crmFollowUpsDueCount ?? 0) + (p.crmTicketsOverdueCount ?? 0)),
        tabs: [
            { title: 'Follow-ups', url: '/crm/queue', can: crm, badge: (p) => count(p.crmFollowUpsDueCount), keywords: 'queue tasks work queue' },
            { title: 'Tickets', url: '/crm/tickets', can: crm, badge: (p) => count(p.crmTicketsOverdueCount), keywords: 'complaints support' },
        ],
    },
    {
        key: 'crm-leads',
        title: 'Leads',
        icon: UserPlus,
        tabs: [
            { title: 'Leads', url: '/crm/leads', also: ['/crm/lead'], can: crm, keywords: 'shops distributors suppliers prospects' },
            { title: 'Pipeline', url: '/crm/pipeline', can: crm, keywords: 'deals opportunities board' },
        ],
    },
    {
        key: 'crm-messages',
        title: 'Phone book & messages',
        icon: Contact,
        tabs: [
            { title: 'Phone book', url: '/crm/contacts', can: (f) => f.canViewContacts, keywords: 'contacts groups' },
            { title: 'SMS', url: '/crm/messages/sms', can: (f) => f.canSendCampaigns, keywords: 'text messages campaign send' },
            { title: 'Email', url: '/crm/messages/email', can: (f) => f.canSendCampaigns, keywords: 'mail campaign send' },
        ],
    },
    {
        key: 'crm-setup',
        title: 'Setup',
        icon: SlidersHorizontal,
        tabs: [
            { title: 'Lists & sources', url: '/crm/settings', can: setup, keywords: 'crm settings lead sources campaigns lost reasons shop types' },
            { title: 'Cadences', url: '/crm/cadences', can: setup, keywords: 'follow up rhythm' },
        ],
    },
];
