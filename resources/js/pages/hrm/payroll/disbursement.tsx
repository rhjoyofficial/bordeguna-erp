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
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
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
    can_correct_date: boolean;
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
    email: {
        address: string | null;
        sent_at: string | null;
        error: string | null;
        reachable: boolean;
    };
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
    paid_dates: string[];
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
    moneyAccounts: { id: number; name: string }[];
    can_correct_paid_date: boolean;
    formats: { key: string; name: string; description: string }[];
    email_summary: {
        sendable: number;
        unreachable: string[];
        sent: number;
        failed: number;
    };
}

const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground';

const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => dhakaToday();

function statusVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (status === 'paid') return 'default';
    if (status === 'partial') return 'secondary';
    return 'outline';
}

function PayDialog({ payslip, methods, moneyAccounts }: { payslip: PayslipRow; methods: Props['methods']; moneyAccounts: Props['moneyAccounts'] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState(String(payslip.outstanding));
    const [method, setMethod] = useState('');
    const [paidOn, setPaidOn] = useState(today());
    const [reference, setReference] = useState('');
    // ⚠⚠ The account is asked, never assumed. Without it the posting threw
    // MappingNotFound and the screen showed a blank 500 mid-disbursement, with
    // the payment rolled back so it looked as though nothing had happened.
    const [account, setAccount] = useState(String(moneyAccounts[0]?.id ?? ''));
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
                        <Label htmlFor="pay_amount" required>
                            Amount
                        </Label>
                        <Input
                            id="pay_amount"
                            aria-required="true"
                            type="number"
                            step="0.01"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                        {errors.amount && <p className="text-destructive text-xs">{errors.amount}</p>}
                        {errors.payslip && <p className="text-destructive text-xs">{errors.payslip}</p>}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="pay_method">Method</Label>
                            <SearchSelect id="pay_method" value={method} onChange={(e) => setMethod(e.target.value)}>
                                <option value="">{payslip.method} (as recorded)</option>
                                {methods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pay_date" required>
                                {t('payroll_pay.paid_on_label')}
                            </Label>
                            <Input
                                id="pay_date"
                                aria-required="true"
                                type="date"
                                max={today()}
                                value={paidOn}
                                onChange={(e) => setPaidOn(e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">{t('payroll_pay.paid_on_hint')}</p>
                            {errors.paid_on && <p className="text-destructive text-xs">{errors.paid_on}</p>}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="pay_account" required>
                            Paid from
                        </Label>
                        <SearchSelect id="pay_account" value={account} onChange={(e) => setAccount(e.target.value)}>
                            <option value="">Select an account</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-xs">{errors.money_account_id}</p>}
                        {errors.accrual && <p className="text-destructive text-xs">{errors.accrual}</p>}
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
                        disabled={amount === '' || account === '' || paidOn === ''}
                        onClick={() =>
                            router.post(
                                route('hrm.payslips.payments.store', payslip.id),
                                {
                                    amount: Number(amount),
                                    method: method || null,
                                    paid_on: paidOn,
                                    reference: reference || null,
                                    money_account_id: Number(account),
                                },
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
                        and carry-forward recovery taken on this payslip is reversed - the installment becomes due again rather than quietly forgiven.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="fail_reason" required>
                        What went wrong
                    </Label>
                    <Input
                        id="fail_reason"
                        aria-required="true"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Account closed"
                    />
                    {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    {errors.payslip && <p className="text-destructive text-xs">{errors.payslip}</p>}
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

/**
 * Owner only (12 Oct 2026). Moves a confirmed batch, or one payment, to the day
 * the money actually left: the wrongly dated journal is reversed on its own
 * date and posted again on the new one. Nothing is edited or deleted.
 */
function CorrectDateDialog({ url, title }: { url: string; title: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [paidOn, setPaidOn] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    {t('payroll_pay.correct_date')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{t('payroll_pay.correct_description')}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="correct_paid_on" required>
                            {t('payroll_pay.new_date')}
                        </Label>
                        <Input
                            id="correct_paid_on"
                            aria-required="true"
                            type="date"
                            max={today()}
                            value={paidOn}
                            onChange={(e) => setPaidOn(e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">{t('payroll_pay.paid_on_hint')}</p>
                        {errors.paid_on && <p className="text-destructive text-xs">{errors.paid_on}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="correct_reason" required>
                            {t('payroll_pay.reason')}
                        </Label>
                        <Input
                            id="correct_reason"
                            aria-required="true"
                            value={reason}
                            placeholder={t('payroll_pay.reason_placeholder')}
                            onChange={(e) => setReason(e.target.value)}
                        />
                        {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{t('payroll_pay.cancel')}</Button>
                    </DialogClose>
                    <Button
                        disabled={paidOn === '' || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                url,
                                { paid_on: paidOn, reason },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setPaidOn('');
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        {t('payroll_pay.save_correction')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function BatchCard({
    batch,
    formats,
    moneyAccounts,
    canCorrect,
}: {
    batch: BatchRow;
    formats: Props['formats'];
    moneyAccounts: Props['moneyAccounts'];
    canCorrect: boolean;
}) {
    const { t } = useTranslation();
    const [format, setFormat] = useState(formats[0]?.key ?? 'generic_csv');
    const [adviceReference, setAdviceReference] = useState(batch.advice_reference ?? '');
    const [failReason, setFailReason] = useState('');
    // ⚠⚠ The button used to post only the date and the advice reference, while
    // the route has always REQUIRED the account the money left from. Confirming
    // a batch therefore failed validation every time, in silence, and August's
    // salary could not be recorded as paid by this route at all.
    const [account, setAccount] = useState(String(moneyAccounts[0]?.id ?? ''));
    // ⚠⚠ EMPTY ON PURPOSE (12 Oct 2026). This used to send today's date without
    // asking. September's salaries, paid by the bank on 30 Sep and confirmed
    // here on 12 Oct, posted nine journals on the wrong day. The person
    // confirming has to read the date off the bank statement and type it.
    const [paidOn, setPaidOn] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
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
                    <p className="text-muted-foreground mt-1 text-sm">
                        {batch.payslip_count} payslip{batch.payslip_count === 1 ? '' : 's'} - {money(batch.paid_total)} of{' '}
                        {money(batch.expected_total)} paid
                        {batch.confirmed_at && batch.confirmed_by ? ` · confirmed ${batch.confirmed_at} by ${batch.confirmed_by}` : ''}
                    </p>
                    {batch.confirmed_at && batch.paid_dates.length > 0 && (
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <p className="text-sm font-medium">{t('payroll_pay.paid_dates', { dates: batch.paid_dates.join(', ') })}</p>
                            {canCorrect && (
                                <CorrectDateDialog
                                    url={route('hrm.batches.correct-paid-date', batch.id)}
                                    title={t('payroll_pay.correct_title_batch', { reference: batch.reference })}
                                />
                            )}
                        </div>
                    )}
                    {batch.advice_file && (
                        <p className="text-muted-foreground mt-1 text-xs">
                            Advice on file: {batch.advice_file}
                            {batch.advice_reference ? ` (${batch.advice_reference})` : ''}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <SearchSelect value={format} onChange={(e) => setFormat(e.target.value)}>
                        {formats.map((f) => (
                            <option key={f.key} value={f.key}>
                                {f.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <a href={route('hrm.batches.register', { batch: batch.id, format })}>
                        <Button size="sm" variant="outline">
                            Download register
                        </Button>
                    </a>
                </div>
            </div>

            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`advice_${batch.id}`}>
                        Bank advice file
                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                            *
                        </span>{' '}
                        &amp; reference
                    </Label>
                    <div className="flex flex-wrap gap-2">
                        <Input id={`advice_${batch.id}`} type="file" ref={fileRef} aria-required="true" className="max-w-[220px]" />
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
                        <Label htmlFor={`account_${batch.id}`} required>
                            Paid from
                        </Label>
                        <SearchSelect id={`account_${batch.id}`} value={account} onChange={(e) => setAccount(e.target.value)}>
                            <option value="">Select an account</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-xs">{errors.money_account_id}</p>}

                        <Label htmlFor={`paid_on_${batch.id}`} required>
                            {t('payroll_pay.paid_on_label')}
                        </Label>
                        <Input
                            id={`paid_on_${batch.id}`}
                            aria-required="true"
                            type="date"
                            max={today()}
                            value={paidOn}
                            onChange={(e) => setPaidOn(e.target.value)}
                            className="max-w-[200px]"
                        />
                        <p className="text-muted-foreground text-xs">{t('payroll_pay.paid_on_hint')}</p>
                        {errors.paid_on && <p className="text-destructive text-xs">{errors.paid_on}</p>}
                        {errors.accrual && <p className="text-destructive text-xs">{errors.accrual}</p>}
                        {errors.batch && <p className="text-destructive text-xs">{errors.batch}</p>}
                        {errors.payslip && <p className="text-destructive text-xs">{errors.payslip}</p>}

                        <Label htmlFor={`fail_${batch.id}`}>Confirm the whole batch, or record it as failed</Label>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                disabled={account === '' || paidOn === ''}
                                title={paidOn === '' ? t('payroll_pay.paid_on_needed') : undefined}
                                onClick={() =>
                                    router.post(
                                        route('hrm.batches.confirm', batch.id),
                                        { paid_on: paidOn, advice_reference: adviceReference || null, money_account_id: Number(account) },
                                        {
                                            preserveScroll: true,
                                            onError: (e: Record<string, string>) => setErrors(e),
                                            onSuccess: () => setErrors({}),
                                        },
                                    )
                                }
                            >
                                Confirm batch paid
                            </Button>
                            <Input
                                id={`fail_${batch.id}`}
                                aria-required="true"
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

function PayslipRowView({
    payslip,
    methods,
    moneyAccounts,
    canCorrect,
}: {
    payslip: PayslipRow;
    methods: Props['methods'];
    moneyAccounts: Props['moneyAccounts'];
    canCorrect: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <>
            <tr className={rowClass}>
                <td className="py-2">
                    <button className="text-left hover:underline" onClick={() => setOpen((v) => !v)}>
                        {payslip.employee}
                        <span className="text-muted-foreground block text-xs">{payslip.employee_code}</span>
                    </button>
                    {payslip.email.error ? (
                        <span className="text-destructive block text-xs">Email failed: {payslip.email.error}</span>
                    ) : payslip.email.sent_at ? (
                        <span className="text-muted-foreground block text-xs">
                            Emailed {payslip.email.sent_at} to {payslip.email.address}
                        </span>
                    ) : !payslip.email.reachable ? (
                        <span className="text-muted-foreground block text-xs">No email address</span>
                    ) : null}
                </td>
                <td className="py-2">{payslip.method}</td>
                <td className="py-2 text-right">{money(payslip.net)}</td>
                <td className="py-2 text-right">{money(payslip.paid_total)}</td>
                <td className="py-2 pr-4 text-right">{money(payslip.outstanding)}</td>
                <td className="py-2">
                    <Badge variant={statusVariant(payslip.payment_status)}>{payslip.payment_status_label}</Badge>
                    {payslip.failure_reason && <span className="text-destructive ml-2 text-xs">{payslip.failure_reason}</span>}
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
                                <PayDialog payslip={payslip} methods={methods} moneyAccounts={moneyAccounts} />
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
                                            {p.failure_reason ? ` - ${p.failure_reason}` : ''}
                                        </td>
                                        <td className="py-1">{p.method}</td>
                                        <td className="py-1">{p.reference ?? '-'}</td>
                                        <td className="py-1 text-right">{money(p.amount)}</td>
                                        <td className="py-1 text-right">
                                            {canCorrect && p.can_correct_date && (
                                                <CorrectDateDialog
                                                    url={route('hrm.payslips.payments.correct-paid-date', p.id)}
                                                    title={t('payroll_pay.correct_title_payment')}
                                                />
                                            )}
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

export default function Disbursement({ run, batches, payslips, methods, moneyAccounts, can_correct_paid_date, formats, email_summary }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Payroll', href: '/hrm/payroll' },
        { title: `Disbursement - ${run.period ?? ''}`, href: `/hrm/payroll/${run.id}/disbursement` },
    ];

    const paid = payslips.reduce((sum, p) => sum + p.paid_total, 0);
    const outstanding = payslips.reduce((sum, p) => sum + Math.max(p.outstanding, 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Disbursement - ${run.period ?? ''}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">
                            Disbursement - {run.period} <span className="text-muted-foreground">v{run.version}</span>
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {money(paid)} paid of {money(run.total_net)}
                            {outstanding > 0 ? ` - ${money(outstanding)} still outstanding` : ' - nothing outstanding'}.
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
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={email_summary.sendable === 0}
                            onClick={() => router.post(route('hrm.payroll.email-payslips', run.id), {}, { preserveScroll: true })}
                        >
                            {/* ⚠ The count is on the button because "queued" after
                                the fact is not the moment somebody needs it. */}
                            Email {email_summary.sendable} payslip{email_summary.sendable === 1 ? '' : 's'}
                        </Button>
                    </div>
                </div>

                {(email_summary.unreachable.length > 0 || email_summary.sent > 0 || email_summary.failed > 0) && (
                    <div className={cardClass}>
                        <p className="text-sm font-medium">Payslips by email</p>
                        <p className="text-muted-foreground text-sm">
                            {/* ⚠ The EXAMPLE is the point. Joy read the old wording, typed the date on its own,
                                and could not open his own employee's payslip. The rule is now the date alone, and
                                it is still shown with a worked example. */}
                            Each PDF is password protected with the employee's date of birth as DDMMYYYY, with no spaces or slashes. For example{' '}
                            <strong>20121992</strong> for someone born on 20 December 1992.
                        </p>
                        <ul className="mt-2 space-y-1 text-sm">
                            {email_summary.sent > 0 && <li>{email_summary.sent} sent.</li>}
                            {email_summary.failed > 0 && (
                                <li className="text-destructive">{email_summary.failed} failed. The reason is on the person's row below.</li>
                            )}
                            {email_summary.unreachable.length > 0 && (
                                <li>
                                    No email address on record for {email_summary.unreachable.join(', ')}. They are skipped rather than queued, so
                                    hand those payslips over in person or add an address to the employee first.
                                </li>
                            )}
                        </ul>
                    </div>
                )}

                {!run.is_disbursed && (
                    <div className={cardClass}>
                        <p className="text-muted-foreground text-sm">
                            This payroll is {run.status_label.toLowerCase()}. Nothing can be recorded as paid until it is disbursed - the loan and
                            carry-forward postings this reconciles against do not exist before then.
                        </p>
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="font-medium">Batches</h3>
                        </div>
                        {run.is_disbursed && (
                            <Button size="sm" onClick={() => router.post(route('hrm.payroll.batches.store', run.id), {}, { preserveScroll: true })}>
                                Create batches
                            </Button>
                        )}
                    </div>

                    {batches.length === 0 ? (
                        <div className={cardClass}>
                            <p className="text-muted-foreground text-sm">No batches yet.</p>
                        </div>
                    ) : (
                        batches.map((batch) => (
                            <BatchCard
                                key={batch.id}
                                batch={batch}
                                formats={formats}
                                moneyAccounts={moneyAccounts}
                                canCorrect={can_correct_paid_date}
                            />
                        ))
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
                                    <PayslipRowView
                                        key={payslip.id}
                                        payslip={payslip}
                                        methods={methods}
                                        moneyAccounts={moneyAccounts}
                                        canCorrect={can_correct_paid_date}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
