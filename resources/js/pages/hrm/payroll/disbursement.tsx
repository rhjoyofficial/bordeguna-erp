import { BusyButton } from '@/components/busy-button';
import { Amount, DateCell } from '@/components/ledger-text';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, MoreHorizontal } from 'lucide-react';
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
    people: string[];
    account: string | null;
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
        year: number | null;
        month: number | null;
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

const cardClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';
const rowClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b';
const headClass = 'border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground text-xs';

const money = (v: number) => v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const today = () => dhakaToday();
const shortDate = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

function PaidBadge({ status, label }: { status: string; label: string }) {
    // Paid is the only state worth a colour; the rest wait on a person.
    return (
        <Badge
            variant="outline"
            className={cn(
                'font-medium',
                status === 'paid' && 'border-primary/30 bg-primary/10 text-primary',
                status !== 'paid' && 'border-amber-500/40 bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-300',
            )}
        >
            {label}
        </Badge>
    );
}

/**
 * One payment for one person (DIS-01's employee-wise confirmation, DIS-02's
 * part payment). Opened from the row's menu rather than a button on every row.
 */
function PayDialog({
    payslip,
    methods,
    moneyAccounts,
    open,
    onOpenChange,
}: {
    payslip: PayslipRow;
    methods: Props['methods'];
    moneyAccounts: Props['moneyAccounts'];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslation();
    const [amount, setAmount] = useState(String(payslip.outstanding));
    const [method, setMethod] = useState('');
    const [paidOn, setPaidOn] = useState(today());
    const [reference, setReference] = useState('');
    // ⚠⚠ The account is asked, never assumed, and it starts EMPTY (15 Oct
    // 2026): a list that opened on its first entry pre-selected bKash for a
    // bank salary and was one press from posting it there. Without an account
    // the posting threw MappingNotFound and the screen showed a blank 500.
    const [account, setAccount] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
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
                            className="w-40"
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
                                className="w-44"
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
                            <option value="">Choose an account…</option>
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
                        <Input id="pay_reference" className="w-60" value={reference} onChange={(e) => setReference(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <BusyButton
                        busyLabel="Recording…"
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
                                        onOpenChange(false);
                                        setErrors({});
                                    },
                                },
                            )
                        }
                    >
                        Record
                    </BusyButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function FailDialog({ payslip, open, onOpenChange }: { payslip: PayslipRow; open: boolean; onOpenChange: (open: boolean) => void }) {
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Payment failed for {payslip.employee}</DialogTitle>
                    <DialogDescription>
                        The attempt is kept on record with its reason so it can be retried. Because nothing reached the employee, any loan installment
                        and carry-forward recovery taken on this payslip is reversed: the installment becomes due again rather than quietly forgiven.
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
                    <BusyButton
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
                                        onOpenChange(false);
                                        setErrors({});
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        Record failure
                    </BusyButton>
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
function CorrectDateDialog({ url, title, label }: { url: string; title: string; label?: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [paidOn, setPaidOn] = useState('');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {label ?? t('payroll_pay.correct_date')}
            </Button>
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
                            className="w-44"
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
                    <BusyButton
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
                    </BusyButton>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * One batch, one row (the approved design, 15 Oct 2026): its state, who is in
 * it, the amount, and either where and when it was paid or the form to say so.
 */
function BatchLine({
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
    const [failing, setFailing] = useState(false);
    const [failReason, setFailReason] = useState('');
    // ⚠⚠ EMPTY ON PURPOSE, BOTH OF THEM. The account used to open on the first
    // entry of the list: on 15 Oct 2026 that was bKash for a bank batch, one
    // press from posting the month's salaries to the wrong account. The date
    // used to be today: September's salaries, paid by the bank on 30 Sep and
    // confirmed here on 12 Oct, posted nine journals on the wrong day. The
    // person confirming reads both off the bank statement and chooses them.
    const [account, setAccount] = useState('');
    const [paidOn, setPaidOn] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const fileRef = useRef<HTMLInputElement>(null);
    const confirmed = batch.confirmed_at !== null;
    const partly = batch.paid_total > 0 && batch.paid_total < batch.expected_total;

    const detail = [
        batch.account,
        batch.paid_dates.length > 0 ? `paid ${batch.paid_dates.map(shortDate).join(', ')}` : null,
        batch.advice_reference ? `ref ${batch.advice_reference}` : null,
    ].filter(Boolean);

    return (
        <div className={cn('px-4 py-3.5', !confirmed && 'bg-amber-50/60 dark:bg-amber-950/10')}>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                <PaidBadge status={confirmed ? 'paid' : 'wait'} label={confirmed ? 'Paid' : 'To confirm'} />
                <span className="text-[13.5px] font-medium">
                    {batch.method_label} · {batch.payslip_count} {batch.payslip_count === 1 ? 'person' : 'people'}
                </span>
                <span className="font-semibold tabular-nums">
                    {partly ? `${money(batch.paid_total)} of ${money(batch.expected_total)}` : money(batch.expected_total)}
                </span>
                <span className="text-muted-foreground text-xs">
                    {confirmed ? detail.join(' · ') : batch.people.join(', ')}
                    {confirmed && batch.confirmed_by ? ` · confirmed by ${batch.confirmed_by}` : ''}
                </span>
                <span className="flex-1" />
                {confirmed && canCorrect && batch.paid_dates.length > 0 && (
                    <CorrectDateDialog
                        url={route('hrm.batches.correct-paid-date', batch.id)}
                        title={t('payroll_pay.correct_title_batch', { reference: batch.reference })}
                        label="Change paid date"
                    />
                )}
                <span className="flex items-center gap-1.5">
                    {formats.length > 1 && (
                        <SearchSelect fit value={format} onChange={(e) => setFormat(e.target.value)} aria-label="Register format">
                            {formats.map((f) => (
                                <option key={f.key} value={f.key}>
                                    {f.name}
                                </option>
                            ))}
                        </SearchSelect>
                    )}
                    <a href={route('hrm.batches.register', { batch: batch.id, format })}>
                        <Button size="sm" variant="outline">
                            Register{formats.length === 1 ? ` (${formats[0].name})` : ''}
                        </Button>
                    </a>
                </span>
            </div>

            {!confirmed && (
                <div className="mt-3.5 flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor={`account_${batch.id}`} className="text-muted-foreground text-xs" required>
                            Paid from
                        </Label>
                        <SearchSelect id={`account_${batch.id}`} value={account} onChange={(e) => setAccount(e.target.value)}>
                            <option value="">Choose an account…</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`paid_on_${batch.id}`} className="text-muted-foreground text-xs" required>
                            {t('payroll_pay.paid_on_label')}
                        </Label>
                        <Input
                            id={`paid_on_${batch.id}`}
                            aria-required="true"
                            type="date"
                            max={today()}
                            value={paidOn}
                            onChange={(e) => setPaidOn(e.target.value)}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor={`ref_${batch.id}`} className="text-muted-foreground text-xs">
                            Reference (optional)
                        </Label>
                        <Input
                            id={`ref_${batch.id}`}
                            value={adviceReference}
                            onChange={(e) => setAdviceReference(e.target.value)}
                            placeholder="e.g. BRAC #59"
                            className="w-44"
                        />
                    </div>
                    <BusyButton
                        size="sm"
                        busyLabel="Confirming…"
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
                        Confirm {money(batch.expected_total)} paid
                    </BusyButton>
                    {failing ? (
                        <span className="flex items-end gap-1.5">
                            <Input
                                id={`fail_${batch.id}`}
                                aria-label="Failure reason"
                                value={failReason}
                                onChange={(e) => setFailReason(e.target.value)}
                                placeholder="Failure reason"
                                className="w-48"
                            />
                            <BusyButton
                                size="sm"
                                variant="outline"
                                disabled={failReason.trim() === ''}
                                onClick={() =>
                                    router.post(
                                        route('hrm.batches.fail', batch.id),
                                        { reason: failReason, failed_on: today() },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setFailReason('');
                                                setFailing(false);
                                            },
                                        },
                                    )
                                }
                            >
                                Record batch failure
                            </BusyButton>
                            <Button size="sm" variant="ghost" onClick={() => setFailing(false)}>
                                Cancel
                            </Button>
                        </span>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => setFailing(true)}>
                            Record a failure
                        </Button>
                    )}
                </div>
            )}
            {(errors.money_account_id || errors.paid_on || errors.accrual || errors.batch || errors.payslip) && (
                <p className="text-destructive mt-2 text-xs">
                    {errors.money_account_id ?? errors.paid_on ?? errors.accrual ?? errors.batch ?? errors.payslip}
                </p>
            )}

            <div className="text-muted-foreground mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                {!confirmed && <span>No account is pre-selected, so a payment never lands on the wrong one by accident.</span>}
                <span>Bank advice file: {batch.advice_file ? <span className="text-foreground">{batch.advice_file}</span> : 'none yet'}</span>
                <Input
                    id={`advice_${batch.id}`}
                    type="file"
                    ref={fileRef}
                    aria-label="Bank advice file"
                    className="h-7 w-56 text-xs"
                    onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (!file) return;
                        router.post(
                            route('hrm.batches.advice', batch.id),
                            { file, advice_reference: adviceReference || null },
                            {
                                preserveScroll: true,
                                forceFormData: true,
                                onFinish: () => {
                                    if (fileRef.current) fileRef.current.value = '';
                                },
                            },
                        );
                    }}
                />
            </div>
        </div>
    );
}

