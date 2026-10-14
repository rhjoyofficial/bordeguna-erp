import { KIND_LABEL, type CounterpartyKind } from '@/components/counterparty-dialog';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { ReportHeading, ReportPage, Verdict } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Rule {
    id: number;
    name: string;
    payee_kinds: string[];
    rate: string;
    legal_basis: string;
    effective_from: string;
    effective_to: string | null;
    is_enabled: boolean;
    confirmed_by: string | null;
    confirmed_on: string | null;
}

/**
 * Accounting > Loans > TDS on loan interest (loans plan v2, phase 2): the
 * effective-dated rule that withholds tax from interest the business pays.
 * Seeded switched off; the Owner switches it on with the accountant's
 * confirmation and the legal basis.
 */
export default function LoanTdsRules({ rules, kinds, canManage, today }: { rules: Rule[]; kinds: string[]; canManage: boolean; today: string }) {
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('loans.title'), href: '/accounting/loans' },
        { title: t('loans.tds.nav'), href: '/accounting/loans/tds-rules' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('loans.tds.nav')} />
            <ReportPage>
                <ReportHeading title={t('loans.tds.title')} description={t('loans.tds.description')} />
                {rules.every((rule) => !rule.is_enabled) && <Verdict>{t('loans.tds.all_off')}</Verdict>}

                {rules.map((rule) => (
                    <RuleForm key={rule.id} rule={rule} kinds={kinds} canManage={canManage} today={today} />
                ))}

                {canManage && <RuleForm rule={null} kinds={kinds} canManage today={today} />}
            </ReportPage>
        </AppLayout>
    );
}

function RuleForm({ rule, kinds, canManage, today }: { rule: Rule | null; kinds: string[]; canManage: boolean; today: string }) {
    const { t } = useTranslation();
    const form = useForm({
        name: rule?.name ?? '',
        payee_kinds: rule?.payee_kinds ?? ['company', 'individual', 'government', 'other'],
        rate: rule?.rate ?? '10',
        legal_basis: rule?.legal_basis ?? '',
        effective_from: rule?.effective_from ?? today,
        effective_to: rule?.effective_to ?? '',
        is_enabled: rule?.is_enabled ?? false,
        confirmed_by: rule?.confirmed_by ?? '',
        confirmed_on: rule?.confirmed_on ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (rule) {
            form.put(route('accounting.loans.tds-rules.update', rule.id), { preserveScroll: true });
        } else {
            form.post(route('accounting.loans.tds-rules.store'), { preserveScroll: true, onSuccess: () => form.reset() });
        }
    };

    const toggleKind = (kind: string, on: boolean) =>
        form.setData('payee_kinds', on ? [...form.data.payee_kinds, kind] : form.data.payee_kinds.filter((k) => k !== kind));

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border grid max-w-4xl gap-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-base font-semibold">{rule ? rule.name : t('loans.tds.new_rule')}</h2>
                {rule && <Badge variant={rule.is_enabled ? 'default' : 'outline'}>{rule.is_enabled ? t('loans.tds.on') : t('loans.tds.off')}</Badge>}
            </div>
            <fieldset disabled={!canManage} className="grid gap-4">
                <FormRow>
                    <Field label={t('loans.tds.name')} htmlFor={`name-${rule?.id ?? 'new'}`} size="lg" required error={errors.name}>
                        <Input id={`name-${rule?.id ?? 'new'}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <Field label={t('loans.tds.rate')} htmlFor={`rate-${rule?.id ?? 'new'}`} size="xs" required error={errors.rate}>
                        <Input
                            id={`rate-${rule?.id ?? 'new'}`}
                            inputMode="decimal"
                            value={form.data.rate}
                            onChange={(e) => form.setData('rate', e.target.value)}
                        />
                    </Field>
                    <Field label={t('loans.tds.from')} htmlFor={`from-${rule?.id ?? 'new'}`} required error={errors.effective_from}>
                        <Input
                            id={`from-${rule?.id ?? 'new'}`}
                            type="date"
                            value={form.data.effective_from}
                            onChange={(e) => form.setData('effective_from', e.target.value)}
                        />
                    </Field>
                    <Field label={t('loans.tds.to')} htmlFor={`to-${rule?.id ?? 'new'}`} error={errors.effective_to}>
                        <Input
                            id={`to-${rule?.id ?? 'new'}`}
                            type="date"
                            value={form.data.effective_to}
                            onChange={(e) => form.setData('effective_to', e.target.value)}
                        />
                    </Field>
                </FormRow>
                <div className="grid gap-2">
                    <Label>{t('loans.tds.payees')}</Label>
                    <div className="flex flex-wrap gap-4">
                        {kinds.map((kind) => (
                            <label key={kind} className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={form.data.payee_kinds.includes(kind)}
                                    onCheckedChange={(value) => toggleKind(kind, value === true)}
                                />
                                {t(KIND_LABEL[kind as CounterpartyKind])}
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.payee_kinds} />
                </div>
                <Field label={t('loans.tds.legal_basis')} htmlFor={`basis-${rule?.id ?? 'new'}`} size="grow" required error={errors.legal_basis}>
                    <Input
                        id={`basis-${rule?.id ?? 'new'}`}
                        value={form.data.legal_basis}
                        onChange={(e) => form.setData('legal_basis', e.target.value)}
                    />
                </Field>
                <FormRow>
                    <label className="flex items-center gap-2 self-end text-sm">
                        <Checkbox checked={form.data.is_enabled} onCheckedChange={(value) => form.setData('is_enabled', value === true)} />
                        {t('loans.tds.enabled')}
                    </label>
                    <Field label={t('loans.tds.confirmed_by')} htmlFor={`by-${rule?.id ?? 'new'}`} size="md" error={errors.confirmed_by}>
                        <Input
                            id={`by-${rule?.id ?? 'new'}`}
                            value={form.data.confirmed_by}
                            onChange={(e) => form.setData('confirmed_by', e.target.value)}
                        />
                    </Field>
                    <Field label={t('loans.tds.confirmed_on')} htmlFor={`on-${rule?.id ?? 'new'}`} error={errors.confirmed_on}>
                        <Input
                            id={`on-${rule?.id ?? 'new'}`}
                            type="date"
                            value={form.data.confirmed_on}
                            onChange={(e) => form.setData('confirmed_on', e.target.value)}
                        />
                    </Field>
                </FormRow>
            </fieldset>
            {canManage && (
                <div>
                    <Button type="submit" size="sm" disabled={form.processing}>
                        {rule ? t('loans.tds.save') : t('loans.tds.add')}
                    </Button>
                </div>
            )}
        </form>
    );
}
