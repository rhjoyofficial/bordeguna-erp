import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

interface Day {
    id: number;
    date: string;
    weekday: string;
    employee_id: number;
    employee: string | null;
    employee_code: string | null;
    status: string;
    status_value: string;
    consequence: string;
    has_consequence: boolean;
    first_in: string | null;
    last_out: string | null;
    worked_hours: number;
    break_minutes: number;
    meal: {
        resolved_from: string;
        minutes: number;
        spans?: { out: string; in: string; minutes: number }[];
        overrun_minutes?: number;
        cause?: string;
    } | null;
    payable_hours: number;
    late_minutes: number;
    early_leave_minutes: number;
    grace_window: string | null;
    occurrence_number: number | null;
    is_provisional: boolean;
    holds_payroll: boolean;
    flags: string[];
    notes: string[];
    policy_version: number | null;
    finalized_by: string | null;
}

interface DailyProps {
    on: string;
    filters: { from: string; to: string; employee_id: number | null; review: boolean; holds: boolean; meals: boolean };
    employees: { id: number; name: string }[];
    days: Day[];
    summary: { total: number; provisional: number; holds: number; with_consequence: number };
    policy: { name: string; version: number; effective_from: string } | null;
    department_policies: { name: string; version: number; department: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Punch log', href: '/hrm/attendance' },
    { title: 'Daily attendance', href: '/hrm/attendance/daily' },
];

const selectClass = 'h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    if (status === 'present' || status === 'field_duty') return 'default';
    if (status === 'absent' || status === 'missing_punch') return 'outline';
    return 'secondary';
}

/** One headline number for the range on screen. */
function Tile({ label, value, tone, hint }: { label: string; value: number; tone?: 'warn' | 'bad'; hint?: string }) {
    return (
        <div className="border-border rounded-lg border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p
                className={
                    tone === 'bad'
                        ? 'text-2xl font-semibold text-red-600 dark:text-red-400'
                        : tone === 'warn'
                          ? 'text-2xl font-semibold text-amber-600 dark:text-amber-400'
                          : 'text-2xl font-semibold'
                }
            >
                {value}
            </p>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

/**
 * A filter that is switched on says so in words, and can be switched off from
 * where it is doing the hiding. Three unlabelled checkboxes in a toolbar left
 * somebody staring at an empty grid wondering what happened to their day.
 */
function FilterToggle({ label, active, onChange }: { label: string; active: boolean; onChange: (next: boolean) => void }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!active)}
            aria-pressed={active}
            className={
                active
                    ? 'border-primary bg-primary text-primary-foreground rounded-full border px-3 py-1 text-xs font-medium'
                    : 'border-border text-muted-foreground hover:text-foreground rounded-full border px-3 py-1 text-xs'
            }
        >
            {label}
        </button>
    );
}

