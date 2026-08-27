import { SearchSelect } from '@/components/search-select';
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
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface EmployeeOption {
    id: number;
    name: string;
    overtime_eligible: boolean;
}

interface PeriodOption {
    id: number;
    label: string;
    is_open: boolean;
}

interface OvertimeRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    work_date: string;
    hours: number;
    is_holiday_work: boolean;
    compensatory_days: number;
    reason: string;
    status: string;
    is_applied: boolean;
}

interface BonusEventRow {
    id: number;
    name: string;
    basis: string;
    basis_label: string;
    value: number;
    period: string | null;
    note: string | null;
    award_count: number;
    award_total: number;
}

interface BonusAwardRow {
    id: number;
    employee: string | null;
    event: string | null;
    amount: number;
    basis_note: string | null;
    status: string;
}

interface AdjustmentRow {
    id: number;
    employee: string | null;
    type: string;
    type_label: string;
    is_earning: boolean;
    amount: number;
    period: string | null;
    reason: string;
    status: string;
    is_generated: boolean;
    evidence_id: number | null;
}

interface BalanceRow {
    id: number;
    date: string;
    entry_type: string;
    amount: number;
    period: string | null;
    note: string | null;
}

interface NegativeRow {
    id: number;
    employee: string | null;
    period: string | null;
    net: number;
    block_reasons: string[];
}

