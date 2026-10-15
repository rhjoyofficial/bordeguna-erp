import { HRM_SECTIONS } from '@/components/hrm/hrm-sections';
import { SETTINGS_SECTIONS } from '@/components/settings/settings-sections';
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

export type SectionTab = {
    title: string;
    url: string;
    /** Further path prefixes that belong to this tab (a tab inside a tab, an old address). */
    also?: string[];
    can: (flags: SharedData) => boolean;
    /** A small count drawn after the title (failed jobs, approvals waiting). */
    badge?: (props: SharedData) => number | undefined;
};

export type ModuleSection = {
    key: string;
    title: string;
    icon: LucideIcon;
    tabs: SectionTab[];
};

/** Every module's sections. A module adds its list here, nowhere else. */
// ⚠ Settings is registered for its TAB ROW only: its sidebar presence is one
// link to the home, not a row per section (app-sidebar). The home itself has
// no entry here, so no tab row is drawn above it.
export const ALL_SECTIONS: ModuleSection[] = [...HRM_SECTIONS, ...SETTINGS_SECTIONS];

export function tabPrefixes(tab: SectionTab): string[] {
    return [tab.url, ...(tab.also ?? [])];
}

function matchLength(path: string, prefix: string): number {
    return path === prefix || path.startsWith(prefix + '/') ? prefix.length : -1;
}

/**
 * The section and tab a path belongs to, by the LONGEST matching prefix across
 * every module (so /hrm/payroll/history is "Salary history", not "Runs").
 */
export function findSectionTab(path: string, sections: ModuleSection[] = ALL_SECTIONS): { section: ModuleSection; tab: SectionTab } | null {
    let best: { section: ModuleSection; tab: SectionTab; length: number } | null = null;
    for (const section of sections) {
        for (const tab of section.tabs) {
            for (const prefix of tabPrefixes(tab)) {
                const length = matchLength(path, prefix);
                if (length > (best?.length ?? -1)) best = { section, tab, length };
            }
        }
    }
    return best ? { section: best.section, tab: best.tab } : null;
}

export function visibleTabs(section: ModuleSection, flags: SharedData): SectionTab[] {
    return section.tabs.filter((tab) => tab.can(flags));
}

/** The sidebar rows of a module: one per section the person may open. */
export function sectionNavItems(sections: ModuleSection[], props: SharedData): NavItem[] {
    return sections.flatMap((section) => {
        const tabs = visibleTabs(section, props);
        if (tabs.length === 0) return [];
        return [{ title: section.title, url: tabs[0].url, icon: section.icon, match: tabs.flatMap(tabPrefixes) }];
    });
}
