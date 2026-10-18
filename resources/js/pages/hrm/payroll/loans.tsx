import { BusyButton } from '@/components/busy-button';
import { MonthSelect } from '@/components/month-select';
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
import { dhakaToday, showDate } from '@/lib/dates';
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
    moneyAccounts: { id: number; name: string }[];
    recoverableFrom: { year: number; month: number; label: string };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Loans & advances', href: '/hrm/loans' }];
const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function RequestDialog({
    employees,
    types,
    recoverableFrom,
}: {
    employees: Props['employees'];
    types: Props['types'];
    recoverableFrom: Props['recoverableFrom'];
}) {
    const [open, setOpen] = useState(false);
    const [employeeId, setEmployeeId] = useState('');
    const [type, setType] = useState(types[0]?.value ?? 'loan');
    const [principal, setPrincipal] = useState('');
    const [installment, setInstallment] = useState('');
    // ⚠ The first payroll month that can still recover this, which is not
    // necessarily this calendar month: an advance handed out in August comes off
    // the August salary while that salary is unpaid, however late it is entered.
    const [year, setYear] = useState(String(recoverableFrom.year));
    const [month, setMonth] = useState(String(recoverableFrom.month));
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
                </DialogHeader>
                <div className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <div className="grid gap-2">
                        <Label htmlFor="loan_employee" required>
                            Employee
                        </Label>
                        <SearchSelect id="loan_employee" value={employeeId} onChange={(e) => setEmployeeId(e.target.value)}>
                            <option value="">- Select -</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                    {e.outstanding > 0 ? ` - ${money(e.outstanding)} outstanding` : ''}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.employee_id && <p className="text-destructive text-xs">{errors.employee_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="loan_type" required>
                            Type
                        </Label>
                        <SearchSelect id="loan_type" value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="loan_principal" required>
                                Principal
                            </Label>
                            <Input
                                id="loan_principal"
                                aria-required="true"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={principal}
                                onChange={(e) => setPrincipal(e.target.value)}
                            />
                            {errors.principal && <p className="text-destructive text-xs">{errors.principal}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="loan_installment" required>
                                Monthly installment
                            </Label>
                            <Input
                                id="loan_installment"
                                aria-required="true"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={installment}
                                onChange={(e) => setInstallment(e.target.value)}
                            />
                            {errors.installment_amount && <p className="text-destructive text-xs">{errors.installment_amount}</p>}
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="loan_year" required>
                                Start year
                            </Label>
                            <Input id="loan_year" aria-required="true" type="number" value={year} onChange={(e) => setYear(e.target.value)} />
                            {errors.start_year && <p className="text-destructive text-xs">{errors.start_year}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="loan_month" required>
                                Start month
                            </Label>
                            <MonthSelect id="loan_month" value={month} onChange={setMonth} />
                            {errors.start_month && <p className="text-destructive text-xs">{errors.start_month}</p>}
                            <p className="text-muted-foreground text-xs">
                                The salary this comes off. {recoverableFrom.label} is the earliest one still unpaid.
                            </p>
                        </div>
                    </div>

                    {count > 0 && (
                        <p className="text-muted-foreground text-xs">
                            {count} installment{count === 1 ? '' : 's'} of {money(i)}
                            {last !== i && count > 1 && <> - the last one is {money(last)}, carrying the remainder so the plan adds up exactly</>}.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="loan_reason" required>
                            Reason
                        </Label>
                        <Input id="loan_reason" aria-required="true" value={reason} onChange={(e) => setReason(e.target.value)} />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
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

function SettleDialog({ loan, moneyAccounts }: { loan: LoanRow; moneyAccounts: Props['moneyAccounts'] }) {
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState(String(loan.outstanding));
    const [accountId, setAccountId] = useState(String(moneyAccounts[0]?.id ?? ''));
    const [receivedOn, setReceivedOn] = useState(dhakaToday());
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
                        original plan stays readable next to what happened. This is a debt paid early, not a debt forgiven - the amount must clear the
                        balance in full.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="settle_amount" required>
                            Amount received
                        </Label>
                        <Input
                            id="settle_amount"
                            aria-required="true"
                            type="number"
                            step="0.01"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                        {errors.amount_received && <p className="text-destructive text-xs">{errors.amount_received}</p>}
                        {errors.loan && <p className="text-destructive text-xs">{errors.loan}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="settle_account" required>
                            Received into
                        </Label>
                        <SearchSelect id="settle_account" value={accountId} onChange={(e) => setAccountId(e.target.value)}>
                            <option value="">Select an account</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-xs">{errors.money_account_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="settle_date" required>
                            Received on
                        </Label>
                        <Input
                            id="settle_date"
                            aria-required="true"
                            type="date"
                            className="w-auto"
                            value={receivedOn}
                            onChange={(e) => setReceivedOn(e.target.value)}
                        />
                        {errors.received_on && <p className="text-destructive text-xs">{errors.received_on}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="settle_note" required>
                            Note
                        </Label>
                        <Input
                            id="settle_note"
                            aria-required="true"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="e.g. Paid by bank transfer"
                        />
                        {errors.note && <p className="text-destructive text-xs">{errors.note}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={note.trim() === '' || accountId === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.loans.settle', loan.id),
                                { amount_received: Number(amount), note, money_account_id: Number(accountId), received_on: receivedOn },
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

/**
 * Approving an advance pays it out, so this is the moment to ask the two things
 * only the person handing the money over knows: which account it left, and on
 * what day.
 *
 * ⚠⚠ The button used to post nothing at all. The approval went through, the
 * disbursement then failed for want of an account, and the screen showed a
 * blank error over a loan that was approved and unpaid. Both answers are asked
 * here, before anything is recorded.
 */
function ApproveDialog({ loan, moneyAccounts }: { loan: LoanRow; moneyAccounts: Props['moneyAccounts'] }) {
    const [open, setOpen] = useState(false);
    const [accountId, setAccountId] = useState(String(moneyAccounts[0]?.id ?? ''));
    const [paidOn, setPaidOn] = useState(dhakaToday());
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">Approve &amp; disburse</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Approve {loan.reference} for {loan.employee}
                    </DialogTitle>
                    <DialogDescription>
                        Approving pays out {money(loan.principal)} and creates the repayment schedule. The ledger records the money leaving the
                        account below on the day below, so both must be what actually happened, not what is convenient today.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="approve_account" required>
                            Paid from
                        </Label>
                        <SearchSelect id="approve_account" value={accountId} onChange={(e) => setAccountId(e.target.value)}>
                            <option value="">Select an account</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-xs">{errors.money_account_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="approve_date" required>
                            Paid on
                        </Label>
                        <Input id="approve_date" aria-required="true" type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
                        {errors.disbursed_on && <p className="text-destructive text-xs">{errors.disbursed_on}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="approve_note">Note</Label>
                        <Input id="approve_note" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Optional" />
                        {errors.note && <p className="text-destructive text-xs">{errors.note}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <BusyButton
                        busyLabel="Approving…"
                        disabled={accountId === '' || paidOn === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.loans.approve', loan.id),
                                { money_account_id: Number(accountId), disbursed_on: paidOn, note: note || null },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setErrors({});
                                        setOpen(false);
                                    },
                                    onError: setErrors,
                                },
                            )
                        }
                    >
                        Approve &amp; disburse
                    </BusyButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function LoanCard({ loan, moneyAccounts }: { loan: LoanRow; moneyAccounts: Props['moneyAccounts'] }) {
    const [showDetail, setShowDetail] = useState(false);
    const progress = loan.principal > 0 ? Math.min(100, (loan.repaid / loan.principal) * 100) : 0;

    return (
        <div className={cardClass}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{loan.reference}</span>
                        <Badge variant={loan.status === 'active' ? 'default' : 'secondary'}>{loan.status_label}</Badge>
                        <span className="text-muted-foreground text-sm">{loan.type_label}</span>
                    </div>
                    <div className="mt-1 text-sm">
                        {loan.employee}
                        <span className="text-muted-foreground ml-2 text-xs">{loan.employee_code}</span>
                    </div>
                    <div className="text-muted-foreground mt-1 text-xs">
                        {money(loan.principal)} at {money(loan.installment_amount)} a month from {loan.start} · {loan.reason}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {loan.approval_status === 'pending' && !loan.is_applied && (
                        <>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post(route('hrm.loans.reject', loan.id), {}, { preserveScroll: true })}
                            >
                                Reject
                            </Button>
                            <ApproveDialog loan={loan} moneyAccounts={moneyAccounts} />
                        </>
                    )}
                    {loan.can_settle && <SettleDialog loan={loan} moneyAccounts={moneyAccounts} />}
                    <Button size="sm" variant="ghost" onClick={() => setShowDetail((v) => !v)}>
                        {showDetail ? 'Hide' : 'Schedule & ledger'}
                    </Button>
                </div>
            </div>

            {loan.is_applied && (
                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                    <div>
                        <div className="text-muted-foreground text-xs">Outstanding</div>
                        <div className="text-xl font-medium">{money(loan.outstanding)}</div>
                    </div>
                    <div>
                        <div className="text-muted-foreground text-xs">Repaid</div>
                        <div className="text-xl font-medium">{money(loan.repaid)}</div>
                    </div>
                    <div className="sm:col-span-1">
                        <div className="text-muted-foreground text-xs">
                            {loan.installments.filter((i) => i.status === 'paid').length} of {loan.installment_count} paid
                        </div>
                        <div className="bg-muted mt-2 h-2 w-full rounded">
                            <div className="bg-primary h-2 rounded" style={{ width: `${progress}%` }} />
                        </div>
                    </div>
                </div>
            )}

            {loan.settlement_note && <p className="text-muted-foreground mt-3 text-xs">Settled: {loan.settlement_note}</p>}

            {showDetail && (
                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <div className="overflow-x-auto">
                        <h4 className="mb-2 text-sm font-medium">Repayment schedule</h4>
                        {loan.installments.length === 0 ? (
                            <p className="text-muted-foreground text-sm">No schedule yet - it is generated on approval.</p>
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
                                            <td className="text-muted-foreground py-2">
                                                {i.status_label}
                                                {i.paid_at && <span className="ml-1 text-xs">{showDate(i.paid_at)}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <div className="overflow-x-auto">
                        <h4 className="mb-2 text-sm font-medium">Ledger</h4>
                        {loan.ledger.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing has moved yet.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <tbody>
                                    {loan.ledger.map((e) => (
                                        <tr key={e.id} className={rowClass}>
                                            <td className="py-2 whitespace-nowrap">{showDate(e.date)}</td>
                                            <td className="py-2">{e.entry_type}</td>
                                            <td className={`py-2 ${e.increases_debt ? 'text-destructive' : ''}`}>
                                                {e.amount > 0 ? `+${money(e.amount)}` : money(e.amount)}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-xs">{e.note}</td>
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

export default function Loans({ filters, loans, employees, types, moneyAccounts, recoverableFrom }: Props) {
    const reload = (employeeId: number | null) =>
        router.get(route('hrm.loans.index'), { employee_id: employeeId }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Loans & advances" />
            <div className="flex flex-col gap-4 p-4">
                {/* The section tab row above names the screen. */}
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <SearchSelect
                        fit
                        value={filters.employee_id ?? ''}
                        onChange={(e) => reload(e.target.value === '' ? null : Number(e.target.value))}
                    >
                        <option value="">All employees</option>
                        {employees.map((e) => (
                            <option key={e.id} value={e.id}>
                                {e.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <RequestDialog employees={employees} types={types} recoverableFrom={recoverableFrom} />
                </div>

                {loans.length === 0 ? (
                    <div className={cardClass}>
                        <p className="text-muted-foreground text-sm">No loans or advances yet.</p>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        {loans.map((loan) => (
                            <LoanCard key={loan.id} loan={loan} moneyAccounts={moneyAccounts} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
