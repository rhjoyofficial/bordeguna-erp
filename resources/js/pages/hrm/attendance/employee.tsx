import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import Heading from '@/components/heading';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Day = {
    date: string;
    weekday: string;
    status: string;
    status_value: string;
    first_in: string | null;
    last_out: string | null;
    worked_minutes: number;
    late_minutes: number;
    holds_payroll: boolean;
    is_provisional: boolean;
    is_locked: boolean;
};

type Summary = {
    days_recorded: number;
    attended: number;
    present: number;
    late: number;
    absent: number;
    missing_punch: number;
    on_leave: number;
    weekly_off: number;
    late_minutes: number;
    worked_hours: number;
    holds_payroll: number;
};

type PageProps = {
    employee: {
        id: number;
        name: string;
        employee_code: string;
        photo_url: string | null;
        department: string | null;
        designation: string | null;
    };
    filters: { from: string; to: string };
    summary: Summary;
    days: Day[];
};

function minutesLabel(minutes: number): string {
    if (minutes <= 0) return '-';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function Tile({ label, value, warn, bad }: { label: string; value: string | number; warn?: boolean; bad?: boolean }) {
    return (
        <div className="rounded-lg border border-border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p
                className={
                    bad
                        ? 'text-2xl font-semibold text-red-600 dark:text-red-400'
                        : warn
                          ? 'text-2xl font-semibold text-amber-600 dark:text-amber-400'
                          : 'text-2xl font-semibold'
                }
            >
                {value}
            </p>
        </div>
    );
}

const statusTone: Record<string, string> = {
    late: 'text-amber-600 dark:text-amber-400',
    absent: 'text-red-600 dark:text-red-400',
    missing_punch: 'text-amber-600 dark:text-amber-400',
};

export default function EmployeeAttendance() {
    const { employee, filters, summary, days } = usePage<PageProps>().props;
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const url = `/hrm/attendance/employees/${employee.id}`;

    const initials = employee.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');

    return (
        <AppLayout>
            <Head title={`Attendance - ${employee.name}`} />
            <div className="px-4 py-6">
                <div className="mb-6 flex flex-wrap items-center gap-4">
                    {employee.photo_url ? (
                        <img src={employee.photo_url} alt={employee.name} className="h-14 w-14 rounded-full object-cover" />
                    ) : (
                        <div className="bg-primary/10 text-primary flex h-14 w-14 items-center justify-center rounded-full font-semibold">
                            {initials || '?'}
                        </div>
                    )}
                    <div>
                        <Heading title={employee.name} description={undefined} />
                        <p className="text-muted-foreground -mt-4 text-sm">
                            {employee.employee_code}
                            {employee.designation ? ` · ${employee.designation}` : ''}
                            {employee.department ? ` · ${employee.department}` : ''}
                        </p>
                    </div>
                    <div className="ms-auto">
                        <Link href={`/hrm/employees/${employee.id}`} className="text-primary text-sm hover:underline">
                            Employee record
                        </Link>
                    </div>
                </div>

                {/* Default is the current payroll month up to today - "the 24th
                    to today" in Joy's words - and any range can be asked for. */}
                <div className="mb-6 flex flex-wrap items-end gap-3 rounded-lg border border-border p-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                    </div>
                    <Button onClick={() => router.get(url, { from, to }, { preserveState: true })}>Show</Button>
                </div>

                <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Tile label="Days attended" value={summary.attended} />
                    <Tile label="Present" value={summary.present} />
                    <Tile label="Late" value={summary.late} warn={summary.late > 0} />
                    <Tile label="Absent" value={summary.absent} bad={summary.absent > 0} />
                    <Tile label="Late by" value={minutesLabel(summary.late_minutes)} warn={summary.late_minutes > 0} />
                    <Tile label="Hours worked" value={summary.worked_hours} />
                </div>

                {summary.holds_payroll > 0 && (
                    <div className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        {summary.holds_payroll} day{summary.holds_payroll === 1 ? '' : 's'} in this period hold payroll
                        and need a correction.
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Date</th>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3 font-medium">In</th>
                                <th className="p-3 font-medium">Out</th>
                                <th className="p-3 text-right font-medium">Worked</th>
                                <th className="p-3 text-right font-medium">Late by</th>
                                <th className="p-3 font-medium">Flags</th>
                            </tr>
                        </thead>
                        <tbody>
                            {days.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        Nothing processed for this period.
                                    </td>
                                </tr>
                            )}
                            {days.map((day) => (
                                <tr key={day.date} className="border-border border-t">
                                    <td className="p-3 whitespace-nowrap">
                                        {day.date} <span className="text-muted-foreground text-xs">{day.weekday}</span>
                                    </td>
                                    <td className={`p-3 font-medium ${statusTone[day.status_value] ?? ''}`}>{day.status}</td>
                                    <td className="p-3">{day.first_in ?? '-'}</td>
                                    <td className="p-3">{day.last_out ?? '-'}</td>
                                    <td className="p-3 text-right">{minutesLabel(day.worked_minutes)}</td>
                                    <td className="p-3 text-right">{minutesLabel(day.late_minutes)}</td>
                                    <td className="p-3">
                                        <span className="flex gap-1">
                                            {day.holds_payroll && <Badge variant="destructive">Holds payroll</Badge>}
                                            {day.is_provisional && <Badge variant="outline">Provisional</Badge>}
                                            {day.is_locked && <Badge variant="secondary">Locked</Badge>}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
