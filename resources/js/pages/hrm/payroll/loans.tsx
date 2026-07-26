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

interface Installment {
    id: number;
    sequence: number;
    period: string;
    amount: number;
    status: string;
    status_label: string;
    paid_at: string | null;
}

interface LedgerRow {
    id: number;
    date: string;
    entry_type: string;
    increases_debt: boolean;
    amount: number;
    period: string | null;
    note: string | null;
}

interface LoanRow {
    id: number;
    reference: string;
    employee: string | null;
    employee_code: string | null;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    approval_status: string;
    is_applied: boolean;
    can_settle: boolean;
    principal: number;
    installment_amount: number;
    installment_count: number;
    start: string;
    reason: string;
    disbursed_on: string | null;
    settlement_note: string | null;
    outstanding: number;
    repaid: number;
    requested_by: string | null;
    installments: Installment[];
    ledger: LedgerRow[];
}

interface Props {
    filters: { employee_id: number | null };
    loans: LoanRow[];
    employees: { id: number; name: string; outstanding: number }[];
    types: { value: string; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Loans & advances', href: '/hrm/loans' }];
const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function RequestDialog({ employees, types }: { employees: Props['employees']; types: Props['types'] }) {
    const now = new Date();
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [type, setType] = useState(types[0]?.value ?? 'loan');
    const [principal, setPrincipal] = useState('');
    const [installment, setInstallment] = useState('');
    const [year, setYear] = useState(String(now.getFullYear()));
    const [month, setMonth] = useState(String(now.getMonth() + 1));
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const p = Number(principal);
    const i = Number(installment);
    const count = p > 0 && i > 0 ? Math.ceil(p / i) : 0;
    const last = count > 0 ? Number((p - i * (count - 1)).toFixed(2)) : 0;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Request a loan</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Request a loan or advance</DialogTitle>
                    <DialogDescription>
                        Nothing is disbursed until this is approved, and a pending loan owes nothing at all. Approval pays the money out and generates
                        the repayment schedule; payroll then deducts one installment a month.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="loan_employee">Employee</Label>
                        <select id="loan_employee" className={selectClass} value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">— Select —</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                    {e.outstanding > 0 ? ` — ${money(e.outstanding)} outstanding` : ''}
                                </option>
                            ))}
                        </select>
                        {errors.employee_id && <p className="text-xs text-destructive">{errors.employee_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="loan_type">Type</Label>
                        <select id="loan_type" className={selectClass} value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="loan_principal">Principal</Label>
                            <Input
                                id="loan_principal"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={principal}
                                onChange={(e) => setPrincipal(e.target.value)}
                            />
                            {errors.principal && <p className="text-xs text-destructive">{errors.principal}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="loan_installment">Monthly installment</Label>
                            <Input
                                id="loan_installment"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={installment}
                                onChange={(e) => setInstallment(e.target.value)}
                            />
                            {errors.installment_amount && <p className="text-xs text-destructive">{errors.installment_amount}</p>}
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="loan_year">Start year</Label>
                            <Input id="loan_year" type="number" value={year} onChange={(e) => setYear(e.target.value)} />
                            {errors.start_year && <p className="text-xs text-destructive">{errors.start_year}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="loan_month">Start month</Label>
                            <Input id="loan_month" type="number" min="1" max="12" value={month} onChange={(e) => setMonth(e.target.value)} />
                            {errors.start_month && <p className="text-xs text-destructive">{errors.start_month}</p>}
                        </div>
                    </div>

                    {count > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {count} installment{count === 1 ? '' : 's'} of {money(i)}
                            {last !== i && count > 1 && <> — the last one is {money(last)}, carrying the remainder so the plan adds up exactly</>}.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="loan_reason">Reason</Label>
                        <Input id="loan_reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={employeeId === '' || principal === '' || installment === '' || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.loans.store'),
                                {
                                    employee_id: Number(employeeId),
                                    type,
                                    principal: Number(principal),
                                    installment_amount: Number(installment),
                                    start_year: Number(year),
                                    start_month: Number(month),
                                    reason,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setPrincipal('');
                                        setInstallment('');
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        Request
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function SettleDialog({ loan }: { loan: LoanRow }) {
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState(String(loan.outstanding));
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Settle early
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Settle {loan.reference} early</DialogTitle>
                    <DialogDescription>
                        The employee pays off the remaining {money(loan.outstanding)} in one go. Remaining installments are cancelled but kept, so the
                        original plan stays readable next to what happened. This is a debt paid early, not a debt forgiven — the amount must clear the
                        balance in full.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="settle_amount">Amount received</Label>
                        <Input id="settle_amount" type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                        {errors.amount_received && <p className="text-xs text-destructive">{errors.amount_received}</p>}
                        {errors.loan && <p className="text-xs text-destructive">{errors.loan}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="settle_note">Note</Label>
                        <Input id="settle_note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Paid by bank transfer" />
                        {errors.note && <p className="text-xs text-destructive">{errors.note}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={note.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.loans.settle', loan.id),
                                { amount_received: Number(amount), note },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                    },
                                },
                            )
                        }
                    >
                        Settle
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function LoanCard({ loan }: { loan: LoanRow }) {
    const [showDetail, setShowDetail] = useState(false);
    const progress = loan.principal > 0 ? Math.min(100, (loan.repaid / loan.principal) * 100) : 0;

    return (
        <div className={cardClass}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{loan.reference}</span>
                        <Badge variant={loan.status === 'active' ? 'default' : 'secondary'}>{loan.status_label}</Badge>
                        <span className="text-sm text-muted-foreground">{loan.type_label}</span>
                    </div>
                    <div className="mt-1 text-sm">
                        {loan.employee}
                        <span className="ml-2 text-xs text-muted-foreground">{loan.employee_code}</span>
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {money(loan.principal)} at {money(loan.installment_amount)} a month from {loan.start} · {loan.reason}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {loan.approval_status === 'pending' && !loan.is_applied && (
                        <>
                            <Button size="sm" variant="ghost" onClick={() => router.post(route('hrm.loans.reject', loan.id), {}, { preserveScroll: true })}>
                                Reject
                            </Button>
                            <Button size="sm" onClick={() => router.post(route('hrm.loans.approve', loan.id), {}, { preserveScroll: true })}>
                                Approve &amp; disburse
                            </Button>
                        </>
                    )}
                    {loan.can_settle && <SettleDialog loan={loan} />}
                    <Button size="sm" variant="ghost" onClick={() => setShowDetail((v) => !v)}>
                        {showDetail ? 'Hide' : 'Schedule & ledger'}
                    </Button>
                </div>
            </div>

            {loan.is_applied && (
                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                    <div>
                        <div className="text-xs text-muted-foreground">Outstanding</div>
                        <div className="text-xl font-medium">{money(loan.outstanding)}</div>
                    </div>
                    <div>
                        <div className="text-xs text-muted-foreground">Repaid</div>
                        <div className="text-xl font-medium">{money(loan.repaid)}</div>
                    </div>
                    <div className="sm:col-span-1">
                        <div className="text-xs text-muted-foreground">
                            {loan.installments.filter((i) => i.status === 'paid').length} of {loan.installment_count} paid
                        </div>
                        <div className="mt-2 h-2 w-full rounded bg-muted">
                            <div className="h-2 rounded bg-primary" style={{ width: `${progress}%` }} />
                        </div>
                    </div>
                </div>
            )}

            {loan.settlement_note && <p className="mt-3 text-xs text-muted-foreground">Settled: {loan.settlement_note}</p>}

            {showDetail && (
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <div className="overflow-x-auto">
                        <h4 className="mb-2 text-sm font-medium">Repayment schedule</h4>
                        {loan.installments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No schedule yet — it is generated on approval.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className={headClass}>
                                        <th className="py-2">#</th>
                                        <th className="py-2">Month</th>
                                        <th className="py-2">Amount</th>
                                        <th className="py-2">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {loan.installments.map((i) => (
                                        <tr key={i.id} className={rowClass}>
                                            <td className="py-2">{i.sequence}</td>
                                            <td className="py-2 whitespace-nowrap">{i.period}</td>
                                            <td className="py-2">{money(i.amount)}</td>
                                            <td className="py-2 text-muted-foreground">
                                                {i.status_label}
                                                {i.paid_at && <span className="ml-1 text-xs">{i.paid_at}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <h4 className="mb-2 text-sm font-medium">Ledger</h4>
                        <p className="mb-2 text-xs text-muted-foreground">
                            The outstanding balance is the sum of these rows — never a stored number.
                        </p>
                        {loan.ledger.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nothing has moved yet.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <tbody>
                                    {loan.ledger.map((e) => (
                                        <tr key={e.id} className={rowClass}>
                                            <td className="py-2 whitespace-nowrap">{e.date}</td>
                                            <td className="py-2">{e.entry_type}</td>
                                            <td className={`py-2 ${e.increases_debt ? 'text-destructive' : ''}`}>
                                                {e.amount > 0 ? `+${money(e.amount)}` : money(e.amount)}
                                            </td>
                                            <td className="py-2 text-xs text-muted-foreground">{e.note}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Loans({ filters, loans, employees, types }: Props) {
    const reload = (employeeId: number | null) =>
        router.get(route('hrm.loans.index'), { employee_id: employeeId }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Loans & advances" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Loans &amp; advances</h2>
                        <p className="text-sm text-muted-foreground">
                            Payroll deducts one scheduled installment a month. A repayment is recorded only once the payroll is disbursed, so
                            recalculating an open run never moves a balance.
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
                        <RequestDialog employees={employees} types={types} />
                    </div>
                </div>

                {loans.length === 0 ? (
                    <div className={cardClass}>
                        <p className="text-sm text-muted-foreground">No loans or advances yet.</p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        {loans.map((loan) => (
                            <LoanCard key={loan.id} loan={loan} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
