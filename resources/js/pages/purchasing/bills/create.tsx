import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

import { DateWords } from '@/components/form/date-words';
import { Field } from '@/components/form/field';
import InputError from '@/components/input-error';
import { PaymentTermsFields, useTermsLabel } from '@/components/purchasing/payment-terms-fields';
import { AdvanceRows, advanceRowsToPost, type AvailableAdvance, proposeUpTo, toPaisa } from '@/components/purchasing/supplier-advances';
import { SupplierCreditPanel, type SupplierCreditView } from '@/components/purchasing/supplier-credit-panel';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { dueDateFor, type PaymentTerms } from '@/lib/payment-terms';
import { deriveLineTotal, deriveUnitPrice, type EntryMode, linePaisaOf, orderValuePaisa } from '@/lib/purchase-line';
import type { BreadcrumbItem } from '@/types';

/** ⭐ Ruling P5 (19 Oct 2026): one delivery line still to bill (ReceiptBilling). */
type ReceiptLine = {
    goods_receipt_line_id: number;
    purchase_order_line_id: number;
    product: string | null;
    sku: string | null;
    quantity_received: string;
    billable: string;
    quantity_ordered: string;
    order_unit_cost: string;
    order_entry_mode: EntryMode;
    order_line_total: string;
    order_value_for_billable: string;
    /** ⭐ PR1: the order's discount for this quantity and its VAT rate, which the bill opens with. */
    order_discount_for_billable?: string;
    order_tax_rate?: string;
    order_reference?: string | null;
};

type Receipt = {
    id: number;
    received_on: string | null;
    challan: string | null;
    purchase_order_id: number;
    po_number: string | null;
    lines: ReceiptLine[];
};

type GoodsDraft = {
    quantity: string;
    unit_cost: string;
    line_total: string;
    entry_mode: EntryMode;
    /** ⭐ Trade discount typed on this line (ruling P3), when the discount is per line. */
    discount: string;
    /** ⭐ PR1: the VAT rate the order carried (read by the server only while VAT is on). */
    tax_rate: string;
};

type ServiceDraft = { expense_account_id: string; description: string; amount: string };

type CarriageDraft = { goods_receipt_id: string; cost_type: string; amount: string; allocation_method: string; description: string };

/** ⭐ Supplier discounts (ruling P3, 18 Oct 2026): how the bill's trade discount is typed. */
type DiscountMode = '' | 'amount' | 'percent' | 'lines';

type Order = {
    id: number | null;
    po_number: string | null;
    supplier: { id: number; name: string; payment_terms: string | null };
    /** ⭐ Ruling P6: the terms this bill defaults to (the order's own, else the supplier's). */
    terms?: PaymentTerms | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
    { title: 'Record', href: '#' },
];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });
const taka = (paisa: number) => money(paisa / 100);
const paisaOf = (value: string) => Math.round((Number(value) || 0) * 100);

function receiptLabel(receipt: Receipt): string {
    return [receipt.po_number, receipt.challan ? `challan ${receipt.challan}` : `receipt #${receipt.id}`, showDate(receipt.received_on)]
        .filter(Boolean)
        .join(', ');
}

function draftFor(line: ReceiptLine): GoodsDraft {
    // Defaulted to what is still billable on the delivery and to the AGREED
    // figures, so the common case is one click and any change is deliberate.
    return {
        quantity: String(Number(line.billable)),
        unit_cost: String(Number(line.order_unit_cost)),
        line_total: String(Number(line.order_value_for_billable)),
        entry_mode: line.order_entry_mode,
        // ⭐ PR1: the discount agreed on the order, so it is not typed twice.
        discount: Number(line.order_discount_for_billable ?? 0) > 0 ? String(Number(line.order_discount_for_billable)) : '',
        tax_rate: line.order_tax_rate !== undefined && Number(line.order_tax_rate) > 0 ? String(Number(line.order_tax_rate)) : '',
    };
}