function PayslipLine({
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
    const [history, setHistory] = useState(false);
    const [paying, setPaying] = useState(false);
    const [failing, setFailing] = useState(false);
    const payable = payslip.outstanding > 0 && !payslip.is_blocked;

    return (
        <>
            <tr className={rowClass}>
                <td className="px-3 py-2">
                    <span className="font-medium">{payslip.employee}</span>
                    <span className="text-muted-foreground ml-2 text-xs">{payslip.employee_code}</span>
                    {payslip.email.error ? (
                        <span className="text-destructive ml-2 text-xs">Email failed: {payslip.email.error}</span>
                    ) : payslip.email.sent_at ? (
                        <span className="text-muted-foreground ml-2 text-xs">
                            Emailed {showDate(payslip.email.sent_at)} to {payslip.email.address}
                        </span>
                    ) : !payslip.email.reachable ? (
                        <Badge variant="outline" className="text-muted-foreground ml-2 font-normal" title="No email address on record">
                            No email
                        </Badge>
                    ) : null}
                </td>
                <td className="text-muted-foreground px-3 py-2 text-xs">{payslip.method}</td>
                <td className="px-3 py-2 text-right tabular-nums">
                    <Amount value={money(payslip.net)} />
                </td>
                <td className={cn('px-3 py-2 text-right tabular-nums', payslip.paid_total === 0 && 'text-muted-foreground')}>
                    <Amount value={money(payslip.paid_total)} />
                </td>
                <td className="px-3 py-2">
                    <PaidBadge status={payslip.payment_status} label={payslip.payment_status_label} />
                    {payslip.failure_reason && <span className="text-destructive ml-2 text-xs">{payslip.failure_reason}</span>}
                </td>
                <td className="px-2 py-1 text-right">
                    <span className="inline-flex items-center gap-0.5">
                        <a href={route('hrm.payslips.show', payslip.id)} className="text-primary text-xs hover:underline">
                            Payslip
                        </a>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="h-8 w-8" aria-label={`More for ${payslip.employee ?? ''}`}>
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            {/* ⚠ The dialogs open a tick AFTER the menu closes. Opened in the
                                same tick, Radix's menu and dialog fight over body pointer-events
                                and the page can be left unclickable when the dialog closes. */}
                            <DropdownMenuContent align="end">
                                {payable && <DropdownMenuItem onSelect={() => setTimeout(() => setPaying(true), 0)}>Record payment</DropdownMenuItem>}
                                {payable && (
                                    <DropdownMenuItem onSelect={() => setTimeout(() => setFailing(true), 0)}>Record failure</DropdownMenuItem>
                                )}
                                <DropdownMenuItem disabled={payslip.payments.length === 0} onSelect={() => setHistory((v) => !v)}>
                                    {history ? 'Hide payments' : `Show payments (${payslip.payments.length})`}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </span>
                    {payable && (
                        <PayDialog payslip={payslip} methods={methods} moneyAccounts={moneyAccounts} open={paying} onOpenChange={setPaying} />
                    )}
                    {payable && <FailDialog payslip={payslip} open={failing} onOpenChange={setFailing} />}
                </td>
            </tr>
            {history && payslip.payments.length > 0 && (
                <tr className={rowClass}>
                    <td colSpan={6} className="bg-muted/30 px-4 py-2.5">
                        <table className="w-full text-xs">
                            <tbody>
                                {payslip.payments.map((p) => (
                                    <tr key={p.id} className="border-sidebar-border/40 border-b last:border-b-0">
                                        <td className="py-1 pr-3">
                                            <DateCell>{showDate(p.paid_on)}</DateCell>
                                        </td>
                                        <td className="py-1 pr-3">
                                            {p.type_label}
                                            {p.failure_reason ? `: ${p.failure_reason}` : ''}
                                        </td>
                                        <td className="py-1 pr-3">{p.method}</td>
                                        <td className="py-1 pr-3">{p.reference ?? '-'}</td>
                                        <td className="py-1 text-right tabular-nums">
                                            <Amount value={money(p.amount)} />
                                        </td>
                                        <td className="py-1 text-right">
                                            <span className="inline-flex items-center gap-1">
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
                                            </span>
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
        { title: `Payments, ${run.period ?? ''}`, href: `/hrm/payroll/${run.id}/disbursement` },
    ];

    const paid = payslips.reduce((sum, p) => sum + p.paid_total, 0);
    const outstanding = payslips.reduce((sum, p) => sum + Math.max(p.outstanding, 0), 0);
    const percent = run.total_net > 0 ? Math.min(100, Math.round((paid / run.total_net) * 100)) : 0;
    const confirmedBatches = batches.filter((b) => b.confirmed_at !== null).length;
    const unbatched = payslips.some((p) => !p.is_blocked && p.batch === null);
    const backHref = run.year && run.month ? route('hrm.payroll.index', { year: run.year, month: run.month }) : route('hrm.payroll.index');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payments, ${run.period ?? ''}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <a href={backHref} className="text-primary inline-flex items-center gap-0.5 text-xs hover:underline">
                            <ChevronLeft className="size-3.5" aria-hidden="true" />
                            {run.period} payroll
                        </a>
                        <h2 className="mt-1 text-lg font-semibold">
                            Payments, {run.period}
                            {run.version > 1 && <span className="text-muted-foreground ml-2 text-sm font-normal">version {run.version}</span>}
                        </h2>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
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
                        <BusyButton
                            size="sm"
                            variant="outline"
                            busyLabel="Queueing…"
                            disabled={email_summary.sendable === 0}
                            onClick={() => router.post(route('hrm.payroll.email-payslips', run.id), {}, { preserveScroll: true })}
                        >
                            {/* ⚠ The count is on the button because "queued" after
                                the fact is not the moment somebody needs it. */}
                            Email payslips ({email_summary.sendable})
                        </BusyButton>
                    </div>
                </div>

                <section className={cn(cardClass, 'flex flex-wrap items-center gap-x-10 gap-y-3.5 px-5 py-4')}>
                    <div className="min-w-0 flex-1 basis-64">
                        <div className="flex justify-between text-[13px]">
                            <span>
                                <span className="font-semibold tabular-nums">{money(paid)}</span> of{' '}
                                <span className="tabular-nums">{money(run.total_net)}</span> paid
                            </span>
                            <span className="text-muted-foreground">{percent}%</span>
                        </div>
                        <div
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={percent}
                            aria-label="Share of net pay paid"
                            className="bg-muted mt-2 h-2 overflow-hidden rounded-full"
                        >
                            <div className="bg-primary h-full rounded-full" style={{ width: `${percent}%` }} />
                        </div>
                    </div>
                    <div>
                        <div className="text-muted-foreground text-xs">Batches</div>
                        <div className="text-sm font-semibold">
                            {batches.length === 0 ? 'none yet' : `${confirmedBatches} of ${batches.length} confirmed`}
                        </div>
                    </div>
                    <div>
                        <div className="text-muted-foreground text-xs">Still to pay</div>
                        <div className={cn('text-sm font-semibold tabular-nums', outstanding > 0 && 'text-amber-700 dark:text-amber-400')}>
                            {money(outstanding)}
                        </div>
                    </div>
                    {!run.is_disbursed && (
                        <p className="text-muted-foreground basis-full text-xs">
                            This payroll is {run.status_label.toLowerCase()}. Nothing can be recorded as paid until it is marked disbursed on the
                            payroll page; the loan and carry-forward postings this reconciles against do not exist before then.
                        </p>
                    )}
                </section>

                {(email_summary.unreachable.length > 0 || email_summary.sent > 0 || email_summary.failed > 0) && (
                    <div className={cn(cardClass, 'px-4 py-3 text-xs')}>
                        <p className="text-sm font-medium">Payslips by email</p>
                        <p className="text-muted-foreground mt-0.5">
                            {/* ⚠ The EXAMPLE is the point. Joy read the old wording, typed the date on its own,
                                and could not open his own employee's payslip. The rule is now the date alone, and
                                it is still shown with a worked example. */}
                            Each PDF is password protected with the employee's date of birth as DDMMYYYY, with no spaces or slashes. For example{' '}
                            <strong>20121992</strong> for someone born on 20 December 1992.
                        </p>
                        <ul className="mt-1.5 space-y-0.5">
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

                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-[15px] font-semibold">Batches</h3>
                        {run.is_disbursed && unbatched && (
                            <BusyButton
                                size="sm"
                                busyLabel="Creating…"
                                onClick={() => router.post(route('hrm.payroll.batches.store', run.id), {}, { preserveScroll: true })}
                            >
                                Create batches
                            </BusyButton>
                        )}
                    </div>
                    {batches.length === 0 ? (
                        <div className={cn(cardClass, 'text-muted-foreground px-4 py-6 text-center text-sm')}>
                            {run.is_disbursed
                                ? 'No batches yet. Create them: one per payment method.'
                                : 'Batches appear once the payroll is disbursed.'}
                        </div>
                    ) : (
                        <section className={cn(cardClass, 'divide-sidebar-border/70 dark:divide-sidebar-border divide-y overflow-hidden')}>
                            {batches.map((batch) => (
                                <BatchLine
                                    key={batch.id}
                                    batch={batch}
                                    formats={formats}
                                    moneyAccounts={moneyAccounts}
                                    canCorrect={can_correct_paid_date}
                                />
                            ))}
                        </section>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    <h3 className="text-[15px] font-semibold">Payslips</h3>
                    {/* ⚠ relative: the sr-only "Actions" heading is absolutely positioned and
                        escaped an unpositioned scroll box, widening a 390px page to 643px. */}
                    <section className={cn(cardClass, 'relative overflow-x-auto')}>
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className={headClass}>
                                    <th className="px-3 py-2.5 font-medium">Employee</th>
                                    <th className="px-3 py-2.5 font-medium">Method</th>
                                    <th className="px-3 py-2.5 text-right font-medium">Net</th>
                                    <th className="px-3 py-2.5 text-right font-medium">Paid</th>
                                    <th className="px-3 py-2.5 font-medium">Status</th>
                                    <th className="px-3 py-2.5">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {payslips.map((payslip) => (
                                    <PayslipLine
                                        key={payslip.id}
                                        payslip={payslip}
                                        methods={methods}
                                        moneyAccounts={moneyAccounts}
                                        canCorrect={can_correct_paid_date}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
