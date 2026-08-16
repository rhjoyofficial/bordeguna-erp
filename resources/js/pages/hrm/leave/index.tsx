import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface LeaveRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    leave_type: string | null;
    start_date: string;
    end_date: string;
    days: number;
    unpaid_days: number;
    reason: string;
    status: string;
    stage: string | null;
    is_applied: boolean;
}

interface MovementRow {
    id: number;
    employee: string | null;
    type: string;
    movement_date: string;
    summary: string;
    reason: string;
    status: string;
    is_applied: boolean;
}

interface Balance {
    id: number;
    name: string;
    balance: number;
    taken: number;
    annual_quota: number;
    deducts_balance: boolean;
    can_carry_forward: boolean;
    can_encash: boolean;
    carry_forward_cap: number | null;
}

interface Props {
    year: number;
    filters: { employee_id: number | null };
    requests: LeaveRow[];
    movements: MovementRow[];
    balances: Balance[];
    ledger: { id: number; date: string; type: string | null; entry_type: string; days: number; note: string | null }[];
    employees: { id: number; name: string }[];
    leave_types: Balance[];
    portions: { value: string; label: string }[];
    movement_types: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave', href: '/hrm/leave' }];
const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

type Option = { id: number; name: string };

/**
 * Record leave on somebody's behalf.
 *
 * ⚠ The screen listed leave and movements and offered no way to create either,
 * so the only route to `hrm.leave.store` and `hrm.movements.store` was the
 * server. HR could read the history of a decision but never make one.
 *
 * Neither form applies anything by itself: both raise a request that goes to
 * the approval queue, which is the point. A day stops counting as absent
 * because somebody with authority said so, not because somebody typed it.
 */
function LeaveDialog({ employees, leaveTypes, portions }: { employees: Option[]; leaveTypes: Balance[]; portions: { value: string; label: string }[] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [leaveTypeId, setLeaveTypeId] = useState('');
    const [startDate, setStartDate] = useState('');
    const [endDate, setEndDate] = useState('');
    const [portion, setPortion] = useState(portions[0]?.value ?? 'full_day');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const type = leaveTypes.find((t) => String(t.id) === leaveTypeId);

    const submit = () =>
        router.post(
            route('hrm.leave.store'),
            {
                employee_id: Number(employeeId),
                leave_type_id: Number(leaveTypeId),
                start_date: startDate,
                // A single-day leave is the common case, so an empty end date
                // means "the same day" rather than a validation error.
                end_date: endDate === '' ? startDate : endDate,
                day_portion: portion,
                reason,
            },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setReason('');
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Record leave</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a leave request</DialogTitle>
                    <DialogDescription>
                        Holidays and weekly offs inside the range are not charged, so applying across one costs only the working days it covers.
                        This goes to the approval queue; the attendance days change once it is approved.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="leave_employee">Employee</Label>
                        <select id="leave_employee" className={selectClass} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">— Select —</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="leave_type">Leave type</Label>
                        <select id="leave_type" className={selectClass} value={leaveTypeId} onChange={(e) => setLeaveTypeId(e.target.value)}>
                            <option value="">— Select —</option>
                            {leaveTypes.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                    {t.deducts_balance ? ` (quota ${t.annual_quota})` : ' (no balance)'}
                                </option>
                            ))}
                        </select>
                        {type?.deducts_balance && type.annual_quota === 0 && (
                            <p className="text-xs text-muted-foreground">
                                This type draws on a balance but its quota is 0, so the balance will go negative unless a quota is granted first.
                            </p>
                        )}
                        {errors.leave_type_id && <p className="text-xs text-destructive">{errors.leave_type_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="leave_start">From</Label>
                            <Input id="leave_start" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
                            {errors.start_date && <p className="text-xs text-destructive">{errors.start_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="leave_end">To</Label>
                            <Input id="leave_end" type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                            {errors.end_date && <p className="text-xs text-destructive">{errors.end_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="leave_portion">Portion</Label>
                            <select id="leave_portion" className={selectClass} value={portion} onChange={(e) => setPortion(e.target.value)}>
                                {portions.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="leave_reason">Reason</Label>
                        <Input
                            id="leave_reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Cousin's marriage ceremony"
                        />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={employeeId === '' || leaveTypeId === '' || startDate === '' || reason.trim() === ''}>
                        Request
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Record a movement: official work, field duty, or work on a holiday.
 *
 * ⚠ Holiday work is the one that would otherwise be LOST. Somebody who comes in
 * on a holiday or a weekly off is recorded but not credited (POL-09), and the
 * day carries `unapproved_holiday_work` until a movement of that type says it
 * was authorised. Without this form there was no way to authorise it.
 */
function MovementDialog({ employees, types }: { employees: Option[]; types: { value: string; label: string }[] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [type, setType] = useState(types[0]?.value ?? 'official_work');
    const [date, setDate] = useState('');
    const [place, setPlace] = useState('');
    const [fromTime, setFromTime] = useState('');
    const [toTime, setToTime] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () =>
        router.post(
            route('hrm.movements.store'),
            {
                employee_id: Number(employeeId),
                type,
                movement_date: date,
                place: place === '' ? null : place,
                from_time: fromTime === '' ? null : fromTime,
                to_time: toTime === '' ? null : toTime,
                reason,
            },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                    setReason('');
                },
            },
        );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Record movement
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a movement</DialogTitle>
                    <DialogDescription>
                        For a day worked away from the device: official work outside the office, field duty, or work on a holiday or weekly off.
                        One movement covers one date, so a trip of several days needs one per day.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="mv_employee">Employee</Label>
                        <select id="mv_employee" className={selectClass} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">— Select —</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="mv_type">Type</Label>
                            <select id="mv_type" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="mv_date">Date</Label>
                            <Input id="mv_date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {errors.movement_date && <p className="text-xs text-destructive">{errors.movement_date}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="mv_place">Place (optional)</Label>
                        <Input id="mv_place" value={place} onChange={(e) => setPlace(e.target.value)} placeholder="e.g. Barishal" />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="mv_from">From time (optional)</Label>
                            <Input id="mv_from" type="time" value={fromTime} onChange={(e) => setFromTime(e.target.value)} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="mv_to">To time (optional)</Label>
                            <Input id="mv_to" type="time" value={toTime} onChange={(e) => setToTime(e.target.value)} />
                            {errors.to_time && <p className="text-xs text-destructive">{errors.to_time}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="mv_reason">Reason</Label>
                        <Input
                            id="mv_reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Outside Dhaka on office work in Barishal"
                        />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={employeeId === '' || date === '' || reason.trim() === ''}>
                        Record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function LeaveIndex({
    year,
    filters,
    requests,
    movements,
    balances,
    ledger,
    employees,
    leave_types,
    portions = [],
    movement_types = [],
}: Props) {
    const reload = (params: Record<string, string | number | null>) =>
        router.get(route('hrm.leave.index'), { year, ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Leave &amp; movement</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <Label htmlFor="year" className="text-sm text-muted-foreground">
                            Year
                        </Label>
                        <Input id="year" type="number" className="w-28" defaultValue={year} onBlur={(e) => reload({ year: Number(e.target.value) })} />
                        <select
                            className={selectClass}
                            value={filters.employee_id ?? ''}
                            onChange={(e) => reload({ employee_id: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.post(route('hrm.leave.quota'), { year }, { preserveScroll: true })}
                        >
                            Grant {year} quota
                        </Button>
                        <LeaveDialog employees={employees} leaveTypes={leave_types} portions={portions} />
                        <MovementDialog employees={employees} types={movement_types} />
                        <Button size="sm" asChild>
                            <Link href={route('hrm.leave.year-end', { year: year - 1 })}>Year end</Link>
                        </Button>
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Leave types</h3>
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                <th className="py-2">Type</th>
                                <th className="py-2">Annual quota</th>
                                <th className="py-2">Draws on balance</th>
                                <th className="py-2">Carries forward</th>
                                <th className="py-2">Encashable</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leave_types.map((t) => (
                                <tr key={t.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">{t.name}</td>
                                    <td className="py-2 text-muted-foreground">{t.annual_quota}</td>
                                    <td className="py-2 text-muted-foreground">{t.deducts_balance ? 'Yes' : 'No'}</td>
                                    <td className="py-2 text-muted-foreground">
                                        {t.can_carry_forward ? `Up to ${t.carry_forward_cap}` : 'No'}
                                    </td>
                                    <td className="py-2 text-muted-foreground">{t.can_encash ? 'On separation' : 'No'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {filters.employee_id !== null && balances.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {balances
                            .filter((b) => b.deducts_balance)
                            .map((b) => (
                                <div key={b.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                                    <div className="text-sm font-medium">{b.name}</div>
                                    <div className="mt-1 text-2xl font-medium">{b.balance}</div>
                                    <div className="text-xs text-muted-foreground">
                                        available · {b.taken} taken of {b.annual_quota}
                                    </div>
                                </div>
                            ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Leave requests</h3>
                    {requests.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No leave requests yet.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Type</th>
                                    <th className="py-2">Dates</th>
                                    <th className="py-2">Days</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((r) => (
                                    <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {r.employee}
                                            <span className="ml-2 text-xs text-muted-foreground">{r.employee_code}</span>
                                        </td>
                                        <td className="py-2 text-muted-foreground">{r.leave_type}</td>
                                        <td className="py-2 whitespace-nowrap">
                                            {r.start_date}
                                            {r.end_date !== r.start_date && ` → ${r.end_date}`}
                                        </td>
                                        <td className="py-2">
                                            {r.days}
                                            {r.unpaid_days > 0 && <span className="ml-1 text-xs text-destructive">({r.unpaid_days} unpaid)</span>}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">{r.reason}</td>
                                        <td className="py-2">
                                            <Badge variant={r.is_applied ? 'default' : 'secondary'}>{r.is_applied ? 'Approved' : r.status}</Badge>
                                            {r.stage && !r.is_applied && <span className="ml-2 text-xs text-muted-foreground">at {r.stage}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Movements</h3>
                    {movements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No movements yet.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <tbody>
                                {movements.map((m) => (
                                    <tr key={m.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 text-muted-foreground">{m.summary}</td>
                                        <td className="py-2 text-xs text-muted-foreground">{m.reason}</td>
                                        <td className="py-2">
                                            <Badge variant={m.is_applied ? 'default' : 'secondary'}>{m.is_applied ? 'Approved' : m.status}</Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {filters.employee_id !== null && ledger.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">Balance ledger</h3>
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {ledger.map((entry) => (
                                    <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{entry.date}</td>
                                        <td className="py-2">{entry.type}</td>
                                        <td className="py-2 text-muted-foreground">{entry.entry_type}</td>
                                        <td className={`py-2 ${entry.days < 0 ? 'text-destructive' : ''}`}>
                                            {entry.days > 0 ? `+${entry.days}` : entry.days}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">{entry.note}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
