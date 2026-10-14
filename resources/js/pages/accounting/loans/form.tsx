import { CounterpartyDialog } from '@/components/counterparty-dialog';
import { Field, FormGroup, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { DAY_COUNT_LABEL, FREQUENCY_LABEL, REPAYMENT_LABEL, WATERFALL_LABEL } from '@/components/loans/loan-labels';
import { ReportHeading, ReportPage } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEventHandler, useMemo, useState } from 'react';

interface CounterpartyOption {
    id: number;
    label: string;
    kind: string;
    related: boolean;
}

interface HeadOption {
    slug: string;
    label: string;
    direction: 'given' | 'taken';
    term: 'short' | 'long';
    related: boolean | null;
    bank: boolean | null;
    warning: boolean;
}

/** The index signatures are what let Inertia's useForm accept these (see journals/create). */
interface CustomRow {
    due_date: string;
    principal: string;
    interest: string;
    [key: string]: string;
}

interface LoanFields {
    direction: 'given' | 'taken';
    counterparty_id: string;
    principal: string;
    start_date: string;
    maturity_date: string;
    annual_rate: string;
    interest_free_reason: string;
    day_count: string;
    repayment_type: string;
    frequency: string;
    grace_months: string;
    waterfall: string;
    collateral: string;
    purpose: string;
    principal_head: string;
    tds_certificate_ref: string;
    tds_certificate_rate: string;
    custom_rows: CustomRow[];
    [key: string]: string | CustomRow[];
}

interface ExistingLoan {
    id: number;
    number: string;
    direction: 'given' | 'taken';
    counterparty_id: number;
    principal: string;
    start_date: string;
    maturity_date: string;
    annual_rate: string;
    interest_free_reason: string;
    day_count: string;
    repayment_type: string;
    frequency: string;
    grace_months: string;
    waterfall: string;
    collateral: string;
    purpose: string;
    principal_head: string;
    tds_certificate_ref: string;
    tds_certificate_rate: string;
    custom_rows: CustomRow[];
}

/** Whether a loan from `start` to `maturity` runs past 12 months (LoanHeads::isLongTerm). */
function isLongTerm(start: string, maturity: string): boolean {
    if (!start || !maturity) {
        return false;
    }

    const from = new Date(`${start}T00:00:00`);
    const limit = new Date(from);
    const day = from.getDate();
    limit.setDate(1);
    limit.setFullYear(limit.getFullYear() + 1);
    const lastDay = new Date(limit.getFullYear(), limit.getMonth() + 1, 0).getDate();
    limit.setDate(Math.min(day, lastDay));

    return new Date(`${maturity}T00:00:00`) > limit;
}

/**
 * A draft loan agreement (loans plan v2, phase 2): who, which way, how much,
 * on what terms. The server checks everything again; the form only offers the
 * principal heads that fit, and shows the schedule once it is saved.
 */
