import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, CircleCheck, Minus, TriangleAlert } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useId } from 'react';
import { AnimatedNumber } from './animated-number';
import { changeAgainst, formatValue } from './format';
import { iconFor } from './palette';
import type { DashboardWidget, Unit, WidgetPayload } from './types';
import type { FeedEntry } from './use-widget-feed';

/**
 * The headline figures of each role dashboard, on the brand gradient (Joy,
 * 11 Oct 2026: "so the main area catch eyes").
 *
 * ⚠⚠ NO NEW QUERY. Every figure here is read from a widget already on the page
 * and from the same live entry the card below uses, so the hero can never
 * disagree with its card, refreshes with it, and shows nothing a person is not
 * already allowed to see. A widget the person hid, or may not see, simply has
 * no tile.
 *
 * ⚠ Ruling 9 holds here too: a widget that failed shows "Could not load",
 * never a zero.
 */

type Better = 'up' | 'down';

interface Recipe {
    key: string;
    /** Which direction of change is good news (for the chip's colour; the arrow always shows the direction). */
    better: Better;
    /** A shorter name for the tile than the widget title. */
    label?: string;
}

const RECIPES: Record<string, Recipe[]> = {
    owner: [
        { key: 'cash_position', better: 'up' },
        { key: 'income_trend', better: 'up' },
        { key: 'money_in_transit', better: 'down', label: 'With couriers and gateway' },
        { key: 'business_health', better: 'down', label: 'Needs attention' },
    ],
    finance: [
        { key: 'cash_position', better: 'up' },
        { key: 'income_trend', better: 'up' },
        { key: 'money_in_transit', better: 'down', label: 'With couriers and gateway' },
        { key: 'bank_unmatched', better: 'down' },
    ],
    manager: [
        { key: 'business_health', better: 'down', label: 'Needs attention' },
        { key: 'orders_to_dispatch', better: 'down' },
        { key: 'approvals_waiting', better: 'down' },
        { key: 'stock_alerts', better: 'down' },
    ],
    sales: [
        { key: 'sales_vs_target', better: 'up' },
        { key: 'my_orders', better: 'up' },
        { key: 'orders_needing_action', better: 'down' },
        { key: 'followups_due', better: 'down' },
    ],
    employee: [
        { key: 'my_payslip', better: 'up' },
        { key: 'my_attendance', better: 'up', label: 'Days present' },
        { key: 'my_tasks', better: 'down' },
        { key: 'my_claims', better: 'down' },
    ],
};

interface Figure {
    value: number | string;
    unit: Unit;
    previous?: number | null;
    caption?: string | null;
    spark?: number[];
    progress?: number | null;
    segments?: { label: string; value: number }[];
    /** Zero things waiting: said in words with a tick, good news is news too. */
    clear?: boolean;
}

