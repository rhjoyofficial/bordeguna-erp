import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface ReturnableLine {
    delivery_line_id: number;
    product_name: string;
    product_sku: string;
    quantity_delivered: string;
    quantity_returnable: string;
    quantity_uninvoiced: string;
    /** ⭐ B20: the exact batches the sale took, and the price it was billed at. */
    batches?: { batch_number: string; expiry_date: string | null; quantity: string }[];
    unit_price?: string | null;
    credit_per_unit?: string | null;
}

interface InvoiceContext {
    id: number;
    invoice_number: string;
    total: string;
    outstanding: string;
    deliveries: { id: number; delivery_number: string; delivered_at: string | null }[];
}

type LineDraft = { delivery_line_id: number; quantity_to_stock: string; quantity_written_off: string; quantity_to_quarantine: string };
type RefundDraft = { money_account_id: string; amount: string };

const num = (value: string | null | undefined) => {
    const parsed = parseFloat(String(value ?? '').replace(/,/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
};

/*
 * Goods coming back, recorded AFTER inspection in one step (C-SR-3, Joy): the
 * person who checked the goods says, per line, how much is good and how much
 * is damaged. The engine derives everything else - which batches restore,
 * whether the money is a GDNI reversal or a customer credit, at which price.
 *
 * ⭐ B20 (Joy, 24 Oct 2026): "You open the original Sales Invoice and click
 * Create Return". Opened from an invoice, the form shows only what it billed,
 * the batch each line left from and the billed price; good goods go back to a
 * chosen selling warehouse (same batch and cost), damaged ones to quarantine;
 * the credit first reduces what is owed, the rest is refunded now (split over
 * accounts if needed) or kept as an open credit; Exchange opens the new order.
 *
 * ⚠ The caps shown here came from the SERVER and the engine re-checks them
 * under lock; this screen never computes its own truth. The credit shown is
 * "about": the engine's own figure is on the return page.
 */
export default function ReturnCreate({
    deliveries,
    deliveryId,
    delivery = null,
    lines,
    can_refund,
    can_exchange = false,
    moneyAccounts,
    invoiceLookupError = null,
    quarantineWarehouses = [],
    invoice = null,
    deliveryWarehouseId = null,
    sellableWarehouses = [],
}: {
    deliveries: { id: number; delivery_number: string; delivered_at: string; customer: { id: number; name: string } | null }[];
    deliveryId: number | null;
    delivery?: {
        id: number;
        delivery_number: string;
        customer: { id: number; name: string };
        warehouse?: { id: number; name: string } | null;
    } | null;
    lines: ReturnableLine[];
    can_refund: boolean;
    can_exchange?: boolean;
    moneyAccounts: { id: number; name: string; type: string }[];
    invoiceLookupError: string | null;
    /** ⭐ B7 (Joy, 24 Oct 2026; Q7 = A): Not for sale warehouses damaged goods may wait in. */
    quarantineWarehouses?: { id: number; name: string }[];
    invoice?: InvoiceContext | null;
    deliveryWarehouseId?: number | null;
    sellableWarehouses?: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const quarantine = quarantineWarehouses.length > 0;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Returns', href: '/sales/returns' },
        { title: t('ips.sret.title'), href: '/sales/returns/create' },
    ];
    // The counter entry: the customer holds a receipt with an INVOICE number,
    // so let the cashier type that instead of hunting for the challan.
    const [invoiceNumber, setInvoiceNumber] = useState('');
    const findByInvoice = () => {
        if (invoiceNumber.trim() !== '') {
            router.get(route('sales.returns.create'), { invoice_number: invoiceNumber.trim() }, { preserveState: false });
        }
    };
    const [moneyMode, setMoneyMode] = useState<'credit' | 'refund'>('credit');
    const { data, setData, post, errors, processing, transform } = useForm<{
        delivery_id: string;
        returned_at: string;
        note: string;
        lines: LineDraft[];
        quarantine_warehouse_id: string;
        stock_warehouse_id: string;
        refunds: RefundDraft[];
        exchange: boolean;
    }>({
        delivery_id: deliveryId ? String(deliveryId) : '',
        returned_at: dhakaToday(),
        note: '',
        lines: lines.map((line) => ({
            delivery_line_id: line.delivery_line_id,
            quantity_to_stock: '',
            quantity_written_off: '',
            quantity_to_quarantine: '',
        })),
        quarantine_warehouse_id: quarantineWarehouses.length === 1 ? String(quarantineWarehouses[0].id) : '',
        stock_warehouse_id: deliveryWarehouseId ? String(deliveryWarehouseId) : '',
        refunds: [{ money_account_id: '', amount: '' }],
        exchange: false,
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const pickDelivery = (id: string) => {
        const query: Record<string, string> = id ? { delivery_id: id } : {};

        if (invoice) {
            query.invoice_id = String(invoice.id);
        }

        router.get(route('sales.returns.create'), query, { preserveState: false });
    };

    const updateLine = (deliveryLineId: number, patch: Partial<LineDraft>) => {
        setData(
            'lines',
            data.lines.map((line) => (line.delivery_line_id === deliveryLineId ? { ...line, ...patch } : line)),
        );
    };

    const setRefund = (index: number, patch: Partial<RefundDraft>) =>
        setData(
            'refunds',
            data.refunds.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        );

    // The refund rows travel only in "Refund now" and only once an account is
    // chosen; "Keep as open credit" sends none, so the credit stays open.
    transform((form) => ({
        delivery_id: form.delivery_id,
        returned_at: form.returned_at || null,
        note: form.note || null,
        lines: form.lines,
        ...(form.quarantine_warehouse_id ? { quarantine_warehouse_id: form.quarantine_warehouse_id } : {}),
        ...(form.stock_warehouse_id ? { stock_warehouse_id: form.stock_warehouse_id } : {}),
        ...(invoice ? { sales_invoice_id: invoice.id } : {}),
        ...(moneyMode === 'refund'
            ? {
                  refunds: form.refunds
                      .filter((row) => row.money_account_id !== '')
                      .map((row) => ({ money_account_id: row.money_account_id, ...(row.amount.trim() !== '' ? { amount: row.amount.trim() } : {}) })),
              }
            : {}),
        exchange: form.exchange,
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.returns.store'));
    };

    const touched = data.lines.filter(
        (line) => num(line.quantity_to_stock) > 0 || num(line.quantity_written_off) > 0 || num(line.quantity_to_quarantine) > 0,
    );

    // "About" the credit: the invoiced part of each line at the billed net.
    // The not-yet-billed part is relieved first and credits nothing (C-SR-4).
    const creditAbout = lines.reduce((sum, line) => {
        const draft = data.lines.find((entry) => entry.delivery_line_id === line.delivery_line_id);
        const quantity = num(draft?.quantity_to_stock) + num(draft?.quantity_written_off) + num(draft?.quantity_to_quarantine);
        const billed = Math.max(0, quantity - Math.max(0, num(line.quantity_uninvoiced)));

        return sum + billed * num(line.credit_per_unit);
    }, 0);
    const owed = invoice ? num(invoice.outstanding) : null;
    const leftOver = owed === null ? creditAbout : Math.max(0, creditAbout - owed);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('ips.sret.title')} />
            <div className="flex flex-col gap-6 p-4">
                {invoice ? (
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold">{t('ips.sret.from_invoice', { number: invoice.invoice_number })}</h1>
                        <p className="text-muted-foreground text-sm">
                            {delivery?.customer?.name ? `${delivery.customer.name} · ` : ''}
                            {t('ips.sret.invoice_owes', { total: formatMoney(invoice.total), owed: formatMoney(invoice.outstanding) })}
                        </p>
                        {invoice.deliveries.length > 1 && (
                            <div className="mt-2 grid w-fit gap-2">
                                <Label>{t('ips.sret.challan_of_invoice')}</Label>
                                <SearchSelect fit value={data.delivery_id} onChange={(e) => pickDelivery(e.target.value)}>
                                    {invoice.deliveries.map((d) => (
                                        <option key={d.id} value={String(d.id)}>
                                            {d.delivery_number} ({formatDate(d.delivered_at)})
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                        )}
                    </div>
                ) : (
                    <>
                        <div className="grid max-w-md gap-2">
                            <Label>Receipt / invoice number the customer is holding</Label>
                            <div className="flex gap-2">
                                <Input
                                    placeholder="e.g. INV-0042"
                                    value={invoiceNumber}
                                    onChange={(e) => setInvoiceNumber(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            findByInvoice();
                                        }
                                    }}
                                />
                                <Button type="button" variant="outline" onClick={findByInvoice}>
                                    Find
                                </Button>
                            </div>
                        </div>

                        <div className="grid max-w-md gap-2">
                            <Label required>Challan the goods came back from</Label>
                            <SearchableSelect
                                value={data.delivery_id}
                                onChange={pickDelivery}
                                options={deliveries.map((d) => ({
                                    value: String(d.id),
                                    label: `${d.delivery_number} - ${d.customer?.name ?? ''} (${formatDate(d.delivered_at)})`,
                                }))}
                                placeholder="Select challan"
                                searchPlaceholder="Search challans…"
                            />
                            <InputError message={errors.delivery_id} />
                        </div>
                    </>
                )}
                {invoiceLookupError && <p className="text-destructive text-sm">{invoiceLookupError}</p>}

                {deliveryId !== null && lines.length > 0 && (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        {/* Joy, 19 Oct 2026: the date comes first in every form. */}
                        <div className="grid w-fit gap-2">
                            <Label required>{t('ips.sret.date')}</Label>
                            <Input type="date" aria-required value={data.returned_at} onChange={(e) => setData('returned_at', e.target.value)} />
                            <InputError message={errors.returned_at} />
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Product</th>
                                        <th className="p-3">{t('ips.sret.col_batches')}</th>
                                        <th className="p-3 text-right">{t('ips.sret.col_price')}</th>
                                        <th className="p-3 text-right">Can return</th>
                                        <th className="p-3">{t('ips.sret.col_good')}</th>
                                        {quarantine && <th className="p-3">{t('ips.sret.col_damaged')}</th>}
                                        {/* Q7 = A: damaged goods wait in quarantine; writing off at once
                                            is offered only where no quarantine warehouse exists. */}
                                        {!quarantine && <th className="p-3">{t('ips.sret.col_write_off')}</th>}
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((line) => {
                                        const draft = data.lines.find((entry) => entry.delivery_line_id === line.delivery_line_id);
                                        const index = data.lines.findIndex((entry) => entry.delivery_line_id === line.delivery_line_id);

                                        return (
                                            <tr key={line.delivery_line_id} className="border-t align-top">
                                                <td className="p-3">
                                                    {line.product_name} <span className="text-muted-foreground">({line.product_sku})</span>
                                                </td>
                                                <td className="text-muted-foreground p-3 text-xs">
                                                    {(line.batches ?? []).length === 0
                                                        ? '-'
                                                        : (line.batches ?? []).map((batch) => (
                                                              <div key={batch.batch_number}>
                                                                  {batch.batch_number} · {formatQuantity(batch.quantity)}
                                                                  {batch.expiry_date ? `, ${t('ips.sret.expires', { date: batch.expiry_date })}` : ''}
                                                              </div>
                                                          ))}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {line.unit_price ? formatMoney(line.unit_price) : '-'}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_returnable)}</td>
                                                <td className="p-3">
                                                    <Input
                                                        className="w-24"
                                                        inputMode="decimal"
                                                        value={draft?.quantity_to_stock ?? ''}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { quantity_to_stock: e.target.value })}
                                                    />
                                                    <InputError message={fieldErrors[`lines.${index}.quantity_to_stock`]} />
                                                    <InputError message={fieldErrors[`lines.${index}.quantity`]} />
                                                </td>
                                                {quarantine && (
                                                    <td className="p-3">
                                                        <Input
                                                            className="w-24"
                                                            inputMode="decimal"
                                                            value={draft?.quantity_to_quarantine ?? ''}
                                                            onChange={(e) =>
                                                                updateLine(line.delivery_line_id, { quantity_to_quarantine: e.target.value })
                                                            }
                                                        />
                                                        <InputError message={fieldErrors[`lines.${index}.quantity_to_quarantine`]} />
                                                    </td>
                                                )}
                                                {!quarantine && (
                                                    <td className="p-3">
                                                        <Input
                                                            className="w-24"
                                                            inputMode="decimal"
                                                            value={draft?.quantity_written_off ?? ''}
                                                            onChange={(e) =>
                                                                updateLine(line.delivery_line_id, { quantity_written_off: e.target.value })
                                                            }
                                                        />
                                                        <InputError message={fieldErrors[`lines.${index}.quantity_written_off`]} />
                                                    </td>
                                                )}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="flex flex-wrap gap-6">
                            {sellableWarehouses.length > 1 && (
                                <div className="grid w-fit gap-2">
                                    <Label htmlFor="good-warehouse">{t('ips.sret.good_warehouse')}</Label>
                                    <SearchSelect
                                        id="good-warehouse"
                                        fit
                                        value={data.stock_warehouse_id}
                                        onChange={(e) => setData('stock_warehouse_id', e.target.value)}
                                    >
                                        {sellableWarehouses.map((w) => (
                                            <option key={w.id} value={String(w.id)}>
                                                {w.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    <p className="text-muted-foreground text-xs">{t('ips.sret.good_warehouse_hint')}</p>
                                    <InputError message={fieldErrors.stock_warehouse_id} />
                                </div>
                            )}
                            {/* ⭐ B7 (Joy, 24 Oct 2026; Q7 = A): damaged goods wait in quarantine. */}
                            {quarantine && (
                                <div className="flex flex-col gap-1">
                                    {quarantineWarehouses.length > 1 && (
                                        <div className="grid w-fit gap-2">
                                            <Label htmlFor="quarantine-warehouse">{t('ips.ret.warehouse')}</Label>
                                            <SearchSelect
                                                id="quarantine-warehouse"
                                                fit
                                                value={data.quarantine_warehouse_id}
                                                onChange={(e) => setData('quarantine_warehouse_id', e.target.value)}
                                            >
                                                <option value="">{t('ips.ret.choose')}</option>
                                                {quarantineWarehouses.map((w) => (
                                                    <option key={w.id} value={w.id}>
                                                        {w.name}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        </div>
                                    )}
                                    <p className="text-muted-foreground max-w-md text-xs">{t('ips.ret.hint')}</p>
                                    <InputError message={fieldErrors.quarantine_warehouse_id} />
                                </div>
                            )}
                        </div>

                        {/* The money: the credit reduces what is owed first; what is
                            left is refunded now or stays an open credit. */}
                        <div className="flex w-fit flex-col gap-2 rounded-lg border p-3">
                            <p className="font-medium">{t('ips.sret.money_title')}</p>
                            <p className="text-sm tabular-nums">{t('ips.sret.credit_about', { amount: formatMoney(creditAbout) })}</p>
                            {invoice && (
                                <p className="text-muted-foreground text-sm">
                                    {t('ips.sret.reduces_due', { owed: formatMoney(invoice.outstanding) })}
                                </p>
                            )}
                            {invoice && leftOver > 0.005 && (
                                <p className="text-muted-foreground text-sm">{t('ips.sret.left_over', { amount: formatMoney(leftOver) })}</p>
                            )}
                            {can_refund && (
                                <div className="flex flex-col gap-2">
                                    <div className="flex flex-wrap gap-4 text-sm">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="money"
                                                checked={moneyMode === 'credit'}
                                                onChange={() => setMoneyMode('credit')}
                                            />
                                            {t('ips.sret.keep_credit')}
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="money"
                                                checked={moneyMode === 'refund'}
                                                onChange={() => setMoneyMode('refund')}
                                            />
                                            {t('ips.sret.refund_now')}
                                        </label>
                                    </div>
                                    {moneyMode === 'refund' &&
                                        data.refunds.map((row, i) => (
                                            <div key={i} className="flex flex-wrap items-end gap-3">
                                                <div className="grid gap-1">
                                                    <Label>{t('ips.sret.refund_from')}</Label>
                                                    <SearchSelect
                                                        fit
                                                        value={row.money_account_id}
                                                        onChange={(e) => setRefund(i, { money_account_id: e.target.value })}
                                                    >
                                                        <option value="">{t('ips.sret.choose_account')}</option>
                                                        {moneyAccounts.map((account) => (
                                                            <option key={account.id} value={String(account.id)}>
                                                                {account.name}
                                                            </option>
                                                        ))}
                                                    </SearchSelect>
                                                    <InputError message={fieldErrors[`refunds.${i}.money_account_id`]} />
                                                </div>
                                                <div className="grid gap-1">
                                                    <Label>{t('ips.sret.refund_amount')}</Label>
                                                    <Input
                                                        className="w-32"
                                                        inputMode="decimal"
                                                        value={row.amount}
                                                        onChange={(e) => setRefund(i, { amount: e.target.value })}
                                                    />
                                                    <InputError message={fieldErrors[`refunds.${i}.amount`]} />
                                                </div>
                                                {data.refunds.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setData(
                                                                'refunds',
                                                                data.refunds.filter((_, j) => j !== i),
                                                            )
                                                        }
                                                    >
                                                        {t('ips.sret.remove')}
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    {moneyMode === 'refund' && data.refunds.length < 5 && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-fit"
                                            onClick={() => setData('refunds', [...data.refunds, { money_account_id: '', amount: '' }])}
                                        >
                                            {t('ips.sret.add_split')}
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="grid w-fit gap-2">
                            <Label>Note (optional)</Label>
                            <Input className="w-80" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        </div>

                        {can_exchange && (
                            <div className="flex flex-col gap-1">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={data.exchange} onCheckedChange={(checked) => setData('exchange', checked === true)} />
                                    {t('ips.sret.exchange')}
                                </label>
                                <p className="text-muted-foreground max-w-xl text-xs">{t('ips.sret.exchange_hint')}</p>
                            </div>
                        )}

                        <InputError message={errors.lines} />
                        <InputError message={fieldErrors.sales_invoice_id} />

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || touched.length === 0}>{t('ips.sret.record')}</Button>
                            <p className="text-muted-foreground text-sm">
                                {touched.length} line{touched.length === 1 ? '' : 's'} with quantities.
                            </p>
                        </div>
                    </form>
                )}

                {deliveryId !== null && lines.length === 0 && (
                    <p className="text-muted-foreground text-sm">This challan has nothing left to return.</p>
                )}
            </div>
        </AppLayout>
    );
}
