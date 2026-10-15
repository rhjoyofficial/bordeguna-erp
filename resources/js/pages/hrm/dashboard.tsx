import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Info } from 'lucide-react';
import { useState } from 'react';

interface Option {
    value: number;
    label: string;
}

interface Widget {
    key: string;
    title: string;
    description: string;
    group: string;
    value: string | number | null;
    display: string;
    type: string;
    delta: number | null;
    delta_display: string | null;
    delta_percent: string | null;
    delta_label: string | null;
    note: string | null;
    /** C11b: the figure describes a state the system has not caught up with. */
    stale: boolean;
    drilldown_report: string | null;
    drilldown_parameters: Record<string, string | number>;
}

interface TrendPoint {
    date: string;
    label: string;
    weekday: string;
    present: number;
    late: number;
    absent: number;
    leave: number;
    off: number;
}

interface Trend {
    points: TrendPoint[];
    totals: Record<string, number>;
    days: number;
    truncated: boolean;
    rows: number;
}

interface Range {
    from: string;
    to: string;
    label?: string;
}

interface PayrollLatest {
    run_id: number;
    period: string;
    year: number;
    month: number;
    version: number;
    status: string;
    status_label: string;
    blocked_count: number;
    net: number;
    net_display: string;
    delta: number | null;
    delta_display: string | null;
    delta_label: string | null;
    paid_on: string | null;
}

interface PayrollNext {
    period: string;
    start: string;
    end: string;
    end_display: string;
    days_to_end: number;
    run_status: string | null;
}

interface Props {
    widgets: Widget[];
    trend: Trend;
    attention: { holds: number };
    distribution: { department: string; count: number }[];
    filters: {
        office_id: number | null;
        department_id: number | null;
        range_from: string;
        range_to: string;
        as_at: string;
    };
    ranges: { today: Range; week: Range; cycle: Range };
    /** Null for anyone the registry withholds the payroll tiles from. */
    payroll: { latest: PayrollLatest | null; next: PayrollNext } | null;
    /** Null for anyone without the employee register. */
    staff: { on_probation: number; joined_this_year: number; left_this_year: number } | null;
    offices: Option[];
    departments: Option[];
    department_locked: boolean;
}

/**
 * The four states a day is made of, in stacking order (present at the foot).
 * Colour by meaning, from the theme: green worked, amber late, red absent,
 * blue away with permission. The same four colours draw the Today strip, the
 * trend bars and both legends, so a reader learns them once.
 */
const SERIES = [
    { key: 'present' as const, widget: 'attendance_present_today', label: 'Present', bar: 'bg-primary', text: '' },
    { key: 'late' as const, widget: 'attendance_late_today', label: 'Late', bar: 'bg-chart-3', text: 'text-amber-800 dark:text-amber-300' },
    { key: 'absent' as const, widget: 'attendance_absent_today', label: 'Absent', bar: 'bg-destructive', text: 'text-destructive' },
    { key: 'leave' as const, widget: 'attendance_on_leave', label: 'On leave', bar: 'bg-chart-4', text: '' },
];

/** Weekly off and holidays: hatched, not coloured, so a quiet Friday never reads as an empty office. */
const HATCH = 'bg-[repeating-linear-gradient(135deg,var(--muted)_0_6px,transparent_6px_12px)]';

/** Count badges in the "Needs you" list, by meaning. */
const TONES = {
    green: 'bg-primary/10 text-primary',
    amber: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    red: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
    blue: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    muted: 'bg-muted text-muted-foreground',
};

type Tone = keyof typeof TONES;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/**
 * "15 Oct" from "2026-10-15", built by hand. `new Date('2026-10-15')` is UTC
 * midnight, which in Dhaka is fine and west of Greenwich is the day before;
 * splitting the string cannot drift.
 */
