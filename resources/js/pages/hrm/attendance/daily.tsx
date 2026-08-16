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
    filters: { from: string; to: string; employee_id: number | null; review: boolean; holds: boolean };
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

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    if (status === 'present' || status === 'field_duty') return 'default';
    if (status === 'absent' || status === 'missing_punch') return 'outline';
    return 'secondary';
}

export default function DailyAttendance({ on, filters, employees = [], days, summary, policy, department_policies = [] }: DailyProps) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    // `on` is left out: it is the single-day shortcut, and sending it with a
    // range would pin the grid to one day whatever the date boxes say.
    const reload = (params: Record<string, string | number | boolean | null>) =>
        router.get(route('hrm.attendance.daily.index'), { ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    const isRange = filters.from !== filters.to;

    const run = (routeName: string, data: Record<string, string | number | boolean | number[]>) => {
        setBusy(true);
        router.post(route(routeName), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Daily attendance" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Daily attendance</h2>
                        <p className="text-sm text-muted-foreground">
                            {summary.total} processed · {summary.provisional} awaiting review
                            {summary.holds > 0 && ` · ${summary.holds} holding payroll`}
                            {summary.with_consequence > 0 && ` · ${summary.with_consequence} with a consequence`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Label htmlFor="from" className="text-sm text-muted-foreground">
                            From
                        </Label>
                        <Input id="from" type="date" className="w-40" value={filters.from} onChange={(e) => reload({ from: e.target.value })} />
                        <Label htmlFor="to" className="text-sm text-muted-foreground">
                            To
                        </Label>
                        <Input id="to" type="date" className="w-40" value={filters.to} onChange={(e) => reload({ to: e.target.value })} />
                        <select
                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
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
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={filters.review} onChange={(e) => reload({ review: e.target.checked })} />
                            Needs review only
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={filters.holds} onChange={(e) => reload({ holds: e.target.checked })} />
                            Holding payroll only
                        </label>
                        {/* Process and finalise act on ONE day, so they are offered only
                            when one day is being looked at. Offering them over a range
                            would suggest they do something they do not. */}
                        {!isRange && (
                            <>
                                <Button size="sm" variant="outline" disabled={busy} onClick={() => run('hrm.attendance.daily.process', { on })}>
                                    {busy ? 'Working…' : 'Process day'}
                                </Button>
                                <Button size="sm" disabled={busy || summary.provisional === 0} onClick={() => run('hrm.attendance.daily.finalize', { on })}>
                                    Finalise all
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {policy === null ? (
                    <p className="text-sm text-destructive">
                        No attendance policy is in force on this date, so lateness and half-day rules cannot be applied.
                    </p>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        Judged by “{policy.name}” v{policy.version}, in force from {policy.effective_from}.
                        {department_policies.length > 0 && (
                            <>
                                {' '}
                                Except{' '}
                                {department_policies
                                    .map((p) => `${p.department ?? 'a department'}, judged by “${p.name}” v${p.version}`)
                                    .join('; ')}
                                .
                            </>
                        )}
                    </p>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    {days.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing processed between {filters.from} and {filters.to}. Pick a single date and use “Process day” to run the engine over
                            the captured punches.
                        </p>
                    ) : (
                        <table className="w-full min-w-[72rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    {isRange && <th className="py-2">Date</th>}
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Status</th>
                                    <th className="py-2">In / Out</th>
                                    <th className="py-2">Worked</th>
                                    <th className="py-2">Payable</th>
                                    <th className="py-2">Late</th>
                                    <th className="py-2">Consequence</th>
                                    <th className="py-2 text-right">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {days.map((day) => (
                                    <Fragment key={day.id}>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            {isRange && (
                                                <td className="py-2 font-mono text-xs whitespace-nowrap">
                                                    {day.date} <span className="text-muted-foreground">{day.weekday}</span>
                                                </td>
                                            )}
                                            <td className="py-2">
                                                {day.employee}
                                                <span className="ml-2 text-xs text-muted-foreground">{day.employee_code}</span>
                                            </td>
                                            <td className="py-2">
                                                <Badge variant={statusVariant(day.status_value)}>{day.status}</Badge>
                                                {day.is_provisional && (
                                                    <span className="ml-2 text-xs text-muted-foreground">provisional</span>
                                                )}
                                                {day.holds_payroll && (
                                                    <Badge variant="outline" className="ml-2">
                                                        Payroll hold
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 font-mono text-xs text-muted-foreground">
                                                {day.first_in ?? '—'} / {day.last_out ?? '—'}
                                            </td>
                                            <td className="py-2 text-muted-foreground">{day.worked_hours}h</td>
                                            <td className="py-2">{day.payable_hours}h</td>
                                            <td className="py-2 text-muted-foreground">
                                                {day.late_minutes > 0 ? `${day.late_minutes}m` : '—'}
                                                {day.occurrence_number !== null && (
                                                    <span className="ml-1 text-xs">#{day.occurrence_number}</span>
                                                )}
                                            </td>
                                            <td className="py-2">
                                                {day.has_consequence ? (
                                                    <Badge variant="outline">{day.consequence}</Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">
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
                                                        <a href={`/hrm/attendance/corrections?employee_id=${day.employee_id}&date=${day.date}`}>Fix</a>
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                        {expanded === day.id && (
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td colSpan={isRange ? 9 : 8} className="py-3">
                                                    <div className="text-xs">
                                                        <p className="mb-1 font-medium">How this day was decided</p>
                                                        <ul className="ml-4 list-disc space-y-1 text-muted-foreground">
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
                                                            <p className="mt-2 text-muted-foreground">Policy version {day.policy_version}.</p>
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

                <p className="text-xs text-muted-foreground">
                    Processed attendance is derived and can be recalculated at any time; the raw punches beneath it never change. Finalising is what
                    makes a day payroll-eligible.
                </p>
            </div>
        </AppLayout>
    );
}
