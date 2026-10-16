import { detailGroups } from '@/components/dashboards/arrangement';
import { CourierCards } from '@/components/dashboards/courier-cards';
import { BrandMenu, LiveIndicator, RangeMenu, sendJson, SoundSettingsPanel } from '@/components/dashboards/dashboard-toolbar';
import { GettingStartedCard, type GettingStartedData } from '@/components/dashboards/getting-started';
import { KeyNumbers } from '@/components/dashboards/key-numbers';
import { spanClasses } from '@/components/dashboards/layout';
import { NeedsToday } from '@/components/dashboards/needs-today';
import { EyeIcon, PrivacyProvider, usePrivacy } from '@/components/dashboards/privacy';
import { sectionAnchor, SectionChips } from '@/components/dashboards/section-chips';
import { Shortcuts, type Shortcut } from '@/components/dashboards/shortcuts';
import type { DashboardWidget, RangeState, SoundSettings } from '@/components/dashboards/types';
import { useDashboardChannel } from '@/components/dashboards/use-dashboard-channel';
import { useWidgetFeed, type FeedEntry } from '@/components/dashboards/use-widget-feed';
import { WidgetCard } from '@/components/dashboards/widget-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { dashboardSound } from '@/lib/dashboard-sound';
import { realtimeAvailable } from '@/lib/echo';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight, LayoutGrid, MoreHorizontal, RotateCcw, Settings2, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

interface SwitcherEntry {
    key: string;
    label: string;
    url: string;
    current: boolean;
}

interface Props {
    dashboard: { key: string; label: string; description: string };
    switcher: SwitcherEntry[];
    offers_classic: boolean;
    widgets: DashboardWidget[];
    hidden_widgets: { key: string; title: string }[];
    range: RangeState;
    range_presets: { value: string; label: string }[];
    range_error: string | null;
    // Joy, 11 Oct 2026: the Brand switcher (null = all brands).
    brand?: { selected: number | null; options: { id: number; name: string }[] };
    sound: SoundSettings;
    realtime: { enabled: boolean; business_channel: string; user_channel: string };
    can_configure: boolean;
    /** What is still missing before the figures mean anything (Owner, see-all only); null = nothing to show. */
    getting_started?: GettingStartedData | null;
    /** Round 3: the Shortcuts row, built and gated on the server (DashboardShortcuts). */
    shortcuts?: Shortcut[];
}

/**
 * The role dashboards (Joy, 11 Oct 2026): one page, five dashboards, each
 * person on the highest one their roles give them, Joy able to open them all.
 *
 * ⭐ Redesign round 3 (Joy, 16 Oct 2026): calm and role-aware. In order:
 * Getting started (one line), "Needs you today", Key numbers (plain cards, no
 * gradient band), Shortcuts (from the server), then the detail by area with
 * the courier widgets as three cards. Colour lives only in words and word
 * badges (Overdue red, Today amber, Waiting grey). Sounds, the classic
 * dashboard and the dashboard settings sit in a More menu. The layout above
 * draws the tabs Dashboard, Needs attention and Business health. The
 * Bismillah greeting is the layout's popup and is untouched.
 *
 * Still alive (Owner override of ChatGPT): widgets refresh the moment an event
 * names them over the socket, poll when the socket is down, and can play a
 * sound. Money is private by default: أمان until a card's eye, or the page's,
 * is tapped (privacy.tsx). No widget key, payload or permission changed.
 */
export default function RoleDashboard(props: Props) {
    const { auth } = usePage<SharedData>().props;
    const sensitive = useMemo(() => props.widgets.filter((w) => w.sensitive).map((w) => w.key), [props.widgets]);

    return (
        <PrivacyProvider userId={auth.user.id} sensitive={sensitive}>
            <DashboardPage {...props} />
        </PrivacyProvider>
    );
}

function greetingKey(): 'dash.greet.morning' | 'dash.greet.afternoon' | 'dash.greet.evening' {
    const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Asia/Dhaka' }).format(new Date()));

    return hour < 12 ? 'dash.greet.morning' : hour < 17 ? 'dash.greet.afternoon' : 'dash.greet.evening';
}