/** ⭐ PR1: an order that agreed a discount opens the bill with it, per line. */
function orderDiscountOf(receipts: Receipt[], selected: number[]): { carried: boolean; reference: string } {
    const lines = receipts.filter((r) => selected.includes(r.id)).flatMap((r) => r.lines.map((line) => ({ receipt: r, line })));
    const carried = lines.some(({ line }) => Number(line.order_discount_for_billable ?? 0) > 0);
    const first = lines.find(({ line }) => Number(line.order_discount_for_billable ?? 0) > 0);

    return { carried, reference: first ? (first.line.order_reference ?? first.receipt.po_number ?? '') : '' };
}

export default function RecordBill({
    order,
    vat,
    advances = [],
    receipts = [],
    selected_receipt_ids = [],
    expense_accounts = [],
    cost_types = [],
    carriage_receipts = [],
    credit,
}: {
    order: Order;
    vat: { applies: boolean };
    advances?: AvailableAdvance[];
    receipts?: Receipt[];
    selected_receipt_ids?: number[];
    expense_accounts?: { id: number; label: string }[];
    cost_types?: { value: string; label: string; default_treatment: string }[];
    carriage_receipts?: { id: number; label: string }[];
    credit?: SupplierCreditView;
}) {
    const { t } = useTranslation();
    const termsLabel = useTermsLabel();
    const supplier = order.supplier;

    // ── Ruling P5: which deliveries this bill covers ──────────────────────
    const [selected, setSelected] = useState<number[]>(() =>
        selected_receipt_ids.length > 0 ? selected_receipt_ids : receipts.length === 1 ? [receipts[0].id] : [],
    );
    const [goods, setGoods] = useState<Record<number, GoodsDraft>>(() =>
        Object.fromEntries(receipts.flatMap((r) => r.lines.map((line) => [line.goods_receipt_line_id, draftFor(line)]))),
    );
    const [picking, setPicking] = useState(false);
    const [pickDraft, setPickDraft] = useState<number[]>([]);
    const rows = receipts.filter((r) => selected.includes(r.id)).flatMap((receipt) => receipt.lines.map((line) => ({ receipt, line })));

    // ── Ruling P5: service lines and carriage ─────────────────────────────
    const [services, setServices] = useState<ServiceDraft[]>([]);
    const [carriage, setCarriage] = useState<CarriageDraft[]>([]);
    const [serviceDialog, setServiceDialog] = useState(false);
    const [carriageDialog, setCarriageDialog] = useState(false);
    const blankService: ServiceDraft = { expense_account_id: '', description: '', amount: '' };
    const [serviceDraft, setServiceDraft] = useState<ServiceDraft>(blankService);
    const blankCarriage = (): CarriageDraft => ({
        goods_receipt_id: String(selected[0] ?? carriage_receipts[0]?.id ?? ''),
        cost_type: 'freight',
        amount: '',
        allocation_method: 'value',
        description: '',
    });
    const [carriageDraft, setCarriageDraft] = useState<CarriageDraft>(blankCarriage);

    const { data, setData, post, errors, processing, transform } = useForm<{
        purchase_order_id: number | null;
        supplier_id: number;
        bill_number: string;
        bill_date: string;
        due_date: string;
        note: string;
        prices_include_tax: boolean;
        tax_recoverable: boolean;
        discount_mode: DiscountMode;
        discount_value: string;
        discount_reference: string;
        discount_reason: string;
        duplicate_reason: string;
        terms_type: string;
        terms_days: string;
        due_date_reason: string;
    }>({
        purchase_order_id: order.id,
        supplier_id: supplier.id,
        bill_number: '',
        bill_date: dhakaToday(),
        due_date: '',
        note: '',
        // VAT plan D7. Sent always, read by the server only while VAT is on.
        prices_include_tax: false,
        tax_recoverable: true,
        discount_mode: orderDiscountOf(receipts, selected).carried ? 'lines' : '',
        discount_value: '',
        discount_reference: orderDiscountOf(receipts, selected).reference,
        discount_reason: orderDiscountOf(receipts, selected).carried ? 'Discount agreed on the purchase order' : '',
        duplicate_reason: '',
        // ⭐ Ruling P6: blank means "as agreed" (the order's or supplier's terms).
        terms_type: '',
        terms_days: '',
        due_date_reason: '',
    });

    // ⭐ Ruling P6: the due date the terms give, worked out as the server will;
    // a different date, or different terms, is an override and needs a reason.
    const agreed = order.terms ?? null;
    const effectiveType = data.terms_type || agreed?.type || '';
    const effectiveDays = data.terms_type ? data.terms_days : agreed?.days === null || agreed?.days === undefined ? '' : String(agreed.days);
    const termsDue = dueDateFor(effectiveType, effectiveDays, data.bill_date);
    const termsChanged =
        agreed !== null && data.terms_type !== '' && (data.terms_type !== agreed?.type || Number(data.terms_days || 0) !== (agreed?.days ?? 0));
    const dateChanged = data.due_date !== '' && termsDue !== null && data.due_date !== termsDue;
    const needsReason = termsChanged || dateChanged;
    const fieldError = (key: string) => (errors as Record<string, string>)[key];

    const setGood = (id: number, patch: Partial<GoodsDraft>) => setGoods((all) => ({ ...all, [id]: { ...all[id], ...patch } }));

    // Last-edited of price and total wins; the other follows (purchase-line.ts).
    const typeQuantity = (id: number, quantity: string) => {
        const line = goods[id];
        setGood(
            id,
            line.entry_mode === 'total'
                ? { quantity, unit_cost: deriveUnitPrice(quantity, line.line_total) }
                : { quantity, line_total: deriveLineTotal(quantity, line.unit_cost) },
        );
    };
    const typeUnitPrice = (id: number, unit_cost: string) =>
        setGood(id, { unit_cost, entry_mode: 'rate', line_total: deriveLineTotal(goods[id].quantity, unit_cost) });
    const typeLineTotal = (id: number, line_total: string) =>
        setGood(id, { line_total, entry_mode: 'total', unit_cost: deriveUnitPrice(goods[id].quantity, line_total) });

    // ⚠⚠ D-SB-2 made visible BEFORE saving: the gap between what the order
    // agreed and what the supplier invoiced, total against total, in paisa.
    const lineVariancePaisa = (line: ReceiptLine): number => {
        const entry = goods[line.goods_receipt_line_id];

        if (!entry) {
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

    const goodsTotal = rows.reduce((carry, { line }) => carry + (linePaisaOf(goods[line.goods_receipt_line_id]) ?? 0), 0);
    const variance = rows.reduce((carry, { line }) => carry + lineVariancePaisa(line), 0);
    const servicesTotal = services.reduce((carry, row) => carry + paisaOf(row.amount), 0);
    const carriageTotal = carriage.reduce((carry, row) => carry + paisaOf(row.amount), 0);
    const billTotal = goodsTotal + servicesTotal;

    // ⭐ Supplier discounts (ruling P3): what the typed trade discount comes to,
    // the way the server works it out (a percentage of the GOODS, half up).
    const discountPaisa =
        data.discount_mode === 'amount'
            ? paisaOf(data.discount_value)
            : data.discount_mode === 'percent'
              ? Math.round((goodsTotal * (Number(data.discount_value) || 0)) / 100)
              : data.discount_mode === 'lines'
                ? rows.reduce((carry, { line }) => carry + paisaOf(goods[line.goods_receipt_line_id]?.discount ?? ''), 0)
                : 0;
    const perLine = data.discount_mode === 'lines';

    // ── Supplier advances (ruling P2): proposed up to what the bill owes.
    const billPayable = Math.max(0, billTotal - discountPaisa);
    const [advanceAmounts, setAdvanceAmounts] = useState<Record<string, string>>(() => proposeUpTo(advances, billPayable));
    const advanceTotal = advances.reduce((sum, row) => sum + toPaisa(advanceAmounts[row.key]), 0);
    // ── end supplier advances

    // ⭐ Ruling P5: the bill names the order it was opened from only while
    // every goods line is that order's; otherwise it is the supplier's bill.
    const billedRows = rows.filter(({ line }) => Number(goods[line.goods_receipt_line_id]?.quantity) > 0);
    const singleOrder = order.id !== null && billedRows.every(({ receipt }) => receipt.purchase_order_id === order.id);
    const nothingToBill = billedRows.length === 0 && services.length === 0 && carriage.length === 0;

    const [duplicateOpen, setDuplicateOpen] = useState(false);

    const send = (duplicateReason: string) => {
        transform((payload) => ({
            ...payload,
            purchase_order_id: singleOrder ? order.id : null,
            duplicate_reason: duplicateReason,
            lines: rows.map(({ line }) => ({
                purchase_order_line_id: line.purchase_order_line_id,
                goods_receipt_line_id: line.goods_receipt_line_id,
                ...goods[line.goods_receipt_line_id],
            })),
            services,
            carriage,
            advances: advanceRowsToPost(advances, advanceAmounts),
        }));
        post('/purchasing/bills', {
            preserveScroll: true,
            onError: (errs) => setDuplicateOpen(Boolean((errs as Record<string, string>).duplicate)),
        });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send('');
    };

    const title = order.po_number ? `Record supplier bill: ${order.po_number}` : `Record supplier bill: ${supplier.name}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    <p className="text-muted-foreground text-sm">{supplier.name}</p>
                </div>

                <div className="flex flex-wrap items-start gap-4">
                    <div className="grid gap-1">
                        <Label htmlFor="bill_number" required>
                            Supplier's invoice number
                        </Label>
                        <Input
                            id="bill_number"
                            className="w-56"
                            required
                            aria-required
                            value={data.bill_number}
                            onChange={(e) => setData('bill_number', e.target.value)}
                        />
                        <InputError message={errors.bill_number} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="bill_date" required>
                            Bill date
                        </Label>
                        <Input
                            id="bill_date"
                            type="date"
                            className="w-auto"
                            required
                            aria-required
                            value={data.bill_date}
                            onChange={(e) => setData('bill_date', e.target.value)}
                        />
                        <DateWords value={data.bill_date} />
                        <InputError message={errors.bill_date} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="due_date">Due date</Label>
                        <Input
                            id="due_date"
                            type="date"
                            className="w-auto"
                            value={data.due_date}
                            onChange={(e) => setData('due_date', e.target.value)}
                        />
                        <DateWords value={data.due_date} />
                        {/* ⭐ Ruling P6: the date the terms give. Left blank, the bill
                            keeps this date; it is stored and never recomputed. */}
                        {termsDue && (
                            <p className="text-muted-foreground mt-1 text-xs" data-terms-due={termsDue}>
                                {t('terms.due_on', { date: showDate(termsDue) })} {data.due_date === '' && t('terms.due_blank')}
                            </p>
                        )}
                        {supplier.payment_terms && (
                            <p className="text-muted-foreground mt-1 text-xs">
                                {t('terms.note')}: {supplier.payment_terms}
                            </p>
                        )}
                        <InputError message={errors.due_date} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" className="w-72" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                </div>

                {/* ⭐ Ruling P6: this bill's terms, "as agreed" unless the supplier's
                    paper says otherwise; any change needs a reason. */}
                <div className="flex flex-wrap items-start gap-3">
                    <PaymentTermsFields
                        idPrefix="bill"
                        label={t('terms.bill_terms')}
                        type={data.terms_type}
                        days={data.terms_days}
                        onType={(value) => setData('terms_type', value)}
                        onDays={(value) => setData('terms_days', value)}
                        defaultLabel={agreed ? t('terms.suppliers_named', { terms: termsLabel(agreed.type, agreed.days) ?? '' }) : t('terms.not_set')}
                        typeError={errors.terms_type}
                        daysError={errors.terms_days}
                    />
                    {(needsReason || errors.due_date_reason) && (
                        <Field
                            label={t('terms.due_reason')}
                            htmlFor="due_date_reason"
                            size="grow"
                            required
                            hint={t('terms.due_reason_hint')}
                            error={errors.due_date_reason}
                        >
                            <Input
                                id="due_date_reason"
                                aria-required
                                value={data.due_date_reason}
                                onChange={(e) => setData('due_date_reason', e.target.value)}
                            />
                        </Field>
                    )}
                </div>

                {credit && <SupplierCreditPanel credit={credit} context="bill" />}

                {vat.applies && (
                    <div className="bg-muted/30 flex flex-wrap gap-4 rounded-lg border p-4">
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

                {/* ── Ruling P5: the deliveries (challans) this bill covers ── */}
                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('bill_flex.deliveries')}</h2>
                        {receipts.length > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setPickDraft(selected);
                                    setPicking(true);
                                }}
                            >
                                {t('bill_flex.add_deliveries')}
                            </Button>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">{t('bill_flex.delivery')}</th>
                                    <th className="p-3">Product</th>
                                    <th className="p-3 text-right">Received</th>
                                    <th className="p-3 text-right">Billable</th>
                                    <th className="p-3 text-right">Order price</th>
                                    <th className="w-28 p-3">
                                        {t('purchase_line.quantity')}
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-32 p-3">{t('purchase_line.unit_price')}</th>
                                    <th className="w-36 p-3">{t('purchase_line.line_total')}</th>
                                    {perLine && <th className="w-28 p-3">Discount</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                            {receipts.length === 0 ? t('bill_flex.no_deliveries') : 'Pick the deliveries this bill covers.'}
                                        </td>
                                    </tr>
                                )}
                                {rows.map(({ receipt, line }, index) => {
                                    const id = line.goods_receipt_line_id;
                                    const entry = goods[id];
                                    const lineVariance = lineVariancePaisa(line);

                                    return (
                                        <tr key={id} className="border-t align-top">
                                            <td className="p-3 text-xs">{receiptLabel(receipt)}</td>
                                            <td className="p-3">
                                                <div className="font-medium">{line.product}</div>
                                                <div className="text-muted-foreground text-xs">{line.sku}</div>
                                            </td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_received)}</td>
                                            <td className="p-3 text-right font-medium tabular-nums">{formatQuantity(line.billable)}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(line.order_unit_cost)}</td>
                                            <td className="p-3">
                                                <Input
                                                    className="w-28 text-right"
                                                    aria-label={t('purchase_line.quantity')}
                                                    aria-required
                                                    value={entry?.quantity ?? ''}
                                                    onChange={(e) => typeQuantity(id, e.target.value)}
                                                />
                                                <InputError message={fieldError(`lines.${index}.quantity`)} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    className={`w-32 text-right ${entry?.entry_mode === 'total' ? 'text-muted-foreground' : ''}`}
                                                    aria-label={t('purchase_line.unit_price')}
                                                    value={entry?.unit_cost ?? ''}
                                                    onChange={(e) => typeUnitPrice(id, e.target.value)}
                                                />
                                                <InputError message={fieldError(`lines.${index}.unit_cost`)} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    className={`w-36 text-right tabular-nums ${entry?.entry_mode === 'rate' ? 'text-muted-foreground' : ''}`}
                                                    aria-label={t('purchase_line.line_total')}
                                                    value={entry?.line_total ?? ''}
                                                    onChange={(e) => typeLineTotal(id, e.target.value)}
                                                />
                                                <p className="text-muted-foreground mt-1 text-xs" data-entry-mode={entry?.entry_mode}>
                                                    {entry?.entry_mode === 'total' ? t('purchase_line.total_typed') : t('purchase_line.price_typed')}
                                                </p>
                                                <InputError message={fieldError(`lines.${index}.line_total`)} />
                                                {lineVariance !== 0 && (
                                                    <p className="mt-1 text-xs text-amber-600">
                                                        {t('purchase_line.vs_order', {
                                                            amount: `${lineVariance > 0 ? '+' : ''}${taka(lineVariance)}`,
                                                        })}
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
                                                        onChange={(e) => setGood(id, { discount: e.target.value })}
                                                    />
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('purchase_line.hint')} Set a quantity to 0 to leave that line off this bill.</p>
                </section>

                {/* ── Ruling P5: services and other non-PO lines ── */}
                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('bill_flex.services')}</h2>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setServiceDraft(blankService);
                                setServiceDialog(true);
                            }}
                        >
                            {t('bill_flex.add_service')}
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('bill_flex.services_hint')}</p>
                    {services.length > 0 && (
                        <ul className="flex flex-col divide-y rounded-lg border text-sm">
                            {services.map((row, index) => (
                                <li key={index} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <span>
                                        <span className="font-medium">{row.description}</span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({expense_accounts.find((a) => String(a.id) === row.expense_account_id)?.label})
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <span className="tabular-nums">{formatMoney(row.amount)}</span>
                                        <button
                                            type="button"
                                            className="text-muted-foreground text-xs underline"
                                            onClick={() => setServices(services.filter((_, i) => i !== index))}
                                        >
                                            {t('bill_flex.remove')}
                                        </button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {/* ── Ruling P5: carriage through landed cost ── */}
                <section className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('bill_flex.carriage')}</h2>
                        {carriage_receipts.length > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setCarriageDraft(blankCarriage());
                                    setCarriageDialog(true);
                                }}
                            >
                                {t('bill_flex.add_carriage')}
                            </Button>
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs">{t('bill_flex.carriage_hint')}</p>
                    {carriage.length > 0 && (
                        <ul className="flex flex-col divide-y rounded-lg border text-sm">
                            {carriage.map((row, index) => (
                                <li key={index} className="flex flex-wrap items-center justify-between gap-3 p-3">
                                    <span>
                                        <span className="font-medium">
                                            {cost_types.find((c) => c.value === row.cost_type)?.label ?? row.cost_type}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            on {carriage_receipts.find((r) => String(r.id) === row.goods_receipt_id)?.label}
                                            {row.description ? `: ${row.description}` : ''}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <span className="tabular-nums">{formatMoney(row.amount)}</span>
                                        <button
                                            type="button"
                                            className="text-muted-foreground text-xs underline"
                                            onClick={() => setCarriage(carriage.filter((_, i) => i !== index))}
                                        >
                                            {t('bill_flex.remove')}
                                        </button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className="flex w-fit min-w-80 flex-col gap-2 rounded-lg border p-4">
                    {servicesTotal > 0 && (
                        <>
                            <div className="flex justify-between gap-6 text-sm">
                                <span>Goods</span>
                                <span className="tabular-nums">{taka(goodsTotal)}</span>
                            </div>
                            <div className="flex justify-between gap-6 text-sm">
                                <span>Services</span>
                                <span className="tabular-nums">{taka(servicesTotal)}</span>
                            </div>
                        </>
                    )}
                    <div className="flex justify-between gap-6 text-sm">
                        <span>Bill total</span>
                        <span className="font-semibold tabular-nums">{taka(billTotal)}</span>
                    </div>
                    {variance !== 0 && (
                        <div className="flex justify-between gap-6 text-sm">
                            <span className="text-amber-600">Purchase price variance ({variance > 0 ? 'unfavourable' : 'favourable'})</span>
                            <span className="font-semibold text-amber-600 tabular-nums">{taka(variance)}</span>
                        </div>
                    )}
                    {discountPaisa > 0 && (
                        <>
                            <div className="flex justify-between gap-6 text-sm">
                                <span>Trade discount</span>
                                <span className="font-semibold tabular-nums">-{taka(discountPaisa)}</span>
                            </div>
                            <div className="flex justify-between gap-6 border-t pt-2 text-sm">
                                <span>Owed to the supplier</span>
                                <span className="font-semibold tabular-nums">{taka(billTotal - discountPaisa)}</span>
                            </div>
                        </>
                    )}
                    {carriageTotal > 0 && (
                        <div className="text-muted-foreground flex justify-between gap-6 text-sm">
                            <span>Carriage (to Landed costs, owed separately)</span>
                            <span className="tabular-nums">{taka(carriageTotal)}</span>
                        </div>
                    )}
                </div>

                {/* ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a discount printed on
                    the supplier's bill lowers what the goods cost (stock on hand, or cost
                    of sales for what is already sold), never other income. */}
                <div className="flex flex-wrap items-start gap-4 rounded-lg border p-4">
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
                                className="w-32 text-right tabular-nums"
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
                                    className="w-56"
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
                                    className="w-72"
                                    value={data.discount_reason}
                                    onChange={(e) => setData('discount_reason', e.target.value)}
                                />
                            </div>
                        </>
                    )}
                    <InputError message={fieldError('discount')} />
                </div>

                {/* ── Supplier advances (ruling P2) ── */}
                {advances.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-sm font-semibold">Apply advances paid to {supplier.name}</h2>
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
                        <InputError message={fieldError('advances')} />
                    </section>
                )}
                {/* ── end supplier advances ── */}

                {/* C-PUR-E1: where a refused bill says why. */}
                <InputError message={fieldError('lines') ?? fieldError('services') ?? fieldError('carriage')} />

                <div>
                    <Button type="submit" disabled={processing || nothingToBill || advanceTotal > billPayable} size="lg">
                        Record bill
                    </Button>
                </div>
            </form>

            {/* ── Ruling P5 popups ── */}
            <Dialog open={picking} onOpenChange={setPicking}>
                <DialogContent className="sm:max-w-2xl" aria-describedby={undefined} confirmDiscard={false}>
                    <DialogHeader>
                        <DialogTitle>{t('bill_flex.add_deliveries')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('bill_flex.pick_deliveries_hint', { supplier: supplier.name })}</p>
                    <ul className="flex flex-col divide-y rounded-lg border text-sm">
                        {receipts.map((receipt) => (
                            <li key={receipt.id} className="flex items-start gap-3 p-3">
                                <Checkbox
                                    id={`receipt-${receipt.id}`}
                                    checked={pickDraft.includes(receipt.id)}
                                    onCheckedChange={(checked) =>
                                        setPickDraft(checked ? [...pickDraft, receipt.id] : pickDraft.filter((id) => id !== receipt.id))
                                    }
                                />
                                <label htmlFor={`receipt-${receipt.id}`} className="flex flex-col">
                                    <span className="font-medium">{receiptLabel(receipt)}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {receipt.lines.map((line) => `${line.product ?? 'Item'} ${formatQuantity(line.billable)}`).join(', ')}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setPicking(false)}>
                            {t('bill_flex.cancel')}
                        </Button>
                        <Button
                            type="button"
                            onClick={() => {
                                setSelected(pickDraft);
                                setPicking(false);
                            }}
                        >
                            {t('bill_flex.add')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={serviceDialog} onOpenChange={setServiceDialog}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('bill_flex.add_service')}</DialogTitle>
                    </DialogHeader>
                    <div className="flex flex-col gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="service_account" required>
                                {t('bill_flex.expense_head')}
                            </Label>
                            <SearchSelect
                                fit
                                id="service_account"
                                value={serviceDraft.expense_account_id}
                                onChange={(e) => setServiceDraft({ ...serviceDraft, expense_account_id: e.target.value })}
                            >
                                <option value="">Select a head</option>
                                {expense_accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="service_description" required>
                                {t('bill_flex.description')}
                            </Label>
                            <Input
                                id="service_description"
                                className="w-72"
                                value={serviceDraft.description}
                                onChange={(e) => setServiceDraft({ ...serviceDraft, description: e.target.value })}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="service_amount" required>
                                {t('bill_flex.amount')}
                            </Label>
                            <Input
                                id="service_amount"
                                inputMode="decimal"
                                className="w-36 text-right tabular-nums"
                                value={serviceDraft.amount}
                                onChange={(e) => setServiceDraft({ ...serviceDraft, amount: e.target.value })}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setServiceDialog(false)}>
                            {t('bill_flex.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={!serviceDraft.expense_account_id || !serviceDraft.description.trim() || !(Number(serviceDraft.amount) > 0)}
                            onClick={() => {
                                setServices([...services, serviceDraft]);
                                setServiceDialog(false);
                            }}
                        >
                            {t('bill_flex.add')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={carriageDialog} onOpenChange={setCarriageDialog}>
                <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('bill_flex.add_carriage')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-muted-foreground text-sm">{t('bill_flex.carriage_hint')}</p>
                    <div className="flex flex-col gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="carriage_receipt" required>
                                {t('bill_flex.delivery')}
                            </Label>
                            <SearchSelect
                                fit
                                id="carriage_receipt"
                                value={carriageDraft.goods_receipt_id}
                                onChange={(e) => setCarriageDraft({ ...carriageDraft, goods_receipt_id: e.target.value })}
                            >
                                {carriage_receipts.map((receipt) => (
                                    <option key={receipt.id} value={receipt.id}>
                                        {receipt.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="flex flex-wrap gap-4">
                            <div className="grid gap-1">
                                <Label htmlFor="carriage_type" required>
                                    {t('bill_flex.cost_type')}
                                </Label>
                                <select
                                    id="carriage_type"
                                    className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                    value={carriageDraft.cost_type}
                                    onChange={(e) => setCarriageDraft({ ...carriageDraft, cost_type: e.target.value })}
                                >
                                    {cost_types
                                        .filter((type) => type.value !== 'other')
                                        .map((type) => (
                                            <option key={type.value} value={type.value}>
                                                {type.label}
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="carriage_spread">{t('bill_flex.spread')}</Label>
                                <select
                                    id="carriage_spread"
                                    className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                    value={carriageDraft.allocation_method}
                                    onChange={(e) => setCarriageDraft({ ...carriageDraft, allocation_method: e.target.value })}
                                >
                                    <option value="value">{t('bill_flex.spread_value')}</option>
                                    <option value="quantity">{t('bill_flex.spread_quantity')}</option>
                                    <option value="weight">{t('bill_flex.spread_weight')}</option>
                                </select>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="carriage_amount" required>
                                    {t('bill_flex.amount')}
                                </Label>
                                <Input
                                    id="carriage_amount"
                                    inputMode="decimal"
                                    className="w-36 text-right tabular-nums"
                                    value={carriageDraft.amount}
                                    onChange={(e) => setCarriageDraft({ ...carriageDraft, amount: e.target.value })}
                                />
                            </div>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="carriage_description">{t('bill_flex.description')}</Label>
                            <Input
                                id="carriage_description"
                                className="w-72"
                                value={carriageDraft.description}
                                onChange={(e) => setCarriageDraft({ ...carriageDraft, description: e.target.value })}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setCarriageDialog(false)}>
                            {t('bill_flex.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={!carriageDraft.goods_receipt_id || !(Number(carriageDraft.amount) > 0)}
                            onClick={() => {
                                setCarriage([...carriage, carriageDraft]);
                                setCarriageDialog(false);
                            }}
                        >
                            {t('bill_flex.add')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={duplicateOpen} onOpenChange={setDuplicateOpen}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('bill_flex.duplicate_title')}</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm">{fieldError('duplicate')}</p>
                    <div className="grid gap-1">
                        <Label htmlFor="duplicate_reason" required>
                            {t('bill_flex.duplicate_reason')}
                        </Label>
                        <textarea
                            id="duplicate_reason"
                            rows={3}
                            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            value={data.duplicate_reason}
                            onChange={(e) => setData('duplicate_reason', e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setDuplicateOpen(false)}>
                            {t('bill_flex.cancel')}
                        </Button>
                        <Button
                            type="button"
                            disabled={processing || data.duplicate_reason.trim().length < 3}
                            onClick={() => send(data.duplicate_reason)}
                        >
                            {t('bill_flex.record_anyway')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