function shortDate(iso: string, withWeekday = false): string {
    const [y, m, d] = iso.split('-').map(Number);
    const text = `${d} ${MONTHS[m - 1] ?? ''}`;
    return withWeekday ? `${DAYS[new Date(y, m - 1, d).getDay()]} ${text}` : text;
}

function count(widget: Widget | undefined): number {
    const n = Number(widget?.value ?? 0);
    return Number.isFinite(n) ? n : 0;
}

/** The (i) that holds what used to be a paragraph under every tile. */
function InfoTip({ text }: { text: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button type="button" className="text-muted-foreground hover:text-foreground inline-flex rounded-full" aria-label="What this counts">
                    <Info className="h-3.5 w-3.5" />
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs text-xs leading-relaxed">
                {text}
            </TooltipContent>
        </Tooltip>
    );
}

function CardTitle({ children }: { children: React.ReactNode }) {
    return <h2 className="text-[15px] font-semibold">{children}</h2>;
}

/**
 * One stacked bar for the day plus the four counts, each a link to the exact
 * rows behind it. The freshness line underneath is the C11b honesty: these
 * figures are only as current as the last ProcessAttendance run.
 */
function TodayStrip({
    byKey,
    asAt,
    canProcess,
    onDrilldown,
}: {
    byKey: Record<string, Widget>;
    asAt: string;
    canProcess: boolean;
    onDrilldown: (widget: Widget) => void;
}) {
    const [processing, setProcessing] = useState(false);
    const present = SERIES.map((s) => ({ ...s, widget: byKey[s.widget] })).filter((s) => s.widget !== undefined);

    if (present.length === 0) return null;

    const total = present.reduce((sum, s) => sum + count(s.widget), 0);
    // The three processed tiles share one freshness note; the leave tile
    // deliberately carries none (it reads the register, not the engine).
    const processed = present.find((s) => s.key !== 'leave')?.widget;

    const processToday = () => {
        setProcessing(true);
        router.post(route('hrm.attendance.daily.process'), { on: asAt }, { preserveScroll: true, onFinish: () => setProcessing(false) });
    };

    return (
        <section className="bg-card flex flex-wrap items-center gap-5 rounded-xl border px-5 py-4">
            <div className="min-w-[180px] flex-1 basis-[200px]">
                <div className="text-muted-foreground flex items-center gap-2 text-xs">
                    <span>
                        {shortDate(asAt, true)}, {total} {total === 1 ? 'person' : 'people'} counted
                    </span>
                    {processed?.stale && (
                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                            Not current
                        </span>
                    )}
                </div>
                <div
                    className="bg-muted mt-2 flex h-2.5 overflow-hidden rounded-full"
                    role="img"
                    aria-label={present.map((s) => `${count(s.widget)} ${s.label.toLowerCase()}`).join(', ')}
                >
                    {total > 0 && present.map((s) => <div key={s.key} className={s.bar} style={{ width: `${(count(s.widget) / total) * 100}%` }} />)}
                </div>
                {processed?.note && (
                    <p className="text-muted-foreground mt-2 text-xs">
                        {processed.note}
                        {canProcess && (
                            <>
                                {' '}
                                <span aria-hidden="true">·</span>{' '}
                                <button
                                    type="button"
                                    onClick={processToday}
                                    disabled={processing}
                                    className="text-primary hover:underline disabled:opacity-60"
                                >
                                    {processing ? 'Processing' : 'Process this day'}
                                </button>
                            </>
                        )}
                    </p>
                )}
            </div>

            <div className="flex flex-wrap gap-x-8 gap-y-2">
                {present.map((s) => {
                    const w = s.widget as Widget;
                    const figure = (
                        <span className={cn('text-2xl font-semibold tabular-nums', s.text, w.stale && 'text-muted-foreground')}>{w.display}</span>
                    );

                    return (
                        <div key={s.key}>
                            <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                <span className={cn('h-2 w-2 rounded-full', s.bar)} />
                                {w.title}
                                <InfoTip text={w.description} />
                            </div>
                            {w.drilldown_report ? (
                                <button type="button" onClick={() => onDrilldown(w)} className="block hover:underline" title="Show me these">
                                    {figure}
                                </button>
                            ) : (
                                figure
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

/**
 * Attendance day by day. Stacked, because the question is "how was that day
 * made up", not "how do four unrelated lines move".
 *
 * ⚠ Weekly off and holidays are drawn as a hatched column rather than left
 * out. A Friday with nine people off would otherwise be an empty column that
 * reads as a catastrophe. A day with nothing processed at all stays empty,
 * because that IS the honest picture of it.
 */
function AttendanceTrendCard({ trend, from, to }: { trend: Trend; from: string; to: string }) {
    const sum = (key: 'present' | 'late' | 'absent' | 'leave') => trend.points.reduce((n, p) => n + p[key], 0);
    const worked = sum('present') + sum('late');
    const onTime = worked > 0 ? Math.round((sum('present') / worked) * 100) : null;
    const max = Math.max(1, ...trend.points.map((p) => p.present + p.late + p.absent + p.leave));

    return (
        <section className="bg-card min-w-0 rounded-xl border px-5 pt-4 pb-3 lg:col-span-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <CardTitle>
                    Attendance, {shortDate(from)} to {shortDate(to)}
                </CardTitle>
                <div className="text-muted-foreground flex flex-wrap gap-3.5 text-xs">
                    {SERIES.map((s) => (
                        <span key={s.key} className="flex items-center gap-1.5">
                            <span className={cn('h-2 w-2 rounded-sm', s.bar)} /> {s.label}
                        </span>
                    ))}
                    <span className="flex items-center gap-1.5">
                        <span className={cn('h-2 w-2 rounded-sm border', HATCH)} /> Weekly off or holiday
                    </span>
                </div>
            </div>

            {trend.rows === 0 ? (
                <p className="text-muted-foreground mt-4 text-sm">
                    No attendance has been processed in this period, so there is nothing to chart yet.
                </p>
            ) : (
                <>
                    <div className="mt-2.5 flex flex-wrap gap-x-5 gap-y-1">
                        <div>
                            <span className="text-xl font-semibold tabular-nums">{onTime === null ? 'n/a' : `${onTime}%`}</span>{' '}
                            <span className="text-muted-foreground text-xs">on time</span>
                        </div>
                        <div>
                            <span className="text-xl font-semibold tabular-nums">{sum('late')}</span>{' '}
                            <span className="text-muted-foreground text-xs">late arrivals</span>
                        </div>
                        <div>
                            <span className="text-xl font-semibold tabular-nums">{sum('absent')}</span>{' '}
                            <span className="text-muted-foreground text-xs">absences</span>
                        </div>
                    </div>

                    <div className="mt-3.5 overflow-x-auto">
                        <div className="flex h-44 min-w-[560px] items-end gap-1.5 border-b pb-1">
                            {trend.points.map((point) => {
                                const dayTotal = point.present + point.late + point.absent + point.leave;
                                const offOnly = dayTotal === 0 && point.off > 0;
                                const title = offOnly
                                    ? `${point.label} (${point.weekday}): weekly off or holiday`
                                    : `${point.label} (${point.weekday}): ${point.present} present, ${point.late} late, ${point.absent} absent, ${point.leave} on leave`;

                                return (
                                    <div key={point.date} className="flex h-full flex-1 flex-col justify-end gap-[2px]" title={title}>
                                        {offOnly ? (
                                            <div className={cn('h-full rounded-sm', HATCH)} />
                                        ) : (
                                            [...SERIES]
                                                .reverse()
                                                .map((s) =>
                                                    point[s.key] > 0 ? (
                                                        <div
                                                            key={s.key}
                                                            className={cn('rounded-sm', s.bar)}
                                                            style={{ height: `${(point[s.key] / max) * 100}%` }}
                                                        />
                                                    ) : null,
                                                )
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        {/* Every third date, so a month of labels does not become a smear. */}
                        <div className="mt-1.5 flex min-w-[560px] gap-1.5">
                            {trend.points.map((point, i) => (
                                <div key={point.date} className="text-muted-foreground flex-1 text-center text-[11px] whitespace-nowrap">
                                    {i % 3 === 0 ? point.label : ''}
                                </div>
                            ))}
                        </div>
                    </div>

                    {trend.truncated && (
                        <p className="text-muted-foreground mt-2 text-xs">
                            Showing the last {trend.days} days of a longer range, so the bars stay readable.
                        </p>
                    )}
                </>
            )}
        </section>
    );
}

interface NeedsRow {
    key: string;
    count: number;
    tone: Tone;
    title: string;
    sub: string | null;
    description?: string;
    href?: string;
    onClick?: () => void;
}

function NeedsYouCard({ rows }: { rows: NeedsRow[] }) {
    return (
        <section className="bg-card min-w-0 overflow-hidden rounded-xl border">
            <div className="flex items-baseline justify-between px-4 pt-4 pb-2.5">
                <CardTitle>Needs you</CardTitle>
                <span className="text-muted-foreground text-xs">
                    {rows.length} {rows.length === 1 ? 'item' : 'items'}
                </span>
            </div>
            {rows.length === 0 && <p className="text-muted-foreground border-t px-4 py-4 text-sm">Nothing is waiting on you right now.</p>}
            {rows.map((row) => {
                // The (i) sits beside the link, not inside it: a button inside
                // an anchor is invalid HTML and reads as one control to a screen
                // reader.
                const body = (
                    <>
                        <span
                            className={cn(
                                'grid h-8 w-8 shrink-0 place-items-center rounded-lg text-[13px] font-semibold tabular-nums',
                                TONES[row.tone],
                            )}
                        >
                            {row.count}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-[13.5px] font-medium">{row.title}</span>
                            {row.sub && <span className="text-muted-foreground block text-xs">{row.sub}</span>}
                        </span>
                    </>
                );
                const className = 'flex min-w-0 flex-1 items-center gap-3 py-3 pl-4 text-left';

                return (
                    <div key={row.key} className="hover:bg-muted/60 flex items-center gap-2 border-t pr-4">
                        {row.href ? (
                            <Link href={row.href} className={className}>
                                {body}
                            </Link>
                        ) : (
                            <button type="button" onClick={row.onClick} className={className}>
                                {body}
                            </button>
                        )}
                        {row.description && <InfoTip text={row.description} />}
                        <ChevronRight className="text-muted-foreground h-4 w-4 shrink-0" aria-hidden="true" />
                    </div>
                );
            })}
        </section>
    );
}

/** The five steps of WFL-02 as a progress line. Draft fills none; a voided version fills none and says why. */
const STEPS = ['Calculated', 'Reviewed', 'Approved', 'Paid', 'Locked'];
const FILLED: Record<string, number> = { draft: 0, calculated: 1, reviewed: 2, approved: 3, disbursed: 4, locked: 5 };

function PayrollCard({ latest, next, links }: { latest: PayrollLatest | null; next: PayrollNext; links: { label: string; widget: Widget }[] }) {
    const filled = latest ? (FILLED[latest.status] ?? 0) : 0;
    const voided = latest !== null && (latest.status === 'rejected' || latest.status === 'cancelled');

    return (
        <section className="bg-card min-w-0 rounded-xl border px-5 py-4">
            <div className="flex items-baseline justify-between gap-2">
                <CardTitle>Payroll</CardTitle>
                <Link href="/hrm/payroll" className="text-primary text-xs hover:underline">
                    Open payroll
                </Link>
            </div>

            {latest === null ? (
                <p className="text-muted-foreground mt-3 text-sm">No payroll run has been made yet.</p>
            ) : (
                <>
                    <div className="mt-3 flex flex-wrap items-baseline justify-between gap-3">
                        <div>
                            <div className="text-muted-foreground text-xs">
                                {latest.period}
                                {latest.paid_on ? `, paid ${latest.paid_on}` : `, ${latest.status_label.toLowerCase()}`}
                                {latest.version > 1 ? ` (v${latest.version})` : ''}
                            </div>
                            <div className="mt-0.5 text-2xl font-semibold tabular-nums">{latest.net_display}</div>
                        </div>
                        {latest.delta_display && (
                            <div
                                className={cn('text-xs font-medium', latest.delta !== null && latest.delta < 0 ? 'text-destructive' : 'text-primary')}
                            >
                                {latest.delta_display} {latest.delta_label}
                            </div>
                        )}
                    </div>

                    <div
                        className="mt-4 flex gap-1"
                        role="img"
                        aria-label={`${latest.period}: ${filled === 0 ? 'not yet calculated' : STEPS.slice(0, filled).join(', ').toLowerCase()}`}
                    >
                        {STEPS.map((step, i) => (
                            <div key={step} className={cn('h-1.5 flex-1 rounded-full', i < filled ? 'bg-primary' : 'bg-muted')} />
                        ))}
                    </div>
                    <div className="text-muted-foreground mt-1.5 flex justify-between text-[11px]">
                        {STEPS.map((step) => (
                            <span key={step}>{step}</span>
                        ))}
                    </div>

                    {voided && (
                        <p className="text-destructive mt-2 text-xs">
                            This version was {latest.status_label.toLowerCase()}; a fresh calculation is needed.
                        </p>
                    )}
                    {latest.blocked_count > 0 && (
                        <p className="mt-2 text-xs text-amber-800 dark:text-amber-300">
                            {latest.blocked_count} {latest.blocked_count === 1 ? 'payslip is' : 'payslips are'} blocked.
                        </p>
                    )}
                </>
            )}

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-3.5">
                <div>
                    <div className="text-[13.5px] font-medium">{next.period}</div>
                    <div className="text-muted-foreground text-xs">
                        {next.run_status ?? 'Not calculated yet'} <span aria-hidden="true">·</span> cycle ends {next.end_display}
                    </div>
                </div>
                <Button variant="outline" size="sm" asChild>
                    <Link href="/hrm/payroll">{next.run_status ? 'Open' : 'Calculate'}</Link>
                </Button>
            </div>

            {links.length > 0 && (
                <div className="text-muted-foreground mt-3 flex flex-wrap gap-x-4 text-xs">
                    {links.map(({ label, widget }) => (
                        <span key={widget.key} className="flex items-center gap-1">
                            <button type="button" onClick={() => drilldown(widget)} className="text-primary hover:underline">
                                {label}
                            </button>
                            <InfoTip text={widget.description} />
                        </span>
                    ))}
                </div>
            )}
        </section>
    );
}

function TeamsCard({ distribution, staff }: { distribution: Props['distribution']; staff: Props['staff'] }) {
    const active = distribution.reduce((n, d) => n + d.count, 0);
    const maxDept = Math.max(1, ...distribution.map((d) => d.count));

    return (
        <section className="bg-card min-w-0 rounded-xl border px-5 py-4">
            <div className="flex items-baseline justify-between">
                <CardTitle>Staff by team</CardTitle>
                <span className="text-muted-foreground text-xs">{active} active</span>
            </div>

            {distribution.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">No active assignments yet.</p>
            ) : (
                <div className="mt-4 flex flex-col gap-3.5">
                    {distribution.map((row) => (
                        <div key={row.department}>
                            <div className="flex justify-between text-[13px]">
                                <span>{row.department}</span>
                                <span className="font-semibold tabular-nums">{row.count}</span>
                            </div>
                            <div className="bg-muted mt-1.5 h-1.5 overflow-hidden rounded-full">
                                <div className="bg-primary h-full rounded-full" style={{ width: `${(row.count / maxDept) * 100}%` }} />
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {staff && (
                <div className="mt-4 flex flex-wrap gap-6 border-t pt-3.5">
                    {[
                        ['On probation', staff.on_probation],
                        ['Joined this year', staff.joined_this_year],
                        ['Left this year', staff.left_this_year],
                    ].map(([label, n]) => (
                        <div key={label}>
                            <div className="text-muted-foreground text-xs">{label}</div>
                            <div className="text-lg font-semibold tabular-nums">{n}</div>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

/** A widget this page has no place for yet: shown plainly rather than dropped. */
function SpareTile({ widget }: { widget: Widget }) {
    return (
        <div className={cn('bg-card flex flex-col gap-1 rounded-xl border p-4', widget.stale && 'border-dashed')}>
            <p className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
                {widget.title}
                <InfoTip text={widget.description} />
            </p>
            <p className={cn('text-2xl font-semibold tabular-nums', widget.stale && 'text-muted-foreground')}>{widget.display}</p>
            {widget.delta_display && (
                <p className="text-xs">
                    <span className={widget.delta !== null && widget.delta < 0 ? 'text-destructive' : ''}>{widget.delta_display}</span>
                    {widget.delta_percent && <span className="ml-1">({widget.delta_percent})</span>}
                    {widget.delta_label && <span className="text-muted-foreground ml-1">{widget.delta_label}</span>}
                </p>
            )}
            {widget.note && <p className="text-muted-foreground text-xs">{widget.note}</p>}
            {widget.drilldown_report && (
                <button type="button" onClick={() => drilldown(widget)} className="text-primary self-start text-xs hover:underline">
                    Show me these
                </button>
            )}
        </div>
    );
}

/** The tile's own link: the report that produced its figure, so the two cannot disagree (C11a). */
function drilldown(widget: Widget) {
    if (!widget.drilldown_report) return;

    const query = new URLSearchParams();
    Object.entries(widget.drilldown_parameters).forEach(([key, value]) => query.append(key, String(value)));
    query.append('format', 'print');

    window.open(`/hrm/reports/${widget.drilldown_report}/run?${query.toString()}`, '_blank', 'noopener');
}

/** The keys this page lays out by hand; anything else lands in the spare row. */
const PLACED = new Set([
    ...SERIES.map((s) => s.widget),
    'attendance_missing_punches',
    'approvals_pending',
    'payroll_current_status',
    'payroll_salary_total',
]);

/**
 * The HRM dashboard (RPT-04), in the layout Joy approved on 15 Oct 2026: a
 * Today strip, the attendance trend, a "Needs you" list, the payroll card
 * and staff by team. The app shell draws the sidebar, header and the HRM
 * tab row above this; the page draws no title and no tabs of its own.
 *
 * It renders what the server sends and computes nothing beyond arithmetic
 * on figures already on the page (a share of a bar, a sum of the trend). A
 * widget the viewer may not see never reaches this file at all: the
 * registry filters the payload, so every card checks for its widget and
 * quietly steps aside when it is absent. There is no client-side permission
 * check to get wrong and no greyed-out figure somebody is not allowed to know.
 */
export default function HrmDashboard({
    widgets,
    trend,
    attention,
    distribution,
    filters,
    ranges,
    payroll,
    staff,
    offices,
    departments,
    department_locked,
}: Props) {
    const [values, setValues] = useState(filters);
    const { auth, canManageEmployees, canManageAttendance } = usePage<SharedData>().props;

    // ⚠ The server's cycle range carries its own `label` ("October 2026");
    // the chip text is set AFTER the spread so it cannot be overwritten.
    const presets = [
        { ...ranges.today, id: 'today', label: 'Today' },
        { ...ranges.week, id: 'week', label: 'This week' },
        { ...ranges.cycle, id: 'cycle', label: 'This pay cycle' },
    ];
    const activePreset = presets.find((p) => p.from === values.range_from && p.to === values.range_to)?.id ?? 'custom';
    // Custom stays open once chosen, even when the typed dates happen to equal
    // a preset: closing the inputs under somebody's hands is worse than a
    // chip lighting up beside them.
    const [customOpen, setCustomOpen] = useState(activePreset === 'custom');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HRM', href: '/hrm/dashboard' },
        { title: 'Overview', href: '/hrm/dashboard' },
    ];

    const apply = (next: typeof values) => {
        setValues(next);

        router.get('/hrm/dashboard', Object.fromEntries(Object.entries(next).filter(([k, v]) => k !== 'as_at' && v !== null && v !== '')), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const byKey = Object.fromEntries(widgets.map((w) => [w.key, w])) as Record<string, Widget>;
    const hasAttendance = SERIES.some((s) => byKey[s.widget] !== undefined);
    const spare = widgets.filter((w) => !PLACED.has(w.key));

    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const firstName = (auth?.user?.name ?? '').split(' ')[0] || 'there';
    const now = new Date();
    const dateLine = `${['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][now.getDay()]}, ${now.getDate()} ${
        ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][now.getMonth()]
    } ${now.getFullYear()}`;

    const office = offices.find((o) => o.value === values.office_id);
    const department = departments.find((d) => d.value === values.department_id);
    const teamLabel = [office?.label, department?.label].filter(Boolean).join(' / ') || 'All teams';

    // "Needs you": real counts, each linking to the exact rows or the screen
    // behind it. A row whose widget is absent is absent too.
    const needs: NeedsRow[] = [];
    const approvals = byKey['approvals_pending'];
    if (approvals) {
        needs.push({
            key: approvals.key,
            count: count(approvals),
            tone: count(approvals) > 0 ? 'green' : 'muted',
            title: 'Approvals waiting',
            sub: approvals.note,
            description: approvals.description,
            href: '/approvals',
        });
    }
    const missing = byKey['attendance_missing_punches'];
    if (missing) {
        needs.push({
            key: missing.key,
            count: count(missing),
            tone: count(missing) > 0 ? 'amber' : 'muted',
            title: missing.title,
            sub:
                attention.holds > 0
                    ? `${attention.holds} ${attention.holds === 1 ? 'day holds' : 'days hold'} payroll until corrected`
                    : 'Nothing is holding payroll',
            description: missing.description,
            onClick: missing.drilldown_report ? () => drilldown(missing) : undefined,
            href: missing.drilldown_report ? undefined : '/hrm/attendance/corrections',
        });
    }
    if (payroll) {
        needs.push({
            key: 'payroll_next',
            count: payroll.next.days_to_end,
            tone: 'blue',
            title: `Days to ${payroll.next.period} payroll`,
            sub: payroll.next.run_status
                ? `${payroll.next.period} run: ${payroll.next.run_status}`
                : `Cycle ends ${payroll.next.end_display}; calculate after that`,
            href: '/hrm/payroll',
        });
    }

    // The two payroll tiles' own report links, kept on the card. They exist
    // only when the SELECTED month has a run, which is exactly when the card's
    // figures are that month's.
    const payrollLinks: { label: string; widget: Widget }[] = [];
    const status = byKey['payroll_current_status'];
    const total = byKey['payroll_salary_total'];
    if (status?.drilldown_report) payrollLinks.push({ label: 'Salary sheet', widget: status });
    if (total?.drilldown_report)
        payrollLinks.push({ label: total.drilldown_report === 'payroll_variance' ? 'Variance report' : 'Run total', widget: total });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR dashboard" />

            <div className="flex flex-col gap-5 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-muted-foreground text-xs">{dateLine}</p>
                        <h1 className="mt-0.5 text-2xl font-semibold tracking-tight">
                            {greeting}, {firstName}
                        </h1>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {presets.map((p) => (
                            <button
                                key={p.id}
                                type="button"
                                onClick={() => {
                                    setCustomOpen(false);
                                    apply({ ...values, range_from: p.from, range_to: p.to });
                                }}
                                aria-pressed={!customOpen && activePreset === p.id}
                                className={cn(
                                    'h-8 rounded-full border px-3 text-xs transition-colors',
                                    !customOpen && activePreset === p.id ? 'bg-foreground text-background border-foreground' : 'hover:bg-muted',
                                )}
                            >
                                {p.label}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setCustomOpen(true)}
                            aria-pressed={customOpen || activePreset === 'custom'}
                            className={cn(
                                'h-8 rounded-full border px-3 text-xs transition-colors',
                                customOpen || activePreset === 'custom' ? 'bg-foreground text-background border-foreground' : 'hover:bg-muted',
                            )}
                        >
                            Custom
                        </button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="sm" className="h-8 gap-1.5">
                                    {teamLabel}
                                    <ChevronDown className="h-3.5 w-3.5 opacity-70" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">Office</DropdownMenuLabel>
                                <DropdownMenuRadioGroup
                                    value={String(values.office_id ?? '')}
                                    onValueChange={(v) => apply({ ...values, office_id: v ? Number(v) : null })}
                                >
                                    <DropdownMenuRadioItem value="">All offices</DropdownMenuRadioItem>
                                    {offices.map((o) => (
                                        <DropdownMenuRadioItem key={o.value} value={String(o.value)}>
                                            {o.label}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">Department</DropdownMenuLabel>
                                <DropdownMenuRadioGroup
                                    value={String(values.department_id ?? '')}
                                    onValueChange={(v) => apply({ ...values, department_id: v ? Number(v) : null })}
                                >
                                    {/* C10d: a restricted manager is offered their own department, fixed. */}
                                    {!department_locked && <DropdownMenuRadioItem value="">All departments</DropdownMenuRadioItem>}
                                    {departments.map((d) => (
                                        <DropdownMenuRadioItem key={d.value} value={String(d.value)} disabled={department_locked}>
                                            {d.label}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                                {department_locked && (
                                    <p className="text-muted-foreground px-2 py-1.5 text-xs">Your dashboard covers your own department.</p>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>

                        {canManageEmployees && (
                            <Button size="sm" className="h-8" asChild>
                                <Link href="/hrm/employees/create">+ Add employee</Link>
                            </Button>
                        )}
                    </div>
                </div>

                {(customOpen || activePreset === 'custom') && (
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="range_from">From</Label>
                            <Input
                                id="range_from"
                                type="date"
                                className="h-8"
                                value={values.range_from}
                                onChange={(e) => apply({ ...values, range_from: e.target.value })}
                            />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="range_to">To</Label>
                            <Input
                                id="range_to"
                                type="date"
                                className="h-8"
                                value={values.range_to}
                                onChange={(e) => apply({ ...values, range_to: e.target.value })}
                            />
                        </div>
                    </div>
                )}

                {widgets.length === 0 && <p className="text-muted-foreground text-sm">There are no dashboard figures available to you.</p>}

                <TodayStrip byKey={byKey} asAt={filters.as_at} canProcess={Boolean(canManageAttendance)} onDrilldown={drilldown} />

                <div className="grid gap-5 lg:grid-cols-2">
                    {hasAttendance && <AttendanceTrendCard trend={trend} from={filters.range_from} to={filters.range_to} />}
                    <NeedsYouCard rows={needs} />
                    {payroll && <PayrollCard latest={payroll.latest} next={payroll.next} links={payrollLinks} />}
                    <TeamsCard distribution={distribution} staff={staff} />
                </div>

                {spare.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {spare.map((w) => (
                            <SpareTile key={w.key} widget={w} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