export default function LoanForm({
    loan,
    counterparties,
    heads,
    preset,
    options,
    today,
}: {
    loan: ExistingLoan | null;
    counterparties: CounterpartyOption[];
    heads: HeadOption[];
    preset: { direction: 'given' | 'taken'; counterparty_id: number | null };
    options: { repayment_types: string[]; frequencies: string[]; day_counts: string[]; waterfalls: string[] };
    today: string;
}) {
    const { t } = useTranslation();
    const { canManageCounterparties } = usePage<SharedData>().props;
    const [adding, setAdding] = useState(false);

    const { data, setData, post, put, transform, processing, errors } = useForm<LoanFields>(
        loan
            ? {
                  direction: loan.direction,
                  counterparty_id: String(loan.counterparty_id),
                  principal: loan.principal,
                  start_date: loan.start_date,
                  maturity_date: loan.maturity_date,
                  annual_rate: loan.annual_rate,
                  interest_free_reason: loan.interest_free_reason,
                  day_count: loan.day_count,
                  repayment_type: loan.repayment_type,
                  frequency: loan.frequency,
                  grace_months: loan.grace_months,
                  waterfall: loan.waterfall,
                  collateral: loan.collateral,
                  purpose: loan.purpose,
                  principal_head: loan.principal_head,
                  tds_certificate_ref: loan.tds_certificate_ref,
                  tds_certificate_rate: loan.tds_certificate_rate,
                  custom_rows: loan.repayment_type === 'custom' ? loan.custom_rows : [],
              }
            : {
                  direction: preset.direction,
                  counterparty_id: preset.counterparty_id ? String(preset.counterparty_id) : '',
                  principal: '',
                  start_date: today,
                  maturity_date: '',
                  annual_rate: '',
                  interest_free_reason: '',
                  day_count: 'actual_365',
                  repayment_type: 'equal_installments',
                  frequency: 'monthly',
                  grace_months: '0',
                  waterfall: 'interest_first',
                  collateral: '',
                  purpose: '',
                  principal_head: '',
                  tds_certificate_ref: '',
                  tds_certificate_rate: '',
                  custom_rows: [],
              },
    );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: loan ? loan.number : t('loans.new'), href: '#' },
    ];

    const counterparty = counterparties.find((c) => String(c.id) === data.counterparty_id) ?? null;
    const longTerm = isLongTerm(data.start_date, data.maturity_date);
    const interestFree = data.annual_rate.trim() === '' || Number(data.annual_rate) === 0;

    // The heads this loan may sit on, the proposed one first (LoanHeads).
    const allowedHeads = useMemo(() => {
        const isBank = counterparty ? ['bank', 'nbfi'].includes(counterparty.kind) : false;

        return heads.filter(
            (head) =>
                head.direction === data.direction &&
                head.term === (longTerm ? 'long' : 'short') &&
                (head.related === null || counterparty === null || head.related === counterparty.related) &&
                (head.bank === null || counterparty === null || head.bank === isBank),
        );
    }, [heads, data.direction, longTerm, counterparty]);

    const chosenHead = allowedHeads.find((h) => h.slug === data.principal_head) ?? allowedHeads[0] ?? null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        // The head the form shows is the one sent, even when it was never touched.
        transform((fields) => ({ ...fields, principal_head: chosenHead?.slug ?? '' }));

        if (loan) {
            put(route('accounting.loans.update', loan.id), { preserveScroll: true });
        } else {
            post(route('accounting.loans.store'), { preserveScroll: true });
        }
    };

    const fieldError = (key: string): string | undefined => (errors as Record<string, string | undefined>)[key];

    const setRow = (index: number, patch: Partial<CustomRow>) =>
        setData(
            'custom_rows',
            data.custom_rows.map((row, i) => (i === index ? ({ ...row, ...patch } as CustomRow) : row)),
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={loan ? loan.number : t('loans.new')} />
            <ReportPage>
                <ReportHeading
                    title={loan ? t('loans.form.edit_title', { number: loan.number }) : t('loans.form.new_title')}
                    description={t('loans.form.description')}
                />

                <form onSubmit={submit} className="flex max-w-5xl flex-col gap-6">
                    <FormGroup title={t('loans.form.who')}>
                        <Field label={t('loans.col.direction')} htmlFor="direction" required error={errors.direction}>
                            <SearchSelect
                                id="direction"
                                value={data.direction}
                                onChange={(e) =>
                                    setData({
                                        ...data,
                                        direction: e.target.value as 'given' | 'taken',
                                        principal_head: '',
                                        tds_certificate_rate: '',
                                        tds_certificate_ref: '',
                                    })
                                }
                            >
                                <option value="given">{t('loans.direction.given')}</option>
                                <option value="taken">{t('loans.direction.taken')}</option>
                            </SearchSelect>
                        </Field>
                        <Field label={t('loans.col.counterparty')} htmlFor="counterparty_id" size="lg" required error={errors.counterparty_id}>
                            <SearchSelect
                                id="counterparty_id"
                                value={data.counterparty_id}
                                onChange={(e) => setData({ ...data, counterparty_id: e.target.value, principal_head: '' })}
                            >
                                <option value="">{t('loans.form.choose_counterparty')}</option>
                                {counterparties.map((c) => (
                                    <option key={c.id} value={String(c.id)}>
                                        {c.label}
                                        {c.related ? ` (${t('loans.related')})` : ''}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {canManageCounterparties && (
                            <div className="self-end">
                                <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)}>
                                    <Plus className="size-4" />
                                    {t('loans.form.new_counterparty')}
                                </Button>
                            </div>
                        )}
                    </FormGroup>

                    <FormGroup title={t('loans.form.amount_and_dates')}>
                        <Field label={t('loans.col.principal')} htmlFor="principal" size="sm" required error={errors.principal}>
                            <Input id="principal" inputMode="decimal" value={data.principal} onChange={(e) => setData('principal', e.target.value)} />
                        </Field>
                        <Field label={t('loans.form.start_date')} htmlFor="start_date" required error={errors.start_date}>
                            <Input id="start_date" type="date" value={data.start_date} onChange={(e) => setData('start_date', e.target.value)} />
                        </Field>
                        <Field label={t('loans.col.maturity')} htmlFor="maturity_date" required error={errors.maturity_date}>
                            <Input
                                id="maturity_date"
                                type="date"
                                value={data.maturity_date}
                                onChange={(e) => setData('maturity_date', e.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('loans.form.rate')}
                            htmlFor="annual_rate"
                            size="xs"
                            error={errors.annual_rate}
                            hint={t('loans.form.rate_hint')}
                        >
                            <Input
                                id="annual_rate"
                                inputMode="decimal"
                                value={data.annual_rate}
                                onChange={(e) => setData('annual_rate', e.target.value)}
                            />
                        </Field>
                        <Field label={t('loans.form.day_count')} htmlFor="day_count">
                            <SearchSelect id="day_count" value={data.day_count} onChange={(e) => setData('day_count', e.target.value)}>
                                {options.day_counts.map((value) => (
                                    <option key={value} value={value}>
                                        {t(DAY_COUNT_LABEL[value])}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormGroup>

                    {interestFree && (
                        <Field
                            label={t('loans.form.interest_free_reason')}
                            htmlFor="interest_free_reason"
                            size="grow"
                            required
                            error={errors.interest_free_reason}
                            hint={t('loans.form.interest_free_hint')}
                        >
                            <textarea
                                id="interest_free_reason"
                                rows={2}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                value={data.interest_free_reason}
                                onChange={(e) => setData('interest_free_reason', e.target.value)}
                            />
                        </Field>
                    )}

                    <FormGroup title={t('loans.form.repayment')}>
                        <Field label={t('loans.form.repayment_type')} htmlFor="repayment_type" required error={errors.repayment_type}>
                            <SearchSelect
                                id="repayment_type"
                                value={data.repayment_type}
                                onChange={(e) =>
                                    setData({
                                        ...data,
                                        repayment_type: e.target.value,
                                        custom_rows:
                                            e.target.value === 'custom' && data.custom_rows.length === 0
                                                ? [{ due_date: data.maturity_date, principal: data.principal, interest: '' }]
                                                : data.custom_rows,
                                    })
                                }
                            >
                                {options.repayment_types.map((value) => (
                                    <option key={value} value={value}>
                                        {t(REPAYMENT_LABEL[value])}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label={t('loans.form.frequency')} htmlFor="frequency" required error={errors.frequency}>
                            <SearchSelect id="frequency" value={data.frequency} onChange={(e) => setData('frequency', e.target.value)}>
                                {options.frequencies.map((value) => (
                                    <option key={value} value={value}>
                                        {t(FREQUENCY_LABEL[value])}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label={t('loans.form.grace_months')} htmlFor="grace_months" size="xs" error={errors.grace_months}>
                            <Input
                                id="grace_months"
                                type="number"
                                min={0}
                                value={data.grace_months}
                                onChange={(e) => setData('grace_months', e.target.value)}
                            />
                        </Field>
                        <Field label={t('loans.form.waterfall')} htmlFor="waterfall" error={errors.waterfall}>
                            <SearchSelect id="waterfall" value={data.waterfall} onChange={(e) => setData('waterfall', e.target.value)}>
                                {options.waterfalls.map((value) => (
                                    <option key={value} value={value}>
                                        {t(WATERFALL_LABEL[value])}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormGroup>

                    {data.repayment_type === 'custom' && (
                        <div className="flex flex-col gap-2">
                            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{t('loans.form.custom_rows')}</p>
                            <p className="text-muted-foreground text-sm">{t('loans.form.custom_hint')}</p>
                            {data.custom_rows.map((row, index) => (
                                <FormRow key={index} className="items-end">
                                    <Field
                                        label={t('loans.col.due_date')}
                                        htmlFor={`row-${index}-date`}
                                        error={fieldError(`custom_rows.${index}.due_date`)}
                                    >
                                        <Input
                                            id={`row-${index}-date`}
                                            type="date"
                                            value={row.due_date}
                                            onChange={(e) => setRow(index, { due_date: e.target.value })}
                                        />
                                    </Field>
                                    <Field
                                        label={t('loans.col.principal')}
                                        htmlFor={`row-${index}-principal`}
                                        size="sm"
                                        error={fieldError(`custom_rows.${index}.principal`)}
                                    >
                                        <Input
                                            id={`row-${index}-principal`}
                                            inputMode="decimal"
                                            value={row.principal}
                                            onChange={(e) => setRow(index, { principal: e.target.value })}
                                        />
                                    </Field>
                                    <Field label={t('loans.col.interest')} htmlFor={`row-${index}-interest`} size="sm">
                                        <Input
                                            id={`row-${index}-interest`}
                                            inputMode="decimal"
                                            placeholder={t('loans.form.interest_auto')}
                                            value={row.interest}
                                            onChange={(e) => setRow(index, { interest: e.target.value })}
                                        />
                                    </Field>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('loans.form.remove_row')}
                                        onClick={() =>
                                            setData(
                                                'custom_rows',
                                                data.custom_rows.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </FormRow>
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData('custom_rows', [...data.custom_rows, { due_date: '', principal: '', interest: '' }])}
                                >
                                    <Plus className="size-4" />
                                    {t('loans.form.add_row')}
                                </Button>
                            </div>
                            <InputError message={errors.custom_rows} />
                        </div>
                    )}

                    <FormGroup title={t('loans.form.books')}>
                        <Field
                            label={t('loans.form.principal_head')}
                            htmlFor="principal_head"
                            size="lg"
                            error={errors.principal_head}
                            hint={longTerm ? t('loans.form.long_term_hint') : t('loans.form.short_term_hint')}
                        >
                            <SearchSelect
                                id="principal_head"
                                value={chosenHead?.slug ?? ''}
                                onChange={(e) => setData('principal_head', e.target.value)}
                            >
                                {allowedHeads.map((head) => (
                                    <option key={head.slug} value={head.slug}>
                                        {head.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormGroup>
                    {chosenHead?.warning && (
                        <p className="max-w-2xl rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm text-amber-800 dark:text-amber-300">
                            {t('loans.owner_warning')}
                        </p>
                    )}

                    {data.direction === 'taken' && (
                        <FormGroup title={t('loans.form.tds_certificate')}>
                            <Field label={t('loans.form.certificate_ref')} htmlFor="tds_certificate_ref" size="md" error={errors.tds_certificate_ref}>
                                <Input
                                    id="tds_certificate_ref"
                                    value={data.tds_certificate_ref}
                                    onChange={(e) => setData('tds_certificate_ref', e.target.value)}
                                />
                            </Field>
                            <Field
                                label={t('loans.form.certificate_rate')}
                                htmlFor="tds_certificate_rate"
                                size="xs"
                                error={errors.tds_certificate_rate}
                                hint={t('loans.form.certificate_hint')}
                            >
                                <Input
                                    id="tds_certificate_rate"
                                    inputMode="decimal"
                                    value={data.tds_certificate_rate}
                                    onChange={(e) => setData('tds_certificate_rate', e.target.value)}
                                />
                            </Field>
                        </FormGroup>
                    )}

                    <FormGroup title={t('loans.form.more')}>
                        <Field label={t('loans.form.collateral')} htmlFor="collateral" size="grow" error={errors.collateral}>
                            <textarea
                                id="collateral"
                                rows={2}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                value={data.collateral}
                                onChange={(e) => setData('collateral', e.target.value)}
                            />
                        </Field>
                        <Field label={t('loans.form.purpose')} htmlFor="purpose" size="grow" error={errors.purpose}>
                            <textarea
                                id="purpose"
                                rows={2}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                value={data.purpose}
                                onChange={(e) => setData('purpose', e.target.value)}
                            />
                        </Field>
                    </FormGroup>

                    <InputError message={fieldError('loan')} />

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={processing}>
                            {t('loans.form.save_draft')}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={loan ? route('accounting.loans.show', loan.id) : route('accounting.loans.index')}>{t('loans.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </ReportPage>

            {canManageCounterparties && (
                <CounterpartyDialog
                    open={adding}
                    onOpenChange={setAdding}
                    quick
                    onSaved={(row) => {
                        setData('counterparty_id', String(row.id));
                        router.reload({ only: ['counterparties'] });
                    }}
                />
            )}
        </AppLayout>
    );
}
