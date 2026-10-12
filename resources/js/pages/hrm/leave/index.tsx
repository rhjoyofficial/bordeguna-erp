import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    paid_by_authority: boolean;
    paid_override_reason: string | null;
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

interface TypeSetting {
    id: number;
    name: string;
    code: string;
    annual_quota: number;
    is_paid: boolean;
    deducts_balance: boolean;
    allows_half_day: boolean;
    can_carry_forward: boolean;
    carry_forward_cap: number | null;
    effective_cap: number | null;
    can_encash: boolean;
    is_active: boolean;
    is_system: boolean;
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
    type_settings: TypeSetting[];
    portions: { value: string; label: string }[];
    movement_types: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave', href: '/hrm/leave' }];

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
function LeaveDialog({
    employees,
    leaveTypes,
    portions,
}: {
    employees: Option[];
    leaveTypes: Balance[];
    portions: { value: string; label: string }[];
}) {
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
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="leave_employee" required>
                            Employee
                        </Label>
                        <SearchSelect id="leave_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="leave_type" required>
                            Leave type
                        </Label>
                        <SearchSelect id="leave_type" value={leaveTypeId} onChange={(e) => setLeaveTypeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {leaveTypes.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                    {t.deducts_balance ? ` (quota ${t.annual_quota})` : ' (no balance)'}
                                </option>
                            ))}
                        </SearchSelect>
                        {type?.deducts_balance && type.annual_quota === 0 && (
                            <p className="text-muted-foreground text-xs">
                                This type draws on a balance but its quota is 0, so the balance will go negative unless a quota is granted first.
                            </p>
                        )}
                        {errors.leave_type_id && <p className="text-destructive text-xs">{errors.leave_type_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="leave_start" required>
                                From
                            </Label>
                            <Input
                                id="leave_start"
                                aria-required="true"
                                type="date"
                                value={startDate}
                                onChange={(e) => setStartDate(e.target.value)}
                            />
                            {errors.start_date && <p className="text-destructive text-xs">{errors.start_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="leave_end" required>
                                To
                            </Label>
                            <Input id="leave_end" aria-required="true" type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
                            {errors.end_date && <p className="text-destructive text-xs">{errors.end_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="leave_portion" required>
                                Portion
                            </Label>
                            <SearchSelect id="leave_portion" value={portion} onChange={(e) => setPortion(e.target.value)}>
                                {portions.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="leave_reason" required>
                            Reason
                        </Label>
                        <Input
                            id="leave_reason"
                            aria-required="true"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Cousin's marriage ceremony"
                        />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
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

    // ⚠ "Home" is filled in rather than demanded: the place of a work-from-home
    // day is not a question worth asking. It stays editable, because somebody
    // will legitimately want to write "Home, Savar".
    const chooseType = (value: string) => {
        setType(value);

        if (value === 'work_from_home' && place.trim() === '') {
            setPlace('Home');
        }
    };
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
                        <span className="font-medium">Working from home still needs a clock in and clock out.</span>
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="mv_employee" required>
                            Employee
                        </Label>
                        <SearchSelect id="mv_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="mv_type" required>
                                Type
                            </Label>
                            <SearchSelect id="mv_type" value={type} onChange={(e) => chooseType(e.target.value)}>
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="mv_date" required>
                                Date
                            </Label>
                            <Input id="mv_date" aria-required="true" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                            {errors.movement_date && <p className="text-destructive text-xs">{errors.movement_date}</p>}
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
                            {errors.to_time && <p className="text-destructive text-xs">{errors.to_time}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="mv_reason" required>
                            Reason
                        </Label>
                        <Input
                            id="mv_reason"
                            aria-required="true"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Outside Dhaka on office work in Barishal"
                        />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
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

/**
 * Create or edit a leave type's rules (SmartHR gap G19). One dialog serves
 * both: with a `type` it edits, without one it creates.
 *
 * The code field only exists on create - the engine addresses types by code
 * (unpaid fallback, compensatory credit), so a rename would detach them.
 * Encashment is not offered at all: switched off by policy on 23 Aug 2026,
 * and a settled question does not get a toggle.
 */
function LeaveTypeDialog({ type }: { type?: TypeSetting }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState(type?.name ?? '');
    const [code, setCode] = useState('');
    const [quota, setQuota] = useState(type ? String(type.annual_quota) : '0');
    const [isPaid, setIsPaid] = useState(type?.is_paid ?? true);
    const [deductsBalance, setDeductsBalance] = useState(type?.deducts_balance ?? true);
    const [allowsHalfDay, setAllowsHalfDay] = useState(type?.allows_half_day ?? true);
    const [carryForward, setCarryForward] = useState(type?.can_carry_forward ?? false);
    const [cap, setCap] = useState(type?.carry_forward_cap != null ? String(type.carry_forward_cap) : '');
    const [errors, setErrors] = useState<Record<string, string>>({});

    // Re-seed the fields on every open: after a save Inertia refreshes the
    // row props but a mounted dialog's state would otherwise show the past.
    const onOpenChange = (next: boolean) => {
        if (next) {
            setName(type?.name ?? '');
            setCode('');
            setQuota(type ? String(type.annual_quota) : '0');
            setIsPaid(type?.is_paid ?? true);
            setDeductsBalance(type?.deducts_balance ?? true);
            setAllowsHalfDay(type?.allows_half_day ?? true);
            setCarryForward(type?.can_carry_forward ?? false);
            setCap(type?.carry_forward_cap != null ? String(type.carry_forward_cap) : '');
            setErrors({});
        }
        setOpen(next);
    };

    const submit = () => {
        const payload = {
            name,
            ...(type ? {} : { code }),
            annual_quota: Number(quota),
            is_paid: isPaid,
            deducts_balance: deductsBalance,
            allows_half_day: allowsHalfDay,
            can_carry_forward: carryForward,
            carry_forward_cap: carryForward && cap !== '' ? Number(cap) : null,
        };
        const options = {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => setOpen(false),
        };
        if (type) {
            router.put(route('hrm.leave.types.update', type.id), payload, options);
        } else {
            router.post(route('hrm.leave.types.store'), payload, options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                {type ? (
                    <Button size="sm" variant="outline">
                        Edit
                    </Button>
                ) : (
                    <Button size="sm">New leave type</Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{type ? `Edit ${type.name}` : 'New leave type'}</DialogTitle>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className={type ? 'grid gap-2' : 'grid gap-4 sm:grid-cols-2'}>
                        <div className="grid gap-2">
                            <Label htmlFor="lt_name" required>
                                Name
                            </Label>
                            <Input
                                id="lt_name"
                                aria-required="true"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g. Study Leave"
                            />
                            {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                        </div>
                        {!type && (
                            <div className="grid gap-2">
                                <Label htmlFor="lt_code" required>
                                    Code
                                </Label>
                                <Input
                                    id="lt_code"
                                    aria-required="true"
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    placeholder="e.g. study (lowercase, permanent)"
                                />
                                {errors.code && <p className="text-destructive text-xs">{errors.code}</p>}
                            </div>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="lt_quota" required>
                            Annual quota (days)
                        </Label>
                        <Input
                            id="lt_quota"
                            aria-required="true"
                            type="number"
                            min="0"
                            step="0.5"
                            value={quota}
                            onChange={(e) => setQuota(e.target.value)}
                        />
                        {errors.annual_quota && <p className="text-destructive text-xs">{errors.annual_quota}</p>}
                    </div>
                    <div className="grid gap-2">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={isPaid} onCheckedChange={(v) => setIsPaid(v === true)} />
                            Paid leave
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={deductsBalance} onCheckedChange={(v) => setDeductsBalance(v === true)} />
                            Draws on a balance
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={allowsHalfDay} onCheckedChange={(v) => setAllowsHalfDay(v === true)} />
                            Half days allowed
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={carryForward} onCheckedChange={(v) => setCarryForward(v === true)} />
                            Unused balance carries into next year
                        </label>
                    </div>
                    {carryForward && (
                        <div className="grid gap-2">
                            <Label htmlFor="lt_cap">Carry-forward cap (days)</Label>
                            <Input
                                id="lt_cap"
                                type="number"
                                min="0"
                                step="0.5"
                                value={cap}
                                onChange={(e) => setCap(e.target.value)}
                                placeholder={`Empty = twice the quota (${Number(quota || 0) * 2})`}
                            />
                            {errors.carry_forward_cap && <p className="text-destructive text-xs">{errors.carry_forward_cap}</p>}
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={name.trim() === '' || (!type && code.trim() === '')}>
                        {type ? 'Save' : 'Create'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The authority pays the unpaid days of an approved leave, even when the
 * employee had no paid balance (Joy, 27 Sep 2026). A reason is required, so the
 * grant is always accountable.
 */
function GrantPaidDialog({ leave }: { leave: LeaveRow }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Pay these days
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Pay this leave</DialogTitle>
                    <DialogDescription>
                        {leave.unpaid_days} unpaid day{leave.unpaid_days === 1 ? '' : 's'} will be paid as a company grant. No leave balance is used,
                        and the Leave Without Pay deduction is removed from the salary. Recalculate payroll afterwards.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor={`paid-reason-${leave.id}`} required>
                        Reason
                    </Label>
                    <Input id={`paid-reason-${leave.id}`} aria-required="true" value={reason} onChange={(e) => setReason(e.target.value)} />
                    {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    {errors.status && <p className="text-destructive text-xs">{errors.status}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.leave.grant-paid', leave.id),
                                { reason },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        Pay these days
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
    type_settings = [],
    portions = [],
    movement_types = [],
}: Props) {
    const reload = (params: Record<string, string | number | null>) =>
        router.get(route('hrm.leave.index'), { year, ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    // ⚠ Compact by instruction (Joy, 23 Aug 2026: "design looks not good.
    // always try to take less area"). Five stacked full-width sections became
    // ONE tabbed card: the work list (requests) first, the rest a click away
    // instead of a scroll away. Nothing was removed - only the geometry.
    const [section, setSection] = useState<'requests' | 'movements' | 'types' | 'ledger'>('requests');

    const sections = [
        { key: 'requests' as const, label: `Requests (${requests.length})` },
        { key: 'movements' as const, label: `Movements (${movements.length})` },
        { key: 'types' as const, label: 'Leave types' },
        ...(filters.employee_id !== null && ledger.length > 0 ? [{ key: 'ledger' as const, label: 'Balance ledger' }] : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Leave &amp; movement</h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            id="year"
                            type="number"
                            className="w-24"
                            aria-label="Year"
                            defaultValue={year}
                            onBlur={(e) => reload({ year: Number(e.target.value) })}
                        />
                        <SearchSelect
                            fit
                            value={filters.employee_id ?? ''}
                            onChange={(e) => reload({ employee_id: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <Button size="sm" variant="outline" onClick={() => router.post(route('hrm.leave.quota'), { year }, { preserveScroll: true })}>
                            Grant {year} quota
                        </Button>
                        <LeaveDialog employees={employees} leaveTypes={leave_types} portions={portions} />
                        <MovementDialog employees={employees} types={movement_types} />
                        <Button size="sm" asChild>
                            <Link href={route('hrm.leave.year-end', { year: year - 1 })}>Year end</Link>
                        </Button>
                    </div>
                </div>

                {/* One employee chosen: their balances as a chip row, not four cards. */}
                {filters.employee_id !== null && balances.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {balances
                            .filter((b) => b.deducts_balance)
                            .map((b) => (
                                <span key={b.id} className="border-border inline-flex items-baseline gap-2 rounded-full border px-3 py-1 text-sm">
                                    {b.name}
                                    <span className="font-semibold tabular-nums">{b.balance}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {b.taken} taken of {b.annual_quota}
                                    </span>
                                </span>
                            ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
                    <div className="border-border flex gap-1 overflow-x-auto border-b px-3 pt-2">
                        {sections.map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setSection(tab.key)}
                                className={
                                    section === tab.key
                                        ? 'border-primary text-foreground -mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap'
                                        : 'text-muted-foreground hover:text-foreground -mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap'
                                }
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    <div className="overflow-x-auto p-3">
                        {section === 'requests' &&
                            (requests.length === 0 ? (
                                <p className="text-muted-foreground p-2 text-sm">No leave requests yet.</p>
                            ) : (
                                <table className="w-full min-w-[60rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                            <th className="py-1.5">Employee</th>
                                            <th className="py-1.5">Type</th>
                                            <th className="py-1.5">Dates</th>
                                            <th className="py-1.5">Days</th>
                                            <th className="py-1.5">Reason</th>
                                            <th className="py-1.5">Status</th>
                                            <th className="py-1.5 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {requests.map((r) => (
                                            <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="py-1.5">
                                                    {r.employee}
                                                    <span className="text-muted-foreground ml-2 text-xs">{r.employee_code}</span>
                                                </td>
                                                <td className="text-muted-foreground py-1.5">{r.leave_type}</td>
                                                <td className="py-1.5 whitespace-nowrap">
                                                    {r.start_date}
                                                    {r.end_date !== r.start_date && ` → ${r.end_date}`}
                                                </td>
                                                <td className="py-1.5">
                                                    {r.days}
                                                    {r.unpaid_days > 0 && (
                                                        <span className="text-destructive ml-1 text-xs">({r.unpaid_days} unpaid)</span>
                                                    )}
                                                    {r.paid_by_authority && (
                                                        <span className="ml-1 text-xs text-emerald-600 dark:text-emerald-400">
                                                            (paid by authority)
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-1.5 text-xs">{r.reason}</td>
                                                <td className="py-1.5">
                                                    <Badge variant={r.is_applied ? 'default' : 'secondary'}>
                                                        {r.is_applied ? 'Approved' : r.status}
                                                    </Badge>
                                                    {r.stage && !r.is_applied && (
                                                        <span className="text-muted-foreground ml-2 text-xs">at {r.stage}</span>
                                                    )}
                                                </td>
                                                <td className="py-1.5 text-right">
                                                    {r.is_applied && r.unpaid_days > 0 && <GrantPaidDialog leave={r} />}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ))}

                        {section === 'movements' &&
                            (movements.length === 0 ? (
                                <p className="text-muted-foreground p-2 text-sm">No movements yet.</p>
                            ) : (
                                <table className="w-full min-w-[48rem] text-sm">
                                    <tbody>
                                        {movements.map((m) => (
                                            <tr key={m.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="text-muted-foreground py-1.5">{m.summary}</td>
                                                <td className="text-muted-foreground py-1.5 text-xs">{m.reason}</td>
                                                <td className="py-1.5">
                                                    <Badge variant={m.is_applied ? 'default' : 'secondary'}>
                                                        {m.is_applied ? 'Approved' : m.status}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ))}

                        {section === 'types' && (
                            <div className="flex flex-col gap-3">
                                <div className="flex justify-end">
                                    <LeaveTypeDialog />
                                </div>
                                <table className="w-full min-w-[56rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                            <th className="py-1.5">Type</th>
                                            <th className="py-1.5">Annual quota</th>
                                            <th className="py-1.5">Paid</th>
                                            <th className="py-1.5">Draws on balance</th>
                                            <th className="py-1.5">Half days</th>
                                            <th className="py-1.5">Carries forward</th>
                                            <th className="py-1.5">Encashable</th>
                                            <th className="py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {type_settings.map((t) => (
                                            <tr
                                                key={t.id}
                                                className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${t.is_active ? '' : 'opacity-50'}`}
                                            >
                                                <td className="py-1.5">
                                                    {t.name}
                                                    <span className="text-muted-foreground ml-2 text-xs">{t.code}</span>
                                                    {!t.is_active && (
                                                        <Badge variant="secondary" className="ml-2">
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-1.5">{t.annual_quota}</td>
                                                <td className="text-muted-foreground py-1.5">{t.is_paid ? 'Yes' : 'No'}</td>
                                                <td className="text-muted-foreground py-1.5">{t.deducts_balance ? 'Yes' : 'No'}</td>
                                                <td className="text-muted-foreground py-1.5">{t.allows_half_day ? 'Yes' : 'No'}</td>
                                                <td className="text-muted-foreground py-1.5">
                                                    {t.can_carry_forward ? `Up to ${t.effective_cap}` : 'No'}
                                                </td>
                                                <td className="text-muted-foreground py-1.5">{t.can_encash ? 'On separation' : 'No'}</td>
                                                <td className="py-1.5">
                                                    <div className="flex justify-end gap-2">
                                                        <LeaveTypeDialog type={t} />
                                                        {/* The unpaid fallback and the compensatory credit
                                                            are engine destinations - no off switch. */}
                                                        {!t.is_system && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() =>
                                                                    router.patch(
                                                                        route('hrm.leave.types.toggle-active', t.id),
                                                                        {},
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                            >
                                                                {t.is_active ? 'Deactivate' : 'Reactivate'}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {section === 'ledger' && (
                            <table className="w-full min-w-[44rem] text-sm">
                                <tbody>
                                    {ledger.map((entry) => (
                                        <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-1.5 whitespace-nowrap">{entry.date}</td>
                                            <td className="py-1.5">{entry.type}</td>
                                            <td className="text-muted-foreground py-1.5">{entry.entry_type}</td>
                                            <td className={`py-1.5 ${entry.days < 0 ? 'text-destructive' : ''}`}>
                                                {entry.days > 0 ? `+${entry.days}` : entry.days}
                                            </td>
                                            <td className="text-muted-foreground py-1.5 text-xs">{entry.note}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
