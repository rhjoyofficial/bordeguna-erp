import { AttentionList } from '@/components/attention/attention-list';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, CircleCheck, Inbox } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useMemo, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AnimatedNumber } from './animated-number';
import { changeAgainst, formatValue } from './format';
import { avatarStyle, initials, PILL, SERIES, STATUS, statusForText, toneStatus, toneText } from './palette';
import type {
    AttentionPayload,
    BreakdownPayload,
    ListPayload,
    Part,
    ProgressPayload,
    SeriesPayload,
    SignalsPayload,
    StatPayload,
    Tone,
    WidgetPayload,
} from './types';

/** Breakdowns whose labels are states, coloured by what the state means. */
const STATUS_BREAKDOWNS = new Set(['courier_parcels_by_status', 'orders_by_state']);

/** List columns drawn as a status pill, and as a person with an initial chip. */
const PILL_COLUMNS = new Set(['status', 'state']);
const PERSON_COLUMNS = new Set(['employee', 'customer']);

const AXIS_TICK = { fill: 'var(--muted-foreground)', fontSize: 11 };
const EASE = [0.22, 1, 0.36, 1] as const;

const toneFill = (tone: Tone, index: number) => {
    const status = toneStatus(tone);

    return status ? STATUS[status] : SERIES[index % SERIES.length];
};

export function WidgetBody({ payload, emptyText, widgetKey }: { payload: WidgetPayload; emptyText: string; widgetKey?: string }) {
    switch (payload.kind) {
        case 'stat':
            return <StatBody payload={payload} />;
        case 'series':
            return <SeriesBody payload={payload} />;
        case 'breakdown':
            return <BreakdownBody payload={payload} emptyText={emptyText} byStatus={STATUS_BREAKDOWNS.has(widgetKey ?? '')} />;
        case 'list':
        case 'lookup':
            return <ListBody payload={payload} emptyText={emptyText} />;
        case 'signals':
            return <SignalsBody payload={payload} />;
        case 'progress':
            return <ProgressBody payload={payload} emptyText={emptyText} />;
        case 'attention':
            return <AttentionBody payload={payload} emptyText={emptyText} />;
        default:
            return <EmptyNote text={payload.message} />;
    }
}

/**
 * An empty state that still belongs to the dashboard: the domain's tint, an
 * icon and the words, never a grey box.
 */
function EmptyNote({ text, calm = false }: { text: string; calm?: boolean }) {
    const Icon = calm ? CircleCheck : Inbox;

    return (
        <div className="flex items-center gap-3 rounded-xl border border-dashed border-[color-mix(in_oklab,var(--acc)_35%,transparent)] bg-[var(--acc-wash)] px-3 py-4 text-sm">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--card)] text-[var(--acc-ink)] shadow-xs">
                <Icon className="size-4" aria-hidden />
            </span>
            <p className="text-muted-foreground">{text}</p>
        </div>
    );
}

/** "+12.4%" with an arrow: the arrow and the sign say the direction, the colour only repeats it. */
function ChangeChip({ change }: { change: { text: string; up: boolean } }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold tabular-nums ring-1 ring-inset',
                change.up ? PILL.good : PILL.bad,
            )}
            title={t('dash.vs_previous')}
        >
            {change.up ? <ArrowUpRight className="size-3" aria-hidden /> : <ArrowDownRight className="size-3" aria-hidden />}
            {change.text}
            <span className="sr-only"> {t('dash.vs_previous')}</span>
        </span>
    );
}

/** A status word as a pill: tinted fill, AA text, and a dot as the second cue. */
function StatusPill({ text }: { text: string }) {
    const status = statusForText(text);

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                PILL[status],
            )}
        >
            <span className="size-1.5 shrink-0 rounded-full" style={{ background: STATUS[status] }} aria-hidden />
            <span className="truncate">{text}</span>
        </span>
    );
}

function Person({ name }: { name: string }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <span
                className="flex size-6 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold"
                style={avatarStyle(name)}
                aria-hidden
            >
                {initials(name)}
            </span>
            <span className="truncate">{name}</span>
        </span>
    );
}