function DashboardPage(props: Props) {
    const { dashboard, switcher, offers_classic, widgets, hidden_widgets, range, range_presets, range_error, realtime, can_configure } = props;
    const brand = props.brand ?? { selected: null, options: [] };
    const brandName = brand.options.find((b) => b.id === brand.selected)?.name ?? null;
    const { auth } = usePage<SharedData>().props;
    const { t, tr } = useTranslation();
    const dashboardLabel = tr(`dash.name.${dashboard.key}`, dashboard.label);
    const rangeLabel = range.preset === 'custom' ? range.label : tr(`dash.preset.${range.preset}`, range.label);
    const [sound, setSound] = useState(props.sound);
    const [now, setNow] = useState(() => Date.now());
    const path = switcher.find((s) => s.current)?.url ?? '/dashboard';

    dashboardSound.settings = sound;
    // The library sounds the Owner chose per event (Settings > Sounds); null = built-in.
    dashboardSound.setUrls(usePage<SharedData>().props.popups?.sounds ?? {});

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 5000);

        return () => window.clearInterval(timer);
    }, []);

    const keys = useMemo(() => widgets.map((w) => w.key), [widgets]);
    const groups = useMemo(() => {
        const byKey = new Map(widgets.map((w) => [w.key, w]));

        return detailGroups(widgets, dashboard.key).map(({ group, keys }) => ({ group, widgets: keys.map((key) => byKey.get(key)!) }));
    }, [widgets, dashboard.key]);
    const socketWanted = realtime.enabled && realtimeAvailable();
    const [connected, setConnected] = useState(false);
    const { entries, refresh } = useWidgetFeed({ widgets, dashboard: dashboard.key, rangeQuery: range.query, live: connected });

    const status = useDashboardChannel({
        enabled: socketWanted,
        businessChannel: realtime.business_channel,
        userChannel: realtime.user_channel,
        userId: auth.user.id,
        widgetKeys: keys,
        onRefresh: refresh,
        onMessage: (message) => toast(message),
    });

    useEffect(() => {
        const isLive = status === 'connected';
        setConnected((was) => {
            // Back online after a gap: catch up on everything missed.
            if (isLive && !was) {
                refresh();
            }

            return isLive;
        });
    }, [status, refresh]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('dash.breadcrumb'), href: '/dashboard' }];

    // Only the hidden list is ever sent: order and sizes are the design's.
    const saveLayout = async (hidden: string[] | null) => {
        const url = `/dashboard/${dashboard.key}/layout`;
        const response = hidden === null ? await sendJson('DELETE', url, {}) : await sendJson('PUT', url, { hidden });

        if (response.ok) {
            router.reload({ only: ['widgets', 'hidden_widgets'] });
        } else {
            toast.error(t('dash.layout_not_saved'));
        }
    };

    const firstName = auth.user.name.split(/\s+/)[0] ?? auth.user.name;
    const today = new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'Asia/Dhaka',
    }).format(new Date(now));
    const brandAware = widgets.some((w) => w.brand_aware);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dash.head_title', { name: dashboardLabel })} />
            <div className="flex flex-1 flex-col gap-5 p-4">
                <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-xs">
                            {today} · {dashboardLabel}
                            {brandName ? ` · ${brandName}` : ''}
                        </p>
                        <h1 className="mt-0.5 text-xl font-semibold tracking-tight sm:text-2xl">{t(greetingKey(), { name: firstName })}</h1>
                        <p className="text-muted-foreground mt-0.5 max-w-[65ch] text-sm">
                            {tr(`dash.description.${dashboard.key}`, dashboard.description)}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <RangeMenu
                            range={range}
                            presets={range_presets}
                            path={path}
                            keep={brand.selected === null ? {} : { brand: String(brand.selected) }}
                        />
                        {brandAware && <BrandMenu brands={brand.options} selected={brand.selected} path={path} rangeQuery={range.query} />}
                        <LiveIndicator status={status} />
                        <RevealAllButton />
                        <CustomisePopover
                            widgets={widgets}
                            hidden={hidden_widgets}
                            onSave={(hidden) => saveLayout(hidden)}
                            onReset={() => saveLayout(null)}
                        />
                        <MoreMenu sound={sound} onSound={setSound} offersClassic={offers_classic} canConfigure={can_configure} />
                    </div>
                </header>

                {switcher.length > 1 && (
                    <nav aria-label={t('dash.switcher')} className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-xs">{t('dash.view_as')}</span>
                        <div className="bg-card inline-flex max-w-full flex-wrap gap-0.5 rounded-lg border p-0.5">
                            {switcher.map((entry) => (
                                <Link
                                    key={entry.key}
                                    href={entry.url}
                                    preserveScroll
                                    data={range.query}
                                    className={cn(
                                        'rounded-md px-2.5 py-1 text-xs transition-colors',
                                        entry.current
                                            ? 'bg-foreground text-background font-medium'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                    aria-current={entry.current ? 'page' : undefined}
                                >
                                    {tr(`dash.name.${entry.key}`, entry.label)}
                                </Link>
                            ))}
                        </div>
                    </nav>
                )}

                {range_error && (
                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <AlertTriangle className="size-4 shrink-0 text-amber-700 dark:text-amber-400" /> {range_error}{' '}
                        {t('dash.range_error_instead', { range: rangeLabel.toLowerCase() })}
                    </div>
                )}

                {props.getting_started && <GettingStartedCard data={props.getting_started} />}

                <NeedsToday dashboardKey={dashboard.key} widgets={widgets} entries={entries} />

                <KeyNumbers dashboardKey={dashboard.key} widgets={widgets} entries={entries} />

                <Shortcuts shortcuts={props.shortcuts ?? []} />

                {widgets.length === 0 ? (
                    <p className="text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                        {hidden_widgets.length > 0 ? t('dash.all_hidden') : t('dash.nothing_for_role')}
                    </p>
                ) : (
                    <>
                        <SectionChips groups={groups.map((g) => g.group)} />
                        {groups.map(({ group, widgets: members }) => (
                            <DetailSection
                                key={group}
                                group={group}
                                widgets={members}
                                entries={entries}
                                now={now}
                                brandName={brandName}
                                onRefresh={refresh}
                            />
                        ))}
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function DetailSection({
    group,
    widgets,
    entries,
    now,
    brandName,
    onRefresh,
}: {
    group: string;
    widgets: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
    now: number;
    brandName: string | null;
    onRefresh: (keys?: string[]) => void;
}) {
    const { tr } = useTranslation();
    const spans = useMemo(() => spanClasses(widgets), [widgets]);

    return (
        <section id={sectionAnchor(group)} data-dash-group={group} aria-labelledby={`${sectionAnchor(group)}-title`} className="scroll-mt-16">
            <h2 id={`${sectionAnchor(group)}-title`} className="text-muted-foreground mb-2 text-[13px] font-semibold tracking-[0.05em] uppercase">
                {tr(`dash.jump.${group}`, group)}
            </h2>
            {group === 'Couriers' ? (
                <CourierCards widgets={widgets} entries={entries} onRefresh={(keys) => onRefresh(keys)} />
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {widgets.map((widget, index) => (
                        <WidgetCard
                            key={widget.key}
                            widget={widget}
                            entry={entries[widget.key] ?? { result: widget.result, fetchedAt: now, loading: false, stale: false }}
                            index={index}
                            spanClass={spans[widget.key]}
                            now={now}
                            brandName={brandName}
                            onRefresh={() => onRefresh([widget.key])}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

/** The page's eye: shows or hides every private figure on the page at once. */
function RevealAllButton() {
    const { t } = useTranslation();
    const { sensitive, allRevealed, toggleAll } = usePrivacy();

    if (sensitive.length === 0) {
        return null;
    }

    const label = allRevealed ? t('dash.privacy.hide_all') : t('dash.privacy.show_all');

    return (
        <Button size="sm" variant="outline" className="size-8 p-0" onClick={toggleAll} aria-pressed={allRevealed} aria-label={label} title={label}>
            <EyeIcon open={allRevealed} className="size-4" />
        </Button>
    );
}

/**
 * Round 3 (16 Oct 2026): what used to crowd the toolbar, in one menu. Sounds
 * (unchanged, still on by default only for see-all holders), the classic
 * dashboard (kept for one month, then retired) and which dashboard each role
 * sees (dashboard.view_all).
 */
function MoreMenu({
    sound,
    onSound,
    offersClassic,
    canConfigure,
}: {
    sound: SoundSettings;
    onSound: (next: SoundSettings) => void;
    offersClassic: boolean;
    canConfigure: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [view, setView] = useState<'menu' | 'sounds'>('menu');
    const item = 'hover:bg-accent flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-left text-sm';

    return (
        <Popover
            open={open}
            onOpenChange={(value) => {
                setOpen(value);

                if (value) {
                    setView('menu');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button size="sm" variant="outline" className="h-8 gap-1.5" aria-label={t('dash.more')}>
                    <MoreHorizontal className="size-4" aria-hidden />
                    <span className="hidden sm:inline">{t('dash.more')}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent className={view === 'sounds' ? 'w-80' : 'w-64 p-1.5'} align="end">
                {view === 'sounds' ? (
                    <div>
                        <button
                            type="button"
                            onClick={() => setView('menu')}
                            className="text-muted-foreground hover:text-foreground mb-3 flex items-center gap-1 text-xs"
                        >
                            <ChevronLeft className="size-3.5" aria-hidden /> {t('dash.more.back')}
                        </button>
                        <SoundSettingsPanel settings={sound} onChange={onSound} />
                    </div>
                ) : (
                    <ul>
                        <li>
                            <button type="button" onClick={() => setView('sounds')} className={item}>
                                {sound.enabled ? <Volume2 className="size-4" aria-hidden /> : <VolumeX className="size-4" aria-hidden />}
                                <span className="flex-1">{t('dash.more.sounds')}</span>
                                <span className="text-muted-foreground text-xs">{sound.enabled ? t('dash.sound_on') : t('dash.sound_off')}</span>
                                <ChevronRight className="text-muted-foreground size-3.5" aria-hidden />
                            </button>
                        </li>
                        {offersClassic && (
                            <li>
                                <Link href="/dashboard/classic" className={item}>
                                    <LayoutGrid className="size-4" aria-hidden /> {t('dash.more.classic')}
                                </Link>
                            </li>
                        )}
                        {canConfigure && (
                            <li>
                                <Link href="/settings/dashboards" className={item}>
                                    <Settings2 className="size-4" aria-hidden /> {t('dash.more.settings')}
                                </Link>
                            </li>
                        )}
                    </ul>
                )}
            </PopoverContent>
        </Popover>
    );
}

/**
 * Show and hide widgets, nothing else (Joy, 14 Oct 2026: reordering broke the
 * designed layout). Presentation only: nothing here changes access.
 */
function CustomisePopover({
    widgets,
    hidden,
    onSave,
    onReset,
}: {
    widgets: DashboardWidget[];
    hidden: { key: string; title: string }[];
    onSave: (hidden: string[]) => void;
    onReset: () => void;
}) {
    const { t, tr } = useTranslation();
    const initial = [
        ...widgets.map((w) => ({ key: w.key, title: tr(`widget.${w.key}.title`, w.title), shown: true })),
        ...hidden.map((w) => ({ ...w, title: tr(`widget.${w.key}.title`, w.title), shown: false })),
    ];
    const [rows, setRows] = useState(initial);
    const [open, setOpen] = useState(false);

    return (
        <Popover
            open={open}
            onOpenChange={(value) => {
                setOpen(value);

                if (value) {
                    setRows(initial);
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button size="sm" variant="outline" className="h-8">
                    {t('dash.customise')}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80" align="end">
                <p className="mb-2 text-sm font-medium">{t('dash.your_widgets')}</p>
                <ul className="max-h-80 space-y-1 overflow-y-auto">
                    {rows.map((row) => (
                        <li key={row.key} className="flex items-center gap-2 py-0.5 text-sm">
                            <Checkbox
                                id={`show-${row.key}`}
                                checked={row.shown}
                                onCheckedChange={(v) => setRows(rows.map((r) => (r.key === row.key ? { ...r, shown: v === true } : r)))}
                            />
                            <label htmlFor={`show-${row.key}`} className="min-w-0 flex-1 truncate">
                                {row.title}
                            </label>
                        </li>
                    ))}
                </ul>
                <p className="text-muted-foreground mt-2 text-xs">{t('dash.hiding_note')}</p>
                <div className="mt-3 flex items-center justify-between gap-2">
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            onReset();
                            setOpen(false);
                        }}
                    >
                        <RotateCcw className="size-3.5" aria-hidden /> {t('dash.reset_default')}
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => {
                            onSave(rows.filter((r) => !r.shown).map((r) => r.key));
                            setOpen(false);
                        }}
                    >
                        {t('dash.save')}
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
