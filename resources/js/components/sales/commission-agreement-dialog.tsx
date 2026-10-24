import { Field, FormRow } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

/**
 * ⭐ Make or edit a commission agreement in a popup (Joy's Q18, 25 Oct 2026),
 * the date box first. The server re-checks every rule; this form only lays
 * the deal out: who, which dates, which products, which deal, which sales.
 */
export interface Named {
    id: number;
    name: string;
}

export interface CommissionOptions {
    employees: Named[];
    customers: Named[];
    suppliers: Named[];
    products: Named[];
    categories: Named[];
    moneyAccounts: { id: number; name: string; type: string }[];
}

export interface AgreementRow {
    id: number;
    number: string;
    agreed_on: string;
    party_type: 'employee' | 'customer' | 'supplier';
    party_id: number | null;
    party_name: string;
    valid_from: string;
    valid_to: string | null;
    deal_type: 'per_unit' | 'percent' | 'target';
    rate: string | null;
    target_basis: 'units' | 'amount' | null;
    counts_credited_sales: boolean;
    counts_own_purchases: boolean;
    deal_summary: string;
    products: { id: number; name: string | null }[];
    categories: { id: number; name: string | null }[];
    tiers: { threshold: string; reward: string }[];
    note: string | null;
    status: string;
    status_label: string;
    progress: {
        sold_quantity: string;
        sold_value: string;
        earned: string;
        settled: string;
        to_settle: string;
        target_percent: number | null;
        next_threshold: string | null;
        next_reward: string | null;
        next_gap: string | null;
    } | null;
}

type FormData = {
    agreed_on: string;
    party_type: string;
    party_id: string;
    valid_from: string;
    valid_to: string;
    deal_type: string;
    rate: string;
    target_basis: string;
    counts_credited_sales: boolean;
    counts_own_purchases: boolean;
    product_ids: number[];
    category_ids: number[];
    tiers: { threshold: string; reward: string }[];
    note: string;
};

function fromRow(row: AgreementRow | null): FormData {
    const today = dhakaToday();

    if (row === null) {
        return {
            agreed_on: today,
            party_type: 'employee',
            party_id: '',
            valid_from: today,
            valid_to: '',
            deal_type: 'per_unit',
            rate: '',
            target_basis: 'amount',
            counts_credited_sales: true,
            counts_own_purchases: false,
            product_ids: [],
            category_ids: [],
            tiers: [{ threshold: '', reward: '' }],
            note: '',
        };
    }

    return {
        agreed_on: row.agreed_on,
        party_type: row.party_type,
        party_id: row.party_id === null ? '' : String(row.party_id),
        valid_from: row.valid_from,
        valid_to: row.valid_to ?? '',
        deal_type: row.deal_type,
        rate: row.rate === null ? '' : String(Number(row.rate)),
        target_basis: row.target_basis ?? 'amount',
        counts_credited_sales: row.counts_credited_sales,
        counts_own_purchases: row.counts_own_purchases,
        product_ids: row.products.map((p) => p.id),
        category_ids: row.categories.map((c) => c.id),
        tiers:
            row.tiers.length > 0
                ? row.tiers.map((t) => ({ threshold: String(Number(t.threshold)), reward: String(Number(t.reward)) }))
                : [{ threshold: '', reward: '' }],
        note: row.note ?? '',
    };
}

