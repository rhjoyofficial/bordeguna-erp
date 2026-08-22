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

interface ComponentOption {
    id: number;
    name: string;
    type: string;
    is_basic: boolean;
    basis_label: string;
    is_percentage: boolean;
    is_priced_by_attendance: boolean;
}

interface EmployeeOption {
    id: number;
    name: string;
    has_structure: boolean;
    overtime_eligible: boolean;
    effective_from: string | null;
    basic: number | null;
    gross: number | null;
    lines: { salary_component_id: number; value: number }[];
}

interface ArrearRow {
    period: string;
    payroll_period_id: number;
    payroll_run_id: number;
    paid_net: number;
    difference: number;
}

interface RevisionRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    effective_from: string;
    is_retroactive: boolean;
    proposed_total: number;
    overtime_eligible: boolean;
    reason: string;
    status: string;
    is_applied: boolean;
    arrear_amount: number;
    arrear_breakdown: ArrearRow[];
    requested_by: string | null;
}

interface Props {
    can_manage: boolean;
    filters: { employee_id: number | null };
    revisions: RevisionRow[];
    employees: EmployeeOption[];
    components: ComponentOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Salary structures', href: '/hrm/salary' },
    { title: 'Increments', href: '/hrm/salary-revisions' },
];

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';

const money = (value: number) => value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