/** The one headline number a payload stands for. */
function figureOf(payload: WidgetPayload): Figure | null {
    switch (payload.kind) {
        case 'stat': {
            // A money figure made of parts (cash, bank, wallet) shows them as one split bar.
            const parts = (payload.parts ?? []).filter((p) => typeof p.value === 'number' && (p.unit ?? 'count') === payload.unit);
            const split =
                payload.unit === 'money' &&
                parts.length >= 2 &&
                parts.every((p) => (p.value as number) >= 0) &&
                parts.some((p) => (p.value as number) > 0)
                    ? parts.map((p) => ({ label: p.label, value: p.value as number }))
                    : undefined;

            return {
                value: payload.value,
                unit: payload.unit,
                previous: payload.previous,
                segments: split,
                caption: split
                    ? null
                    : (payload.caption ??
                      (payload.parts ?? [])
                          .slice(0, 2)
                          .map((p) => `${p.label} ${formatValue(p.value, p.unit ?? 'text', true)}`)
                          .join(', ')),
            };
        }
        case 'series': {
            const first = payload.series[0]?.key;
            const total = payload.total ?? payload.points.reduce((sum, p) => sum + Number(first ? (p[first] ?? 0) : 0), 0);

            return {
                value: total,
                unit: payload.unit,
                previous: payload.previous_total,
                spark: first ? payload.points.map((p) => Number(p[first] ?? 0)) : undefined,
                caption: payload.total_label ?? payload.series[0]?.label ?? null,
            };
        }
        case 'progress':
            return {
                value: payload.value,
                unit: payload.unit,
                progress: payload.target ? payload.value / payload.target : null,
                caption: payload.target ? `of ${formatValue(payload.target, payload.unit, true)} target` : 'No target set',
            };
        case 'signals':
            return {
                value: payload.items.reduce((sum, i) => sum + i.count, 0),
                unit: 'count',
                segments: payload.items.filter((i) => i.count > 0).map((i) => ({ label: i.label, value: i.count })),
                clear: payload.items.every((i) => i.count === 0),
                caption: null,
            };
        case 'list':
        case 'lookup':
            return { value: payload.total_count, unit: 'count', clear: payload.total_count === 0, caption: null };
        case 'breakdown': {
            if (payload.total !== undefined) {
                return { value: payload.total, unit: payload.unit, caption: payload.headline ?? null };
            }

            const first = payload.items[0];

            return first ? { value: first.value, unit: payload.unit, caption: payload.headline ?? null } : null;
        }
        default:
            return null;
    }
}

