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
import { useRef, useState } from 'react';

interface PaymentRow {
    id: number;
    type: string;
    type_label: string;
    amount: number;
    method: string;
    paid_on: string;
    reference: string | null;
    failure_reason: string | null;
    is_reversed: boolean;
}

interface PayslipRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    method: string;
    net: number;
    paid_total: number;
    outstanding: number;
    payment_status: string;
    payment_status_label: string;
    failure_reason: string | null;
    is_blocked: boolean;
    batch: string | null;
    payments: PaymentRow[];
}

interface BatchRow {
    id: number;
    reference: string;
    method: string;
    method_label: string;
    payslip_count: number;
    expected_total: number;
    paid_total: number;
    advice_reference: string | null;
    advice_file: string | null;
    confirmed_at: string | null;
    confirmed_by: string | null;
}

interface Props {
    run: {
        id: number;
        version: number;
        status: string;
        status_label: string;
        period: string | null;
        total_net: number;
        employee_count: number;
        is_disbursed: boolean;
    };
    batches: BatchRow[];
    payslips: PayslipRow[];
    methods: { value: string; label: string }[];
    formats: { key: string; name: string; description: string }[];
}

const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';
const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => new Date().toISOString().slice(0, 10);

function statusVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'paid') return 'default';
    if (status === 'partial') return 'secondary';
    return 'outline';
}

