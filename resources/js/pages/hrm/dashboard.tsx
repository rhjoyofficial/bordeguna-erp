import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
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

interface Props {
    widgets: Widget[];
    trend: Trend;
    filters: {
        office_id: number | null;
        department_id: number | null;
        range_from: string;
        range_to: string;
    };
    offices: Option[];
    departments: Option[];
    department_locked: boolean;
}

const SERIES = [
    { key: 'present' as const, label: 'Present', bar: 'bg-chart-2', dot: 'bg-chart-2' },
    { key: 'late' as const, label: 'Late', bar: 'bg-chart-4', dot: 'bg-chart-4' },
    { key: 'absent' as const, label: 'Absent', bar: 'bg-destructive', dot: 'bg-destructive' },
    { key: 'leave' as const, label: 'On leave', bar: 'bg-chart-3', dot: 'bg-chart-3' },
];

/**
 * Attendance day by day. Stacked, because the question is "how was that day
 * made up", not "how do four unrelated lines move".
 *
 * ⚠ Weekly off and holidays are drawn as a faint base rather than left out.
 * A Friday with nine people off would otherwise be an empty column that reads
 * as a catastrophe.
 */
function AttendanceTrendChart({ trend }: { trend: Trend }) {
    if (trend.rows === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                No attendance has been processed in this period, so there is nothing to chart yet.
            </p>
        );
    }

    const max = Math.max(
        1,
        ...trend.points.map((p) => p.present + p.late + p.absent + p.leave + p.off),
    );

    return (
        <div>
            <div className="flex h-48 items-end gap-1 overflow-x-auto">
                {trend.points.map((point) => {
                    const total = point.present + point.late + point.absent + point.leave + point.off;

                    return (
                        <div key={point.date} className="flex h-full min-w-[10px] flex-1 flex-col items-center justify-end gap-1">
                            <div
                                className="flex w-full max-w-6 flex-col-reverse justify-start overflow-hidden rounded-t-sm"
                                style={{ height: `${(total / max) * 100}%` }}
                                title={`${point.date} (${point.weekday}): present ${point.present}, late ${point.late}, absent ${point.absent}, leave ${point.leave}, off ${point.off}`}
                            >
                                {SERIES.map((s) =>
                                    point[s.key] > 0 ? (
                                        <div
                                            key={s.key}
                                            className={s.bar}
                                            style={{ height: `${(point[s.key] / Math.max(total, 1)) * 100}%` }}
                                        />
                                    ) : null,
                                )}
                                {point.off > 0 && (
                                    <div className="bg-muted" style={{ height: `${(point.off / Math.max(total, 1)) * 100}%` }} />
                                )}
                            </div>
                            <span className="text-muted-foreground text-[9px] whitespace-nowrap">{point.label}</span>
                        </div>
                    );
                })}
            </div>

            <div className="text-muted-foreground mt-3 flex flex-wrap items-center gap-4 text-xs">
                {SERIES.map((s) => (
                    <span key={s.key} className="flex items-center gap-1.5">
                        <span className={`${s.dot} h-2.5 w-2.5 rounded-sm`} /> {s.label}
                    </span>
                ))}
                <span className="flex items-center gap-1.5">
                    <span className="bg-muted h-2.5 w-2.5 rounded-sm" /> Weekly off or holiday
                </span>
            </div>

            {trend.truncated && (
                <p className="text-muted-foreground mt-2 text-xs">
                    Showing the last {trend.days} days of a longer range, so the bars stay readable.
                </p>
            )}
        </div>
    );
}

/**
 * The HRM dashboard (RPT-04).
 *
 * It renders what the server sends and computes nothing - no KPI arithmetic
 * lives here, which is the separation the plan asked for. A widget the viewer
 * may not see never reaches this file at all: `DashboardRegistry` filters the
 * payload, so there is no client-side permission check to get wrong and no
 * greyed-out tile hinting at a figure somebody is not allowed to know.
 */
