import { HrCorrectionActions, type HrActions } from '@/components/hrm/hr-correction-actions';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { ReasonDialog } from '@/components/sales/correction-actions';
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
import { datesInText, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
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
    // ⭐ 27 Oct 2026 (Joy): the pay decision, apart from the approval.
    pay_label: string;
    deducts_balance: boolean;
    /** Why the pay can no longer change (payroll closed), or null. */
    pay_blocked: string | null;
    pay_history: PayHistory[];
    // ⭐ Corrections wave 3 (24 Oct 2026): Cancel after approval.
    cancelled_at: string | null;
    cancel_reason: string | null;
    actions: HrActions;
}

interface PayHistory {
    from: string;
    to: string;
    quota_days: number;
    granted_days: number;
    at_approval: boolean;
    reason: string;
    by: string | null;
    at: string | null;
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
    cancelled_at: string | null;
    cancel_reason: string | null;
    actions: HrActions;
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
    // ⭐ B24 (25 Oct 2026): null when never used (Delete); else what uses it.
    used: string | null;
}

// ⭐ Leave register (27 Oct 2026): everyone's leave year on one table.
interface Register {
    types: { id: number; name: string }[];
    rows: {
        id: number;
        name: string;
        code: string;
        types: Record<number, { got: number; used: number; left: number }>;
        paid_leave: number;
        unpaid_leave: number;
        absent: number;
        made_unpaid: number;
    }[];
}

