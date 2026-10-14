import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import {
    FREQUENCY_LABEL,
    REPAYMENT_LABEL,
    WATERFALL_LABEL,
    formatPaisa,
    newSubmissionKey,
    paisaToInput,
    toPaisa,
} from '@/components/loans/loan-labels';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState, type ReactNode } from 'react';

export interface ScheduleRow {
    id: number;
    version: number;
    sequence: number;
    due_date: string;
    principal_due: string;
    interest_due: string;
    principal_paid: string;
    interest_paid: string;
    principal_unpaid_minor: number;
    interest_unpaid_minor: number;
    status: string;
    days_late: number;
}

interface MoneyAccount {
    id: number;
    label: string;
}

function ActionDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    onSubmit,
    submitLabel,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    children: ReactNode;
    onSubmit: FormEventHandler;
    submitLabel: string;
    processing: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={onSubmit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    {children}
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            {t('loans.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The principal moving at the start: disbursed on a loan given, received on a
 * loan taken. Never more than is left of the approved principal.
 */
export function AdvanceDialog({
    loanId,
    direction,
    open,
    onOpenChange,
    moneyAccounts,
    leftMinor,
    today,
}: {
    loanId: number;
    direction: 'given' | 'taken';
    open: boolean;
    onOpenChange: (open: boolean) => void;
    moneyAccounts: MoneyAccount[];
    leftMinor: number;
    today: string;
}) {
    const { t } = useTranslation();
    const form = useForm({ date: today, money_account_id: '', amount: paisaToInput(leftMinor), note: '', submission_key: newSubmissionKey() });

    useEffect(() => {
        if (open) {
            form.setData({ date: today, money_account_id: '', amount: paisaToInput(leftMinor), note: '', submission_key: newSubmissionKey() });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.loans.advance', loanId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={direction === 'given' ? t('loans.advance.disburse_title') : t('loans.advance.receive_title')}
            description={direction === 'given' ? t('loans.advance.disburse_hint') : t('loans.advance.receive_hint')}
            onSubmit={submit}
            submitLabel={direction === 'given' ? t('loans.advance.disburse') : t('loans.advance.receive')}
            processing={form.processing}
        >
            <FormRow>
                <Field label={t('loans.money.date')} htmlFor="advance-date" required error={errors.date}>
                    <Input id="advance-date" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                </Field>
                <Field
                    label={direction === 'given' ? t('loans.money.paid_from') : t('loans.money.received_into')}
                    htmlFor="advance-account"
                    size="lg"
                    required
                    error={errors.money_account_id}
                >
                    <SearchSelect
                        id="advance-account"
                        value={form.data.money_account_id}
                        onChange={(e) => form.setData('money_account_id', e.target.value)}
                    >
                        <option value="">{t('loans.money.choose_account')}</option>
                        {moneyAccounts.map((account) => (
                            <option key={account.id} value={String(account.id)}>
                                {account.label}
                            </option>
                        ))}
                    </SearchSelect>
                </Field>
                <Field label={t('loans.money.amount')} htmlFor="advance-amount" size="sm" required error={errors.amount}>
                    <Input
                        id="advance-amount"
                        inputMode="decimal"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                </Field>
            </FormRow>
            <Field label={t('loans.money.note')} htmlFor="advance-note" size="grow" error={errors.note}>
                <Input id="advance-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </Field>
            <InputError message={errors.loan} />
        </ActionDialog>
    );
}

/**
 * A repayment, split by the loan's waterfall unless the person splits it
 * themselves. The preview mirrors the server (RepaymentPlan); the server's
 * split is the record.
 */
export function RepaymentDialog({
    loanId,
    direction,
    waterfall,
    open,
    onOpenChange,
    moneyAccounts,
    schedule,
    outstandingMinor,
    tdsRateE4,
    today,
}: {
    loanId: number;
    direction: 'given' | 'taken';
    waterfall: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    moneyAccounts: MoneyAccount[];
    schedule: ScheduleRow[];
    outstandingMinor: number;
    tdsRateE4: number;
    today: string;
}) {
    const { t } = useTranslation();
    const [own, setOwn] = useState(false);
    const blank = {
        date: today,
        money_account_id: '',
        amount: '',
        principal: '',
        interest: '',
        tds_withheld: '',
        note: '',
        submission_key: newSubmissionKey(),
    };
    const form = useForm(blank);

    useEffect(() => {
        if (open) {
            form.setData({ ...blank, submission_key: newSubmissionKey() });
            form.clearErrors();
            setOwn(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const proposal = useMemo(() => {
        const amount = Math.max(0, toPaisa(form.data.amount));
        const due = schedule.filter((row) => row.due_date <= form.data.date && row.principal_unpaid_minor + row.interest_unpaid_minor > 0);
        const dueInterest = due.reduce((sum, row) => sum + row.interest_unpaid_minor, 0);
        const outstanding = Math.max(0, outstandingMinor);
        const duePrincipal = Math.min(
            due.reduce((sum, row) => sum + row.principal_unpaid_minor, 0),
            outstanding,
        );
        let principal = 0;
        let interest = 0;
        let rest = amount;

        if (waterfall === 'principal_first') {
            principal = Math.min(rest, duePrincipal);
            rest -= principal;
            interest = Math.min(rest, dueInterest);
            rest -= interest;
            const more = Math.min(rest, outstanding - principal);
            principal += more;
            rest -= more;
        } else {
            interest = Math.min(rest, dueInterest);
            rest -= interest;
            principal = Math.min(rest, outstanding);
            rest -= principal;
        }

        return { principal, interest: interest + rest };
    }, [form.data.amount, form.data.date, schedule, outstandingMinor, waterfall]);

    const principal = own ? toPaisa(form.data.principal) : proposal.principal;
    const interest = own ? toPaisa(form.data.interest) : proposal.interest;
    const tds = direction === 'taken' ? (tdsRateE4 > 0 ? Math.round((interest * tdsRateE4) / 1_000_000) : 0) : toPaisa(form.data.tds_withheld);
    const cash = principal + interest - tds;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => (own ? { ...data, amount: '' } : { ...data, principal: '', interest: '' }));
        form.post(route('accounting.loans.repay', loanId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={direction === 'given' ? t('loans.repay.received_title') : t('loans.repay.paid_title')}
            description={t(WATERFALL_LABEL[waterfall] ?? 'loans.waterfall.interest_first')}
            onSubmit={submit}
            submitLabel={t('loans.repay.record')}
            processing={form.processing}
        >
            <FormRow>
                <Field label={t('loans.money.date')} htmlFor="repay-date" required error={errors.date}>
                    <Input id="repay-date" type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />
                </Field>
                <Field
                    label={direction === 'given' ? t('loans.money.received_into') : t('loans.money.paid_from')}
                    htmlFor="repay-account"
                    size="lg"
                    required
                    error={errors.money_account_id}
                >
                    <SearchSelect
                        id="repay-account"
                        value={form.data.money_account_id}
                        onChange={(e) => form.setData('money_account_id', e.target.value)}
                    >
                        <option value="">{t('loans.money.choose_account')}</option>
                        {moneyAccounts.map((account) => (
                            <option key={account.id} value={String(account.id)}>
                                {account.label}
                            </option>
                        ))}
                    </SearchSelect>
                </Field>
                {!own && (
                    <Field
                        label={t('loans.repay.gross')}
                        htmlFor="repay-amount"
                        size="sm"
                        required
                        error={errors.amount}
                        hint={t('loans.repay.gross_hint')}
                    >
                        <Input
                            id="repay-amount"
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                        />
                    </Field>
                )}
            </FormRow>

            <div className="flex items-center gap-2">
                <Checkbox id="repay-own" checked={own} onCheckedChange={(value) => setOwn(value === true)} />
                <Label htmlFor="repay-own">{t('loans.repay.split_myself')}</Label>
            </div>

            {own && (
                <FormRow>
                    <Field label={t('loans.col.principal')} htmlFor="repay-principal" size="sm" error={errors.principal}>
                        <Input
                            id="repay-principal"
                            inputMode="decimal"
                            value={form.data.principal}
                            onChange={(e) => form.setData('principal', e.target.value)}
                        />
                    </Field>
                    <Field label={t('loans.repay.interest_gross')} htmlFor="repay-interest" size="sm" error={errors.interest}>
                        <Input
                            id="repay-interest"
                            inputMode="decimal"
                            value={form.data.interest}
                            onChange={(e) => form.setData('interest', e.target.value)}
                        />
                    </Field>
                </FormRow>
            )}

            {direction === 'given' && (
                <Field
                    label={t('loans.repay.tds_withheld')}
                    htmlFor="repay-tds"
                    size="sm"
                    error={errors.tds_withheld}
                    hint={t('loans.repay.tds_withheld_hint')}
                >
                    <Input
                        id="repay-tds"
                        inputMode="decimal"
                        value={form.data.tds_withheld}
                        onChange={(e) => form.setData('tds_withheld', e.target.value)}
                    />
                </Field>
            )}

            <dl className="border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 gap-x-6 gap-y-1 rounded-md border p-3 text-sm sm:grid-cols-4">
                <dt className="text-muted-foreground">{t('loans.col.principal')}</dt>
                <dd className="tabular-nums">{formatPaisa(principal)}</dd>
                <dt className="text-muted-foreground">{t('loans.repay.interest_gross')}</dt>
                <dd className="tabular-nums">{formatPaisa(interest)}</dd>
                <dt className="text-muted-foreground">{direction === 'taken' ? t('loans.repay.tds_on_interest') : t('loans.repay.tds_withheld')}</dt>
                <dd className="tabular-nums">{formatPaisa(tds)}</dd>
                <dt className="text-muted-foreground font-medium">{direction === 'given' ? t('loans.repay.cash_in') : t('loans.repay.cash_out')}</dt>
                <dd className="font-medium tabular-nums">{formatPaisa(cash)}</dd>
            </dl>
            {direction === 'taken' && tdsRateE4 > 0 && <p className="text-muted-foreground text-xs">{t('loans.repay.tds_note')}</p>}

            <Field label={t('loans.money.note')} htmlFor="repay-note" size="grow" error={errors.note}>
                <Input id="repay-note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </Field>
            <InputError message={errors.loan} />
        </ActionDialog>
    );
}

/** A restructure: new terms from a date, for the Owner to approve. */
export function AmendmentDialog({
    loanId,
    open,
    onOpenChange,
    current,
    today,
}: {
    loanId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    current: {
        rate: string;
        repayment_type: string;
        frequency: string;
        maturity_date: string;
        waterfall: string;
        day_count: string;
        grace_months: number;
    };
    today: string;
}) {
    const { t } = useTranslation();
    const form = useForm({
        effective_from: today,
        annual_rate: current.rate,
        interest_free_reason: '',
        day_count: current.day_count,
        repayment_type: current.repayment_type === 'custom' ? 'equal_installments' : current.repayment_type,
        frequency: current.frequency,
        grace_months: '0',
        maturity_date: current.maturity_date,
        waterfall: current.waterfall,
        reason: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.loans.amendments.store', loanId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('loans.amend.title')}
            description={t('loans.amend.hint')}
            onSubmit={submit}
            submitLabel={t('loans.amend.send')}
            processing={form.processing}
        >
            <FormRow>
                <Field label={t('loans.amend.effective_from')} htmlFor="amend-from" required error={errors.effective_from}>
                    <Input
                        id="amend-from"
                        type="date"
                        value={form.data.effective_from}
                        onChange={(e) => form.setData('effective_from', e.target.value)}
                    />
                </Field>
                <Field label={t('loans.form.rate')} htmlFor="amend-rate" size="xs" error={errors.annual_rate}>
                    <Input
                        id="amend-rate"
                        inputMode="decimal"
                        value={form.data.annual_rate}
                        onChange={(e) => form.setData('annual_rate', e.target.value)}
                    />
                </Field>
                <Field label={t('loans.col.maturity')} htmlFor="amend-maturity" required error={errors.maturity_date}>
                    <Input
                        id="amend-maturity"
                        type="date"
                        value={form.data.maturity_date}
                        onChange={(e) => form.setData('maturity_date', e.target.value)}
                    />
                </Field>
            </FormRow>
            <FormRow>
                <Field label={t('loans.form.repayment_type')} htmlFor="amend-type" error={errors.repayment_type}>
                    <SearchSelect id="amend-type" value={form.data.repayment_type} onChange={(e) => form.setData('repayment_type', e.target.value)}>
                        {['equal_installments', 'bullet'].map((value) => (
                            <option key={value} value={value}>
                                {t(REPAYMENT_LABEL[value])}
                            </option>
                        ))}
                    </SearchSelect>
                </Field>
                <Field label={t('loans.form.frequency')} htmlFor="amend-frequency" error={errors.frequency}>
                    <SearchSelect id="amend-frequency" value={form.data.frequency} onChange={(e) => form.setData('frequency', e.target.value)}>
                        {Object.keys(FREQUENCY_LABEL).map((value) => (
                            <option key={value} value={value}>
                                {t(FREQUENCY_LABEL[value])}
                            </option>
                        ))}
                    </SearchSelect>
                </Field>
                <Field label={t('loans.form.grace_months')} htmlFor="amend-grace" size="xs" error={errors.grace_months}>
                    <Input
                        id="amend-grace"
                        type="number"
                        min={0}
                        value={form.data.grace_months}
                        onChange={(e) => form.setData('grace_months', e.target.value)}
                    />
                </Field>
            </FormRow>
            {(form.data.annual_rate.trim() === '' || Number(form.data.annual_rate) === 0) && (
                <Field label={t('loans.form.interest_free_reason')} htmlFor="amend-free" size="grow" required error={errors.interest_free_reason}>
                    <Input
                        id="amend-free"
                        value={form.data.interest_free_reason}
                        onChange={(e) => form.setData('interest_free_reason', e.target.value)}
                    />
                </Field>
            )}
            <Field label={t('loans.amend.reason')} htmlFor="amend-reason" size="grow" required error={errors.reason}>
                <textarea
                    id="amend-reason"
                    rows={3}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            <InputError message={errors.loan} />
        </ActionDialog>
    );
}

/** A principal write-off or an interest waiver, with evidence, for the Owner. */
export function WriteOffDialog({
    loanId,
    direction,
    open,
    onOpenChange,
    today,
}: {
    loanId: number;
    direction: 'given' | 'taken';
    open: boolean;
    onOpenChange: (open: boolean) => void;
    today: string;
}) {
    const { t } = useTranslation();
    const form = useForm<{ kind: string; amount: string; forgo: string; effective_date: string; reason: string; evidence: File[] }>({
        kind: direction === 'given' ? 'principal_write_off' : 'interest_waiver',
        amount: '',
        forgo: '',
        effective_date: today,
        reason: '',
        evidence: [],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.loans.write-offs.store', loanId), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;
    const waiver = form.data.kind === 'interest_waiver';

    return (
        <ActionDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('loans.writeoff.title')}
            description={t('loans.writeoff.hint')}
            onSubmit={submit}
            submitLabel={t('loans.writeoff.send')}
            processing={form.processing}
        >
            <FormRow>
                <Field label={t('loans.writeoff.kind')} htmlFor="wo-kind" required error={errors.kind}>
                    <SearchSelect id="wo-kind" value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value)}>
                        {direction === 'given' && <option value="principal_write_off">{t('loans.writeoff.principal')}</option>}
                        <option value="interest_waiver">{t('loans.writeoff.interest')}</option>
                    </SearchSelect>
                </Field>
                <Field
                    label={waiver ? t('loans.writeoff.accrued_amount') : t('loans.writeoff.principal_amount')}
                    htmlFor="wo-amount"
                    size="sm"
                    error={errors.amount}
                >
                    <Input id="wo-amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                </Field>
                {waiver && (
                    <Field label={t('loans.writeoff.forgo')} htmlFor="wo-forgo" size="sm" error={errors.forgo} hint={t('loans.writeoff.forgo_hint')}>
                        <Input id="wo-forgo" inputMode="decimal" value={form.data.forgo} onChange={(e) => form.setData('forgo', e.target.value)} />
                    </Field>
                )}
                <Field label={t('loans.writeoff.effective')} htmlFor="wo-date" required error={errors.effective_date}>
                    <Input
                        id="wo-date"
                        type="date"
                        value={form.data.effective_date}
                        onChange={(e) => form.setData('effective_date', e.target.value)}
                    />
                </Field>
            </FormRow>
            <Field label={t('loans.writeoff.reason')} htmlFor="wo-reason" size="grow" required error={errors.reason}>
                <textarea
                    id="wo-reason"
                    rows={3}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </Field>
            <Field label={t('loans.writeoff.evidence')} htmlFor="wo-evidence" size="grow" required error={errors.evidence ?? errors['evidence.0']}>
                <Input
                    id="wo-evidence"
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                    onChange={(e) => form.setData('evidence', Array.from(e.target.files ?? []))}
                />
            </Field>
            <InputError message={errors.loan} />
        </ActionDialog>
    );
}
