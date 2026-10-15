import { findHrmTab, visibleTabs } from '@/components/hrm/hrm-sections';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/**
 * The tab row of an HRM section, drawn by the app layout above every HRM page
 * (Joy, 15 Oct 2026). A page never renders it itself: the section is read
 * from the address, so a new HRM screen only needs an entry in
 * HRM_SECTIONS. Nothing is drawn outside HRM, or when the user may open just
 * one tab of the section (a single tab is a heading, not a choice).
 */
export function HrmSectionTabs() {
    const page = usePage<SharedData>();
    const path = page.url.split('?')[0];
    const found = findHrmTab(path);

    if (!found) return null;

    const tabs = visibleTabs(found.section, page.props);

    if (tabs.length < 2) return null;

    return (
        <nav
            aria-label={`${found.section.title} sections`}
            className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto border-b px-4"
        >
            <ul className="flex min-w-max gap-5">
                {tabs.map((tab) => {
                    const active = tab === found.tab;
                    return (
                        <li key={tab.url}>
                            <Link
                                href={tab.url}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px inline-flex h-11 items-center border-b-2 text-[13.5px] whitespace-nowrap transition-colors',
                                    active
                                        ? 'border-primary text-foreground font-semibold'
                                        : 'text-muted-foreground hover:text-foreground border-transparent',
                                )}
                            >
                                {tab.title}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
