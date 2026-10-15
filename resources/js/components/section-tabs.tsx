import { findSectionTab, visibleTabs } from '@/lib/sections';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/**
 * The tab row of a module section, drawn by the app layout above every page
 * (Joy, 15 Oct 2026). A page never renders it itself: the section is read from
 * the address, so a new screen only needs an entry in its module's sections.
 * Nothing is drawn outside a sectioned module, or when the person may open
 * just one tab of the section (a single tab is a heading, not a choice).
 */
export function SectionTabs() {
    const page = usePage<SharedData>();
    const path = page.url.split('?')[0];
    const found = findSectionTab(path);

    if (!found) return null;

    const tabs = visibleTabs(found.section, page.props);

    // A tab this person may not open is not their section: the Owner reads
    // Inventory valuation under Accounts, not under Inventory's Overview.
    if (tabs.length < 2 || !tabs.includes(found.tab)) return null;

    return (
        <nav
            aria-label={`${found.section.title} sections`}
            className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto border-b px-4"
        >
            <ul className="flex min-w-max gap-5">
                {tabs.map((tab) => {
                    const active = tab === found.tab;
                    const badge = tab.badge?.(page.props);
                    return (
                        <li key={tab.url}>
                            <Link
                                href={tab.url}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px inline-flex h-11 items-center gap-1.5 border-b-2 text-[13.5px] whitespace-nowrap transition-colors',
                                    active
                                        ? 'border-primary text-foreground font-semibold'
                                        : 'text-muted-foreground hover:text-foreground border-transparent',
                                )}
                            >
                                {tab.title}
                                {!!badge && (
                                    <span className="bg-destructive/10 text-destructive rounded-full px-1.5 text-[11px] font-medium">{badge}</span>
                                )}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
