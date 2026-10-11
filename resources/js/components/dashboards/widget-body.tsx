import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useMemo, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AnimatedNumber } from './animated-number';
import { changeAgainst, formatValue } from './format';
import type { BreakdownPayload, ListPayload, Part, ProgressPayload, SeriesPayload, SignalsPayload, StatPayload, Tone, WidgetPayload } from './types';

const SERIES_COLORS = ['var(--chart-1)', 'var(--chart-4)', 'var(--chart-3)', 'var(--chart-2)', 'var(--chart-5)'];

const toneText = (tone: Tone) =>
    tone === 'bad'
        ? 'text-red-600 dark:text-red-400'
        : tone === 'warn'
          ? 'text-amber-600 dark:text-amber-400'
          : tone === 'good'
            ? 'text-emerald-600 dark:text-emerald-400'
            : '';

const toneFill = (tone: Tone, index: number) =>
    tone === 'bad'
        ? 'var(--destructive)'
        : tone === 'warn'
          ? 'var(--chart-3)'
          : tone === 'good'
            ? 'var(--chart-1)'
            : SERIES_COLORS[index % SERIES_COLORS.length];

export function WidgetBody({ payload, emptyText }: { payload: WidgetPayload; emptyText: string }) {
    switch (payload.kind) {
        case 'stat':
            return <StatBody payload={payload} />;
        case 'series':
            return <SeriesBody payload={payload} />;
        case 'breakdown':
            return <BreakdownBody payload={payload} emptyText={emptyText} />;
        case 'list':
        case 'lookup':
            return <ListBody payload={payload} emptyText={emptyText} />;
        case 'signals':
            return <SignalsBody payload={payload} />;
        case 'progress':
            return <ProgressBody payload={payload} emptyText={emptyText} />;
        default:
            return <p className="text-muted-foreground text-sm">{payload.message}</p>;
    }
}

function Parts({ parts }: { parts?: Part[] }) {
    if (!parts || parts.length === 0) {
        return null;
    }

    return (
        <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs sm:grid-cols-3">
            {parts.map((part) => {
                const body = (
                    <>
                        <dt className="text-muted-foreground truncate">{part.label}</dt>
                        <dd className={cn('font-medium', toneText(part.tone))}>
                            {typeof part.value === 'number' && part.unit !== 'text' ? (
                                <AnimatedNumber value={part.value} unit={part.unit ?? 'count'} compact />
                            ) : (
                                formatValue(part.value, part.unit ?? 'text')
                            )}
                        </dd>
                    </>
                );

                return part.url ? (
                    <Link key={part.label} href={part.url} className="hover:bg-accent -mx-1 rounded px-1">
                        {body}
                    </Link>
                ) : (
                    <div key={part.label}>{body}</div>
                );
            })}
        </dl>
    );
}

function StatBody({ payload }: { payload: StatPayload }) {
    const change = typeof payload.value === 'number' ? changeAgainst(payload.value, payload.previous) : null;

    return (
        <div>
            <div className="flex items-baseline gap-2">
                <p className={cn('text-2xl font-semibold tracking-tight', toneText(payload.tone))}>
                    {typeof payload.value === 'number' ? <AnimatedNumber value={payload.value} unit={payload.unit} /> : payload.value}
                </p>
                {change && (
                    <span
                        className={cn(
                            'text-xs font-medium',
                            change.up ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400',
                        )}
                        title="Against the previous period"
                    >
                        {change.text}
                    </span>
                )}
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
    payload?: { name: string; value: number; color: string }[];
    label?: string;
    unit: StatPayload['unit'];
}) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md" role="status">
            <p className="mb-1 font-medium">{label}</p>
            {payload.map((item) => (
                <p key={item.name} className="flex items-center gap-2">
                    <span className="inline-block size-2 rounded-sm" style={{ background: item.color }} />
                    <span className="text-muted-foreground">{item.name}</span>
                    <span className="ml-auto font-medium tabular-nums">{formatValue(item.value, unit)}</span>
                </p>
            ))}
        </div>
    );
}

