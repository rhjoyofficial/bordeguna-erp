import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const blank = (today: string) => ({
    asset_code: '',
    name: '',
    account_id: '',
    asset_category_id: '',
    acquired_on: today,
    cost: '',
    salvage_value: '',
    useful_life_months: '',
    depreciation_starts_on: '',
    money_account_id: '',
    supplier_id: '',
    note: '',
    cost_in_opening_balances: false as boolean,
    // FEA-004 (14 Oct 2026): an opening asset's figures at cutover.
    opening_accumulated_depreciation: '',
    remaining_life_months: '',
});

/**
 * Record a fixed asset in a popup over the register (Joy, 14 Oct 2026: "click
 * add button pop up will add"). The form that sat open above the register is
 * gone; the register is the page.
 *
 * Add only, as before. Same fields, same route, same server rules: the dialog
 * only changes where the form is drawn. ⚠⚠ Every money and depreciation rule
 * (which accounts, the capitalisation threshold, salvage above cost, the
 * opening-balance figures) is the server's, unchanged.
 */
export function FixedAssetDialog({
    open,
    onOpenChange,
    assetAccounts,
    moneyAccounts,
    suppliers,
    today,
    categories,
    cutoverDate,
    canRecordOpening,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assetAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    suppliers: { id: number; name: string }[];
    today: string;
    categories: { id: number; name: string; code: string; default_useful_life_months: number | null }[];
    cutoverDate: string;
    canRecordOpening: boolean;
}) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(blank(today));

    // Blank fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = blank(today);
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const lifeRequired = !categories.find((c) => String(c.id) === data.asset_category_id)?.default_useful_life_months;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('assets.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Record an asset</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Code" htmlFor="asset_code" className="w-32" error={errors.asset_code} required>
                            <Input
                                id="asset_code"
                                aria-required
                                value={data.asset_code}
                                onChange={(e) => setData('asset_code', e.target.value)}
                                placeholder="VAN-01"
                            />
                        </Field>
                        <Field label="What it is" htmlFor="name" className="w-56" error={errors.name} required>
                            <Input
                                id="name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Delivery van"
                            />
                        </Field>
                        <Field label="Reports under" htmlFor="account_id" error={errors.account_id} required>
                            {/*
                              ⚠⚠ Only NON-CURRENT asset accounts, and never Accumulated
                              Depreciation. Every postable asset account would include
                              Cash at Bank and Stock on Hand - a list whose choices are
                              overwhelmingly wrong.
                            */}
                            <SearchSelect
                                newLedger={{ types: ['asset'], reload: ['assetAccounts'] }}
                                fit
                                id="account_id"
                                value={data.account_id}
                                onChange={(e) => setData('account_id', e.target.value)}
                            >
                                <option value="">Choose an asset account</option>
                                {assetAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Acquired" htmlFor="acquired_on" error={errors.acquired_on} required>
                            <Input
                                id="acquired_on"
                                type="date"
                                aria-required
                                value={data.acquired_on}
                                onChange={(e) => setData('acquired_on', e.target.value)}
                            />
                        </Field>
                        <Field label="Cost" htmlFor="cost" className="w-32" error={errors.cost} required>
                            <Input
                                id="cost"
                                aria-required
                                value={data.cost}
                                onChange={(e) => setData('cost', e.target.value)}
                                className="text-right"
                                placeholder="0.00"
                                inputMode="decimal"
                            />
                        </Field>
                        {/* What it is expected to be worth at the end of its life;
                            never written off, so it stays on the balance sheet. */}
                        <Field label="Salvage value" htmlFor="salvage_value" className="w-32" error={errors.salvage_value}>
                            <Input
                                id="salvage_value"
                                value={data.salvage_value}
                                onChange={(e) => setData('salvage_value', e.target.value)}
                                className="text-right"
                                placeholder="0.00"
                                inputMode="decimal"
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Class" htmlFor="asset_category_id" error={errors.asset_category_id}>
                            <SearchSelect
                                fit
                                id="asset_category_id"
                                value={data.asset_category_id}
                                onChange={(e) => {
                                    const chosen = categories.find((c) => String(c.id) === e.target.value);
                                    setData({
                                        ...data,
                                        asset_category_id: e.target.value,
                                        useful_life_months: chosen?.default_useful_life_months
                                            ? String(chosen.default_useful_life_months)
                                            : data.useful_life_months,
                                    });
                                }}
                            >
                                <option value="">No class</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                        {category.default_useful_life_months ? ` (${category.default_useful_life_months} months)` : ''}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {/* Required unless the chosen class carries a default life (the server falls back to it). */}
                        <Field
                            label="Useful life (months)"
                            htmlFor="useful_life_months"
                            className="w-40"
                            error={errors.useful_life_months}
                            required={lifeRequired}
                        >
                            <Input
                                id="useful_life_months"
                                aria-required={lifeRequired}
                                value={data.useful_life_months}
                                onChange={(e) => setData('useful_life_months', e.target.value)}
                                className="text-right"
                                placeholder="36"
                                inputMode="numeric"
                            />
                        </Field>
                        <Field label="Depreciation starts (optional)" htmlFor="depreciation_starts_on" error={errors.depreciation_starts_on}>
                            <Input
                                id="depreciation_starts_on"
                                type="date"
                                value={data.depreciation_starts_on}
                                onChange={(e) => setData('depreciation_starts_on', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        {/*
                          ⚠ Optional. Leaving it empty records the asset in the
                          register WITHOUT any journal - knowing what you own is
                          not an accounting question, and the engine skips the
                          posting deliberately in that case.
                        */}
                        <Field label="Paid from (optional)" htmlFor="money_account_id" error={errors.money_account_id}>
                            <SearchSelect
                                fit
                                id="money_account_id"
                                value={data.money_account_id}
                                onChange={(e) => setData('money_account_id', e.target.value)}
                            >
                                <option value="">Not paid through the ERP</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Bought from (optional)" htmlFor="supplier_id" error={errors.supplier_id}>
                            <SearchSelect fit id="supplier_id" value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                                <option value="">Not recorded</option>
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    {/* ⚠⚠ Without a payment, depreciation needs the cost to be in the
                        books some other way: the opening balances. Otherwise it would
                        write off a cost the ledger never carried (16 Sep 2026). */}
                    {data.money_account_id === '' && canRecordOpening && (
                        <div className="flex max-w-md items-start gap-2">
                            <Checkbox
                                id="cost_in_opening_balances"
                                checked={data.cost_in_opening_balances}
                                onCheckedChange={(checked) => setData('cost_in_opening_balances', checked === true)}
                            />
                            <Label htmlFor="cost_in_opening_balances" className="leading-snug font-normal">
                                Its cost is already in the opening balances, so it can be depreciated
                            </Label>
                        </div>
                    )}

                    {/* ⭐ FEA-003/004 (owner decision 14 Oct 2026): an opening asset carries
                        the accountant's figures at cutover; nothing is posted for them. */}
                    {data.money_account_id === '' && data.cost_in_opening_balances && (
                        <div className="grid gap-2">
                            <FormRow>
                                <Field
                                    label={t('asset.opening.accumulated')}
                                    htmlFor="opening_accumulated_depreciation"
                                    className="w-64"
                                    error={errors.opening_accumulated_depreciation}
                                >
                                    <Input
                                        id="opening_accumulated_depreciation"
                                        value={data.opening_accumulated_depreciation}
                                        onChange={(e) => setData('opening_accumulated_depreciation', e.target.value)}
                                        className="text-right"
                                        placeholder="0.00"
                                        inputMode="decimal"
                                    />
                                </Field>
                                <Field
                                    label={t('asset.opening.remaining_life')}
                                    htmlFor="remaining_life_months"
                                    className="w-56"
                                    error={errors.remaining_life_months}
                                >
                                    <Input
                                        id="remaining_life_months"
                                        value={data.remaining_life_months}
                                        onChange={(e) => setData('remaining_life_months', e.target.value)}
                                        className="text-right"
                                        placeholder={t('asset.opening.remaining_life_auto')}
                                        title={t('asset.opening.remaining_life_auto')}
                                        inputMode="numeric"
                                    />
                                </Field>
                            </FormRow>
                            <p className="text-muted-foreground text-sm">{t('asset.opening.hint', { date: cutoverDate })}</p>
                            <InputError message={errors.cost_in_opening_balances} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Record asset</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
