import { ACCOUNTS_SECTIONS } from '@/components/accounting/accounts-sections';
import { ASSETS_SECTIONS } from '@/components/assets/assets-sections';
import { CRM_SECTIONS } from '@/components/crm/crm-sections';
import { HRM_SECTIONS } from '@/components/hrm/hrm-sections';
import { INVENTORY_SECTIONS } from '@/components/inventory/inventory-sections';
import { type NavGroup } from '@/components/nav-groups';
import { PURCHASES_SECTIONS } from '@/components/purchasing/purchases-sections';
import { SALES_SECTIONS, SHOPS_SECTIONS } from '@/components/sales/sales-sections';
import { SETTINGS_SECTIONS } from '@/components/settings/settings-sections';
import { GROUP_SECTIONS, HOME_SECTIONS, WORKSPACE_SECTIONS } from '@/components/workspace/workspace-sections';
import { type TranslationKey } from '@/lib/i18n';
import { type NavItem, type SharedData } from '@/types';
import { type LucideIcon } from 'lucide-react';

/**
 * ⭐ A module in ONE sidebar group, its screens as TABS (Joy, 15 Oct 2026,
 * approved for HRM first, then Assets and Settings). Each module lists its
 * sections once; the sidebar shows a section when the person may open at
 * least one tab (linked to the first they may open) and the layout draws the
 * tab row from the address. Gates are the same shared permission flags the
 * old links used, so nobody gains or loses a screen.
 */

/** The translator of useTranslation(), passed in so this file stays free of hooks. */
export type Translate = (key: TranslationKey) => string;

export type SectionTab = {
    title: string;
    /** Translated title (Bangla for a person who chose it); `title` stays the English fallback. */
    titleKey?: TranslationKey;
    url: string;
    /** Further path prefixes that belong to this tab (a tab inside a tab, an old address). */
    also?: string[];
    can: (flags: SharedData) => boolean;
    /** A small count drawn after the title (failed jobs, approvals waiting). */
    badge?: (props: SharedData) => number | undefined;
    /** Words the header search matches besides the title: old link names, what people call it. */
    keywords?: string;
};

export type ModuleSection = {
    key: string;
    title: string;
    titleKey?: TranslationKey;
    icon: LucideIcon;
    tabs: SectionTab[];
    /**
     * The count on the section's SIDEBAR row. Explicit, never the sum of the
     * tab badges: My work would otherwise add the unread notifications the
     * bell already shows.
     */
    badge?: (props: SharedData) => number | undefined;
};

/**
 * Every module's sections. A module adds its list here, nowhere else.
 *
 * ⚠ ORDER DECIDES TIES (round 3, 16 Oct 2026). Five report addresses belong
 * to two modules (Receivables ageing and Customer statement to Sales and
 * Accounts, Payables ageing and Supplier statement to Purchases and Accounts,
 * Inventory valuation to Inventory and Accounts). On such an address the
 * first section here whose tab the person may open draws the tab row, so
 * Inventory, Purchases and Sales stay BEFORE Accounts, in the same order as
 * the sidebar groups (which decide the sidebar highlight the same way).
 */
export const ALL_SECTIONS: ModuleSection[] = [
    ...HOME_SECTIONS,
    ...WORKSPACE_SECTIONS,
    ...GROUP_SECTIONS,
    ...HRM_SECTIONS,
    ...ASSETS_SECTIONS,
    ...INVENTORY_SECTIONS,
    ...PURCHASES_SECTIONS,
    ...SALES_SECTIONS,
    ...SHOPS_SECTIONS,
    ...CRM_SECTIONS,
    ...ACCOUNTS_SECTIONS,
    ...SETTINGS_SECTIONS,
];

export function tabPrefixes(tab: SectionTab): string[] {
    return [tab.url, ...(tab.also ?? [])];
}

function matchLength(path: string, prefix: string): number {
    return path === prefix || path.startsWith(prefix + '/') ? prefix.length : -1;
}