export function HeroBand({
    dashboardKey,
    title,
    description,
    context,
    widgets,
    entries,
}: {
    dashboardKey: string;
    title: string;
    description: string;
    /** What the figures cover, e.g. "Last 30 days, all brands". */
    context: string;
    widgets: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
}) {
    const reduce = useReducedMotion();
    const byKey = new Map(widgets.map((w) => [w.key, w]));
    const tiles = (RECIPES[dashboardKey] ?? []).flatMap((recipe) => {
        const widget = byKey.get(recipe.key);

        return widget ? [{ recipe, widget, entry: entries[widget.key] }] : [];
    });

    return (
        <motion.section
            aria-label={`${title} headline figures`}
            initial={reduce ? false : { opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
            className="dash-hero relative isolate overflow-hidden rounded-2xl p-4 text-white shadow-[0_1px_2px_rgba(6,40,30,0.18),0_18px_40px_-20px_rgba(6,60,50,0.55)] sm:p-6"
        >
            <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-tight sm:text-[1.75rem]">{title}</h1>
                    <p className="mt-1 max-w-[60ch] text-sm text-white/85">{description}</p>
                </div>
                <p className="rounded-full bg-white/12 px-3 py-1 text-xs font-medium text-white ring-1 ring-white/20">{context}</p>
            </div>

            {tiles.length > 0 && (
                <div
                    className={cn(
                        'mt-5 grid grid-cols-1 gap-3 min-[420px]:grid-cols-2',
                        tiles.length >= 4 ? 'xl:grid-cols-4' : tiles.length === 3 ? 'lg:grid-cols-3' : '',
                    )}
                >
                    {tiles.map(({ recipe, widget, entry }, index) => (
                        <HeroTile key={widget.key} recipe={recipe} widget={widget} entry={entry} index={index} />
                    ))}
                </div>
            )}
        </motion.section>
    );
}

function HeroTile({ recipe, widget, entry, index }: { recipe: Recipe; widget: DashboardWidget; entry: FeedEntry | undefined; index: number }) {
    const reduce = useReducedMotion();
    const payload = (entry?.result ?? widget.result).data;
    const figure = payload ? figureOf(payload) : null;
    const Icon = iconFor(widget.key);
    const label = recipe.label ?? widget.title;
    const numeric = figure && typeof figure.value === 'number' ? figure.value : null;
    const change = numeric !== null ? changeAgainst(numeric, figure?.previous) : null;
    const good = change ? (recipe.better === 'up') === change.up : null;
    const fullText = figure ? formatValue(figure.value, figure.unit) : '';

    const body = (
        <>
            <div className="flex items-center gap-2.5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20">
                    <Icon className="size-4" aria-hidden />
                </span>
                <span className="min-w-0 truncate text-sm font-medium text-white/90">{label}</span>
            </div>

            {figure === null ? (
                payload === null ? (
                    // ⚠ Ruling 9: a failure is said, never drawn as zero.
                    <p className="mt-3 flex items-center gap-1.5 text-sm font-medium">
                        <TriangleAlert className="size-4 shrink-0" aria-hidden /> Could not load
                    </p>
                ) : (
                    <>
                        <p className="mt-3 text-[1.75rem] leading-none font-semibold text-white/70 sm:text-[2rem]" aria-hidden>
                            -
                        </p>
                        <p className="mt-3 line-clamp-2 text-xs text-white/85">{(payload as { message?: string }).message ?? 'Nothing yet'}</p>
                    </>
                )
            ) : (
                <>
                    <div className="mt-3 flex flex-wrap items-end gap-x-2.5 gap-y-1.5">
                        <p className="text-[1.75rem] leading-none font-semibold tracking-tight tabular-nums sm:text-[2rem]" title={fullText}>
                            {numeric !== null ? <AnimatedNumber value={numeric} unit={figure.unit} compact flash={false} /> : figure.value}
                        </p>
                        {change && (
                            <span
                                className={cn(
                                    'inline-flex items-center gap-0.5 rounded-full bg-white px-1.5 py-0.5 text-[11px] font-semibold tabular-nums shadow-sm',
                                    good ? 'text-emerald-800' : 'text-red-700',
                                )}
                                title="Against the previous period of the same length"
                            >
                                {change.up ? <ArrowUpRight className="size-3" aria-hidden /> : <ArrowDownRight className="size-3" aria-hidden />}
                                {change.text}
                                <span className="sr-only">{good ? ', better than' : ', worse than'} the previous period</span>
                            </span>
                        )}
                    </div>
                    <div className="mt-3 min-h-7">
                        {figure.spark && figure.spark.length > 1 ? (
                            <Sparkline values={figure.spark} label={`${label} over the range`} delay={reduce ? 0 : 0.25 + index * 0.06} />
                        ) : figure.progress !== undefined && figure.progress !== null ? (
                            <ProgressLine share={figure.progress} />
                        ) : figure.segments && figure.segments.length > 0 ? (
                            <Segments segments={figure.segments} unit={figure.unit} />
                        ) : figure.clear && recipe.better === 'down' ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-xs font-medium ring-1 ring-white/20">
                                <CircleCheck className="size-3.5" aria-hidden /> All clear, nothing waiting
                            </span>
                        ) : null}
                        {figure.caption && <p className="mt-1.5 truncate text-xs text-white/85">{figure.caption}</p>}
                        {figure.clear && recipe.better === 'up' && <p className="mt-1.5 text-xs text-white/85">None in this range yet</p>}
                        {!figure.caption && change === null && numeric !== null && !figure.spark && !figure.segments && !figure.clear && (
                            <p className="mt-1.5 flex items-center gap-1 text-xs text-white/80">
                                <Minus className="size-3" aria-hidden /> No earlier figure to compare
                            </p>
                        )}
                    </div>
                </>
            )}
        </>
    );

    const tileClass =
        'group block h-full rounded-xl bg-[var(--dash-hero-tile)] p-3.5 ring-1 ring-[var(--dash-hero-ring)] transition-[background-color,transform] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)] hover:bg-[var(--dash-hero-tile-hover)] motion-safe:hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:p-4';

    return (
        <motion.div
            initial={reduce ? false : { opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1], delay: reduce ? 0 : 0.08 + index * 0.06 }}
        >
            {widget.drill_down ? (
                <Link href={widget.drill_down} className={tileClass} aria-label={`${label}: ${fullText}. Open the full screen`}>
                    {body}
                </Link>
            ) : (
                <div className={tileClass}>{body}</div>
            )}
        </motion.div>
    );
}