export default function HrmDashboard({ widgets, trend, filters, offices, departments, department_locked }: Props) {
    const [values, setValues] = useState(filters);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HRM', href: '/hrm/employees' },
        { title: 'HR dashboard', href: '/hrm/dashboard' },
    ];

    const apply = (next: typeof values) => {
        setValues(next);

        router.get('/hrm/dashboard', Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '')), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const drilldown = (widget: Widget) => {
        if (!widget.drilldown_report) return;

        const query = new URLSearchParams();
        Object.entries(widget.drilldown_parameters).forEach(([key, value]) => query.append(key, String(value)));
        query.append('format', 'print');

        window.open(`/hrm/reports/${widget.drilldown_report}/run?${query.toString()}`, '_blank', 'noopener');
    };

    const groups = widgets.reduce<Record<string, Widget[]>>((acc, widget) => {
        (acc[widget.group] ??= []).push(widget);
        return acc;
    }, {});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR dashboard" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end gap-4 rounded-lg border p-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="range_from">From</Label>
                        <Input
                            id="range_from"
                            type="date"
                            value={values.range_from}
                            onChange={(e) => apply({ ...values, range_from: e.target.value })}
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="range_to">To</Label>
                        <Input
                            id="range_to"
                            type="date"
                            value={values.range_to}
                            onChange={(e) => apply({ ...values, range_to: e.target.value })}
                        />
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="office_id">Office</Label>
                        <select
                            id="office_id"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={values.office_id ?? ''}
                            onChange={(e) => apply({ ...values, office_id: e.target.value ? Number(e.target.value) : null })}
                        >
                            <option value="">All</option>
                            {offices.map((office) => (
                                <option key={office.value} value={office.value}>
                                    {office.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="department_id">Department</Label>
                        <select
                            id="department_id"
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm disabled:opacity-70"
                            disabled={department_locked}
                            value={values.department_id ?? ''}
                            onChange={(e) => apply({ ...values, department_id: e.target.value ? Number(e.target.value) : null })}
                        >
                            {/* C10d: a restricted manager is offered their own department, fixed. */}
                            {!department_locked && <option value="">All</option>}
                            {departments.map((department) => (
                                <option key={department.value} value={department.value}>
                                    {department.label}
                                </option>
                            ))}
                        </select>
                        {department_locked && <p className="text-muted-foreground text-xs">Your dashboard covers your own department.</p>}
                    </div>
                </div>

                {widgets.length === 0 && (
                    <p className="text-muted-foreground text-sm">There are no dashboard figures available to you.</p>
                )}

                <div className="rounded-lg border p-4">
                    <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="text-sm font-semibold">Attendance across the period</h2>
                        <p className="text-muted-foreground text-xs">
                            {filters.range_from} to {filters.range_to}
                        </p>
                    </div>
                    <AttendanceTrendChart trend={trend} />
                </div>

                {Object.entries(groups).map(([group, groupWidgets]) => (
                    <div key={group} className="flex flex-col gap-3">
                        <h2 className="text-sm font-semibold">{group}</h2>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {groupWidgets.map((widget) => (
                                <div
                                    key={widget.key}
                                    className={`flex flex-col gap-2 rounded-lg border p-4 ${widget.stale ? 'border-dashed' : ''}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-muted-foreground text-xs font-medium">{widget.title}</p>
                                        {/*
                                          C11b. The marker is visual and sits beside the title, because a
                                          caveat nobody notices is not honesty. A dashed border and this
                                          badge say "not settled" before anybody reads the note below.
                                        */}
                                        {widget.stale && (
                                            <span
                                                className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                                                title="These figures are only as current as the last attendance processing."
                                            >
                                                Not current
                                            </span>
                                        )}
                                    </div>

                                    <p className={`text-2xl font-semibold tabular-nums ${widget.stale ? 'text-muted-foreground' : ''}`}>
                                        {widget.display}
                                    </p>

                                    {widget.delta_display && (
                                        <p className="text-xs">
                                            <span className={widget.delta && widget.delta < 0 ? 'text-destructive' : ''}>
                                                {widget.delta_display}
                                            </span>
                                            {/* Blank wherever a percentage would be a fabrication (C10c / C11c). */}
                                            {widget.delta_percent && <span className="ml-1">({widget.delta_percent})</span>}
                                            {widget.delta_label && <span className="text-muted-foreground ml-1">{widget.delta_label}</span>}
                                        </p>
                                    )}

                                    {widget.note && <p className="text-muted-foreground text-xs">{widget.note}</p>}

                                    {widget.drilldown_report && (
                                        <Button variant="link" className="h-auto justify-start p-0 text-xs" onClick={() => drilldown(widget)}>
                                            Show me these
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
