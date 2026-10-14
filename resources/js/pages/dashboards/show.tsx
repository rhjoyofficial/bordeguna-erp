import { BrandBar, LiveIndicator, RangeBar, sendJson, SoundSettingsPopover } from '@/components/dashboards/dashboard-toolbar';
import { GettingStartedCard, type GettingStartedData } from '@/components/dashboards/getting-started';
import { HeroBand } from '@/components/dashboards/hero-band';
import { spanClasses } from '@/components/dashboards/layout';
import { EyeIcon, PrivacyProvider, usePrivacy } from '@/components/dashboards/privacy';
import { SectionChips } from '@/components/dashboards/section-chips';
import type { DashboardWidget, RangeState, SoundSettings } from '@/components/dashboards/types';
import { useDashboardChannel } from '@/components/dashboards/use-dashboard-channel';
import { useWidgetFeed } from '@/components/dashboards/use-widget-feed';
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
import { AlertTriangle, LayoutGrid, RotateCcw, Settings2 } from 'lucide-react';
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
}

/**
 * The role dashboards (Joy, 11 Oct 2026): one page, five dashboards, each
 * person on the highest one their roles give them, Joy able to open them all.
 *
 * Alive on purpose (Owner override of ChatGPT): widgets refresh the moment an
 * event names them over the socket, poll on their own interval when the socket
 * is down, count their numbers to new values, and can play a sound. Motion
 * never delays a figure and stands down under prefers-reduced-motion.
 *
 * 14 Oct 2026 (Joy): the layout is the design's. Customise only hides and
 * shows (the reorder arrows and the per-card hide button are gone; the server
 * ignores any stored order), and "Reset to default" forgets the hidden list.
 * Money is private by default: أمان until a card's eye, or the toolbar's,
 * is tapped (privacy.tsx).
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
    const spans = useMemo(() => spanClasses(widgets), [widgets]);
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dash.head_title', { name: dashboardLabel })} />
            <div className="flex flex-1 flex-col gap-4 p-4 sm:gap-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {switcher.length > 1 || offers_classic ? (
                        <nav
                            aria-label={t('dash.switcher')}
                            className="bg-muted/70 ring-border/60 flex max-w-full flex-wrap gap-1 rounded-2xl p-1 ring-1 sm:rounded-full"
                        >
                            {switcher.map((entry) => (
                                <Link
                                    key={entry.key}
                                    href={entry.url}
                                    preserveScroll
                                    data={range.query}
                                    className={cn(
                                        'rounded-full px-3 py-1 text-sm transition-colors',
                                        entry.current
                                            ? 'bg-primary text-primary-foreground font-medium shadow-sm'
                                            : 'text-muted-foreground hover:bg-background hover:text-foreground',
                                    )}
                                    aria-current={entry.current ? 'page' : undefined}
                                >
                                    {tr(`dash.name.${entry.key}`, entry.label)}
                                </Link>
                            ))}
                            {offers_classic && (
                                <Link
                                    href="/dashboard/classic"
                                    className="text-muted-foreground hover:bg-background hover:text-foreground flex items-center gap-1.5 rounded-full px-3 py-1 text-sm"
                                >
                                    <LayoutGrid className="size-3.5" /> {t('dash.classic')}
                                </Link>
                            )}
                        </nav>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <LiveIndicator status={status} />
                        <RevealAllButton />
                        <SoundSettingsPopover settings={sound} onChange={setSound} />
                        <CustomisePopover
                            widgets={widgets}
                            hidden={hidden_widgets}
                            onSave={(hidden) => saveLayout(hidden)}
                            onReset={() => saveLayout(null)}
                        />
                        {can_configure && (
                            <Button asChild size="sm" variant="ghost" className="h-8" title={t('dash.settings_title')}>
                                <Link href="/settings/dashboards">
                                    <Settings2 className="size-4" />
                                    <span className="sr-only">{t('dash.settings')}</span>
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <RangeBar
                        range={range}
                        presets={range_presets}
                        path={path}
                        keep={brand.selected === null ? {} : { brand: String(brand.selected) }}
                    />
                    <BrandBar brands={brand.options} selected={brand.selected} path={path} rangeQuery={range.query} />
                </div>

                <HeroBand
                    dashboardKey={dashboard.key}
                    title={dashboardLabel}
                    description={tr(`dash.description.${dashboard.key}`, dashboard.description)}
                    context={t('dash.hero.context', { range: rangeLabel, brand: brandName ?? t('dash.hero.all_brands') })}
                    widgets={widgets}
                    entries={entries}
                />

                {range_error && (
                    <div className="flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm">
                        <AlertTriangle className="size-4 text-amber-700 dark:text-amber-400" /> {range_error}{' '}
                        {t('dash.range_error_instead', { range: rangeLabel.toLowerCase() })}
                    </div>
                )}

                {props.getting_started && <GettingStartedCard data={props.getting_started} />}

                <SectionChips widgets={widgets} />

                {widgets.length === 0 ? (
                    <p className="text-muted-foreground rounded-2xl border border-dashed bg-gradient-to-br from-emerald-500/6 via-sky-500/6 to-violet-500/6 p-8 text-center text-sm">
                        {hidden_widgets.length > 0 ? t('dash.all_hidden') : t('dash.nothing_for_role')}
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {widgets.map((widget, index) => (
                            <WidgetCard
                                key={widget.key}
                                widget={widget}
                                entry={entries[widget.key] ?? { result: widget.result, fetchedAt: now, loading: false, stale: false }}
                                index={index}
                                spanClass={spans[widget.key]}
                                now={now}
                                brandName={brandName}
                                onRefresh={() => refresh([widget.key])}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/** The toolbar eye: shows or hides every private figure on the page at once. */
function RevealAllButton() {
    const { t } = useTranslation();
    const { sensitive, allRevealed, toggleAll } = usePrivacy();

    if (sensitive.length === 0) {
        return null;
    }

    const label = allRevealed ? t('dash.privacy.hide_all') : t('dash.privacy.show_all');

    return (
        <Button size="sm" variant="ghost" className="h-8" onClick={toggleAll} aria-pressed={allRevealed} aria-label={label} title={label}>
            <EyeIcon open={allRevealed} className="size-4" />
        </Button>
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