/**
 * ui-motion: the line draws itself once (Masking: pathLength), so the eye
 * follows the trend in time order. Under reduced motion it is simply there.
 */
function Sparkline({ values, label, delay }: { values: number[]; label: string; delay: number }) {
    const reduce = useReducedMotion();
    const id = useId().replace(/:/g, '');
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const W = 240;
    const step = W / (values.length - 1);
    const pts = values.map((v, i) => [i * step, 26 - ((v - min) / span) * 22] as const);
    const line = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(2)},${y.toFixed(2)}`).join(' ');
    const area = `${line} L${W},28 L0,28 Z`;
    const [lx, ly] = pts[pts.length - 1];

    return (
        <div className="relative h-7 w-full">
            <svg
                viewBox={`0 0 ${W} 28`}
                preserveAspectRatio="none"
                className="absolute inset-0 h-full w-full overflow-visible"
                role="img"
                aria-label={label}
            >
                <defs>
                    <linearGradient id={`spark-${id}`} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="white" stopOpacity={0.32} />
                        <stop offset="100%" stopColor="white" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <motion.path
                    d={area}
                    fill={`url(#spark-${id})`}
                    initial={reduce ? false : { opacity: 0 }}
                    animate={{ opacity: 1 }}
                    transition={{ duration: 0.6, delay: delay + 0.3 }}
                />
                <motion.path
                    d={line}
                    fill="none"
                    stroke="white"
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    initial={reduce ? false : { pathLength: 0 }}
                    animate={{ pathLength: 1 }}
                    transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1], delay }}
                />
            </svg>
            {/* The latest point, as an HTML dot so the stretched viewBox cannot squash it. */}
            <span
                aria-hidden
                className="absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white shadow-[0_0_0_3px_rgba(255,255,255,0.25)]"
                style={{ left: `${(lx / W) * 100}%`, top: `${(ly / 28) * 100}%` }}
            />
        </div>
    );
}

function ProgressLine({ share }: { share: number }) {
    const reduce = useReducedMotion();
    const clamped = Math.min(1, Math.max(0, share));

    return (
        <div className="flex items-center gap-2">
            <div
                className="h-2 flex-1 overflow-hidden rounded-full bg-white/18"
                role="progressbar"
                aria-valuenow={Math.round(clamped * 100)}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <motion.div
                    className="h-full w-full origin-left rounded-full bg-white"
                    initial={reduce ? false : { scaleX: 0 }}
                    animate={{ scaleX: clamped }}
                    transition={reduce ? { duration: 0 } : { duration: 0.9, ease: [0.22, 1, 0.36, 1], delay: 0.3 }}
                />
            </div>
            <span className="text-xs font-semibold tabular-nums">{Math.round(share * 100)}%</span>
        </div>
    );
}

/** Each waiting signal as its own segment; the words sit under it, never colour alone. */
function Segments({ segments, unit }: { segments: { label: string; value: number }[]; unit: Unit }) {
    const total = segments.reduce((sum, s) => sum + s.value, 0) || 1;
    const top = [...segments].sort((a, b) => b.value - a.value).slice(0, 2);

    return (
        <div>
            <div className="flex h-2 gap-0.5 overflow-hidden rounded-full" aria-hidden>
                {segments.map((s, i) => (
                    <span key={s.label} className="h-full rounded-full bg-white" style={{ flexGrow: s.value / total, opacity: 1 - (i % 4) * 0.2 }} />
                ))}
            </div>
            <p className="mt-1.5 truncate text-xs text-white/85">
                {top
                    .map((s) => (unit === 'count' ? `${s.value} ${s.label.toLowerCase()}` : `${s.label} ${formatValue(s.value, unit, true)}`))
                    .join(', ')}
            </p>
        </div>
    );
}