export default function DailyAttendance({ on, filters, employees = [], days, summary, policy, department_policies = [] }: DailyProps) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    // `on` is left out: it is the single-day shortcut, and sending it with a
    // range would pin the grid to one day whatever the date boxes say.
    const reload = (params: Record<string, string | number | boolean | null>) =>
        router.get(route('hrm.attendance.daily.index'), { ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    const isRange = filters.from !== filters.to;
    const anyFilter = filters.review || filters.holds || filters.meals || filters.employee_id !== null;

    const run = (routeName: string, data: Record<string, string | number | boolean | number[]>) => {
        setBusy(true);
        router.post(route(routeName), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    // Presets count back from the To date already on screen, not from today, so
    // "last 7 days" while reading last March means the last seven days of that
    // window rather than a jump home.
    const jump = (span: number) => {
        const end = new Date(filters.to);
        const start = new Date(end);
        start.setDate(start.getDate() - span + 1);
        const iso = (d: Date) => d.toISOString().slice(0, 10);
        reload({ from: iso(start), to: iso(end) });
    };

    const showToday = () => {
        const iso = new Date().toISOString().slice(0, 10);
        reload({ from: iso, to: iso });
    };

    const columns = isRange ? 10 : 9;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Daily attendance" />
            <div className="flex flex-col gap-6 px-4 py-6">
                <Heading
                    title="Daily attendance"
                    description="What the engine made of each day, and the review step that turns a proposal into a payroll-eligible fact."
                />

                {/* Filters first, in labelled fields rather than a crowded toolbar.
                    Running the engine is a different kind of action and lives in
                    its own strip below, so nobody presses it looking for Show. */}
                <div className="border-border rounded-xl border">
                    <div className="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="from" className="text-muted-foreground text-xs">
                                From
                            </Label>
                            <Input id="from" type="date" className="h-9" value={filters.from} onChange={(e) => reload({ from: e.target.value })} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="to" className="text-muted-foreground text-xs">
                                To
                            </Label>
                            <Input id="to" type="date" className="h-9" value={filters.to} onChange={(e) => reload({ to: e.target.value })} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="employee" className="text-muted-foreground text-xs">
                                Employee
                            </Label>
                            <select
                                id="employee"
                                className={selectClass}
                                value={filters.employee_id ?? ''}
                                onChange={(e) => reload({ employee_id: e.target.value || null })}
                            >
                                <option value="">All employees</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="border-border flex flex-wrap items-center gap-2 border-t px-4 py-3">
                        <span className="text-muted-foreground text-xs">Show only</span>
                        <FilterToggle label="Needs review" active={filters.review} onChange={(v) => reload({ review: v })} />
                        <FilterToggle label="Holding payroll" active={filters.holds} onChange={(v) => reload({ holds: v })} />
                        <FilterToggle label="Meal exceptions" active={filters.meals} onChange={(v) => reload({ meals: v })} />
                        <span className="bg-border mx-1 hidden h-4 w-px sm:inline-block" />
                        <Button variant="outline" size="sm" onClick={showToday}>
                            Today
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => jump(7)}>
                            Last 7 days
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => jump(30)}>
                            Last 30 days
                        </Button>
                        {anyFilter && (
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground ml-auto text-xs underline underline-offset-2"
                                onClick={() => reload({ review: false, holds: false, meals: false, employee_id: null })}
                            >
                                Clear filters
                            </button>
                        )}
                    </div>

                    {/* Process and finalise act on ONE day, so they are offered only
                        when one day is being looked at, and the day is named on the
                        button. Offering them over a range would suggest they do
                        something they do not. */}
                    <div className="border-border bg-muted/40 flex flex-wrap items-center gap-3 border-t px-4 py-3">
                        {isRange ? (
                            <p className="text-muted-foreground text-xs">
                                Processing and finalising work on a single day. Set From and To to the same date to run the engine.
                            </p>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-xs">Engine actions for {on}</p>
                                <Button size="sm" variant="outline" disabled={busy} onClick={() => run('hrm.attendance.daily.process', { on })}>
                                    {busy ? 'Working…' : 'Process day'}
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={busy || summary.provisional === 0}
                                    onClick={() => run('hrm.attendance.daily.finalize', { on })}
                                >
                                    Finalise all
                                </Button>
                                {summary.provisional === 0 && summary.total > 0 && (
                                    <span className="text-muted-foreground text-xs">Nothing is awaiting review on this day.</span>
                                )}
                            </>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Tile label="Days processed" value={summary.total} hint={isRange ? `${filters.from} to ${filters.to}` : on} />
                    <Tile label="Awaiting review" value={summary.provisional} tone={summary.provisional > 0 ? 'warn' : undefined} />
                    <Tile
                        label="Holding payroll"
                        value={summary.holds}
                        tone={summary.holds > 0 ? 'bad' : undefined}
                        hint="Needs an approved correction"
                    />
                    <Tile label="With a consequence" value={summary.with_consequence} tone={summary.with_consequence > 0 ? 'warn' : undefined} />
                </div>

                {policy === null ? (
                    <div className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border p-3 text-sm">
                        No attendance policy is in force on this date, so lateness and half-day rules cannot be applied.
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        Judged by “{policy.name}” v{policy.version}, in force from {policy.effective_from}.
                        {department_policies.length > 0 && (
                            <>
                                {' '}
                                Except{' '}
                                {department_policies.map((p) => `${p.department ?? 'a department'}, judged by “${p.name}” v${p.version}`).join('; ')}.
                            </>
                        )}
                    </p>
                )}

                <div className="border-border overflow-x-auto rounded-xl border">
                    {days.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-sm">
                            {anyFilter
                                ? 'Nothing matches these filters. Clear them to see the whole range.'
                                : `Nothing processed between ${filters.from} and ${filters.to}. Pick a single date and use “Process day” to run the engine over the captured punches.`}
                        </p>
                    ) : (
                        <table className="w-full min-w-[72rem] text-sm">
                            <thead>
                                <tr className="border-border bg-muted/40 text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                    {isRange && <th className="px-4 py-2 font-medium">Date</th>}
                                    <th className="px-4 py-2 font-medium">Employee</th>
                                    <th className="px-4 py-2 font-medium">Status</th>
                                    <th className="px-4 py-2 font-medium">In / Out</th>
                                    <th className="px-4 py-2 text-right font-medium">Worked</th>
                                    <th className="px-4 py-2 font-medium">Meal</th>
                                    <th className="px-4 py-2 text-right font-medium">Payable</th>
                                    <th className="px-4 py-2 text-right font-medium">Late</th>
                                    <th className="px-4 py-2 font-medium">Consequence</th>
                                    <th className="px-4 py-2 text-right font-medium">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {days.map((day) => (
                                    <Fragment key={day.id}>
                                        <tr className="border-border hover:bg-muted/30 border-b align-top">
                                            {isRange && (
                                                <td className="px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                    {day.date} <span className="text-muted-foreground">{day.weekday}</span>
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <span className="font-medium">{day.employee}</span>
                                                <span className="text-muted-foreground ml-2 text-xs">{day.employee_code}</span>
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <Badge variant={statusVariant(day.status_value)}>{day.status}</Badge>
                                                {day.is_provisional && <span className="text-muted-foreground ml-2 text-xs">provisional</span>}
                                                {day.holds_payroll && (
                                                    <Badge variant="outline" className="ml-2 border-red-500/50 text-red-600 dark:text-red-400">
                                                        Payroll hold
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                {day.first_in ?? '-'} / {day.last_out ?? '-'}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                {day.worked_hours}h
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                {day.meal === null
                                                    ? '-'
                                                    : day.meal.resolved_from === 'punches' && day.meal.spans?.length
                                                      ? `${day.meal.spans[0].out.slice(0, 5)}-${day.meal.spans[0].in.slice(0, 5)} (${day.meal.minutes}m)`
                                                      : `${day.meal.minutes}m fallback`}
                                                {day.meal?.overrun_minutes !== undefined && (
                                                    <Badge variant="outline" className="ml-1">
                                                        +{day.meal.overrun_minutes}m
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-right whitespace-nowrap tabular-nums">{day.payable_hours}h</td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                {day.late_minutes > 0 ? `${day.late_minutes}m` : '-'}
                                                {day.occurrence_number !== null && <span className="ml-1 text-xs">#{day.occurrence_number}</span>}
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                {day.has_consequence ? (
                                                    <Badge variant="outline">{day.consequence}</Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                <Button variant="ghost" size="sm" onClick={() => setExpanded(expanded === day.id ? null : day.id)}>
                                                    {expanded === day.id ? 'Hide' : 'Explain'}
                                                </Button>
                                                {day.is_provisional && !day.holds_payroll && !isRange && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() => run('hrm.attendance.daily.finalize', { on, ids: [day.id] })}
                                                    >
                                                        Finalise
                                                    </Button>
                                                )}
                                                {/* A day held for a missing punch can only be cleared by an
                                                    approved correction, so the row links straight to the form
                                                    instead of leaving someone to find it. */}
                                                {day.holds_payroll && (
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <a href={`/hrm/attendance/corrections?employee_id=${day.employee_id}&date=${day.date}`}>
                                                            Fix
                                                        </a>
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                        {expanded === day.id && (
                                            <tr className="border-border bg-muted/30 border-b">
                                                <td colSpan={columns} className="px-4 py-3">
                                                    <div className="text-xs">
                                                        <p className="mb-1 font-medium">How this day was decided</p>
                                                        <ul className="text-muted-foreground ml-4 list-disc space-y-1">
                                                            {day.notes.map((note, i) => (
                                                                <li key={i}>{note}</li>
                                                            ))}
                                                        </ul>
                                                        {day.flags.length > 0 && (
                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                {day.flags.map((flag) => (
                                                                    <Badge key={flag} variant="secondary">
                                                                        {flag.replace(/_/g, ' ')}
                                                                    </Badge>
                                                                ))}
                                                            </div>
                                                        )}
                                                        {day.policy_version !== null && (
                                                            <p className="text-muted-foreground mt-2">Policy version {day.policy_version}.</p>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <p className="text-muted-foreground text-xs">
                    Processed attendance is derived and can be recalculated at any time; the raw punches beneath it never change. Finalising is what
                    makes a day payroll-eligible.
                </p>
            </div>
        </AppLayout>
    );
}