function Parts({ parts }: { parts?: Part[] }) {
    if (!parts || parts.length === 0) {
        return null;
    }

    const tile =
        'block min-w-0 rounded-lg bg-[var(--acc-tile)] px-2.5 py-1.5 ring-1 ring-inset ring-[color-mix(in_oklab,var(--acc)_30%,transparent)]';

    return (
        <dl className="mt-4 grid grid-cols-2 gap-2 text-xs @md:grid-cols-3">
            {parts.map((part) => {
                const body = (
                    <>
                        <dt className="truncate font-medium text-[var(--acc-ink)]">{part.label}</dt>
                        <dd className={cn('mt-0.5 truncate text-[13px] font-semibold', toneText(part.tone))}>
                            {typeof part.value === 'number' && part.unit !== 'text' ? (
                                <AnimatedNumber value={part.value} unit={part.unit ?? 'count'} compact />
                            ) : (
                                formatValue(part.value, part.unit ?? 'text')
                            )}
                        </dd>
                    </>
                );

                return part.url ? (
                    <Link
                        key={part.label}
                        href={part.url}
                        className={cn(tile, 'transition-colors hover:bg-[color-mix(in_oklab,var(--acc)_24%,var(--card))]')}
                    >
                        {body}
                    </Link>
                ) : (
                    <div key={part.label} className={tile}>
                        {body}
                    </div>
                );
            })}
        </dl>
    );
}

function StatBody({ payload }: { payload: StatPayload }) {
    const change = typeof payload.value === 'number' ? changeAgainst(payload.value, payload.previous) : null;

    return (
        <div>
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <p className={cn('text-[1.75rem] leading-tight font-semibold tracking-tight', toneText(payload.tone))}>
                    {typeof payload.value === 'number' ? <AnimatedNumber value={payload.value} unit={payload.unit} /> : payload.value}
                </p>
                {change && <ChangeChip change={change} />}
            </div>
            {payload.caption && <p className="text-muted-foreground mt-0.5 text-xs">{payload.caption}</p>}
            <Parts parts={payload.parts} />
        </div>
    );
}

function ChartTooltip({
    active,
    payload,
    label,
    unit,
}: {
    active?: boolean;
    payload?: { name: string; value: number; color: string; payload?: { fill?: string } }[];
    label?: string;
    unit: StatPayload['unit'];
}) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground min-w-36 rounded-lg border px-3 py-2 text-xs shadow-lg" role="status">
            {label && <p className="mb-1 font-medium">{label}</p>}
            {payload.map((item) => (
                <p key={item.name} className="flex items-center gap-2">
                    <span className="inline-block h-2 w-3 rounded-full" style={{ background: item.color ?? item.payload?.fill }} />
                    <span className="text-muted-foreground">{item.name}</span>
                    <span className="ml-auto pl-3 font-semibold tabular-nums">{formatValue(item.value, unit)}</span>
                </p>
            ))}
        </div>
    );
}