export function CommissionAgreementDialog({
    open,
    onOpenChange,
    options,
    agreement = null,
    settledLock = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: CommissionOptions;
    /** null = a new agreement. */
    agreement?: AgreementRow | null;
    /** Money was settled on it: only the end date and the note may change. */
    settledLock?: boolean;
}) {
    const { t } = useTranslation();
    const { data, setData, post, put, errors, processing, clearErrors, setDefaults, transform } = useForm<FormData>(fromRow(agreement));

    useEffect(() => {
        if (!open) {
            return;
        }

        const next = fromRow(agreement);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, agreement?.id]);

    const close = () => {
        clearErrors();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({
            ...form,
            valid_to: form.valid_to === '' ? null : form.valid_to,
            rate: form.deal_type === 'target' ? null : form.rate,
            tiers: form.deal_type === 'target' ? form.tiers : [],
            counts_own_purchases: form.party_type === 'customer' && form.counts_own_purchases,
        }));

        if (agreement === null) {
            post('/sales/commission-agreements', { preserveScroll: true, onSuccess: close });
        } else {
            put(`/sales/commission-agreements/${agreement.id}`, { preserveScroll: true, onSuccess: close });
        }
    };

    const people = data.party_type === 'employee' ? options.employees : data.party_type === 'customer' ? options.customers : options.suppliers;
    const locked = settledLock;
    const errorOf = (key: string) => (errors as Record<string, string | undefined>)[key];
    const named = (list: Named[], id: number) => list.find((item) => item.id === id)?.name ?? `#${id}`;

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {agreement === null ? t('commission.dialog_new') : `${t('commission.dialog_edit')} ${agreement.number}`}
                        </DialogTitle>
                        <DialogDescription>{locked ? t('commission.settled_lock') : t('commission.approval_hint')}</DialogDescription>
                    </DialogHeader>

                    <FormRow>
                        <Field label={t('commission.agreed_on')} htmlFor="ca-agreed_on" error={errors.agreed_on} required>
                            <Input
                                id="ca-agreed_on"
                                type="date"
                                disabled={locked}
                                value={data.agreed_on}
                                onChange={(e) => setData('agreed_on', e.target.value)}
                            />
                        </Field>
                        <Field label={t('commission.valid_from')} htmlFor="ca-valid_from" error={errors.valid_from} required>
                            <Input
                                id="ca-valid_from"
                                type="date"
                                disabled={locked}
                                value={data.valid_from}
                                onChange={(e) => setData('valid_from', e.target.value)}
                            />
                        </Field>
                        <Field label={t('commission.valid_to')} htmlFor="ca-valid_to" error={errors.valid_to} hint={t('commission.valid_to_hint')}>
                            <Input id="ca-valid_to" type="date" value={data.valid_to} onChange={(e) => setData('valid_to', e.target.value)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label={t('commission.party_type')} htmlFor="ca-party_type" error={errors.party_type} required>
                            <Select
                                value={data.party_type}
                                disabled={locked}
                                onValueChange={(value) => {
                                    setData((form) => ({ ...form, party_type: value, party_id: '' }));
                                }}
                            >
                                <SelectTrigger id="ca-party_type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="employee">{t('commission.party_employee')}</SelectItem>
                                    <SelectItem value="customer">{t('commission.party_customer')}</SelectItem>
                                    <SelectItem value="supplier">{t('commission.party_supplier')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label={t('commission.person')} htmlFor="ca-party_id" size="lg" error={errors.party_id} required>
                            <SearchableSelect
                                id="ca-party_id"
                                value={data.party_id}
                                disabled={locked}
                                placeholder={t('commission.party_pick')}
                                options={people.map((p) => ({ value: String(p.id), label: p.name }))}
                                onChange={(value) => setData('party_id', value)}
                            />
                        </Field>
                    </FormRow>

                    <div className="grid gap-2">
                        <Label>{t('commission.products')}</Label>
                        <p className="text-muted-foreground text-xs">{t('commission.products_hint')}</p>
                        <div className="flex flex-wrap gap-2">
                            {data.product_ids.length === 0 && data.category_ids.length === 0 && (
                                <span className="bg-muted rounded px-2 py-1 text-xs">{t('commission.every_product')}</span>
                            )}
                            {data.product_ids.map((id) => (
                                <span key={`p${id}`} className="bg-muted inline-flex items-center gap-1 rounded px-2 py-1 text-xs">
                                    {named(options.products, id)}
                                    {!locked && (
                                        <button
                                            type="button"
                                            className="text-muted-foreground hover:text-foreground"
                                            aria-label={t('commission.remove')}
                                            onClick={() =>
                                                setData(
                                                    'product_ids',
                                                    data.product_ids.filter((x) => x !== id),
                                                )
                                            }
                                        >
                                            <X className="size-3" />
                                        </button>
                                    )}
                                </span>
                            ))}
                            {data.category_ids.map((id) => (
                                <span key={`c${id}`} className="bg-muted inline-flex items-center gap-1 rounded px-2 py-1 text-xs">
                                    {named(options.categories, id)}
                                    {!locked && (
                                        <button
                                            type="button"
                                            className="text-muted-foreground hover:text-foreground"
                                            aria-label={t('commission.remove')}
                                            onClick={() =>
                                                setData(
                                                    'category_ids',
                                                    data.category_ids.filter((x) => x !== id),
                                                )
                                            }
                                        >
                                            <X className="size-3" />
                                        </button>
                                    )}
                                </span>
                            ))}
                        </div>
                        {!locked && (
                            <FormRow>
                                <Field label={t('commission.add_product')} htmlFor="ca-add-product" size="lg" error={errorOf('product_ids.0')}>
                                    <SearchableSelect
                                        id="ca-add-product"
                                        value=""
                                        placeholder={t('commission.add_product')}
                                        options={options.products
                                            .filter((p) => !data.product_ids.includes(p.id))
                                            .map((p) => ({ value: String(p.id), label: p.name }))}
                                        onChange={(value) => value !== '' && setData('product_ids', [...data.product_ids, Number(value)])}
                                    />
                                </Field>
                                <Field label={t('commission.add_category')} htmlFor="ca-add-category" size="md" error={errorOf('category_ids.0')}>
                                    <SearchableSelect
                                        id="ca-add-category"
                                        value=""
                                        placeholder={t('commission.add_category')}
                                        options={options.categories
                                            .filter((c) => !data.category_ids.includes(c.id))
                                            .map((c) => ({ value: String(c.id), label: c.name }))}
                                        onChange={(value) => value !== '' && setData('category_ids', [...data.category_ids, Number(value)])}
                                    />
                                </Field>
                            </FormRow>
                        )}
                    </div>

                    <FormRow>
                        <Field label={t('commission.deal_type')} htmlFor="ca-deal_type" error={errors.deal_type} required>
                            <Select value={data.deal_type} disabled={locked} onValueChange={(value) => setData('deal_type', value)}>
                                <SelectTrigger id="ca-deal_type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="per_unit">{t('commission.deal_per_unit')}</SelectItem>
                                    <SelectItem value="percent">{t('commission.deal_percent')}</SelectItem>
                                    <SelectItem value="target">{t('commission.deal_target')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {data.deal_type !== 'target' ? (
                            <Field
                                label={data.deal_type === 'percent' ? t('commission.rate_percent') : t('commission.rate_per_unit')}
                                htmlFor="ca-rate"
                                size="sm"
                                error={errors.rate}
                                required
                            >
                                <Input
                                    id="ca-rate"
                                    type="number"
                                    inputMode="decimal"
                                    step="0.01"
                                    min="0"
                                    disabled={locked}
                                    value={data.rate}
                                    onChange={(e) => setData('rate', e.target.value)}
                                />
                            </Field>
                        ) : (
                            <Field label={t('commission.target_basis')} htmlFor="ca-target_basis" error={errors.target_basis} required>
                                <Select value={data.target_basis} disabled={locked} onValueChange={(value) => setData('target_basis', value)}>
                                    <SelectTrigger id="ca-target_basis">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="amount">{t('commission.basis_amount')}</SelectItem>
                                        <SelectItem value="units">{t('commission.basis_units')}</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                    </FormRow>

                    {data.deal_type === 'target' && (
                        <div className="grid gap-2">
                            <Label>{t('commission.tiers')}</Label>
                            <p className="text-muted-foreground text-xs">{t('commission.tiers_hint')}</p>
                            {errorOf('tiers') && <p className="text-destructive text-sm">{errorOf('tiers')}</p>}
                            {data.tiers.map((tier, index) => (
                                <FormRow key={index}>
                                    <Field
                                        label={t('commission.threshold')}
                                        htmlFor={`ca-tier-${index}-threshold`}
                                        size="sm"
                                        error={errorOf(`tiers.${index}.threshold`)}
                                        required
                                    >
                                        <Input
                                            id={`ca-tier-${index}-threshold`}
                                            type="number"
                                            inputMode="decimal"
                                            step="0.01"
                                            min="0"
                                            disabled={locked}
                                            value={tier.threshold}
                                            onChange={(e) =>
                                                setData(
                                                    'tiers',
                                                    data.tiers.map((x, i) => (i === index ? { ...x, threshold: e.target.value } : x)),
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label={t('commission.reward')}
                                        htmlFor={`ca-tier-${index}-reward`}
                                        size="sm"
                                        error={errorOf(`tiers.${index}.reward`)}
                                        required
                                    >
                                        <Input
                                            id={`ca-tier-${index}-reward`}
                                            type="number"
                                            inputMode="decimal"
                                            step="0.01"
                                            min="0"
                                            disabled={locked}
                                            value={tier.reward}
                                            onChange={(e) =>
                                                setData(
                                                    'tiers',
                                                    data.tiers.map((x, i) => (i === index ? { ...x, reward: e.target.value } : x)),
                                                )
                                            }
                                        />
                                    </Field>
                                    {!locked && data.tiers.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="self-end"
                                            onClick={() =>
                                                setData(
                                                    'tiers',
                                                    data.tiers.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            {t('commission.remove')}
                                        </Button>
                                    )}
                                </FormRow>
                            ))}
                            {!locked && data.tiers.length < 10 && (
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setData('tiers', [...data.tiers, { threshold: '', reward: '' }])}
                                    >
                                        {t('commission.add_step')}
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="grid gap-2">
                        <Label>{t('commission.which_sales')}</Label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.counts_credited_sales}
                                disabled={locked}
                                onCheckedChange={(checked) => setData('counts_credited_sales', checked === true)}
                            />
                            {t('commission.counts_credited')}
                        </label>
                        {data.party_type === 'customer' && (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={data.counts_own_purchases}
                                    disabled={locked}
                                    onCheckedChange={(checked) => setData('counts_own_purchases', checked === true)}
                                />
                                {t('commission.counts_own')}
                            </label>
                        )}
                        {(errors.counts_credited_sales || errors.counts_own_purchases) && (
                            <p className="text-destructive text-sm">{errors.counts_credited_sales ?? errors.counts_own_purchases}</p>
                        )}
                    </div>

                    <Field label={t('commission.note')} htmlFor="ca-note" error={errors.note}>
                        <textarea
                            id="ca-note"
                            rows={2}
                            className="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </Field>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('commission.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {t('commission.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
