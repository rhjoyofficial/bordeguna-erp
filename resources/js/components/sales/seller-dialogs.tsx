import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

/**
 * ⭐ Initial plan B21 (24 Oct 2026, Joy's Q3 = A): the three popups of a
 * retail seller's page. Date box first (Joy, 19 Oct 2026), no <form>
 * element, so Enter never sends: only the Save button does. Every popup
 * carries a one-time `submission` key so a double click records once.
 *
 *   handover   warehouse -> his seller stock, each line shows the Trade Price
 *   return     his seller stock -> a warehouse, reason optional
 *   sales      what he sold: to whom (optional), price (TP or the customer's
 *              own), what happened to the money
 */

export type SellerForms = {
    warehouses: { id: number; name: string; is_default: boolean }[];
    products: { id: number; name: string; sku: string | null; trade_price: string | null }[];
    on_hand: Record<string, Record<string, string>>;
    seller_stock_id: number;
    trade_group: string | null;
    customers: { id: number; name: string; phone: string | null }[];
    money_accounts: { id: number; name: string; type: string; custodian_employee_id: number | null }[];
    own_cash_id: number | null;
    reversal_reasons: { id: number; name: string }[];
};

type Mode = 'handover' | 'return' | 'sales';
type Line = { product_id: string; quantity: string };

function blank(mode: Mode, forms: SellerForms, submission: string) {
    const warehouse = forms.warehouses.find((w) => w.is_default) ?? forms.warehouses[0];

    return {
        submission,
        moved_on: dhakaToday(),
        sold_on: dhakaToday(),
        warehouse_id: warehouse ? String(warehouse.id) : '',
        reason: '',
        note: '',
        customer_id: '',
        sold_to: '',
        price_basis: 'trade',
        payment: 'collected',
        money_account_id: forms.own_cash_id ? String(forms.own_cash_id) : '',
        lines: [{ product_id: '', quantity: '' }] as Line[],
        mode,
    };
}

