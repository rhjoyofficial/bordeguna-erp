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

interface Line {
    component_id: number;
    name: string;
    type: string;
    is_basic: boolean;
    is_statutory: boolean;
    value: number;
    amount: number;
    formula: string;
}

interface Structure {
    id: number;
    effective_from: string;
    effective_to: string | null;
    is_current: boolean;
    is_future: boolean;
    overtime_eligible: boolean;
    reason: string | null;
    created_by: string | null;
    basic: number;
    gross: number;
    contractual_deductions: number;
    contractual_net: number;
    lines: Line[];
}

interface Component {
    id: number;
    name: string;
    type: string;
    is_basic: boolean;
    is_percentage: boolean;
    is_priced_by_attendance: boolean;
    basis_label: string;
    is_statutory: boolean;
}

interface Props {
    can_manage: boolean;
    employee: { id: number; employee_code: string; name: string; office: string | null; designation: string | null };
    current: Structure | null;
    history: Structure[];
    components: Component[];
}

const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function StructureDialog({ employeeId, components, current }: { employeeId: number; components: Component[]; current: Structure | null }) {
    const [open, setOpen] = useState(false);
    const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10));
    const [reason, setReason] = useState('');
    // ADJ-01: who may be assigned overtime at all. Carried forward from the
    // current structure so a routine revision does not silently revoke it.
    const [overtimeEligible, setOvertimeEligible] = useState(current?.overtime_eligible ?? false);
    // Seeded from the current structure so a revision starts from today's
    // figures rather than a blank form.
    const [values, setValues] = useState<Record<number, string>>(() => {
        const seed: Record<number, string> = {};
        current?.lines.forEach((l) => {
            seed[l.component_id] = String(l.value);
        });
        return seed;
    });
    const [errors, setErrors] = useState<Record<string, string>>({});

    const set = (id: number, value: string) => setValues((v) => ({ ...v, [id]: value }));

    const lines = () =>
        Object.entries(values)
            .filter(([, v]) => v !== '' && Number(v) >= 0)
            .map(([id, v]) => ({ salary_component_id: Number(id), value: Number(v) }));

    const basicSet = components.some((c) => c.is_basic && values[c.id] !== undefined && values[c.id] !== '');

    const submit = () => {
        router.post(
            route('hrm.salary.store', employeeId),
            { effective_from: effectiveFrom, reason: reason || null, overtime_eligible: overtimeEligible, lines: lines() },
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
    };

    const earnings = components.filter((c) => c.type === 'earning');
    const deductions = components.filter((c) => c.type === 'deduction');

    const field = (c: Component) => (
        <div key={c.id} className="grid gap-2">
            <Label htmlFor={`c-${c.id}`} required={c.is_basic}>
                {c.name}
                {(c.is_percentage || c.is_priced_by_attendance) && <span className="text-muted-foreground ml-1 text-xs">- {c.basis_label}</span>}
            </Label>
            <Input
                id={`c-${c.id}`}
                type="number"
                step="0.0001"
                min={0}
                value={values[c.id] ?? ''}
                onChange={(e) => set(c.id, e.target.value)}
                placeholder={c.is_percentage ? '%' : '0.00'}
            />
            {/* C14: this field is a daily rate, and it looks exactly like a
                monthly amount unless it says otherwise. */}
            {c.is_priced_by_attendance && <p className="text-muted-foreground text-xs">Rate for each day reported for work.</p>}
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">{current ? 'Revise salary' : 'Set salary'}</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{current ? 'Revise salary' : 'Set salary'}</DialogTitle>
                    <DialogDescription>
                        A revision closes the current structure the day before this date and opens a new one. Nothing already paid is rewritten, so a
                        closed month keeps the figures it was calculated on.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[60vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="effective_from" required>
                                Effective from
                            </Label>
                            <Input id="effective_from" type="date" value={effectiveFrom} onChange={(e) => setEffectiveFrom(e.target.value)} />
                            {errors.effective_from && <p className="text-destructive text-xs">{errors.effective_from}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason (optional)</Label>
                            <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Annual increment" />
                        </div>
                    </div>

                    <div>
                        <h4 className="mb-2 text-sm font-medium">Earnings</h4>
                        <div className="grid gap-4 sm:grid-cols-2">{earnings.map(field)}</div>
                    </div>

                    <div>
                        <h4 className="mb-2 text-sm font-medium">Deductions</h4>
                        <div className="grid gap-4 sm:grid-cols-2">{deductions.map(field)}</div>
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={overtimeEligible} onChange={(e) => setOvertimeEligible(e.target.checked)} />
                        <span>Eligible for overtime</span>
                    </label>

                    {errors.lines && <p className="text-destructive text-xs">{errors.lines}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={!basicSet}>
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function StructureCard({ structure }: { structure: Structure }) {
    const earnings = structure.lines.filter((l) => l.type === 'earning');
    const deductions = structure.lines.filter((l) => l.type === 'deduction');

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <span className="text-sm font-medium">
                    {structure.effective_from} → {structure.effective_to ?? 'Current'}
                </span>
                {structure.is_current && <Badge variant="default">In effect</Badge>}
                {structure.is_future && <Badge variant="outline">Scheduled</Badge>}
                {structure.reason && <span className="text-muted-foreground text-xs">{structure.reason}</span>}
                {structure.created_by && <span className="text-muted-foreground text-xs">· set by {structure.created_by}</span>}
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[36rem] text-sm">
                    <tbody>
                        {earnings.map((l) => (
                            <tr key={l.component_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    {l.name}
                                    {l.is_basic && <span className="text-muted-foreground ml-2 text-xs">Basic</span>}
                                </td>
                                <td className="text-muted-foreground py-2 text-xs">{l.formula}</td>
                                <td className="py-2 text-right">{money(l.amount)}</td>
                            </tr>
                        ))}
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b font-medium">
                            <td className="py-2" colSpan={2}>
                                Gross
                            </td>
                            <td className="py-2 text-right">{money(structure.gross)}</td>
                        </tr>
                        {deductions.map((l) => (
                            <tr key={l.component_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    {l.name}
                                    {l.is_statutory && (
                                        <Badge variant="outline" className="ml-2">
                                            Statutory
                                        </Badge>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2 text-xs">{l.formula}</td>
                                <td className="text-destructive py-2 text-right">−{money(l.amount)}</td>
                            </tr>
                        ))}
                        {deductions.length > 0 && (
                            <tr className="font-medium">
                                <td className="py-2" colSpan={2}>
                                    Contractual net
                                </td>
                                <td className="py-2 text-right">{money(structure.contractual_net)}</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function SalaryShow({ can_manage, employee, current, history, components }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Salary', href: '/hrm/salary' },
        { title: employee.employee_code, href: `/hrm/salary/${employee.id}` },
    ];

    // Everything except the card already shown above - past revisions and any
    // scheduled future one, which must stay visible.
    const others = history.filter((h) => h.id !== current?.id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Salary - ${employee.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{employee.name}</h2>
                        <p className="text-muted-foreground text-sm">
                            {employee.employee_code}
                            {employee.office && ` · ${employee.office}`}
                            {employee.designation && ` · ${employee.designation}`}
                        </p>
                    </div>
                    {can_manage && <StructureDialog employeeId={employee.id} components={components} current={current} />}
                </div>

                {current === null ? (
                    <p className="text-muted-foreground text-sm">
                        No salary structure yet. Payroll cannot calculate for this employee until one is set.
                    </p>
                ) : (
                    <StructureCard structure={current} />
                )}

                {others.length > 0 && (
                    <div className="flex flex-col gap-3">
                        <h3 className="text-sm font-medium">Other revisions</h3>
                        {others.map((s) => (
                            <StructureCard key={s.id} structure={s} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