interface Props {
    year: number;
    register?: Register;
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
    reasons?: Record<string, ReasonOption[]>;
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
 * ⭐ 27 Oct 2026 (Joy): the pay on an approved leave, decided apart from the
 * approval. Paid, Unpaid, or some days unpaid in half-day steps, with a reason.
 * Replaces "Pay these days" (27 Sep 2026), which is now its Paid choice.
 */
function PayDecisionDialog({ leave }: { leave: LeaveRow }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const initialMode = leave.unpaid_days <= 0 ? 'paid' : leave.unpaid_days >= leave.days ? 'unpaid' : 'part';
    const [mode, setMode] = useState<'paid' | 'unpaid' | 'part'>(initialMode);
    const [part, setPart] = useState(String(leave.unpaid_days > 0 && leave.unpaid_days < leave.days ? leave.unpaid_days : 0.5));
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const unpaid = mode === 'paid' ? 0 : mode === 'unpaid' ? leave.days : Number(part);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setMode(initialMode);
                    setErrors({});
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    {t('pay.button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('pay.title')}</DialogTitle>
                    <DialogDescription>{t('pay.intro', { name: leave.employee ?? '', days: leave.days, now: leave.pay_label })}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    <div className="flex flex-wrap gap-3 text-sm" role="radiogroup" aria-label={t('pay.title')}>
                        {(['paid', 'unpaid', 'part'] as const)
                            .filter((m) => m !== 'part' || leave.days > 0.5)
                            .map((m) => (
                                <label key={m} className="inline-flex items-center gap-1.5">
                                    <input type="radio" name={`pay-mode-${leave.id}`} checked={mode === m} onChange={() => setMode(m)} />
                                    {t(m === 'paid' ? 'pay.paid' : m === 'unpaid' ? 'pay.unpaid' : 'pay.part')}
                                </label>
                            ))}
                    </div>
                    {mode === 'part' && (
                        <div className="grid gap-1">
                            <Label htmlFor={`pay-days-${leave.id}`}>{t('pay.unpaid_days')}</Label>
                            <Input
                                id={`pay-days-${leave.id}`}
                                type="number"
                                className="w-24"
                                min={0.5}
                                max={leave.days - 0.5}
                                step={0.5}
                                value={part}
                                onChange={(e) => setPart(e.target.value)}
                            />
                        </div>
                    )}
                    {errors.unpaid_days && <p className="text-destructive text-xs">{errors.unpaid_days}</p>}
                    <p className="text-muted-foreground text-xs">
                        {leave.deducts_balance
                            ? t('pay.balance_note', { type: leave.leave_type ?? '' })
                            : t('pay.grant_note', { type: leave.leave_type ?? '' })}{' '}
                        {t('pay.payroll_note')}
                    </p>
                    <div className="grid gap-1">
                        <Label htmlFor={`pay-reason-${leave.id}`} required>
                            {t('pay.reason')}
                        </Label>
                        <Input
                            id={`pay-reason-${leave.id}`}
                            aria-required="true"
                            placeholder={t('pay.reason_placeholder')}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                        {errors.status && <p className="text-destructive text-xs">{errors.status}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('pay.cancel')}</Button>
                    </DialogClose>
                    <Button
                        disabled={busy || reason.trim() === '' || Number.isNaN(unpaid)}
                        onClick={() =>
                            router.post(
                                route('hrm.leave.pay', leave.id),
                                { unpaid_days: unpaid, reason },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
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
                        {t('pay.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Who decided the pay, when and why: newest first, under the Days figure. */
function PayHistoryList({ history }: { history: PayHistory[] }) {
    const { t } = useTranslation();

    if (history.length === 0) {
        return null;
    }

    return (
        <details className="mt-0.5 text-xs">
            <summary className="text-muted-foreground cursor-pointer">{t('pay.history')}</summary>
            <ul className="mt-1 grid gap-1">
                {[...history].reverse().map((h, i) => (
                    <li key={i} className="text-muted-foreground">
                        {t('pay.history_line', { from: h.from, to: h.to })}
                        {h.at_approval && ` (${t('pay.at_approval')})`}
                        {h.quota_days > 0 && `; ${t('pay.quota_back', { days: h.quota_days })}`}
                        {h.quota_days < 0 && `; ${t('pay.quota_drawn', { days: -h.quota_days })}`}
                        {h.granted_days > 0 && `; ${t('pay.granted', { days: h.granted_days })}`}
                        {`. ${h.reason} (${t('pay.decided_by', { by: h.by ?? '', at: h.at ?? '' })})`}
                    </li>
                ))}
            </ul>
        </details>
    );
}

export default function LeaveIndex({
    year,
    filters,
    requests,
    movements,
    balances,
    ledger,
    register = { types: [], rows: [] },
    employees,
    leave_types,
    type_settings = [],
    portions = [],
    movement_types = [],
    reasons = {},
}: Props) {
    const { t } = useTranslation();
    // The type rows below are called `t`, so the leave type buttons say() their words.
    const say = t;
    const reload = (params: Record<string, string | number | null>) =>
        router.get(route('hrm.leave.index'), { year, ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    // ⚠ Compact by instruction (Joy, 23 Aug 2026: "design looks not good.
    // always try to take less area"). Five stacked full-width sections became
    // ONE tabbed card: the work list (requests) first, the rest a click away
    // instead of a scroll away. Nothing was removed - only the geometry.
    const [section, setSection] = useState<'requests' | 'register' | 'movements' | 'types' | 'ledger'>('requests');

    const sections = [
        { key: 'requests' as const, label: `Requests (${requests.length})` },
        { key: 'register' as const, label: `Everyone's leave ${year}` },
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
                        {section === 'register' && (
                            <div className="flex flex-col gap-2">
                                <p className="text-muted-foreground text-xs">
                                    Each leave type shows left / got, with what was used under it. Paid and unpaid leave count the approved leave that
                                    starts in {year}; unpaid is what payroll cuts. Absent is a working day with no punch and no leave. Made unpaid is
                                    a worked day cut for a rule break (approved). Click a name for that person&apos;s requests and balance ledger.
                                </p>
                                <table className="w-full min-w-[60rem] text-sm">
                                    <thead>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                            <th className="py-1.5">Employee</th>
                                            {register.types.map((type) => (
                                                <th key={type.id} className="py-1.5 text-right">
                                                    {type.name}
                                                </th>
                                            ))}
                                            <th className="py-1.5 text-right">Paid leave</th>
                                            <th className="py-1.5 text-right">Unpaid leave</th>
                                            <th className="py-1.5 text-right">Absent</th>
                                            <th className="py-1.5 text-right">Made unpaid</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {register.rows.length === 0 && (
                                            <tr>
                                                <td colSpan={register.types.length + 5} className="text-muted-foreground py-2">
                                                    No employees.
                                                </td>
                                            </tr>
                                        )}
                                        {register.rows.map((row) => (
                                            <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="py-1.5">
                                                    <button
                                                        type="button"
                                                        className="font-medium hover:underline"
                                                        onClick={() => reload({ employee_id: row.id })}
                                                    >
                                                        {row.name}
                                                    </button>
                                                    <div className="text-muted-foreground text-xs">{row.code}</div>
                                                </td>
                                                {register.types.map((type) => {
                                                    const cell = row.types[type.id];
                                                    return (
                                                        <td key={type.id} className="py-1.5 text-right tabular-nums">
                                                            {cell && cell.got !== 0 ? (
                                                                <>
                                                                    <span
                                                                        className={cell.left < 0 ? 'text-destructive font-semibold' : 'font-semibold'}
                                                                    >
                                                                        {cell.left}
                                                                    </span>
                                                                    <span className="text-muted-foreground"> / {cell.got}</span>
                                                                    <div className="text-muted-foreground text-xs">{cell.used} used</div>
                                                                </>
                                                            ) : cell && cell.used > 0 ? (
                                                                <span className="text-muted-foreground text-xs">{cell.used} used</span>
                                                            ) : (
                                                                <span className="text-muted-foreground">-</span>
                                                            )}
                                                        </td>
                                                    );
                                                })}
                                                <td className="py-1.5 text-right tabular-nums">{row.paid_leave || '-'}</td>
                                                <td className={`py-1.5 text-right tabular-nums ${row.unpaid_leave > 0 ? 'text-destructive' : ''}`}>
                                                    {row.unpaid_leave || '-'}
                                                </td>
                                                <td className={`py-1.5 text-right tabular-nums ${row.absent > 0 ? 'text-destructive' : ''}`}>
                                                    {row.absent || '-'}
                                                </td>
                                                <td className={`py-1.5 text-right tabular-nums ${row.made_unpaid > 0 ? 'text-destructive' : ''}`}>
                                                    {row.made_unpaid || '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
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
                                                    {showDate(r.start_date)}
                                                    {r.end_date !== r.start_date && ` → ${showDate(r.end_date)}`}
                                                </td>
                                                <td className="py-1.5">
                                                    {r.days}
                                                    <span
                                                        className={
                                                            r.unpaid_days > 0
                                                                ? 'text-destructive ml-1 text-xs'
                                                                : 'ml-1 text-xs text-emerald-600 dark:text-emerald-400'
                                                        }
                                                    >
                                                        ({r.pay_label})
                                                    </span>
                                                    {r.paid_by_authority && (
                                                        <span className="ml-1 text-xs text-emerald-600 dark:text-emerald-400">
                                                            (paid by authority)
                                                        </span>
                                                    )}
                                                    {r.pay_history.length > 0 && (
                                                        <span className="text-muted-foreground block text-xs">
                                                            {t('pay.decided_by', {
                                                                by: r.pay_history[r.pay_history.length - 1].by ?? '',
                                                                at: r.pay_history[r.pay_history.length - 1].at ?? '',
                                                            })}
                                                        </span>
                                                    )}
                                                    <PayHistoryList history={r.pay_history} />
                                                </td>
                                                <td className="text-muted-foreground py-1.5 text-xs">{r.reason}</td>
                                                <td className="py-1.5">
                                                    {r.cancelled_at ? (
                                                        <span className="flex flex-col items-start gap-0.5">
                                                            <Badge variant="outline">{t('corr.hrm.cancelled_on', { date: r.cancelled_at })}</Badge>
                                                            {r.cancel_reason && (
                                                                <span className="text-muted-foreground text-xs">{r.cancel_reason}</span>
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <Badge variant={r.is_applied ? 'default' : 'secondary'}>
                                                            {r.is_applied ? 'Approved' : r.status}
                                                        </Badge>
                                                    )}
                                                    {r.stage && !r.is_applied && (
                                                        <span className="text-muted-foreground ml-2 text-xs">at {r.stage}</span>
                                                    )}
                                                </td>
                                                <td className="py-1.5 text-right">
                                                    <span className="inline-flex flex-wrap items-start justify-end gap-2">
                                                        {r.is_applied &&
                                                            !r.cancelled_at &&
                                                            (r.pay_blocked === null ? (
                                                                <PayDecisionDialog leave={r} />
                                                            ) : (
                                                                <Button size="sm" variant="outline" disabled title={r.pay_blocked}>
                                                                    {t('pay.button')}
                                                                </Button>
                                                            ))}
                                                        <HrCorrectionActions
                                                            kind="leave"
                                                            id={r.id}
                                                            label={`${r.employee ?? ''}, ${showDate(r.start_date)}${r.end_date !== r.start_date ? ` → ${showDate(r.end_date)}` : ''}`}
                                                            actions={r.actions}
                                                            reasons={reasons}
                                                            url={`/hrm/leave/${r.id}/cancel`}
                                                        />
                                                    </span>
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
                                                <td className="text-muted-foreground py-1.5">{datesInText(m.summary)}</td>
                                                <td className="text-muted-foreground py-1.5 text-xs">{m.reason}</td>
                                                <td className="py-1.5">
                                                    {m.cancelled_at ? (
                                                        <span className="flex flex-col items-start gap-0.5">
                                                            <Badge variant="outline">{t('corr.hrm.cancelled_on', { date: m.cancelled_at })}</Badge>
                                                            {m.cancel_reason && (
                                                                <span className="text-muted-foreground text-xs">{m.cancel_reason}</span>
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <Badge variant={m.is_applied ? 'default' : 'secondary'}>
                                                            {m.is_applied ? 'Approved' : m.status}
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="py-1.5 text-right">
                                                    <HrCorrectionActions
                                                        kind="movement"
                                                        id={m.id}
                                                        label={`${m.employee ?? ''}, ${m.type}, ${showDate(m.movement_date)}`}
                                                        actions={m.actions}
                                                        reasons={reasons}
                                                        url={`/hrm/movements/${m.id}/cancel`}
                                                    />
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
                                                        {!t.is_system && t.is_active && t.used !== null && (
                                                            <ReasonDialog
                                                                trigger={
                                                                    <Button size="sm" variant="outline">
                                                                        {say('leave_type.switch_off')}
                                                                    </Button>
                                                                }
                                                                title={say('leave_type.switch_off_title', { name: t.name })}
                                                                description={say('leave_type.switch_off_text')}
                                                                url={route('hrm.leave.types.switch-off', t.id)}
                                                                reasons={reasons.deactivation ?? []}
                                                                confirmLabel={say('leave_type.switch_off')}
                                                            />
                                                        )}
                                                        {!t.is_system && !t.is_active && (
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
                                                                {say('leave_type.reactivate')}
                                                            </Button>
                                                        )}
                                                        {!t.is_system && t.used === null && (
                                                            <ReasonDialog
                                                                trigger={
                                                                    <Button size="sm" variant="outline">
                                                                        {say('leave_type.delete')}
                                                                    </Button>
                                                                }
                                                                title={say('leave_type.delete_title', { name: t.name })}
                                                                description={say('leave_type.delete_text')}
                                                                url={route('hrm.leave.types.bin', t.id)}
                                                                reasons={reasons.deletion ?? []}
                                                                confirmLabel={say('leave_type.delete')}
                                                                destructive
                                                            />
                                                        )}
                                                    </div>
                                                    {!t.is_system && t.is_active && t.used && (
                                                        <p className="text-muted-foreground mt-1 max-w-72 text-right text-xs">{t.used}</p>
                                                    )}
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
                                            <td className="py-1.5 whitespace-nowrap">{showDate(entry.date)}</td>
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