function PayDialog({ payslip, methods }: { payslip: PayslipRow; methods: Props['methods'] }) {
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState(String(payslip.outstanding));
    const [method, setMethod] = useState('');
    const [paidOn, setPaidOn] = useState(today());
    const [reference, setReference] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    Record payment
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record a payment for {payslip.employee}</DialogTitle>
                    <DialogDescription>
                        {money(payslip.outstanding)} is still outstanding on this payslip. A part payment is allowed; anything more than the
                        outstanding amount is refused.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="pay_amount">Amount</Label>
                        <Input id="pay_amount" type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
                        {errors.amount && <p className="text-xs text-destructive">{errors.amount}</p>}
                        {errors.payslip && <p className="text-xs text-destructive">{errors.payslip}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="pay_method">Method</Label>
                            <select id="pay_method" className={selectClass} value={method} onChange={(e) => setMethod(e.target.value)}>
                                <option value="">{payslip.method} (as recorded)</option>
                                {methods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pay_date">Paid on</Label>
                            <Input id="pay_date" type="date" value={paidOn} onChange={(e) => setPaidOn(e.target.value)} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pay_reference">Reference</Label>
                        <Input id="pay_reference" value={reference} onChange={(e) => setReference(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={amount === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.payslips.payments.store', payslip.id),
                                { amount: Number(amount), method: method || null, paid_on: paidOn, reference: reference || null },
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
                        Record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function FailDialog({ payslip }: { payslip: PayslipRow }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost">
                    Record failure
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Payment failed for {payslip.employee}</DialogTitle>
                    <DialogDescription>
                        The attempt is kept on record with its reason so it can be retried. Because nothing reached the employee, any loan installment
                        and carry-forward recovery taken on this payslip is reversed — the installment becomes due again rather than quietly forgiven.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="fail_reason">What went wrong</Label>
                    <Input id="fail_reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Account closed" />
                    {errors.reason && <p className="text-xs text-destructive">{errors.reason}</p>}
                    {errors.payslip && <p className="text-xs text-destructive">{errors.payslip}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.payslips.payments.fail', payslip.id),
                                { reason, failed_on: today() },
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
                        Record failure
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function BatchCard({ batch, formats }: { batch: BatchRow; formats: Props['formats'] }) {
    const [format, setFormat] = useState(formats[0]?.key ?? 'generic_csv');
    const [adviceReference, setAdviceReference] = useState(batch.advice_reference ?? '');
    const [failReason, setFailReason] = useState('');
    const fileRef = useRef<HTMLInputElement>(null);

    return (
        <div className={cardClass}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <h3 className="font-medium">{batch.reference}</h3>
                        <Badge variant="secondary">{batch.method_label}</Badge>
                        {batch.confirmed_at ? <Badge>Confirmed</Badge> : <Badge variant="outline">Awaiting confirmation</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {batch.payslip_count} payslip{batch.payslip_count === 1 ? '' : 's'} — {money(batch.paid_total)} of{' '}
                        {money(batch.expected_total)} paid
                        {batch.confirmed_at && batch.confirmed_by ? ` · confirmed ${batch.confirmed_at} by ${batch.confirmed_by}` : ''}
                    </p>
                    {batch.advice_file && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Advice on file: {batch.advice_file}
                            {batch.advice_reference ? ` (${batch.advice_reference})` : ''}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <select className={selectClass} value={format} onChange={(e) => setFormat(e.target.value)}>
                        {formats.map((f) => (
                            <option key={f.key} value={f.key}>
                                {f.name}
                            </option>
                        ))}
                    </select>
                    <a href={route('hrm.batches.register', { batch: batch.id, format })}>
                        <Button size="sm" variant="outline">
                            Download register
                        </Button>
                    </a>
                </div>
            </div>

            <p className="mt-2 text-xs text-muted-foreground">{formats.find((f) => f.key === format)?.description}</p>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`advice_${batch.id}`}>Bank advice file &amp; reference</Label>
                    <div className="flex flex-wrap gap-2">
                        <Input id={`advice_${batch.id}`} type="file" ref={fileRef} className="max-w-[220px]" />
                        <Input
                            value={adviceReference}
                            onChange={(e) => setAdviceReference(e.target.value)}
                            placeholder="Reference"
                            className="max-w-[160px]"
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                const file = fileRef.current?.files?.[0];
                                if (!file) return;
                                router.post(
                                    route('hrm.batches.advice', batch.id),
                                    { file, advice_reference: adviceReference || null },
                                    { preserveScroll: true, forceFormData: true },
                                );
                            }}
                        >
                            Upload
                        </Button>
                    </div>
                </div>

                {!batch.confirmed_at && (
                    <div className="grid gap-2">
                        <Label htmlFor={`fail_${batch.id}`}>Confirm the whole batch, or record it as failed</Label>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        route('hrm.batches.confirm', batch.id),
                                        { paid_on: today(), advice_reference: adviceReference || null },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Confirm batch paid
                            </Button>
                            <Input
                                id={`fail_${batch.id}`}
                                value={failReason}
                                onChange={(e) => setFailReason(e.target.value)}
                                placeholder="Failure reason"
                                className="max-w-[180px]"
                            />
                            <Button
                                size="sm"
                                variant="ghost"
                                disabled={failReason.trim() === ''}
                                onClick={() =>
                                    router.post(
                                        route('hrm.batches.fail', batch.id),
                                        { reason: failReason, failed_on: today() },
                                        { preserveScroll: true, onSuccess: () => setFailReason('') },
                                    )
                                }
                            >
                                Record batch failure
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function PayslipRowView({ payslip, methods }: { payslip: PayslipRow; methods: Props['methods'] }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <tr className={rowClass}>
                <td className="py-2">
                    <button className="text-left hover:underline" onClick={() => setOpen((v) => !v)}>
                        {payslip.employee}
                        <span className="block text-xs text-muted-foreground">{payslip.employee_code}</span>
                    </button>
                </td>
                <td className="py-2">{payslip.method}</td>
                <td className="py-2 text-right">{money(payslip.net)}</td>
                <td className="py-2 text-right">{money(payslip.paid_total)}</td>
                <td className="py-2 pr-4 text-right">{money(payslip.outstanding)}</td>
                <td className="py-2">
                    <Badge variant={statusVariant(payslip.payment_status)}>{payslip.payment_status_label}</Badge>
                    {payslip.failure_reason && <span className="ml-2 text-xs text-destructive">{payslip.failure_reason}</span>}
                </td>
                <td className="py-2">
                    <div className="flex flex-wrap justify-end gap-1">
                        <a href={route('hrm.payslips.show', payslip.id)}>
                            <Button size="sm" variant="ghost">
                                Payslip
                            </Button>
                        </a>
                        {payslip.outstanding > 0 && !payslip.is_blocked && (
                            <>
                                <PayDialog payslip={payslip} methods={methods} />
                                <FailDialog payslip={payslip} />
                            </>
                        )}
                    </div>
                </td>
            </tr>
            {open && payslip.payments.length > 0 && (
                <tr>
                    <td colSpan={7} className="bg-muted/40 px-3 py-3">
                        <table className="w-full text-xs">
                            <tbody>
                                {payslip.payments.map((p) => (
                                    <tr key={p.id} className={rowClass}>
                                        <td className="py-1 whitespace-nowrap">{p.paid_on}</td>
                                        <td className="py-1">
                                            {p.type_label}
                                            {p.failure_reason ? ` — ${p.failure_reason}` : ''}
                                        </td>
                                        <td className="py-1">{p.method}</td>
                                        <td className="py-1">{p.reference ?? '—'}</td>
                                        <td className="py-1 text-right">{money(p.amount)}</td>
                                        <td className="py-1 text-right">
                                            {p.type === 'paid' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => {
                                                        const reason = window.prompt('Why is this payment being reversed?');
                                                        if (reason && reason.trim() !== '') {
                                                            router.post(
                                                                route('hrm.payslips.payments.reverse', p.id),
                                                                { reason, reversed_on: today() },
                                                                { preserveScroll: true },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    Reverse
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </td>
                </tr>
            )}
        </>
    );
}

export default function Disbursement({ run, batches, payslips, methods, formats }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Payroll', href: '/hrm/payroll' },
        { title: `Disbursement — ${run.period ?? ''}`, href: `/hrm/payroll/${run.id}/disbursement` },
    ];

    const paid = payslips.reduce((sum, p) => sum + p.paid_total, 0);
    const outstanding = payslips.reduce((sum, p) => sum + Math.max(p.outstanding, 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Disbursement — ${run.period ?? ''}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">
                            Disbursement — {run.period} <span className="text-muted-foreground">v{run.version}</span>
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {money(paid)} paid of {money(run.total_net)}
                            {outstanding > 0 ? ` — ${money(outstanding)} still outstanding` : ' — nothing outstanding'}.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={run.is_disbursed ? 'default' : 'outline'}>{run.status_label}</Badge>
                        <a href={route('hrm.payroll.accounting-export', { run: run.id, mode: 'summary' })}>
                            <Button size="sm" variant="outline">
                                Accounting summary
                            </Button>
                        </a>
                        <a href={route('hrm.payroll.accounting-export', { run: run.id, mode: 'detail' })}>
                            <Button size="sm" variant="outline">
                                Accounting detail
                            </Button>
                        </a>
                        <Button size="sm" variant="outline" onClick={() => router.post(route('hrm.payroll.email-payslips', run.id), {}, { preserveScroll: true })}>
                            Email all payslips
                        </Button>
                    </div>
                </div>

                {!run.is_disbursed && (
                    <div className={cardClass}>
                        <p className="text-sm text-muted-foreground">
                            This payroll is {run.status_label.toLowerCase()}. Nothing can be recorded as paid until it is disbursed — the loan and
                            carry-forward postings this reconciles against do not exist before then.
                        </p>
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="font-medium">Batches</h3>
                            <p className="text-sm text-muted-foreground">
                                One per payment method, so cash never mixes with a bank transfer. Confirming a batch records a payment for every
                                payslip in it, exactly as confirming them one at a time would.
                            </p>
                        </div>
                        {run.is_disbursed && (
                            <Button size="sm" onClick={() => router.post(route('hrm.payroll.batches.store', run.id), {}, { preserveScroll: true })}>
                                Create batches
                            </Button>
                        )}
                    </div>

                    {batches.length === 0 ? (
                        <div className={cardClass}>
                            <p className="text-sm text-muted-foreground">No batches yet.</p>
                        </div>
                    ) : (
                        batches.map((batch) => <BatchCard key={batch.id} batch={batch} formats={formats} />)
                    )}
                </div>

                <div className={cardClass}>
                    <h3 className="mb-3 font-medium">Payslips</h3>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Method</th>
                                    <th className="py-2 text-right">Net</th>
                                    <th className="py-2 text-right">Paid</th>
                                    <th className="py-2 pr-4 text-right">Outstanding</th>
                                    <th className="py-2">Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {payslips.map((payslip) => (
                                    <PayslipRowView key={payslip.id} payslip={payslip} methods={methods} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