function ProposeDialog({ employees, components }: { employees: EmployeeOption[]; components: ComponentOption[] }) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [effectiveFrom, setEffectiveFrom] = useState(today());
    const [reason, setReason] = useState('');
    const [overtimeEligible, setOvertimeEligible] = useState(false);
    const [values, setValues] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    const selected = employees.find((e) => String(e.id) === employeeId);

    // Pre-fill from the salary currently in force, so a raise is edited rather
    // than retyped from nothing. Seeded on selection, never at mount - state
    // seeded from props at mount freezes stale.
    const chooseEmployee = (id: string) => {
        setEmployeeId(id);
        const employee = employees.find((e) => String(e.id) === id);
        setValues(Object.fromEntries((employee?.lines ?? []).map((l) => [l.salary_component_id, String(l.value)])));
        setOvertimeEligible(employee?.overtime_eligible ?? false);
    };

    const lines = components
        .filter((c) => values[c.id] !== undefined && values[c.id] !== '')
        .map((c) => ({ salary_component_id: c.id, value: Number(values[c.id]) }));

    const isBackdated = effectiveFrom < today();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Propose an increment</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Propose a salary revision</DialogTitle>
                    <DialogDescription>
                        Nothing changes until this is approved - no salary structure exists while a proposal is pending. A backdated revision recomputes
                        the months already paid and posts the difference forward as an arrear; it never rewrites a closed payslip.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="rev_employee">Employee</Label>
                        <select id="rev_employee" className={selectClass} value={employeeId} onChange={(e) => chooseEmployee(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id} disabled={!e.has_structure}>
                                    {e.name}
                                    {e.has_structure ? '' : ' - no salary structure yet'}
                                </option>
                            ))}
                        </select>
                        {selected?.has_structure && (
                            <p className="text-xs text-muted-foreground">
                                Currently Basic {money(selected.basic ?? 0)}, Gross {money(selected.gross ?? 0)} since {selected.effective_from}.
                            </p>
                        )}
                        {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="rev_from">Effective from</Label>
                        <Input id="rev_from" type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
                        {isBackdated && (
                            <p className="text-xs text-muted-foreground">
                                Backdated - every already-disbursed month from this date is re-priced and the difference posted as an arrear.
                            </p>
                        )}
                        {errors.effective_from && <p className="text-xs text-destructive">{errors.effective_from}</p>}
                    </div>

                    {employeeId !== '' && (
                        <div className="grid gap-2">
                            <Label>Proposed figures</Label>
                            {components.map((c) => (
                                <div key={c.id} className="flex items-center gap-3">
                                    <span className="w-48 text-sm">
                                        {c.name}
                                        {c.is_basic && <span className="ml-1 text-xs text-muted-foreground">basic</span>}
                                    </span>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="w-40"
                                        value={values[c.id] ?? ''}
                                        onChange={(e) => setValues({ ...values, [c.id]: e.target.value })}
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {c.is_percentage || c.is_priced_by_attendance ? c.basis_label : c.type}
                                    </span>
                                </div>
                            ))}
                            {errors.lines && <p className="text-xs text-destructive">{errors.lines}</p>}
                        </div>
                    )}

                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={overtimeEligible} onChange={(e) => setOvertimeEligible(e.target.checked)} />
                        Eligible for overtime
                    </label>

                    <div className="grid gap-2">
                        <Label htmlFor="rev_reason">Reason</Label>
                        <Input id="rev_reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Annual increment 2026" />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={employeeId === '' || reason.trim() === '' || lines.length === 0}
                        onClick={() =>
                            router.post(
                                route('hrm.revisions.store'),
                                {
                                    employee_id: Number(employeeId),
                                    effective_from: effectiveFrom,
                                    reason,
                                    overtime_eligible: overtimeEligible,
                                    lines,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setReason('');
                                        setEmployeeId('');
                                        setValues({});
                                    },
                                },
                            )
                        }
                    >
                        Propose
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SalaryRevisions({ can_manage, filters, revisions, employees, components }: Props) {
    const reload = (employeeId: number | null) =>
        router.get(route('hrm.revisions.index'), { employee_id: employeeId }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Increments" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Increments</h2>
                        <p className="text-sm text-muted-foreground">
                            A raise is a proposal before it is a fact. Approving one opens a new effective-dated structure and, if it reaches
                            backwards, posts the difference forward as an arrear.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <select
                            className={selectClass}
                            value={filters.employee_id ?? ''}
                            onChange={(e) => reload(e.target.value === '' ? null : Number(e.target.value))}
                        >
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        {can_manage && <ProposeDialog employees={employees} components={components} />}
                    </div>
                </div>

                <div className={cardClass}>
                    {revisions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No revisions proposed yet.</p>
                    ) : (
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Effective</th>
                                    <th className="py-2">Proposed</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2">Arrear</th>
                                    <th className="py-2 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revisions.map((r) => (
                                    <tr key={r.id} className={rowClass}>
                                        <td className="py-2">
                                            {r.employee}
                                            <span className="ml-2 text-xs text-muted-foreground">{r.employee_code}</span>
                                        </td>
                                        <td className="py-2 whitespace-nowrap">
                                            {r.effective_from}
                                            {r.is_retroactive && <span className="ml-2 text-xs text-muted-foreground">backdated</span>}
                                        </td>
                                        <td className="py-2">{money(r.proposed_total)}</td>
                                        <td className="py-2 text-xs text-muted-foreground">
                                            {r.reason}
                                            {r.overtime_eligible && <div className="text-xs">overtime-eligible</div>}
                                        </td>
                                        <td className="py-2">
                                            {r.arrear_amount === 0 ? (
                                                <span className="text-muted-foreground">-</span>
                                            ) : (
                                                <>
                                                    <span className={r.arrear_amount < 0 ? 'text-destructive' : ''}>{money(r.arrear_amount)}</span>
                                                    {r.arrear_breakdown.map((b) => (
                                                        <div key={b.payroll_run_id} className="text-xs text-muted-foreground">
                                                            {b.period}: {money(b.difference)}
                                                        </div>
                                                    ))}
                                                </>
                                            )}
                                        </td>
                                        <td className="py-2 text-right">
                                            {r.is_applied ? (
                                                <Badge variant="default">Applied</Badge>
                                            ) : r.status === 'pending' ? (
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => router.post(route('hrm.revisions.reject', r.id), {}, { preserveScroll: true })}
                                                    >
                                                        Reject
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        onClick={() => router.post(route('hrm.revisions.approve', r.id), {}, { preserveScroll: true })}
                                                    >
                                                        Approve
                                                    </Button>
                                                </div>
                                            ) : (
                                                <Badge variant="secondary">{r.status}</Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