function SeriesBody({ payload }: { payload: SeriesPayload }) {
    const reduce = useReducedMotion();
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

    return (
        <div>
            {payload.total !== undefined && (
                <div className="mb-2 flex items-baseline gap-2">
                    <span className="text-muted-foreground text-xs">{payload.total_label ?? 'Total'}</span>
                    <span className="text-xl font-semibold">
                        <AnimatedNumber value={payload.total} unit={payload.unit} />
                    </span>
                    {change && (
                        <span
                            className={cn('text-xs font-medium', change.up ? 'text-emerald-600' : 'text-amber-600')}
                            title="Against the previous period"
                        >
                            {change.text}
                        </span>
                    )}
                </div>
            )}
            {!hasData ? (
                <p className="text-muted-foreground py-10 text-center text-sm">Nothing posted in this range.</p>
            ) : (
                <div className="h-56 w-full" role="img" aria-label={summary}>
                    <ResponsiveContainer width="100%" height="100%">
                        {payload.chart === 'bar' ? (
                            <BarChart data={payload.points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} minTickGap={16} />
                                <YAxis
                                    tickLine={false}
                                    axisLine={false}
                                    fontSize={11}
                                    width={56}
                                    tickFormatter={(v: number) => formatValue(v, 'count', true)}
                                />
                                <Tooltip content={<ChartTooltip unit={payload.unit} />} cursor={{ fill: 'var(--accent)' }} />
                                {payload.series.map((s, i) => (
                                    <Bar
                                        key={s.key}
                                        dataKey={s.key}
                                        name={s.label}
                                        fill={SERIES_COLORS[i]}
                                        radius={[3, 3, 0, 0]}
                                        isAnimationActive={!reduce}
                                        animationDuration={500}
                                    />
                                ))}
                            </BarChart>
                        ) : (
                            <AreaChart data={payload.points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                                <defs>
                                    {payload.series.map((s, i) => (
                                        <linearGradient key={s.key} id={`fill-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor={SERIES_COLORS[i]} stopOpacity={0.35} />
                                            <stop offset="100%" stopColor={SERIES_COLORS[i]} stopOpacity={0.02} />
                                        </linearGradient>
                                    ))}
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                                <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={11} minTickGap={16} />
                                <YAxis
                                    tickLine={false}
                                    axisLine={false}
                                    fontSize={11}
                                    width={56}
                                    tickFormatter={(v: number) => formatValue(v, 'count', true)}
                                />
                                <Tooltip content={<ChartTooltip unit={payload.unit} />} />
                                {payload.series.map((s, i) => (
                                    <Area
                                        key={s.key}
                                        type="monotone"
                                        dataKey={s.key}
                                        name={s.label}
                                        stroke={SERIES_COLORS[i]}
                                        strokeWidth={2}
                                        fill={`url(#fill-${s.key})`}
                                        isAnimationActive={!reduce}
                                        animationDuration={600}
                                    />
                                ))}
                            </AreaChart>
                        )}
                    </ResponsiveContainer>
                </div>
            )}
            <div className="text-muted-foreground mt-2 flex flex-wrap gap-4 text-xs">
                {payload.series.map((s, i) => (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span className="inline-block size-2.5 rounded-sm" style={{ background: SERIES_COLORS[i] }} />
                        {s.label}
                    </span>
                ))}
            </div>
        </div>
    );
}

function BreakdownBody({ payload, emptyText }: { payload: BreakdownPayload; emptyText: string }) {
    const reduce = useReducedMotion();
    const max = Math.max(1, ...payload.items.map((i) => Math.abs(i.value)));
    const total = payload.items.reduce((sum, i) => sum + i.value, 0);

    return (
        <div>
            {payload.headline && <p className="mb-2 text-sm font-medium">{payload.headline}</p>}
            {payload.total !== undefined && (
                <p className="mb-2 text-xl font-semibold">
                    <AnimatedNumber value={payload.total} unit={payload.unit} />
                </p>
            )}
            {payload.items.length === 0 ? (
                <p className="text-muted-foreground text-sm">{emptyText}</p>
            ) : payload.chart === 'donut' ? (
                <div className="flex items-center gap-4">
                    <div className="size-32 shrink-0" role="img" aria-label={payload.items.map((i) => `${i.label} ${i.value}`).join(', ')}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={payload.items}
                                    dataKey="value"
                                    nameKey="label"
                                    innerRadius="58%"
                                    outerRadius="100%"
                                    paddingAngle={2}
                                    stroke="none"
                                    isAnimationActive={!reduce}
                                    animationDuration={600}
                                >
                                    {payload.items.map((item, index) => (
                                        <Cell key={item.label} fill={toneFill(item.tone, index)} />
                                    ))}
                                </Pie>
                                <Tooltip content={<ChartTooltip unit={payload.unit} />} />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                    <ul className="min-w-0 flex-1 space-y-1 text-xs">
                        {payload.items.map((item, index) => (
                            <li key={item.label} className="flex items-center gap-2">
                                <span className="inline-block size-2.5 shrink-0 rounded-sm" style={{ background: toneFill(item.tone, index) }} />
                                <span className="text-muted-foreground truncate">{item.label}</span>
                                <span className="ml-auto font-medium tabular-nums">
                                    <AnimatedNumber value={item.value} unit={payload.unit} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <ul className="space-y-2">
                    {payload.items.map((item, index) => {
                        const row = (
                            <>
                                <div className="flex items-baseline justify-between gap-2 text-xs">
                                    <span className="text-muted-foreground truncate" title={item.hint}>
                                        {item.label}
                                    </span>
                                    <span className={cn('font-medium tabular-nums', toneText(item.tone))}>
                                        <AnimatedNumber value={item.value} unit={payload.unit} compact />
                                    </span>
                                </div>
                                <div className="bg-muted mt-1 h-2 overflow-hidden rounded-full">
                                    {/* ui-motion: Transformation (scale from the left edge), no layout property animated. */}
                                    <motion.div
                                        className="h-full origin-left rounded-full"
                                        style={{ background: toneFill(item.tone, index), width: '100%' }}
                                        initial={false}
                                        animate={{ scaleX: Math.max(Math.abs(item.value) / max, item.value === 0 ? 0 : 0.02) }}
                                        transition={reduce ? { duration: 0 } : { duration: 0.6, ease: [0.22, 1, 0.36, 1], delay: index * 0.03 }}
                                    />
                                </div>
                            </>
                        );

                        return (
                            <li key={item.label}>
                                {item.url ? (
                                    <Link href={item.url} className="hover:bg-accent -mx-1 block rounded px-1 py-0.5">
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
            {payload.caption && <p className="text-muted-foreground mt-2 text-xs">{payload.caption}</p>}
            {payload.chart === 'donut' && payload.total === undefined && <p className="sr-only">Total {formatValue(total, payload.unit)}</p>}
            <Parts parts={payload.parts} />
        </div>
    );
}

function ListBody({ payload, emptyText }: { payload: ListPayload; emptyText: string }) {
    const reduce = useReducedMotion();
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
                    placeholder="Search a product or SKU"
                    className="mt-1 mb-2 h-8 text-sm"
                    aria-label="Search stock"
                />
            )}
            {rows.length === 0 ? (
                <p className="text-muted-foreground mt-2 text-sm">{query ? 'Nothing matches.' : emptyText}</p>
            ) : (
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-xs">
                                {payload.columns.map((column) => (
                                    <th key={column.key} className={cn('pb-1.5 font-medium', column.align === 'right' && 'text-right')}>
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
                                        transition={{ duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                                        className={cn('border-b last:border-0', toneText(row.tone))}
                                    >
                                        {payload.columns.map((column, index) => {
                                            const cell = row.cells[column.key];
                                            const text =
                                                typeof cell === 'number' && column.unit
                                                    ? formatValue(cell, column.unit)
                                                    : cell === null || cell === ''
                                                      ? '-'
                                                      : String(cell);

                                            return (
                                                <td
                                                    key={column.key}
                                                    className={cn(
                                                        'py-1.5 pr-2 align-top',
                                                        column.align === 'right' && 'text-right whitespace-nowrap tabular-nums',
                                                    )}
                                                >
                                                    {index === 0 && row.url ? (
                                                        <Link href={row.url} className="text-primary hover:underline">
                                                            {text}
                                                        </Link>
                                                    ) : (
                                                        <span className={index === 0 ? '' : 'text-muted-foreground'}>{text}</span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                    </motion.tr>
                                ))}
                            </AnimatePresence>
                        </tbody>
                    </table>
                    {payload.total_count > rows.length && payload.kind === 'list' && (
                        <p className="text-muted-foreground mt-1.5 text-xs">
                            {rows.length} of {payload.total_count} shown
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function SignalsBody({ payload }: { payload: SignalsPayload }) {
    if (payload.items.length === 0) {
        return <p className="text-muted-foreground text-sm">No signals for your role.</p>;
    }

    return (
        <ul className="divide-y">
            {payload.items.map((item) => (
                <li key={item.key}>
                    <Link href={item.url} className="hover:bg-accent -mx-2 flex items-center gap-3 rounded px-2 py-2">
                        <span
                            className={cn(
                                'flex h-7 min-w-7 items-center justify-center rounded-full px-2 text-xs font-semibold',
                                item.count > 0
                                    ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                    : 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
                            )}
                        >
                            <AnimatedNumber value={item.count} unit="count" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm">{item.label}</span>
                            <span className="text-muted-foreground block truncate text-xs">{item.hint}</span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function ProgressBody({ payload, emptyText }: { payload: ProgressPayload; emptyText: string }) {
    const reduce = useReducedMotion();
    const share = payload.target ? Math.min(1, Math.max(0, payload.value / payload.target)) : 0;

    return (
        <div>
            <p className="text-2xl font-semibold">
                <AnimatedNumber value={payload.value} unit={payload.unit} />
            </p>
            {payload.target ? (
                <>
                    <p className="text-muted-foreground text-xs">
                        of {formatValue(payload.target, payload.unit)} target ({Math.round(share * 100)}%)
                    </p>
                    <div
                        className="bg-muted mt-2 h-3 overflow-hidden rounded-full"
                        role="progressbar"
                        aria-valuenow={Math.round(share * 100)}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    >
                        <motion.div
                            className="h-full w-full origin-left rounded-full"
                            style={{ background: share >= 1 ? 'var(--chart-1)' : 'var(--chart-4)' }}
                            initial={false}
                            animate={{ scaleX: share }}
                            transition={reduce ? { duration: 0 } : { duration: 0.8, ease: [0.22, 1, 0.36, 1] }}
                        />
                    </div>
                </>
            ) : (
                <p className="text-muted-foreground text-xs">{emptyText}</p>
            )}
            <Parts parts={payload.parts} />
        </div>
    );
}