/**
 * The section and tab a path belongs to.
 *
 * 1. The LONGEST matching prefix across every module wins (so
 *    /hrm/payroll/history is "Salary history", not "Runs").
 * 2. When several tabs match at exactly that length (a shared address), the
 *    first in registry order whose tab this person may open wins.
 * 3. When nobody's tab at that length may be opened, the first one is
 *    returned anyway and SectionTabs draws nothing. A SHORTER prefix never
 *    wins instead: skipping a closed tab would light /inventory's Overview on
 *    a stock request somebody may not approve.
 *
 * Without `flags` the first match at the longest length is returned.
 */
export function findSectionTab(
    path: string,
    flags?: SharedData,
    sections: ModuleSection[] = ALL_SECTIONS,
): { section: ModuleSection; tab: SectionTab } | null {
    let longest = -1;
    let ties: { section: ModuleSection; tab: SectionTab }[] = [];

    for (const section of sections) {
        for (const tab of section.tabs) {
            const length = Math.max(...tabPrefixes(tab).map((prefix) => matchLength(path, prefix)));
            if (length < 0 || length < longest) continue;
            if (length > longest) {
                longest = length;
                ties = [];
            }
            ties.push({ section, tab });
        }
    }

    if (ties.length === 0) return null;

    return (flags ? ties.find(({ tab }) => tab.can(flags)) : undefined) ?? ties[0];
}

export function visibleTabs(section: ModuleSection, flags: SharedData): SectionTab[] {
    return section.tabs.filter((tab) => tab.can(flags));
}

/**
 * The tab row SectionTabs draws on a page, or null for none: outside every
 * section, when the person may open fewer than two of its tabs (a single tab
 * is a heading, not a choice), or when the tab found is one they may not open.
 *
 * A page may name its tab with the `sectionTab` prop when its address cannot
 * (a voucher's own page lights Payment, Receipt, Contra or Journal by its
 * type). It counts only when it is a tab of the section found from the
 * address that the person may open; the address must still find the section
 * (give the tab an `also` prefix for it).
 */
export function sectionTabRow(
    path: string,
    props: SharedData,
    sections: ModuleSection[] = ALL_SECTIONS,
): { section: ModuleSection; tabs: SectionTab[]; current: SectionTab } | null {
    const found = findSectionTab(path, props, sections);
    if (!found) return null;

    const tabs = visibleTabs(found.section, props);
    const declared = typeof props.sectionTab === 'string' ? tabs.find((tab) => tab.url === props.sectionTab) : undefined;
    const current = declared ?? (tabs.includes(found.tab) ? found.tab : undefined);

    return tabs.length < 2 || !current ? null : { section: found.section, tabs, current };
}

/** A tab's title in the reader's language when it has a key, else its English title. */
export function tabTitle(tab: SectionTab, t?: Translate): string {
    return tab.titleKey && t ? t(tab.titleKey) : tab.title;
}

export function sectionTitle(section: ModuleSection, t?: Translate): string {
    return section.titleKey && t ? t(section.titleKey) : section.title;
}

/** The sidebar rows of a module: one per section the person may open. */
export function sectionNavItems(sections: ModuleSection[], props: SharedData, t?: Translate): NavItem[] {
    return sections.flatMap((section) => {
        const tabs = visibleTabs(section, props);
        if (tabs.length === 0) return [];
        return [
            {
                title: sectionTitle(section, t),
                url: tabs[0].url,
                icon: section.icon,
                match: tabs.flatMap(tabPrefixes),
                badge: section.badge?.(props),
            },
        ];
    });
}

/**
 * Every TAB the person may open, for the header search only (round 3): one
 * never-drawn group per section, labelled "<Module> › <Section>". The sidebar
 * shows one row per section, so without these a tab ("Quotations", "Low
 * stock", "Trial balance") could not be found by name.
 *
 * ⚠ These groups go LAST in useNavGroups: the sidebar highlight also reads
 * them, and on an equal prefix the earlier, drawn item must win.
 */
export function sectionSearchGroups(modules: [label: string, sections: ModuleSection[]][], props: SharedData, t?: Translate): NavGroup[] {
    return modules.flatMap(([label, sections]) =>
        sections.flatMap((section) => {
            const items = visibleTabs(section, props).map((tab) => ({
                title: tabTitle(tab, t),
                url: tab.url,
                icon: section.icon,
                keywords: tab.keywords,
            }));
            return items.length === 0 ? [] : [{ label: `${label} › ${sectionTitle(section, t)}`, searchOnly: true, items }];
        }),
    );
}
