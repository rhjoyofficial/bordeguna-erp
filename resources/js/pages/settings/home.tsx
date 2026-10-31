import { SETTINGS_GROUPS, SETTINGS_SECTIONS, visibleShortcuts, type SettingsArea } from '@/components/settings/settings-sections';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { visibleTabs } from '@/lib/sections';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

/**
 * The Settings home (Joy, 15 Oct 2026): every area this person may set up,
 * as grouped cards behind ONE sidebar link, with a search box that filters as
 * they type. "Your account" comes first and is the one personal group; every
 * other group says it is company-wide.
 *
 * ⚠ A card shows only when at least one of its tabs is allowed, and lists
 * only the tabs that are; the gates are the ones in settings-sections.ts,
 * read off the controllers. The same list draws the tab row above each
 * screen, so what the home offers and what the tab row offers can never
 * differ. Nothing here is a security boundary: every route authorizes itself.
 *
 * ⚠ The failed-jobs count is the one coloured thing on the page, and it is red
 * because it means something is broken (feedback_dashboard_colour_taste).
 */

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Settings', href: '/settings' }];

type VisibleArea = {
    area: SettingsArea;
    tabs: SettingsArea['tabs'];
    badge: number | undefined;
};

function words(q: string): string[] {
    return q.trim().toLowerCase().split(/\s+/).filter(Boolean);
}

function matches(haystack: string, terms: string[]): boolean {
    const text = haystack.toLowerCase();
    return terms.every((term) => text.includes(term));
}

export default function SettingsHome() {
    const props = usePage<SharedData>().props;
    const [query, setQuery] = useState('');
    const terms = words(query);

    // Every area this person may open, before the search narrows it.
    const allowed = useMemo<VisibleArea[]>(
        () =>
            SETTINGS_SECTIONS.map((area) => {
                const tabs = visibleTabs(area, props);
                const badge = tabs.map((tab) => tab.badge?.(props)).find((count) => !!count);
                return { area, tabs, badge };
            }).filter(({ tabs }) => tabs.length > 0),
        [props],
    );
    const shortcuts = useMemo(() => visibleShortcuts(props), [props]);

    const found = allowed.filter(({ area, tabs }) =>
        matches(`${area.title} ${area.description} ${area.keywords} ${tabs.map((tab) => tab.title).join(' ')}`, terms),
    );
    const foundShortcuts = shortcuts.filter((shortcut) => matches(`${shortcut.title} ${shortcut.module} ${shortcut.keywords}`, terms));
    const groups = SETTINGS_GROUPS.map((group) => ({ ...group, areas: found.filter(({ area }) => area.group === group.key) })).filter(
        (group) => group.areas.length > 0,
    );

    const screens = allowed.reduce((sum, { tabs }) => sum + tabs.length, 0);
    const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;
    const countLine =
        terms.length > 0
            ? `${plural(found.length, 'area')} match "${query.trim()}".`
            : `Everything you may set up, in ${plural(allowed.length, 'area')} (${plural(screens, 'screen')}). You only see what you are allowed to change.`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Settings" />
            <div className="flex w-full flex-col gap-6 p-4">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
                    <p className="text-muted-foreground text-sm">{countLine}</p>
                </header>

                {/* As wide as a sentence, not the window: a search box is a short field. */}
                <label className="relative block max-w-xl">
                    <span className="sr-only">Search settings</span>
                    <Search
                        className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                        aria-hidden="true"
                    />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search settings: printer, SMS, courier, password, popup"
                        className="h-10 pl-9"
                        autoComplete="off"
                    />
                </label>

                {found.length === 0 && foundShortcuts.length === 0 && (
                    <div className="bg-card text-card-foreground rounded-lg border p-7 text-center shadow-xs">
                        <p className="text-sm font-medium">Nothing in settings matches "{query.trim()}".</p>
                        <p className="text-muted-foreground mt-1 text-xs">Try a word like printer, SMS, courier or password.</p>
                    </div>
                )}

                {groups.map((group) => (
                    <section key={group.key} className="space-y-3" aria-labelledby={`settings-group-${group.key}`}>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 id={`settings-group-${group.key}`} className="text-[15px] font-semibold">
                                {group.title}
                            </h2>
                            <p className="text-muted-foreground text-xs">{group.description}</p>
                        </div>
                        <div className="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-3">
                            {group.areas.map((visible) => (
                                <AreaCard key={visible.area.key} visible={visible} terms={terms} />
                            ))}
                        </div>
                    </section>
                ))}

                {foundShortcuts.length > 0 && (
                    <section className="space-y-3 border-t pt-6" aria-labelledby="settings-shortcuts">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 id="settings-shortcuts" className="text-[15px] font-semibold">
                                Set up inside their own module
                            </h2>
                        </div>
                        <ul className="flex flex-wrap gap-2">
                            {foundShortcuts.map((shortcut) => (
                                <li key={shortcut.url}>
                                    <Link
                                        href={shortcut.url}
                                        prefetch
                                        className="bg-card hover:border-primary inline-flex min-h-9 items-center gap-1.5 rounded-md border px-3 text-[13px] transition-colors"
                                    >
                                        {shortcut.title}
                                        <span className="text-muted-foreground text-xs">in {shortcut.module}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}

function AreaCard({ visible: { area, tabs, badge }, terms }: { visible: VisibleArea; terms: string[] }) {
    const Icon = area.icon;

    return (
        <article className="bg-card text-card-foreground hover:border-primary/40 flex h-full flex-col gap-3 rounded-lg border p-4 shadow-xs transition-colors">
            <Link href={tabs[0].url} prefetch className="flex items-start gap-3">
                <span className="bg-primary/10 text-primary grid size-9 shrink-0 place-items-center rounded-md" aria-hidden="true">
                    <Icon className="size-[18px]" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-[14.5px] font-semibold">{area.title}</span>
                        {area.personal && <span className="text-muted-foreground rounded-full border px-2 text-[11px]">Personal</span>}
                        {!!badge && (
                            <span className="bg-destructive/10 text-destructive rounded-full px-2 text-[11.5px] font-semibold">{badge} failed</span>
                        )}
                    </span>
                    <span className="text-muted-foreground mt-0.5 block text-xs leading-relaxed">{area.description}</span>
                </span>
                <ChevronRight className="text-muted-foreground mt-1 size-4 shrink-0" aria-hidden="true" />
            </Link>
            {tabs.length > 1 && (
                <ul className="flex flex-wrap gap-1.5 pl-12">
                    {tabs.map((tab) => {
                        const hit = terms.length > 0 && matches(tab.title, terms);
                        return (
                            <li key={tab.url}>
                                <Link
                                    href={tab.url}
                                    prefetch
                                    className={cn(
                                        'inline-flex min-h-6.5 items-center rounded-full border px-2.5 text-xs transition-colors',
                                        hit
                                            ? 'border-primary bg-primary/10 text-primary font-medium'
                                            : 'bg-background hover:border-primary hover:text-primary',
                                    )}
                                >
                                    {tab.title}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </article>
    );
}
