import { useTranslation } from '@/lib/i18n';
import { sectionTabRow, sectionTitle, tabTitle } from '@/lib/sections';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/**
 * The tab row of a module section, drawn by the app layout above every page
 * (Joy, 15 Oct 2026). A page never renders it itself: the section is read from
 * the address, so a new screen only needs an entry in its module's sections.
 * Nothing is drawn outside a sectioned module, or when the person may open
 * just one tab of the section (a single tab is a heading, not a choice).
 *
 * On a shared address the first module whose tab this person may open owns
 * the row, and a page may name its own tab with the `sectionTab` prop when
 * its address cannot (lib/sections, sectionTabRow; round 3, 16 Oct 2026).
 * Titles with a `titleKey` are drawn in the reader's language.
 */
export function SectionTabs() {
    const page = usePage<SharedData>();
    const { t } = useTranslation();
    const row = sectionTabRow(page.url.split('?')[0], page.props);

    if (!row) return null;

    return (
        <nav
            aria-label={`${sectionTitle(row.section, t)} sections`}
            className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto border-b px-4"
        >
            <ul className="flex min-w-max gap-5">
                {row.tabs.map((tab) => {
                    const active = tab === row.current;
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
                                {tabTitle(tab, t)}
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
