import { LiveIndicator, RangeBar, sendJson, SoundSettingsPopover } from '@/components/dashboards/dashboard-toolbar';
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
import { AlertTriangle, ArrowDown, ArrowUp, LayoutGrid, Settings2 } from 'lucide-react';
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
    sound: SoundSettings;
    realtime: { enabled: boolean; business_channel: string; user_channel: string };
    can_configure: boolean;
}

/**
 * The role dashboards (Joy, 11 Oct 2026): one page, five dashboards, each
 * person on the highest one their roles give them, Joy able to open them all.
 *
 * Alive on purpose (Owner override of ChatGPT): widgets refresh the moment an
 * event names them over the socket, poll on their own interval when the socket
 * is down, count their numbers to new values, and can play a sound. Motion
 * never delays a figure and stands down under prefers-reduced-motion.
 */
export default function RoleDashboard(props: Props) {
    const { dashboard, switcher, offers_classic, widgets, hidden_widgets, range, range_presets, range_error, realtime, can_configure } = props;
    const { auth } = usePage<SharedData>().props;
    const { t, tr } = useTranslation();
    const dashboardLabel = tr(`dash.name.${dashboard.key}`, dashboard.label);
    const rangeLabel = range.preset === 'custom' ? range.label : tr(`dash.preset.${range.preset}`, range.label);
    const [sound, setSound] = useState(props.sound);
    const [now, setNow] = useState(() => Date.now());
    const path = switcher.find((s) => s.current)?.url ?? '/dashboard';

    dashboardSound.settings = sound;

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 5000);

        return () => window.clearInterval(timer);
    }, []);

    const keys = useMemo(() => widgets.map((w) => w.key), [widgets]);
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

    const saveLayout = async (order: string[], hidden: string[]) => {
        const response = await sendJson('PUT', `/dashboard/${dashboard.key}/layout`, { order, hidden });

        if (response.ok) {
            router.reload({ only: ['widgets', 'hidden_widgets'] });
        } else {
            toast.error(t('dash.layout_not_saved'));
        }
    };

    const allKeys = [...widgets.map((w) => w.key), ...hidden_widgets.map((w) => w.key)];
    const hiddenKeys = hidden_widgets.map((w) => w.key);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dash.head_title', { name: dashboardLabel })} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tracking-tight">{dashboardLabel}</h1>
                        <p className="text-muted-foreground text-sm">{tr(`dash.description.${dashboard.key}`, dashboard.description)}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <LiveIndicator status={status} />
                        <SoundSettingsPopover settings={sound} onChange={setSound} />
                        <CustomisePopover widgets={widgets} hidden={hidden_widgets} onSave={saveLayout} />
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

                {(switcher.length > 1 || offers_classic) && (
                    <nav aria-label={t('dash.switcher')} className="flex flex-wrap gap-1 border-b pb-2">
                        {switcher.map((entry) => (
                            <Link
                                key={entry.key}
                                href={entry.url}
                                preserveScroll
                                data={range.query}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm transition-colors',
                                    entry.current
                                        ? 'bg-primary text-primary-foreground font-medium'
                                        : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                )}
                                aria-current={entry.current ? 'page' : undefined}
                            >
                                {tr(`dash.name.${entry.key}`, entry.label)}
                            </Link>
                        ))}
                        {offers_classic && (
                            <Link
                                href="/dashboard/classic"
                                className="text-muted-foreground hover:bg-accent hover:text-foreground flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm"
                            >
                                <LayoutGrid className="size-3.5" /> {t('dash.classic')}
                            </Link>
                        )}
                    </nav>
                )}

                <RangeBar range={range} presets={range_presets} path={path} />

                {range_error && (
                    <div className="flex items-center gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm">
                        <AlertTriangle className="size-4 text-amber-600" /> {range_error}{' '}
                        {t('dash.range_error_instead', { range: rangeLabel.toLowerCase() })}
                    </div>
                )}

                {widgets.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
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
                                now={now}
                                onRefresh={() => refresh([widget.key])}
                                onHide={() => saveLayout(allKeys, [...hiddenKeys, widget.key])}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

/** Show, hide and reorder widgets. Presentation only: nothing here changes access. */
function CustomisePopover({
    widgets,
    hidden,
    onSave,
}: {
    widgets: DashboardWidget[];
    hidden: { key: string; title: string }[];
    onSave: (order: string[], hidden: string[]) => void;
}) {
    const { t, tr } = useTranslation();
    const initial = [
        ...widgets.map((w) => ({ key: w.key, title: tr(`widget.${w.key}.title`, w.title), shown: true })),
        ...hidden.map((w) => ({ ...w, title: tr(`widget.${w.key}.title`, w.title), shown: false })),
    ];
    const [rows, setRows] = useState(initial);
    const [open, setOpen] = useState(false);

    const move = (index: number, by: number) => {
        const next = [...rows];
        const [row] = next.splice(index, 1);
        next.splice(Math.max(0, Math.min(next.length, index + by)), 0, row);
        setRows(next);
    };

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
                    {rows.map((row, index) => (
                        <li key={row.key} className="flex items-center gap-2 text-sm">
                            <Checkbox
                                id={`show-${row.key}`}
                                checked={row.shown}
                                onCheckedChange={(v) => setRows(rows.map((r) => (r.key === row.key ? { ...r, shown: v === true } : r)))}
                            />
                            <label htmlFor={`show-${row.key}`} className="min-w-0 flex-1 truncate">
                                {row.title}
                            </label>
                            <button
                                type="button"
                                className="hover:bg-accent rounded p-1"
                                onClick={() => move(index, -1)}
                                aria-label={t('dash.move_up', { name: row.title })}
                            >
                                <ArrowUp className="size-3.5" />
                            </button>
                            <button
                                type="button"
                                className="hover:bg-accent rounded p-1"
                                onClick={() => move(index, 1)}
                                aria-label={t('dash.move_down', { name: row.title })}
                            >
                                <ArrowDown className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
                <p className="text-muted-foreground mt-2 text-xs">{t('dash.hiding_note')}</p>
                <div className="mt-3 flex justify-end gap-2">
                    <Button size="sm" variant="ghost" onClick={() => onSave([], [])}>
                        {t('dash.reset')}
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => {
                            onSave(
                                rows.map((r) => r.key),
                                rows.filter((r) => !r.shown).map((r) => r.key),
                            );
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