interface Props {
    filters: { employee_id: number | null; payroll_period_id: number | null };
    overtime: OvertimeRow[];
    bonus_events: BonusEventRow[];
    bonus_awards: BonusAwardRow[];
    adjustments: AdjustmentRow[];
    balances: BalanceRow[];
    outstanding: number;
    negative_payslips: NegativeRow[];
    employees: EmployeeOption[];
    periods: PeriodOption[];
    adjustment_types: { value: string; label: string; is_earning: boolean; describe: string }[];
    bonus_bases: { value: string; label: string }[];
    overtime_rules: { daily_max_hours: number; monthly_max_hours: number; minimum_minutes: number; standard_hours: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payroll inputs', href: '/hrm/payroll-inputs' }];
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (value: number) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const trim = (value: number) => String(Number(value.toFixed(2)));

function ApprovalButtons({ approveUrl, rejectUrl, status }: { approveUrl: string; rejectUrl: string; status: string }) {
    if (status !== 'pending') {
        return <Badge variant={status === 'approved' || status === 'generated' ? 'default' : 'secondary'}>{status}</Badge>;
    }

    return (
        <div className="flex justify-end gap-2">
            <Button size="sm" variant="ghost" onClick={() => router.post(rejectUrl, {}, { preserveScroll: true })}>
                Reject
            </Button>
            <Button size="sm" onClick={() => router.post(approveUrl, {}, { preserveScroll: true })}>
                Approve
            </Button>
        </div>
    );
}

function OvertimeDialog({ employees, rules }: { employees: EmployeeOption[]; rules: Props['overtime_rules'] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [workDate, setWorkDate] = useState(new Date().toISOString().slice(0, 10));
    const [hours, setHours] = useState('');
    const [isHoliday, setIsHoliday] = useState(false);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selected = employees.find((e) => String(e.id) === employeeId);
    const compDays = isHoliday && hours !== '' ? Math.min(1, Math.max(0.5, Math.round((Number(hours) / rules.standard_hours) * 2) / 2)) : 0;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Assign overtime</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Assign overtime</DialogTitle>
                    <DialogDescription>
                        Overtime is authorised, never inferred from punches. Normal overtime is paid; holiday or off-day work is compensated in leave
                        instead, so the hours never reach a payslip.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="ot_employee">Employee</Label>
                        <SearchSelect id="ot_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                    {e.overtime_eligible ? '' : ' - not overtime-eligible'}
                                </option>
                            ))}
                        </SearchSelect>
                        {selected && !selected.overtime_eligible && (
                            <p className="text-destructive text-xs">
                                This employee is not marked overtime-eligible on their salary structure, so the assignment will be refused.
                            </p>
                        )}
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="ot_date">Date worked</Label>
                            <Input id="ot_date" type="date" value={workDate} onChange={(e) => setWorkDate(e.target.value)} />
                            {errors.work_date && <p className="text-destructive text-xs">{errors.work_date}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="ot_hours">Hours</Label>
                            <Input id="ot_hours" type="number" step="0.25" min="0.25" value={hours} onChange={(e) => setHours(e.target.value)} />
                            {errors.hours && <p className="text-destructive text-xs">{errors.hours}</p>}
                        </div>
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={isHoliday} onChange={(e) => setIsHoliday(e.target.checked)} />
                        <span>
                            Worked on a holiday or weekly off
                            <span className="text-muted-foreground block text-xs">
                                {isHoliday
                                    ? `Compensated in leave, not money - about ${trim(compDays)} day(s) of compensatory leave.`
                                    : `Paid overtime, capped at ${trim(rules.daily_max_hours)} hours a day and ${trim(rules.monthly_max_hours)} a month.`}
                            </span>
                        </span>
                    </label>

                    <div className="grid gap-2">
                        <Label htmlFor="ot_reason">Reason</Label>
                        <Input id="ot_reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why this overtime was needed" />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={employeeId === '' || hours === '' || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.overtime.store'),
                                {
                                    employee_id: Number(employeeId),
                                    work_date: workDate,
                                    hours: Number(hours),
                                    is_holiday_work: isHoliday,
                                    reason,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setHours('');
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        Assign
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function BonusEventDialog({ periods, bases }: { periods: PeriodOption[]; bases: { value: string; label: string }[] }) {
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [basis, setBasis] = useState(bases[0]?.value ?? 'fixed');
    const [value, setValue] = useState('');
    const [periodId, setPeriodId] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    New bonus event
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create a bonus event</DialogTitle>
                    <DialogDescription>
                        A bonus is an occasion carrying its own rule - Eid, a festival, a one-off - and the payroll month it lands on. Award it to
                        employees afterwards.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="bonus_name">Name</Label>
                        <Input id="bonus_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Eid-ul-Fitr bonus" />
                        {errors.name && <p className="text-destructive text-xs">{errors.name}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="bonus_basis">Basis</Label>
                            <SearchSelect id="bonus_basis" value={basis} onChange={(e) => setBasis(e.target.value)}>
                                {bases.map((b) => (
                                    <option key={b.value} value={b.value}>
                                        {b.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="bonus_value">{basis === 'fixed' ? 'Amount' : 'Percentage'}</Label>
                            <Input id="bonus_value" type="number" step="0.01" min="0.01" value={value} onChange={(e) => setValue(e.target.value)} />
                            {errors.value && <p className="text-destructive text-xs">{errors.value}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="bonus_period">Payroll month</Label>
                        <SearchSelect id="bonus_period" value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
                            <option value="">- Select -</option>
                            {periods.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.label}
                                    {p.is_open ? '' : ' (closed)'}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.payroll_period_id && <p className="text-destructive text-xs">{errors.payroll_period_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="bonus_note">Note (optional)</Label>
                        <Input id="bonus_note" value={note} onChange={(e) => setNote(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={name.trim() === '' || value === '' || periodId === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.bonus.events.store'),
                                {
                                    name,
                                    calculation_basis: basis,
                                    value: Number(value),
                                    payroll_period_id: Number(periodId),
                                    note: note === '' ? null : note,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setName('');
                                        setValue('');
                                    },
                                },
                            )
                        }
                    >
                        Create
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AwardDialog({ event, employees }: { event: BonusEventRow; employees: EmployeeOption[] }) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);

    const toggle = (id: number) => setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost">
                    Award
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Award “{event.name}”</DialogTitle>
                    <DialogDescription>
                        The amount is resolved against each employee&rsquo;s salary now and stored, so a later increment cannot restate what this
                        bonus was worth. Each award is approved individually.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[50vh] gap-2 overflow-y-auto pr-1">
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={selected.length === employees.length && employees.length > 0}
                            onChange={(e) => setSelected(e.target.checked ? employees.map((x) => x.id) : [])}
                        />
                        Everyone ({employees.length})
                    </label>
                    {employees.map((e) => (
                        <label key={e.id} className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={selected.includes(e.id)} onChange={() => toggle(e.id)} />
                            {e.name}
                        </label>
                    ))}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={selected.length === 0}
                        onClick={() =>
                            router.post(
                                route('hrm.bonus.awards.store', event.id),
                                { employee_ids: selected },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setOpen(false);
                                        setSelected([]);
                                    },
                                },
                            )
                        }
                    >
                        Award to {selected.length}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AdjustmentDialog({ employees, periods, types }: { employees: EmployeeOption[]; periods: PeriodOption[]; types: Props['adjustment_types'] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [type, setType] = useState(types[0]?.value ?? 'addition');
    const [amount, setAmount] = useState('');
    const [periodId, setPeriodId] = useState('');
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selectedType = types.find((t) => t.value === type);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Record adjustment</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a payroll adjustment</DialogTitle>
                    <DialogDescription>
                        Reason, evidence, approval and payroll month are all required - an adjustment is the one place an arbitrary sum can be moved
                        by hand, so all four controls apply.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="adj_employee">Employee</Label>
                        <SearchSelect id="adj_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
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
                        <Label htmlFor="adj_type">Type</Label>
                        <SearchSelect id="adj_type" value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label} - {t.is_earning ? 'adds' : 'deducts'}
                                </option>
                            ))}
                        </SearchSelect>
                        {selectedType && <p className="text-muted-foreground text-xs">{selectedType.describe}</p>}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="adj_amount">Amount</Label>
                            <Input id="adj_amount" type="number" step="0.01" min="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                            {errors.amount && <p className="text-destructive text-xs">{errors.amount}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="adj_period">Payroll month</Label>
                            <SearchSelect id="adj_period" value={periodId} onChange={(e) => setPeriodId(e.target.value)}>
                                <option value="">- Select -</option>
                                {periods.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.label}
                                        {p.is_open ? '' : ' (closed)'}
                                    </option>
                                ))}
                            </SearchSelect>
                            {errors.payroll_period_id && <p className="text-destructive text-xs">{errors.payroll_period_id}</p>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adj_reason">Reason</Label>
                        <Input id="adj_reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="adj_evidence">Evidence (required)</Label>
                        <Input
                            id="adj_evidence"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                            onChange={(e) => setEvidence(e.target.files?.[0] ?? null)}
                        />
                        {errors.evidence && <p className="text-destructive text-xs">{errors.evidence}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={employeeId === '' || amount === '' || periodId === '' || reason.trim() === '' || evidence === null}
                        onClick={() =>
                            router.post(
                                route('hrm.adjustments.store'),
                                {
                                    employee_id: Number(employeeId),
                                    type,
                                    amount: Number(amount),
                                    payroll_period_id: Number(periodId),
                                    reason,
                                    evidence,
                                },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setAmount('');
                                        setReason('');
                                        setEvidence(null);
                                    },
                                },
                            )
                        }
                    >
                        Record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function PayrollInputs({
    filters,
    overtime,
    bonus_events,
    bonus_awards,
    adjustments,
    balances,
    outstanding,
    negative_payslips,
    employees,
    periods,
    adjustment_types,
    bonus_bases,
    overtime_rules,
}: Props) {
    const reload = (params: Record<string, number | null>) =>
        router.get(route('hrm.payroll.inputs.index'), { ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll inputs" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Payroll inputs</h2>
                        <p className="text-muted-foreground text-sm">
                            Everything a payslip pays that the salary structure does not describe. Nothing here calculates - each entry is an
                            authorisation the next payroll run picks up.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <SearchSelect
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
                        <SearchSelect
                            value={filters.payroll_period_id ?? ''}
                            onChange={(e) => reload({ payroll_period_id: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">All months</option>
                            {periods.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                </div>

                {negative_payslips.length > 0 && (
                    <div className={cardClass}>
                        <h3 className="text-destructive mb-1 text-sm font-medium">Negative net - needs a decision</h3>
                        <p className="text-muted-foreground mb-3 text-xs">
                            Deductions exceeded earnings, so these payslips are blocked. Carrying the shortfall forward records it as a balance;
                            recalculate the run afterwards and the credit squares the month, with recovery taken from the next one.
                        </p>
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Month</th>
                                    <th className="py-2">Net</th>
                                    <th className="py-2">Why</th>
                                    <th className="py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {negative_payslips.map((p) => (
                                    <tr key={p.id} className={rowClass}>
                                        <td className="py-2">{p.employee}</td>
                                        <td className="text-muted-foreground py-2">{p.period}</td>
                                        <td className="text-destructive py-2">{money(p.net)}</td>
                                        <td className="text-muted-foreground py-2 text-xs">{p.block_reasons.join(' ')}</td>
                                        <td className="py-2 text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => router.post(route('hrm.payslips.carry-forward', p.id), {}, { preserveScroll: true })}
                                            >
                                                Carry forward
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className={cardClass}>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">Overtime</h3>
                        <OvertimeDialog employees={employees} rules={overtime_rules} />
                    </div>
                    {overtime.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No overtime assigned yet.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Date</th>
                                    <th className="py-2">Hours</th>
                                    <th className="py-2">Compensation</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {overtime.map((a) => (
                                    <tr key={a.id} className={rowClass}>
                                        <td className="py-2">
                                            {a.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{a.employee_code}</span>
                                        </td>
                                        <td className="py-2 whitespace-nowrap">{a.work_date}</td>
                                        <td className="py-2">{trim(a.hours)}</td>
                                        <td className="text-muted-foreground py-2">
                                            {a.is_holiday_work ? (
                                                <>
                                                    Compensatory leave
                                                    {a.compensatory_days > 0 && (
                                                        <span className="ml-1 text-xs">({trim(a.compensatory_days)} day)</span>
                                                    )}
                                                </>
                                            ) : (
                                                'Paid'
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">{a.reason}</td>
                                        <td className="py-2 text-right">
                                            {a.is_applied ? (
                                                <Badge variant="default">Approved</Badge>
                                            ) : (
                                                <ApprovalButtons
                                                    approveUrl={route('hrm.overtime.approve', a.id)}
                                                    rejectUrl={route('hrm.overtime.reject', a.id)}
                                                    status={a.status}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className={cardClass}>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">Bonus events</h3>
                        <BonusEventDialog periods={periods} bases={bonus_bases} />
                    </div>
                    {bonus_events.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No bonus events yet.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Event</th>
                                    <th className="py-2">Rule</th>
                                    <th className="py-2">Month</th>
                                    <th className="py-2">Awarded</th>
                                    <th className="py-2 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bonus_events.map((e) => (
                                    <tr key={e.id} className={rowClass}>
                                        <td className="py-2">
                                            {e.name}
                                            {e.note && <div className="text-muted-foreground text-xs">{e.note}</div>}
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {e.basis === 'fixed' ? money(e.value) : `${trim(e.value)}% - ${e.basis_label}`}
                                        </td>
                                        <td className="text-muted-foreground py-2">{e.period}</td>
                                        <td className="text-muted-foreground py-2">
                                            {e.award_count} employee(s) · {money(e.award_total)}
                                        </td>
                                        <td className="py-2 text-right">
                                            <AwardDialog event={e} employees={employees} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {bonus_awards.length > 0 && (
                    <div className={cardClass}>
                        <h3 className="mb-3 text-sm font-medium">Bonus awards</h3>
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Event</th>
                                    <th className="py-2">Amount</th>
                                    <th className="py-2">Basis</th>
                                    <th className="py-2 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bonus_awards.map((a) => (
                                    <tr key={a.id} className={rowClass}>
                                        <td className="py-2">{a.employee}</td>
                                        <td className="text-muted-foreground py-2">{a.event}</td>
                                        <td className="py-2">{money(a.amount)}</td>
                                        <td className="text-muted-foreground py-2 text-xs">{a.basis_note}</td>
                                        <td className="py-2 text-right">
                                            <ApprovalButtons
                                                approveUrl={route('hrm.bonus.awards.approve', a.id)}
                                                rejectUrl={route('hrm.bonus.awards.reject', a.id)}
                                                status={a.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className={cardClass}>
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-sm font-medium">Adjustments</h3>
                        <AdjustmentDialog employees={employees} periods={periods} types={adjustment_types} />
                    </div>
                    {adjustments.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No adjustments recorded yet.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Type</th>
                                    <th className="py-2">Amount</th>
                                    <th className="py-2">Month</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {adjustments.map((a) => (
                                    <tr key={a.id} className={rowClass}>
                                        <td className="py-2">{a.employee}</td>
                                        <td className="py-2">
                                            {a.type_label}
                                            {a.is_generated && <span className="text-muted-foreground ml-2 text-xs">auto</span>}
                                        </td>
                                        <td className={`py-2 ${a.is_earning ? '' : 'text-destructive'}`}>
                                            {a.is_earning ? '+' : '−'}
                                            {money(a.amount)}
                                        </td>
                                        <td className="text-muted-foreground py-2">{a.period}</td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {a.reason}
                                            {a.evidence_id && (
                                                <a
                                                    className="ml-2 underline"
                                                    href={route('attachments.show', a.evidence_id)}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    evidence
                                                </a>
                                            )}
                                        </td>
                                        <td className="py-2 text-right">
                                            <ApprovalButtons
                                                approveUrl={route('hrm.adjustments.approve', a.id)}
                                                rejectUrl={route('hrm.adjustments.reject', a.id)}
                                                status={a.status}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {filters.employee_id !== null && (
                    <div className={cardClass}>
                        <h3 className="mb-1 text-sm font-medium">Carry-forward balance</h3>
                        <p className="text-muted-foreground mb-3 text-xs">
                            Outstanding: <span className={outstanding > 0 ? 'text-destructive' : ''}>{money(outstanding)}</span> - recovered in full
                            from the next payroll.
                        </p>
                        {balances.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing carried forward.</p>
                        ) : (
                            <table className="w-full min-w-[44rem] text-sm">
                                <tbody>
                                    {balances.map((b) => (
                                        <tr key={b.id} className={rowClass}>
                                            <td className="py-2 whitespace-nowrap">{b.date}</td>
                                            <td className="py-2">{b.entry_type}</td>
                                            <td className="text-muted-foreground py-2">{b.period}</td>
                                            <td className={`py-2 ${b.amount < 0 ? '' : 'text-destructive'}`}>
                                                {b.amount > 0 ? `+${money(b.amount)}` : money(b.amount)}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-xs">{b.note}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
