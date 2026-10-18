import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import { AdvanceRows, advanceRowsToPost, type AvailableAdvance, proposeUpTo, toPaisa } from '@/components/purchasing/supplier-advances';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { deriveLineTotal, deriveUnitPrice, type EntryMode, linePaisaOf, orderValuePaisa } from '@/lib/purchase-line';
import type { BreadcrumbItem } from '@/types';

type Line = {
    id: number;
    product: string | null;
    sku: string | null;
    quantity_ordered: string;
    quantity_received: string;
    quantity_returned: string;
    quantity_invoiced: string;
    billable: string;
    order_unit_cost: string;
    // ⭐ 17 Oct 2026: how the order line was typed, its stored total, and what
    // the billable quantity is worth on it (PurchaseOrderLine::valueOf).
    order_entry_mode: EntryMode;
    order_line_total: string;
    order_value_for_billable: string;
};

type BillLineDraft = {
    purchase_order_line_id: number;
    quantity: string;
    unit_cost: string;
    line_total: string;
    entry_mode: EntryMode;
    /** ⭐ Trade discount typed on this line (ruling P3), when the discount is per line. */
    discount: string;
};

/** ⭐ Supplier discounts (ruling P3, 18 Oct 2026): how the bill's trade discount is typed. */
type DiscountMode = '' | 'amount' | 'percent' | 'lines';

type Order = {
    id: number;
    po_number: string;
    supplier: { id: number; name: string; payment_terms: string | null };
    lines: Line[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
    { title: 'Record', href: '#' },
];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });
const taka = (paisa: number) => money(paisa / 100);