function SeriesBody({ payload }: { payload: SeriesPayload }) {
    const reduce = useReducedMotion();
    const { t } = useTranslation();
    const change = payload.total !== undefined ? changeAgainst(payload.total, payload.previous_total) : null;
    const hasData = payload.points.some((p) => payload.series.some((s) => Number(p[s.key]) !== 0));
    const summary = payload.series
        .map(
            (s) =>
                `${s.label} ${formatValue(
                    payload.points.reduce((sum, p) => sum + Number(p[s.key] ?? 0), 0),
                    payload.unit,
                )}`,
        )
        .join(', ');

    const axes = (
        <>
            <CartesianGrid vertical={false} stroke="var(--dash-grid)" />
            <XAxis dataKey="label" tickLine={false} axisLine={false} tick={AXIS_TICK} minTickGap={16} />
            <YAxis tickLine={false} axisLine={false} tick={AXIS_TICK} width={56} tickFormatter={(v: number) => formatValue(v, 'count', true)} />
        </>
    );

    return (
        <div>
            {payload.total !== undefined && (
                <div className="mb-3 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <span className="text-muted-foreground w-full text-xs">{payload.total_label ?? t('dash.total')}</span>
                    <span className="text-2xl font-semibold tracking-tight">
                        <AnimatedNumber value={payload.total} unit={payload.unit} />
                    </span>
                    {change && <ChangeChip change={change} />}
                </div>
            )}
            {!hasData ? (
                <EmptyNote text={t('dash.nothing_posted')} />
            ) : (
                <div className="h-56 w-full" role="img" aria-label={summary}>
                    <ResponsiveContainer width="100%" height="100%">
                        {payload.chart === 'bar' ? (
                            <BarChart data={payload.points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barGap={2}>
                                {axes}
                                <Tooltip content={<ChartTooltip unit={payload.unit} />} cursor={{ fill: 'var(--acc-wash)' }} />
                                {payload.series.map((s, i) => (
                                    <Bar
                                        key={s.key}
                                        dataKey={s.key}
                                        name={s.label}
                                        fill={SERIES[i]}
                                        radius={[4, 4, 0, 0]}
                                        maxBarSize={26}
                                        isAnimationActive={!reduce}
                                        animationDuration={700}
                                        animationEasing="ease-out"
                                    />
                                ))}
                            </BarChart>
                        ) : (
                            <AreaChart data={payload.points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                                <defs>
                                    {payload.series.map((s, i) => (
                                        <linearGradient key={s.key} id={`fill-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor={SERIES[i]} stopOpacity={0.38} />
                                            <stop offset="70%" stopColor={SERIES[i]} stopOpacity={0.08} />
                                            <stop offset="100%" stopColor={SERIES[i]} stopOpacity={0} />
                                        </linearGradient>
                                    ))}
                                </defs>
                                {axes}
                                <Tooltip
                                    content={<ChartTooltip unit={payload.unit} />}
                                    cursor={{ stroke: 'var(--muted-foreground)', strokeWidth: 1, strokeDasharray: '3 3' }}
                                />
                                {payload.series.map((s, i) => (
                                    <Area
                                        key={s.key}
                                        type="monotone"
                                        dataKey={s.key}
                                        name={s.label}
                                        stroke={SERIES[i]}
                                        strokeWidth={2}
                                        fill={`url(#fill-${s.key})`}
                                        activeDot={{ r: 4, strokeWidth: 2, stroke: 'var(--card)' }}
                                        isAnimationActive={!reduce}
                                        animationDuration={900}
                                        animationEasing="ease-out"
                                    />
                                ))}
                            </AreaChart>
                        )}
                    </ResponsiveContainer>
                </div>
            )}
            <div className="text-muted-foreground mt-3 flex flex-wrap gap-4 text-xs">
                {payload.series.map((s, i) => (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span className="inline-block h-2 w-3.5 rounded-full" style={{ background: SERIES[i] }} />
                        {s.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function BreakdownBody({ payload, emptyText, byStatus }: { payload: BreakdownPayload; emptyText: string; byStatus: boolean }) {
    const reduce = useReducedMotion();
    const { t } = useTranslation();
    const max = Math.max(1, ...payload.items.map((i) => Math.abs(i.value)));
    const total = payload.items.reduce((sum, i) => sum + i.value, 0);
    const fill = (item: BreakdownPayload['items'][number], index: number) =>
        byStatus ? STATUS[statusForText(item.label)] : toneFill(item.tone, index);

    return (
        <div>
            {payload.headline && <p className="mb-2 text-sm font-medium">{payload.headline}</p>}
            {payload.total !== undefined && payload.chart !== 'donut' && (
                <p className="mb-3 text-2xl font-semibold tracking-tight">
                    <AnimatedNumber value={payload.total} unit={payload.unit} />
                </p>
            )}
            {payload.items.length === 0 ? (
                <EmptyNote text={emptyText} calm />
            ) : payload.chart === 'donut' ? (
                <div className="flex flex-col items-center gap-4 min-[420px]:flex-row">
                    <div className="relative size-36 shrink-0" role="img" aria-label={payload.items.map((i) => `${i.label} ${i.value}`).join(', ')}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={payload.items}
                                    dataKey="value"
                                    nameKey="label"
                                    innerRadius="66%"
                                    outerRadius="100%"
                                    paddingAngle={2}
                                    cornerRadius={4}
                                    stroke="var(--card)"
                                    strokeWidth={2}
                                    isAnimationActive={!reduce}
                                    animationDuration={800}
                                >
                                    {payload.items.map((item, index) => (
                                        <Cell key={item.label} fill={fill(item, index)} />
                                    ))}
                                </Pie>
                                <Tooltip content={<ChartTooltip unit={payload.unit} />} />
                            </PieChart>
                        </ResponsiveContainer>
                        {/* The total in the hole, so the ring is read as parts of one number. */}
                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-xl font-semibold tracking-tight">
                                <AnimatedNumber value={payload.total ?? total} unit={payload.unit} compact />
                            </span>
                            <span className="text-muted-foreground text-[11px]">{t('dash.colour.in_all')}</span>
                        </div>
                    </div>
                    <ul className="w-full min-w-0 flex-1 space-y-1.5 text-xs">
                        {payload.items.map((item, index) => (
                            <li key={item.label} className="flex items-center gap-2 rounded-md px-1.5 py-1 odd:bg-[var(--dash-zebra)]">
                                <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: fill(item, index) }} />
                                <span className="text-muted-foreground truncate">{item.label}</span>
                                <span className="ml-auto font-semibold tabular-nums">
                                    <AnimatedNumber value={item.value} unit={payload.unit} />
                                </span>
                                {total > 0 && (
                                    <span className="text-muted-foreground w-9 text-right tabular-nums">
                                        {Math.round((item.value / total) * 100)}%
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <ul className="space-y-2.5">
                    {payload.items.map((item, index) => {
                        const colour = fill(item, index);
                        const row = (
                            <>
                                <div className="flex items-baseline justify-between gap-2 text-xs">
                                    <span className="flex min-w-0 items-center gap-1.5" title={item.hint}>
                                        <span className="inline-block size-2 shrink-0 rounded-full" style={{ background: colour }} aria-hidden />
                                        <span className="text-muted-foreground truncate">{item.label}</span>
                                    </span>
                                    <span className={cn('font-semibold tabular-nums', toneText(item.tone))}>
                                        <AnimatedNumber value={item.value} unit={payload.unit} compact />
                                    </span>
                                </div>
                                <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--dash-track)]">
                                    {/* ui-motion: Transformation (scale from the left edge), drawn in once, no layout property animated. */}
                                    <motion.div
                                        className="h-full origin-left rounded-full"
                                        style={{ background: colour, width: '100%' }}
                                        initial={reduce ? false : { scaleX: 0 }}
                                        animate={{ scaleX: Math.max(Math.abs(item.value) / max, item.value === 0 ? 0 : 0.02) }}
                                        transition={reduce ? { duration: 0 } : { duration: 0.7, ease: EASE, delay: 0.15 + index * 0.04 }}
                                    />
                                </div>
                            </>
                        );

                        return (
                            <li key={item.label}>
                                {item.url ? (
                                    <Link
                                        href={item.url}
                                        className="-mx-1.5 block rounded-lg px-1.5 py-1 transition-colors hover:bg-[var(--acc-wash)]"
                                    >
                                        {row}
                                    </Link>
                                ) : (
                                    row
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            {payload.caption && <p className="text-muted-foreground mt-3 text-xs">{payload.caption}</p>}
            {payload.chart === 'donut' && payload.total === undefined && (
                <p className="sr-only">
                    {t('dash.total')} {formatValue(total, payload.unit)}
                </p>
            )}
            <Parts parts={payload.parts} />
        </div>
    );
}

function ListBody({ payload, emptyText }: { payload: ListPayload; emptyText: string }) {
    const reduce = useReducedMotion();
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const rows = useMemo(() => {
        if (payload.kind !== 'lookup' || query.trim() === '') {
            return payload.kind === 'lookup' ? payload.rows.slice(0, 12) : payload.rows;
        }

        const needle = query.trim().toLowerCase();

        return payload.rows
            .filter((row) =>
                Object.values(row.cells).some((cell) =>
                    String(cell ?? '')
                        .toLowerCase()
                        .includes(needle),
                ),
            )
            .slice(0, 25);
    }, [payload, query]);

    return (
        <div>
            <Parts parts={payload.parts} />
            {payload.kind === 'lookup' && (
                <Input
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('dash.search_stock')}
                    className="mt-1 mb-2 h-8 text-sm"
                    aria-label={t('dash.search_stock_label')}
                />
            )}
            {rows.length === 0 ? (
                <div className="mt-2">
                    <EmptyNote text={query ? t('dash.nothing_matches') : emptyText} calm={!query} />
                </div>
            ) : (
                <div className="-mx-1 mt-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground text-left text-[11px] tracking-wide">
                                {payload.columns.map((column) => (
                                    <th key={column.key} className={cn('px-1.5 pb-2 font-medium', column.align === 'right' && 'text-right')}>
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            <AnimatePresence initial={false}>
                                {rows.map((row) => (
                                    // ui-motion: Obscuration in reverse - a new row grows in where it
                                    // belongs, a finished one fades out, so the list never jumps.
                                    <motion.tr
                                        key={row.id}
                                        layout={!reduce}
                                        initial={reduce ? false : { opacity: 0, y: -6 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        exit={reduce ? { opacity: 0, transition: { duration: 0 } } : { opacity: 0, x: 12 }}
                                        transition={{ duration: 0.3, ease: EASE }}
                                        className={cn('transition-colors odd:bg-[var(--dash-zebra)] hover:bg-[var(--acc-wash)]', toneText(row.tone))}
                                    >
                                        {payload.columns.map((column, index) => {
                                            const cell = row.cells[column.key];
                                            const text =
                                                typeof cell === 'number' && column.unit
                                                    ? formatValue(cell, column.unit)
                                                    : cell === null || cell === ''
                                                      ? '-'
                                                      : String(cell);
                                            const edge = index === 0 ? 'rounded-l-lg' : index === payload.columns.length - 1 ? 'rounded-r-lg' : '';

                                            let content;

                                            if (index === 0 && row.url) {
                                                content = (
                                                    <Link href={row.url} className="font-medium text-[var(--acc-ink)] hover:underline">
                                                        {text}
                                                    </Link>
                                                );
                                            } else if (PILL_COLUMNS.has(column.key) && text !== '-') {
                                                content = <StatusPill text={text} />;
                                            } else if (PERSON_COLUMNS.has(column.key) && text !== '-') {
                                                content = <Person name={text} />;
                                            } else {
                                                content = <span className={index === 0 ? 'font-medium' : 'text-muted-foreground'}>{text}</span>;
                                            }

                                            return (
                                                <td
                                                    key={column.key}
                                                    className={cn(
                                                        'px-1.5 py-2 align-middle',
                                                        edge,
                                                        column.align === 'right' && 'text-right whitespace-nowrap tabular-nums',
                                                    )}
                                                >
                                                    {content}
                                                </td>
                                            );
                                        })}
                                    </motion.tr>
                                ))}
                            </AnimatePresence>
                        </tbody>
                    </table>
                    {payload.total_count > rows.length && payload.kind === 'list' && (
                        <p className="text-muted-foreground mt-2 px-1.5 text-xs">
                            {t('dash.shown_of', { shown: rows.length, total: payload.total_count })}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function SignalsBody({ payload }: { payload: SignalsPayload }) {
    const { t } = useTranslation();

    if (payload.items.length === 0) {
        return <EmptyNote text={t('dash.no_signals')} calm />;
    }

    return (
        <ul className="grid gap-1.5 sm:grid-cols-2">
            {payload.items.map((item) => {
                const waiting = item.count > 0;

                return (
                    <li key={item.key}>
                        <Link
                            href={item.url}
                            className={cn(
                                'flex h-full items-center gap-3 rounded-xl px-2.5 py-2 ring-1 transition-colors ring-inset',
                                waiting
                                    ? 'bg-amber-500/8 ring-amber-500/20 hover:bg-amber-500/15'
                                    : 'bg-emerald-500/6 ring-emerald-500/15 hover:bg-emerald-500/12',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-semibold tabular-nums',
                                    waiting
                                        ? 'bg-amber-500/20 text-amber-800 dark:text-amber-300'
                                        : 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300',
                                )}
                            >
                                {waiting ? (
                                    <AnimatedNumber value={item.count} unit="count" />
                                ) : (
                                    <CircleCheck className="size-4" aria-label={t('dash.colour.none')} />
                                )}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">{item.label}</span>
                                <span className="text-muted-foreground block truncate text-xs">{item.hint}</span>
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

function ProgressBody({ payload, emptyText }: { payload: ProgressPayload; emptyText: string }) {
    const reduce = useReducedMotion();
    const { t } = useTranslation();
    const share = payload.target ? Math.min(1, Math.max(0, payload.value / payload.target)) : 0;

    return (
        <div>
            <p className="text-[1.75rem] leading-tight font-semibold tracking-tight">
                <AnimatedNumber value={payload.value} unit={payload.unit} />
            </p>
            {payload.target ? (
                <>
                    <p className="text-muted-foreground flex items-center gap-2 text-xs">
                        {t('dash.colour.of_target', { target: formatValue(payload.target, payload.unit) })}
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                                share >= 1 ? PILL.good : PILL.info,
                            )}
                        >
                            {Math.round(share * 100)}%
                        </span>
                    </p>
                    <div
                        className="mt-3 h-3 overflow-hidden rounded-full bg-[var(--dash-track)]"
                        role="progressbar"
                        aria-valuenow={Math.round(share * 100)}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    >
                        <motion.div
                            className="h-full w-full origin-left rounded-full bg-gradient-to-r from-[var(--acc)] to-[var(--acc-2)]"
                            initial={reduce ? false : { scaleX: 0 }}
                            animate={{ scaleX: share }}
                            transition={reduce ? { duration: 0 } : { duration: 0.9, ease: EASE, delay: 0.2 }}
                        />
                    </div>
                </>
            ) : (
                <div className="mt-2">
                    <EmptyNote text={emptyText} />
                </div>
            )}
            <Parts parts={payload.parts} />
        </div>
    );
}

/** Needs attention's top rows, the same component as /attention, with the counts above as coloured pills. */
function AttentionBody({ payload, emptyText }: { payload: AttentionPayload; emptyText: string }) {
    const { t } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const userId = (auth?.user?.id as number | undefined) ?? null;
    const severityPill = { critical: PILL.bad, high: PILL.warn, normal: PILL.info } as const;

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2 text-xs">
                {(['critical', 'high', 'normal'] as const).map((severity) => (
                    <span
                        key={severity}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 tabular-nums ring-1 ring-inset',
                            payload.counts[severity] > 0 ? severityPill[severity] : PILL.neutral,
                        )}
                    >
                        <span className="text-sm font-semibold">{payload.counts[severity]}</span> {t(`attention.severity.${severity}`)}
                    </span>
                ))}
            </div>
            {payload.failed.length > 0 && (
                <p className="text-xs text-amber-700 dark:text-amber-300">
                    {t('attention.failed_note', { areas: payload.failed.map((f) => f.area).join(', ') })}
                </p>
            )}
            {payload.items.length === 0 ? <EmptyNote text={emptyText} calm /> : <AttentionList items={payload.items} userId={userId} compact />}
            {payload.total_count > payload.items.length && (
                <Link href="/attention" className="text-xs font-medium text-[var(--acc-ink)] hover:underline">
                    {t('attention.more', { count: payload.total_count })}
                </Link>
            )}
        </div>
    );
}
