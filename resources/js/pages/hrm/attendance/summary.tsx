import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Row = {
    employee_id: number;
    employee_name: string;
    employee_code: string | null;
    days_recorded: number;
    attended: number;
    present: number;
    late: number;
    absent: number;
    missing_punch: number;
    on_leave: number;
    weekly_off: number;
    holiday: number;
    half_day: number;
    field_duty: number;
    late_minutes: number;
    early_leave_minutes: number;
    worked_hours: number;
    payable_hours: number;
    holds_payroll: number;
    provisional: number;
    with_consequence: number;
};

type PageProps = {
    filters: { from: string; to: string };
    rows: Row[];
    totals: {
        employees: number;
        days_recorded: number;
        attended: number;
        late: number;
        absent: number;
        missing_punch: number;
        on_leave: number;
        late_minutes: number;
        worked_hours: number;
        holds_payroll: number;
        provisional: number;
    };
};

function hoursAndMinutes(minutes: number): string {
    if (minutes <= 0) return '0m';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function Tile({ label, value, tone }: { label: string; value: string | number; tone?: 'warn' | 'bad' }) {
    return (
        <div className="rounded-lg border border-border p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
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
        </div>
    );
}

export default function AttendanceSummary() {
    const { filters, rows, totals } = usePage<PageProps>().props;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const apply = () => router.get('/hrm/attendance/summary', { from, to }, { preserveState: true });

    const preset = (days: number) => {
        const end = new Date(filters.to);
        const start = new Date(end);
        start.setDate(start.getDate() - days + 1);
        const iso = (d: Date) => d.toISOString().slice(0, 10);
        setFrom(iso(start));
        setTo(iso(end));
        router.get('/hrm/attendance/summary', { from: iso(start), to: iso(end) }, { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Attendance summary" />
            <div className="px-4 py-6">
                <Heading
                    title="Attendance summary"
                    description="How many days each person was present, late, absent or on leave, over any period."
                />

                <div className="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                    <Button onClick={apply}>Show</Button>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => preset(7)}>
                            Last 7 days
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => preset(30)}>
                            Last 30 days
                        </Button>
                    </div>
                </div>

                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Tile label="People" value={totals.employees} />
                    <Tile label="Days attended" value={totals.attended} />
                    <Tile label="Late days" value={totals.late} tone={totals.late > 0 ? 'warn' : undefined} />
                    <Tile label="Absent days" value={totals.absent} tone={totals.absent > 0 ? 'bad' : undefined} />
                    <Tile
                        label="Missing punches"
                        value={totals.missing_punch}
                        tone={totals.missing_punch > 0 ? 'warn' : undefined}
                    />
                    <Tile label="Hours worked" value={totals.worked_hours} />
                </div>

                {totals.holds_payroll > 0 && (
                    <div className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        <strong>{totals.holds_payroll}</strong> day
                        {totals.holds_payroll === 1 ? '' : 's'} still hold payroll and need a correction before a payroll
                        run can include them.
                        {totals.provisional > 0 && <> {totals.provisional} day(s) are still provisional.</>}
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Employee</th>
                                <th className="p-3 text-right font-medium">Days</th>
                                <th className="p-3 text-right font-medium">Attended</th>
                                <th className="p-3 text-right font-medium">Present</th>
                                <th className="p-3 text-right font-medium">Late</th>
                                <th className="p-3 text-right font-medium">Absent</th>
                                <th className="p-3 text-right font-medium">Missing punch</th>
                                <th className="p-3 text-right font-medium">On leave</th>
                                <th className="p-3 text-right font-medium">Weekly off</th>
                                <th className="p-3 text-right font-medium">Late by</th>
                                <th className="p-3 text-right font-medium">Hours</th>
                                <th className="p-3 text-right font-medium">Holds payroll</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={12} className="p-6 text-center text-muted-foreground">
                                        No attendance has been processed for this period yet.
                                    </td>
                                </tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.employee_id} className="border-t border-border">
                                    <td className="p-3">
                                        {/* The row is the question; the person's own
                                            page is the answer in full. */}
                                        <Link
                                            href={`/hrm/attendance/employees/${r.employee_id}?from=${filters.from}&to=${filters.to}`}
                                            className="text-primary font-medium hover:underline"
                                        >
                                            {r.employee_name}
                                        </Link>
                                        {r.employee_code && (
                                            <div className="text-xs text-muted-foreground">{r.employee_code}</div>
                                        )}
                                    </td>
                                    <td className="p-3 text-right">{r.days_recorded}</td>
                                    <td className="p-3 text-right font-medium">{r.attended}</td>
                                    <td className="p-3 text-right">{r.present}</td>
                                    <td
                                        className={
                                            r.late > 0
                                                ? 'p-3 text-right font-medium text-amber-600 dark:text-amber-400'
                                                : 'p-3 text-right text-muted-foreground'
                                        }
                                    >
                                        {r.late}
                                    </td>
                                    <td
                                        className={
                                            r.absent > 0
                                                ? 'p-3 text-right font-medium text-red-600 dark:text-red-400'
                                                : 'p-3 text-right text-muted-foreground'
                                        }
                                    >
                                        {r.absent}
                                    </td>
                                    <td
                                        className={
                                            r.missing_punch > 0
                                                ? 'p-3 text-right font-medium text-amber-600 dark:text-amber-400'
                                                : 'p-3 text-right text-muted-foreground'
                                        }
                                    >
                                        {r.missing_punch}
                                    </td>
                                    <td className="p-3 text-right">{r.on_leave}</td>
                                    <td className="p-3 text-right text-muted-foreground">{r.weekly_off}</td>
                                    <td className="p-3 text-right">{hoursAndMinutes(r.late_minutes)}</td>
                                    <td className="p-3 text-right">{r.worked_hours}</td>
                                    <td
                                        className={
                                            r.holds_payroll > 0
                                                ? 'p-3 text-right font-medium text-red-600 dark:text-red-400'
                                                : 'p-3 text-right text-muted-foreground'
                                        }
                                    >
                                        {r.holds_payroll}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="mt-3 text-xs text-muted-foreground">
                    Field duty and days with no punches are counted separately, so a person who was working away from
                    the office is not shown as absent.
                </p>
            </div>
        </AppLayout>
    );
}