export default function RecordBill({ order, vat, advances = [] }: { order: Order; vat: { applies: boolean }; advances?: AvailableAdvance[] }) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, transform } = useForm<{
        purchase_order_id: number;
        bill_number: string;
        bill_date: string;
        due_date: string;
        note: string;
        prices_include_tax: boolean;
        tax_recoverable: boolean;
        lines: BillLineDraft[];
        discount_mode: DiscountMode;
        discount_value: string;
        discount_reference: string;
        discount_reason: string;
    }>({
        purchase_order_id: order.id,
        bill_number: '',
        bill_date: dhakaToday(),
        due_date: '',
        note: '',
        // VAT plan D7. Sent always, read by the server only while VAT is on.
        prices_include_tax: false,
        tax_recoverable: true,
        lines: order.lines.map((line) => ({
            purchase_order_line_id: line.id,
            // Defaulted to what is still billable and to the AGREED figures, so
            // the common case is one click and any deviation is deliberate.
            // Plain numbers in the boxes, not the stored "100.0000".
            // ⭐ 17 Oct 2026: typed the way the order line was typed (price or
            // total), so a "3,000 for 100,000" order opens a 100,000 bill.
            quantity: String(Number(line.billable)),
            unit_cost: String(Number(line.order_unit_cost)),
            line_total: String(Number(line.order_value_for_billable)),
            entry_mode: line.order_entry_mode,
            discount: '',
        })),
        discount_mode: '',
        discount_value: '',
        discount_reference: '',
        discount_reason: '',
    });

    const setLine = (index: number, patch: Partial<BillLineDraft>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    // Last-edited of price and total wins; the other follows (purchase-line.ts).
    const typeQuantity = (index: number, quantity: string) => {
        const line = data.lines[index];
        setLine(
            index,
            line.entry_mode === 'total'
                ? { quantity, unit_cost: deriveUnitPrice(quantity, line.line_total) }
                : { quantity, line_total: deriveLineTotal(quantity, line.unit_cost) },
        );
    };
    const typeUnitPrice = (index: number, unit_cost: string) =>
        setLine(index, { unit_cost, entry_mode: 'rate', line_total: deriveLineTotal(data.lines[index].quantity, unit_cost) });
    const typeLineTotal = (index: number, line_total: string) =>
        setLine(index, { line_total, entry_mode: 'total', unit_cost: deriveUnitPrice(data.lines[index].quantity, line_total) });

    // ⚠⚠ D-SB-2 made visible BEFORE saving. The variance is the gap between
    // what the order agreed and what the supplier has invoiced, and somebody
    // holding the paper should see it at the moment they type it, not discover
    // it in a management report next month.
    // ⭐ 17 Oct 2026: total against total, in paisa, the way the server
    // compares them (PurchaseOrderLine::valueOf): no rounded rate in between.
    const lineVariancePaisa = (index: number): number => {
        const line = order.lines[index];
        const entry = data.lines[index];

        if (!line || !entry) {
            return 0;
        }

        const billed = linePaisaOf(entry);
        const agreed = orderValuePaisa(
            {
                quantity_ordered: line.quantity_ordered,
                unit_cost: line.order_unit_cost,
                line_total: line.order_line_total,
                entry_mode: line.order_entry_mode,
            },
            entry.quantity,
        );

        return billed === null || agreed === null ? 0 : billed - agreed;
    };

    const variance = data.lines.reduce((carry, _line, index) => carry + lineVariancePaisa(index), 0);
    const billTotal = data.lines.reduce((carry, line) => carry + (linePaisaOf(line) ?? 0), 0);

    // ⭐ Supplier discounts (ruling P3): what the typed trade discount comes to,
    // the way the server works it out (a percentage rounded half up to the paisa).
    const paisaOf = (value: string) => Math.round((Number(value) || 0) * 100);
    const discountPaisa =
        data.discount_mode === 'amount'
            ? paisaOf(data.discount_value)
            : data.discount_mode === 'percent'
              ? Math.round((billTotal * (Number(data.discount_value) || 0)) / 100)
              : data.discount_mode === 'lines'
                ? data.lines.reduce((carry, line) => carry + paisaOf(line.discount), 0)
                : 0;
    const perLine = data.discount_mode === 'lines';

    // ── Supplier advances (ruling P2): proposed up to what the bill owes, this
    // order's own advances first (the server sends them in that order).
    // ⚠ The trade discount is taken first (same transaction), so an advance
    // applies only to what the bill still owes after it.
    const billPayable = Math.max(0, billTotal - discountPaisa);
    const [advanceAmounts, setAdvanceAmounts] = useState<Record<string, string>>(() => proposeUpTo(advances, billPayable));
    const advanceTotal = advances.reduce((sum, row) => sum + toPaisa(advanceAmounts[row.key]), 0);
    // ── end supplier advances

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((payload) => ({ ...payload, advances: advanceRowsToPost(advances, advanceAmounts) }));
        post('/purchasing/bills');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill against ${order.po_number}`} />

            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Record supplier bill against {order.po_number}</h1>
                    <p className="text-muted-foreground text-sm">{order.supplier.name}</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <Label htmlFor="bill_number" required>
                            Supplier's invoice number
                        </Label>
                        <Input
                            id="bill_number"
                            required
                            aria-required
                            value={data.bill_number}
                            onChange={(e) => setData('bill_number', e.target.value)}
                        />
                        <InputError message={errors.bill_number} />
                    </div>
                    <div>
                        <Label htmlFor="bill_date" required>
                            Bill date
                        </Label>
                        <Input
                            id="bill_date"
                            type="date"
                            required
                            aria-required
                            value={data.bill_date}
                            onChange={(e) => setData('bill_date', e.target.value)}
                        />
                        <DateWords value={data.bill_date} />
                        <InputError message={errors.bill_date} />
                    </div>
                    <div>
                        <Label htmlFor="due_date">Due date</Label>
                        <Input id="due_date" type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                        <DateWords value={data.due_date} />
                        {/* D-SB-7: guidance only. The terms are free text and are
                            deliberately never parsed into a date. */}
                        {order.supplier.payment_terms && (
                            <p className="text-muted-foreground mt-1 text-xs">Supplier's terms: {order.supplier.payment_terms}</p>
                        )}
                        <InputError message={errors.due_date} />
                    </div>
                    <div>
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                </div>

                {vat.applies && (
                    <div className="bg-muted/30 grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                        <div className="grid gap-1">
                            <Label>The costs on the supplier&apos;s bill</Label>
                            <select
                                className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                value={data.prices_include_tax ? 'inclusive' : 'exclusive'}
                                onChange={(e) => setData('prices_include_tax', e.target.value === 'inclusive')}
                            >
                                <option value="exclusive">have VAT added on top</option>
                                <option value="inclusive">already include VAT</option>
                            </select>
                        </div>
                        <div className="grid gap-1">
                            <Label>The VAT on this bill</Label>
                            <select
                                className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                value={data.tax_recoverable ? 'yes' : 'no'}
                                onChange={(e) => setData('tax_recoverable', e.target.value === 'yes')}
                            >
                                <option value="yes">can be claimed back (a proper VAT invoice)</option>
                                <option value="no">cannot be claimed back</option>
                            </select>
                        </div>
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Received</th>
                                <th className="p-3 text-right">Returned</th>
                                <th className="p-3 text-right">Billed</th>
                                <th className="p-3 text-right">Billable</th>
                                <th className="p-3 text-right">Order price</th>
                                <th className="w-28 p-3">
                                    {t('purchase_line.quantity')}
                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                        *
                                    </span>
                                </th>
                                {/* ⭐ 17 Oct 2026: price OR total, whichever the
                                    supplier's paper gives; the box typed last is
                                    the truth and the caption says which. */}
                                <th className="w-32 p-3">{t('purchase_line.unit_price')}</th>
                                <th className="w-36 p-3">{t('purchase_line.line_total')}</th>
                                {perLine && <th className="w-28 p-3">Discount</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {order.lines.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                        Nothing on this order is still billable. Receive goods first, or the goods received have already been billed
                                        or returned.
                                    </td>
                                </tr>
                            )}
                            {order.lines.map((line, index) => {
                                const entry = data.lines[index];
                                const lineVariance = lineVariancePaisa(index);

                                return (
                                    <tr key={line.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{line.product}</div>
                                            <div className="text-muted-foreground text-xs">{line.sku}</div>
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_received)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_returned)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_invoiced)}</td>
                                        <td className="p-3 text-right font-medium tabular-nums">{formatQuantity(line.billable)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(line.order_unit_cost)}</td>
                                        <td className="p-3">
                                            <Input
                                                className="w-28 text-right"
                                                aria-label={t('purchase_line.quantity')}
                                                aria-required
                                                value={entry?.quantity ?? ''}
                                                onChange={(e) => typeQuantity(index, e.target.value)}
                                            />
                                            <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                className={`w-32 text-right ${entry?.entry_mode === 'total' ? 'text-muted-foreground' : ''}`}
                                                aria-label={t('purchase_line.unit_price')}
                                                value={entry?.unit_cost ?? ''}
                                                onChange={(e) => typeUnitPrice(index, e.target.value)}
                                            />
                                            <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                className={`w-36 text-right tabular-nums ${entry?.entry_mode === 'rate' ? 'text-muted-foreground' : ''}`}
                                                aria-label={t('purchase_line.line_total')}
                                                value={entry?.line_total ?? ''}
                                                onChange={(e) => typeLineTotal(index, e.target.value)}
                                            />
                                            <p className="text-muted-foreground mt-1 text-xs" data-entry-mode={entry?.entry_mode}>
                                                {entry?.entry_mode === 'total' ? t('purchase_line.total_typed') : t('purchase_line.price_typed')}
                                            </p>
                                            <InputError message={errors[`lines.${index}.line_total` as keyof typeof errors]} />
                                            {lineVariance !== 0 && (
                                                <p className="mt-1 text-xs text-amber-600">
                                                    {t('purchase_line.vs_order', { amount: `${lineVariance > 0 ? '+' : ''}${taka(lineVariance)}` })}
                                                </p>
                                            )}
                                        </td>
                                        {perLine && (
                                            <td className="p-3">
                                                <Input
                                                    className="w-28 text-right tabular-nums"
                                                    aria-label={`Discount on ${line.product ?? 'this line'}`}
                                                    inputMode="decimal"
                                                    value={entry?.discount ?? ''}
                                                    onChange={(e) => setLine(index, { discount: e.target.value })}
                                                />
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground text-xs">{t('purchase_line.hint')}</p>

                <div className="flex flex-col gap-2 rounded-lg border p-4">
                    <div className="flex justify-between text-sm">
                        <span>Bill total</span>
                        <span className="font-semibold tabular-nums">{taka(billTotal)}</span>
                    </div>
                    {variance !== 0 && (
                        <div className="flex justify-between text-sm">
                            <span className="text-amber-600">Purchase price variance ({variance > 0 ? 'unfavourable' : 'favourable'})</span>
                            <span className="font-semibold text-amber-600 tabular-nums">{taka(variance)}</span>
                        </div>
                    )}
                    {discountPaisa > 0 && (
                        <>
                            <div className="flex justify-between text-sm">
                                <span>Trade discount</span>
                                <span className="font-semibold tabular-nums">-{taka(discountPaisa)}</span>
                            </div>
                            <div className="flex justify-between border-t pt-2 text-sm">
                                <span>Owed to the supplier</span>
                                <span className="font-semibold tabular-nums">{taka(billTotal - discountPaisa)}</span>
                            </div>
                        </>
                    )}
                </div>

                {/* ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a discount printed on
                    the supplier's bill lowers what the goods cost (stock on hand, or cost
                    of sales for what is already sold), never other income. */}
                <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-1">
                        <Label htmlFor="discount_mode">Trade discount on the bill</Label>
                        <select
                            id="discount_mode"
                            className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                            value={data.discount_mode}
                            onChange={(e) => setData('discount_mode', e.target.value as DiscountMode)}
                        >
                            <option value="">No discount</option>
                            <option value="amount">An amount (Tk)</option>
                            <option value="percent">A percentage of the goods</option>
                            <option value="lines">Per line</option>
                        </select>
                        <p className="text-muted-foreground text-xs">Lowers the cost of these goods. Shared by value unless typed per line.</p>
                    </div>
                    {(data.discount_mode === 'amount' || data.discount_mode === 'percent') && (
                        <div className="grid gap-1">
                            <Label htmlFor="discount_value" required>
                                {data.discount_mode === 'percent' ? 'Percentage' : 'Amount (Tk)'}
                            </Label>
                            <Input
                                id="discount_value"
                                inputMode="decimal"
                                className="text-right tabular-nums"
                                value={data.discount_value}
                                onChange={(e) => setData('discount_value', e.target.value)}
                            />
                            <InputError message={errors.discount_value} />
                        </div>
                    )}
                    {data.discount_mode !== '' && (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor="discount_reference" required>
                                    Supplier's document number
                                </Label>
                                <Input
                                    id="discount_reference"
                                    placeholder={data.bill_number || 'As printed on the bill'}
                                    value={data.discount_reference}
                                    onChange={(e) => setData('discount_reference', e.target.value)}
                                />
                                <InputError message={errors.discount_reference} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="discount_reason">Note</Label>
                                <Input
                                    id="discount_reason"
                                    value={data.discount_reason}
                                    onChange={(e) => setData('discount_reason', e.target.value)}
                                />
                            </div>
                        </>
                    )}
                    <div className="md:col-span-4">
                        <InputError message={(errors as Record<string, string>).discount} />
                    </div>
                </div>

                {/* ── Supplier advances (ruling P2) ── */}
                {advances.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-sm font-semibold">Apply advances paid to {order.supplier.name}</h2>
                            <button
                                type="button"
                                className="text-muted-foreground text-xs underline"
                                onClick={() => setAdvanceAmounts(proposeUpTo(advances, billPayable))}
                            >
                                Propose again for {taka(billPayable)}
                            </button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            This order&apos;s own advances first, then the oldest. Clear a box to keep that advance for later.
                        </p>
                        <AdvanceRows
                            available={advances}
                            amounts={advanceAmounts}
                            onChange={setAdvanceAmounts}
                            errors={errors as Record<string, string>}
                            purchaseOrderId={order.id}
                        />
                        {advanceTotal > 0 && (
                            <p className={`text-sm ${advanceTotal > billPayable ? 'text-destructive' : 'text-muted-foreground'}`}>
                                {taka(advanceTotal)} of advances applied; the bill will owe {taka(Math.max(0, billPayable - advanceTotal))}.
                            </p>
                        )}
                        <InputError message={(errors as Record<string, string>).advances} />
                    </section>
                )}
                {/* ── end supplier advances ── */}

                {/* C-PUR-E1: where a refused bill says why. */}
                <InputError message={errors.lines} />

                <div>
                    <Button type="submit" disabled={processing || order.lines.length === 0 || advanceTotal > billPayable} size="lg">
                        Record bill
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