export function SellerDialog({
    mode,
    open,
    onOpenChange,
    employeeId,
    sellerName,
    forms,
    submission,
}: {
    mode: Mode;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    sellerName: string;
    forms: SellerForms;
    submission: string;
}) {
    const { t } = useTranslation();
    const form = useForm(blank(mode, forms, submission));
    const { data, setData, errors, processing } = form;

    useEffect(() => {
        if (open) {
            form.setData(blank(mode, forms, submission));
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, submission]);

    // Where the goods are counted from: the warehouse on a hand-over, his own
    // stock on a sale or a return.
    const stockFrom = mode === 'handover' ? data.warehouse_id : String(forms.seller_stock_id);
    const available = (productId: string) => forms.on_hand[stockFrom]?.[productId] ?? '0';
    const productOptions = useMemo(() => {
        const list =
            mode === 'handover'
                ? forms.products
                : forms.products.filter((p) => Number(forms.on_hand[String(forms.seller_stock_id)]?.[String(p.id)] ?? 0) > 0);

        return list.map((p) => ({
            value: String(p.id),
            label: p.name,
            keywords: p.sku ?? undefined,
            hint: mode === 'handover' ? (p.trade_price ? t('seller.tp_each', { price: formatMoney(p.trade_price) }) : t('seller.no_tp')) : undefined,
        }));
    }, [mode, forms, t]);

    const tradeTotal = data.lines.reduce((sum, line) => {
        const product = forms.products.find((p) => String(p.id) === line.product_id);

        return sum + (product?.trade_price ? Number(product.trade_price) * Number(line.quantity || 0) : 0);
    }, 0);

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );

    const save = () => {
        const url = `/sales/sellers/${employeeId}/${mode === 'handover' ? 'handover' : mode === 'return' ? 'return' : 'sales'}`;
        form.transform((current) => {
            const lines = current.lines.filter((l) => l.product_id !== '' && l.quantity !== '');

            if (mode === 'sales') {
                return {
                    submission: current.submission,
                    sold_on: current.sold_on,
                    customer_id: current.customer_id || null,
                    sold_to: current.sold_to,
                    price_basis: current.price_basis,
                    payment: current.payment,
                    money_account_id: current.payment === 'collected' ? current.money_account_id || null : null,
                    note: current.note,
                    lines,
                };
            }

            return {
                submission: current.submission,
                moved_on: current.moved_on,
                warehouse_id: current.warehouse_id,
                reason: current.reason,
                note: current.note,
                lines,
            };
        });
        form.post(url, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const title =
        mode === 'handover'
            ? t('seller.handover_title', { name: sellerName })
            : mode === 'return'
              ? t('seller.return_title', { name: sellerName })
              : t('seller.report_title', { name: sellerName });
    const hint = mode === 'handover' ? t('seller.handover_hint') : mode === 'return' ? t('seller.return_hint') : t('seller.report_hint');
    const lineError = (index: number) =>
        (errors as Record<string, string>)[`lines.${index}.product_id`] ?? (errors as Record<string, string>)[`lines.${index}.quantity`];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{hint}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="grid gap-1">
                            <Label htmlFor="seller-date">{t('seller.date')}</Label>
                            <Input
                                id="seller-date"
                                type="date"
                                className="w-fit"
                                value={mode === 'sales' ? data.sold_on : data.moved_on}
                                max={dhakaToday()}
                                onChange={(e) => setData(mode === 'sales' ? 'sold_on' : 'moved_on', e.target.value)}
                            />
                            <InputError message={errors.sold_on ?? errors.moved_on} />
                        </div>

                        {mode !== 'sales' && (
                            <div className="grid gap-1">
                                <Label>{mode === 'handover' ? t('seller.warehouse_from') : t('seller.warehouse_to')}</Label>
                                <SearchableSelect
                                    fit
                                    value={data.warehouse_id}
                                    onChange={(value) => setData('warehouse_id', value)}
                                    options={forms.warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                                />
                                <InputError message={errors.warehouse_id} />
                            </div>
                        )}
                    </div>

                    {mode === 'sales' && (
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="grid gap-1">
                                <Label>{t('seller.customer')}</Label>
                                <SearchableSelect
                                    fit
                                    clearable
                                    value={data.customer_id}
                                    placeholder={t('seller.walk_in')}
                                    onChange={(value) => {
                                        setData((current) => ({
                                            ...current,
                                            customer_id: value,
                                            price_basis: value === '' ? 'trade' : current.price_basis,
                                            payment: value === '' && current.payment === 'on_account' ? 'collected' : current.payment,
                                        }));
                                    }}
                                    options={forms.customers.map((c) => ({ value: String(c.id), label: c.name, keywords: c.phone ?? undefined }))}
                                />
                                <InputError message={errors.customer_id} />
                            </div>
                            {data.customer_id === '' && (
                                <div className="grid gap-1">
                                    <Label htmlFor="seller-sold-to">{t('seller.sold_to')}</Label>
                                    <Input
                                        id="seller-sold-to"
                                        className="w-64"
                                        value={data.sold_to}
                                        onChange={(e) => setData('sold_to', e.target.value)}
                                    />
                                </div>
                            )}
                            <div className="grid gap-1">
                                <Label>{t('seller.price')}</Label>
                                <div className="flex gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={data.price_basis === 'trade' ? 'default' : 'outline'}
                                        onClick={() => setData('price_basis', 'trade')}
                                    >
                                        {t('seller.price_trade')}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={data.price_basis === 'customer' ? 'default' : 'outline'}
                                        disabled={data.customer_id === ''}
                                        onClick={() => setData('price_basis', 'customer')}
                                    >
                                        {t('seller.price_customer')}
                                    </Button>
                                </div>
                                <InputError message={errors.price_basis} />
                            </div>
                        </div>
                    )}

                    <div className="grid gap-2">
                        {data.lines.map((line, index) => (
                            <div key={index} className="flex flex-wrap items-end gap-2">
                                <div className="grid gap-1">
                                    {index === 0 && <Label>{t('seller.item')}</Label>}
                                    <SearchableSelect
                                        fit
                                        value={line.product_id}
                                        placeholder={t('seller.pick_item')}
                                        onChange={(value) => setLine(index, { product_id: value })}
                                        options={productOptions}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    {index === 0 && <Label>{t('seller.qty')}</Label>}
                                    <Input
                                        className="w-24"
                                        inputMode="decimal"
                                        value={line.quantity}
                                        onChange={(e) => setLine(index, { quantity: e.target.value })}
                                    />
                                </div>
                                {line.product_id !== '' && (
                                    <span className="text-muted-foreground pb-2 text-xs">
                                        {mode === 'handover'
                                            ? t('seller.available', { qty: available(line.product_id) })
                                            : t('seller.with_him', { qty: available(line.product_id) })}
                                    </span>
                                )}
                                {data.lines.length > 1 && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setData(
                                                'lines',
                                                data.lines.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        {t('seller.remove')}
                                    </Button>
                                )}
                                <InputError className="w-full" message={lineError(index)} />
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setData('lines', [...data.lines, { product_id: '', quantity: '' }])}
                            >
                                {t('seller.add_line')}
                            </Button>
                        </div>
                        <InputError message={errors.lines} />
                        {mode === 'handover' && tradeTotal > 0 && (
                            <p className="font-medium">{t('seller.total_tp', { amount: formatMoney(tradeTotal) })}</p>
                        )}
                    </div>

                    {mode === 'sales' && (
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="grid gap-1">
                                <Label>{t('seller.money')}</Label>
                                <div className="flex flex-wrap gap-2">
                                    {(['collected', 'seller_owes', 'on_account'] as const).map((option) => (
                                        <Button
                                            key={option}
                                            type="button"
                                            size="sm"
                                            variant={data.payment === option ? 'default' : 'outline'}
                                            disabled={option === 'on_account' && data.customer_id === ''}
                                            onClick={() => setData('payment', option)}
                                        >
                                            {t(`seller.pay_${option}`)}
                                        </Button>
                                    ))}
                                </div>
                                <InputError message={errors.payment} />
                            </div>
                            {data.payment === 'collected' && (
                                <div className="grid gap-1">
                                    <Label>{t('seller.into')}</Label>
                                    <SearchableSelect
                                        fit
                                        value={data.money_account_id}
                                        onChange={(value) => setData('money_account_id', value)}
                                        options={forms.money_accounts.map((a) => ({ value: String(a.id), label: a.name }))}
                                    />
                                    <InputError message={errors.money_account_id} />
                                </div>
                            )}
                        </div>
                    )}

                    {mode === 'return' && (
                        <div className="grid gap-1">
                            <Label htmlFor="seller-reason">{t('seller.reason')}</Label>
                            <Input id="seller-reason" className="w-80" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                            <InputError message={errors.reason} />
                        </div>
                    )}

                    <div className="grid gap-1">
                        <Label htmlFor="seller-note">{t('seller.note')}</Label>
                        <textarea
                            id="seller-note"
                            className="border-input min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        {t('seller.cancel')}
                    </Button>
                    <Button type="button" disabled={processing} onClick={save}>
                        {t('seller.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
