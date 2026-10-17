import { Hint, Pill, type Tone } from '@/components/assets/assets-ui';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn, formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Warehouse } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

/**
 * ⚠⚠ The unit a purchase quantity is TYPED IN. For a product bought in one unit
 * and stocked in another this is the PURCHASE unit, because the order, the
 * receipt, the supplier bill and the return all stay in the unit the supplier
 * invoices. Typing the stocked figure instead is a ~9% error that balances
 * everywhere downstream, so the box has to say which one it wants.
 */
const purchaseUnitOf = (line: Line): string | null => line.product.purchase_unit?.abbreviation ?? line.product.unit_of_measure?.abbreviation ?? null;

/**
 * One display row per ORDER LINE, summing the per-cost-layer rows behind it.
 * ⚠ The stored rows stay one-per-batch - that is what lets the journal value
 * every layer a return empties. This is presentation only.
 */
const aggregateReturnLines = (
    lines: { id: number; quantity_returned: string; purchase_order_line: { id: number; product: { name: string; sku: string } } }[],
) => {
    const byLine = new Map<number, { key: string; name: string; sku: string; quantity: number; batches: number }>();

    for (const line of lines) {
        const id = line.purchase_order_line.id;
        const existing = byLine.get(id);

        if (existing) {
            existing.quantity += parseFloat(line.quantity_returned);
            existing.batches += 1;
        } else {
            byLine.set(id, {
                key: `${id}`,
                name: line.purchase_order_line.product.name,
                sku: line.purchase_order_line.product.sku,
                quantity: parseFloat(line.quantity_returned),
                batches: 1,
            });
        }
    }

    return [...byLine.values()].map((e) => ({ ...e, quantity: String(Number(e.quantity.toFixed(4))) }));
};

interface Line {
    id: number;
    product: {
        id: number;
        name: string;
        sku: string;
        type: 'raw_material' | 'finished_good' | 'service';
        tracks_expiry?: boolean;
        shelf_life_warning_days?: number | null;
        unit_of_measure?: { abbreviation: string } | null;
        purchase_unit?: { abbreviation: string } | null;
    };
    quantity_ordered: string;
    quantity_received: string;
    quantity_returned: string;
    quantity_invoiced?: string;
    unit_cost: string;
}

/**
 * A receipt as the server shapes it: dates already formatted in Dhaka, and each
 * line's label data read from the stock batch it created (where FEFO reads it).
 */
interface Receipt {
    id: number;
    received_by: string | null;
    received_at: string | null;
    supplier_challan_number: string | null;
    note: string | null;
    lines: {
        id: number;
        product: string | null;
        sku: string | null;
        quantity_received: string;
        /** The batch this line made, for its trace page (17 Oct 2026). */
        batch_id: number | null;
        batch_number: string | null;
        manufactured_date: string | null;
        expiry_date: string | null;
    }[];
}

interface BillRow {
    id: number;
    bill_number: string;
    bill_date: string | null;
    total: string;
    outstanding: string;
    is_reversed: boolean;
}

/** Derived from the order's supplier bills; see PurchaseOrderBilling. */
interface Billing {
    state: 'nothing_billed' | 'owed' | 'settled';
    billed: string;
    outstanding: string;
}

interface LastCost {
    unit_cost: string;
    po_number: string | null;
    received_on: string | null;
}

interface Return {
    id: number;
    returned_by: { name: string };
    returned_at: string;
    note: string | null;
    lines: { id: number; quantity_returned: string; purchase_order_line: { id: number; product: { name: string; sku: string } } }[];
}

interface Order {
    id: number;
    po_number: string;
    status: 'open' | 'partially_received' | 'received' | 'cancelled';
    expected_date: string | null;
    note: string | null;
    created_at?: string | null;
    supplier: { id?: number; name: string; contact_name: string | null; phone: string | null; payment_terms: string | null };
    warehouse: { name: string };
    created_by: { name: string } | null;
    approval_request: {
        status: 'pending' | 'approved' | 'rejected' | 'returned';
        requested_by: { name: string } | null;
        reviewed_by: { name: string } | null;
        review_note: string | null;
    };
    lines: Line[];
    returns: Return[];
}

const STATUS: Record<Order['status'], { label: string; tone: Tone }> = {
    open: { label: 'Open', tone: 'muted' },
    partially_received: { label: 'Partly received', tone: 'warning' },
    received: { label: 'Received', tone: 'ok' },
    cancelled: { label: 'Cancelled', tone: 'danger' },
};

const APPROVAL: Record<Order['approval_request']['status'], { label: string; tone: Tone }> = {
    pending: { label: 'Awaiting approval', tone: 'warning' },
    approved: { label: 'Approved', tone: 'ok' },
    rejected: { label: 'Rejected', tone: 'danger' },
    returned: { label: 'Sent back', tone: 'warning' },
};

function remaining(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_ordered) - parseFloat(line.quantity_received));
}

function returnable(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_received) - parseFloat(line.quantity_returned));
}

function billable(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_received) - parseFloat(line.quantity_returned) - parseFloat(line.quantity_invoiced ?? '0'));
}

function daysBetween(fromIso: string, toIso: string): number {
    const from = new Date(fromIso.slice(0, 10) + 'T00:00:00').getTime();
    const to = new Date(toIso.slice(0, 10) + 'T00:00:00').getTime();

    return Math.round((to - from) / 86_400_000);
}

type ReceiveLine = {
    purchase_order_line_id: number;
    quantity: string;
    batch_number: string;
    manufactured_date: string;
    expiry_date: string;
};

const blankReceiveLine = (line: Line): ReceiveLine => ({
    purchase_order_line_id: line.id,
    quantity: String(remaining(line)),
    batch_number: '',
    manufactured_date: '',
    expiry_date: '',
});

const inputClass = 'border-input bg-background h-9 w-full rounded-md border px-2.5 text-sm';

/**
 * Step 3 of the chain, the guided Receive goods step (Purchases redesign,
 * 15 Oct 2026). Same fields, same names, same route as before
 * (PurchaseOrderController@receive); only the shape changed: the warehouse
 * is shown fixed from the order, the earlier-day case is a checkbox that
 * reveals the actual date and its reason, and every line is one table row
 * with "required" only where the product tracks expiry.
 */
function ReceiveGoodsStep({ order, today, onClose }: { order: Order; today: string; onClose: () => void }) {
    const { t } = useTranslation();
    const receivableLines = order.lines.filter((line) => remaining(line) > 0);
    const [earlier, setEarlier] = useState(false);
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        supplier_challan_number: string;
        received_on: string;
        backdate_reason: string;
        note: string;
        lines: ReceiveLine[];
    }>({
        supplier_challan_number: '',
        received_on: today,
        backdate_reason: '',
        note: '',
        lines: [],
    });

    // Untouched lines default to the full remaining quantity and no label data -
    // computed here (not pre-seeded into `lines`) so it always reflects the
    // current order prop rather than whatever it was when this component first
    // mounted.
    // ⚠ The ORDER of this array is the order of receivableLines, and the server
    // keys every per-line error by that index (`lines.2.expiry_date`). The form
    // renders errors by the same index, so the two must never be sorted apart.
    transform((formData) => ({
        ...formData,
        // ⚠ The checkbox off means "today": the date box under it is ignored.
        received_on: earlier ? formData.received_on : today,
        backdate_reason: earlier ? formData.backdate_reason : '',
        lines: receivableLines.map((line) => formData.lines.find((entry) => entry.purchase_order_line_id === line.id) ?? blankReceiveLine(line)),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.purchase-orders.receive', order.id), { onSuccess: () => reset() });
    };

    const entryFor = (line: Line): ReceiveLine => data.lines.find((entry) => entry.purchase_order_line_id === line.id) ?? blankReceiveLine(line);

    // Upserts rather than only mapping existing entries - this component can
    // mount from before anything was receivable, so useForm's initial `lines`
    // must not be relied on to already contain a row for every current line.
    const updateLine = (line: Line, patch: Partial<ReceiveLine>) => {
        const exists = data.lines.some((entry) => entry.purchase_order_line_id === line.id);
        setData(
            'lines',
            exists
                ? data.lines.map((entry) => (entry.purchase_order_line_id === line.id ? { ...entry, ...patch } : entry))
                : [...data.lines, { ...entryFor(line), ...patch }],
        );
    };

    const lineError = (index: number, field: keyof ReceiveLine): string | undefined => errors[`lines.${index}.${field}` as keyof typeof errors];

    if (receivableLines.length === 0) {
        return null;
    }

    const receiving = receivableLines.map((line) => ({ line, qty: Number(entryFor(line).quantity) || 0 })).filter((row) => row.qty > 0);
    const units = [...new Set(receiving.map((row) => purchaseUnitOf(row.line)).filter(Boolean))];
    const receivingTotal = receiving.reduce((sum, row) => sum + row.qty, 0);

    return (
        <form onSubmit={submit} className="border-primary/40 bg-card overflow-hidden rounded-xl border">
            <div className="bg-primary/5 flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
                <div>
                    <p className="text-[15px] font-semibold">Step 3: Receive goods</p>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        Fill in what arrived. Stock enters the warehouse batch by batch; the supplier bill is the next step.
                    </p>
                </div>
                <span className="bg-card inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs">
                    <Warehouse className="text-muted-foreground size-3.5" aria-hidden="true" />
                    Into: {order.warehouse.name}
                    <Hint text="The warehouse is set on the order. One order, one warehouse." />
                </span>
            </div>

            <div className="grid gap-4 px-5 pt-4 pb-2 sm:grid-cols-2 lg:grid-cols-4">
                <div className="grid gap-1.5">
                    <Label htmlFor="supplier_challan_number" className="flex items-center gap-1.5 text-xs">
                        {t('grn.supplier_challan')}
                        <Hint text="The delivery slip number. A number this supplier already sent is refused." />
                    </Label>
                    <Input
                        id="supplier_challan_number"
                        value={data.supplier_challan_number}
                        placeholder={t('grn.supplier_challan_placeholder')}
                        onChange={(e) => setData('supplier_challan_number', e.target.value)}
                    />
                    <InputError message={errors.supplier_challan_number} />
                </div>
                <div className="grid gap-1.5 lg:col-span-3">
                    <Label htmlFor="receipt_note" className="text-xs">
                        {t('grn.note')}
                    </Label>
                    <Input
                        id="receipt_note"
                        value={data.note}
                        placeholder="Anything the storekeeper should remember about this delivery"
                        onChange={(e) => setData('note', e.target.value)}
                    />
                    <InputError message={errors.note} />
                </div>
            </div>

            <div className="px-5 pb-3">
                {/* ⭐ 26 Sep 2026 (Joy, Q11): an earlier received-on date needs its reason, kept on the receipt. */}
                <label className="inline-flex min-h-8 cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" checked={earlier} onChange={(e) => setEarlier(e.target.checked)} className="accent-primary size-4" />
                    This delivery arrived on an earlier day
                </label>
                {earlier && (
                    <div className="grid gap-4 pt-2 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="received_on" required className="text-xs">
                                {t('grn.received_on')}
                            </Label>
                            <Input
                                id="received_on"
                                type="date"
                                max={today}
                                aria-required
                                value={data.received_on}
                                onChange={(e) => setData('received_on', e.target.value)}
                            />
                            <InputError message={errors.received_on} />
                        </div>
                        <div className="grid gap-1.5 lg:col-span-3">
                            <Label htmlFor="backdate_reason" required className="text-xs">
                                {t('grn.backdate_reason')}
                            </Label>
                            <Input
                                id="backdate_reason"
                                aria-required
                                value={data.backdate_reason}
                                placeholder={t('grn.backdate_reason_placeholder')}
                                onChange={(e) => setData('backdate_reason', e.target.value)}
                            />
                            <InputError message={errors.backdate_reason} />
                        </div>
                    </div>
                )}
            </div>

            {/* ⚠ 17 Oct 2026 (practice run F17): Batch, Made on and Expires on were three
                more columns, and the table ran wider than its card at 1280 px (they hid
                behind a sideways scroll). They now share one cell and stack when it is
                narrow; each keeps its own label. */}
            <div className="overflow-x-auto border-t">
                <table className="w-full min-w-[40rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3 [&_tbody_td]:py-2.5 [&_tbody_td]:align-top [&_thead_th]:px-3 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                    <thead className="text-muted-foreground">
                        <tr>
                            <th className="pl-5!">Item</th>
                            <th className="text-right!">Still to come</th>
                            <th className="w-36">{t('grn.quantity')}</th>
                            <th className="pr-5!">Batch and dates</th>
                        </tr>
                    </thead>
                    <tbody>
                        {receivableLines.map((line, index) => {
                            const entry = entryFor(line);
                            const unit = purchaseUnitOf(line);
                            // A service has no stock, so it has no batch and no label.
                            const stocked = line.product.type !== 'service';
                            // Batch and expiry are refused only on a product that tracks expiry
                            // and only on a line actually received (PurchaseOrderController@receive).
                            const tracks = Boolean(line.product.tracks_expiry);
                            const expiryRequired = tracks && Number(entry.quantity) > 0;
                            const warnBelow = line.product.shelf_life_warning_days ?? 90;
                            const daysLeft = entry.expiry_date ? daysBetween(today, entry.expiry_date) : null;
                            const shortLife = tracks && daysLeft !== null && daysLeft >= 0 && daysLeft < warnBelow;
                            const expiryError = lineError(index, 'expiry_date');

                            return (
                                <tr key={line.id}>
                                    <td className="pl-5!">
                                        <span className="font-medium">{line.product.name}</span>
                                        <div className="text-muted-foreground text-xs">
                                            {line.product.sku}
                                            {stocked &&
                                                (tracks
                                                    ? ' · tracks expiry: batch and expiry are required'
                                                    : ' · no expiry tracking: batch and dates optional')}
                                            {!stocked && ' · service, no stock'}
                                        </div>
                                        <InputError message={lineError(index, 'purchase_order_line_id')} />
                                    </td>
                                    <td className="text-right whitespace-nowrap tabular-nums">
                                        {formatQuantity(remaining(line))}
                                        {unit ? ` ${unit}` : ''}
                                    </td>
                                    <td>
                                        <div className="flex items-center gap-1.5">
                                            <input
                                                id={`receive-qty-${line.id}`}
                                                inputMode="decimal"
                                                required
                                                aria-required
                                                aria-label={`Quantity received, ${line.product.name}`}
                                                value={entry.quantity}
                                                onChange={(e) => updateLine(line, { quantity: e.target.value })}
                                                className={cn(inputClass, 'w-20 text-right tabular-nums')}
                                            />
                                            {unit && <span className="text-muted-foreground shrink-0 text-xs">{unit}</span>}
                                        </div>
                                        <InputError message={lineError(index, 'quantity')} />
                                    </td>
                                    {stocked ? (
                                        <td className="pr-5!">
                                            <div className="grid grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))] gap-x-3 gap-y-2">
                                                <div className="grid content-start gap-1">
                                                    <label htmlFor={`receive-batch-${line.id}`} className="text-muted-foreground text-[11px]">
                                                        {t('grn.batch_no')}
                                                    </label>
                                                    <input
                                                        id={`receive-batch-${line.id}`}
                                                        aria-required={expiryRequired}
                                                        aria-label={`Batch number, ${line.product.name}`}
                                                        value={entry.batch_number}
                                                        placeholder={expiryRequired ? t('grn.batch_placeholder') : 'optional'}
                                                        onChange={(e) => updateLine(line, { batch_number: e.target.value })}
                                                        className={cn(inputClass, lineError(index, 'batch_number') && 'border-destructive')}
                                                    />
                                                    {/* Only while it is still empty (F17: it stayed under a filled box). */}
                                                    {expiryRequired && entry.batch_number.trim() === '' && (
                                                        <span className="text-destructive text-[11px]">required</span>
                                                    )}
                                                    <InputError message={lineError(index, 'batch_number')} />
                                                </div>
                                                <div className="grid content-start gap-1">
                                                    <label htmlFor={`receive-mfg-${line.id}`} className="text-muted-foreground text-[11px]">
                                                        {t('grn.made_on')}
                                                    </label>
                                                    <input
                                                        id={`receive-mfg-${line.id}`}
                                                        type="date"
                                                        max={today}
                                                        aria-label={`Made on, ${line.product.name}`}
                                                        value={entry.manufactured_date}
                                                        onChange={(e) => updateLine(line, { manufactured_date: e.target.value })}
                                                        className={inputClass}
                                                    />
                                                    <DateWords value={entry.manufactured_date} />
                                                    <InputError message={lineError(index, 'manufactured_date')} />
                                                </div>
                                                <div className="grid content-start gap-1">
                                                    <label htmlFor={`receive-exp-${line.id}`} className="text-muted-foreground text-[11px]">
                                                        {t('grn.expires_on')}
                                                    </label>
                                                    {/* ⚠ `min` only saves a round trip. The server refuses an
                                                        expiry before today whatever the browser allows. */}
                                                    <input
                                                        id={`receive-exp-${line.id}`}
                                                        type="date"
                                                        min={today}
                                                        aria-required={expiryRequired}
                                                        aria-label={`Expires on, ${line.product.name}`}
                                                        value={entry.expiry_date}
                                                        onChange={(e) => updateLine(line, { expiry_date: e.target.value })}
                                                        className={cn(
                                                            inputClass,
                                                            shortLife && 'border-amber-500',
                                                            expiryError && 'border-destructive',
                                                        )}
                                                    />
                                                    <DateWords value={entry.expiry_date} />
                                                    {expiryRequired && !entry.expiry_date && (
                                                        <span className="text-destructive text-[11px]">required</span>
                                                    )}
                                                    {shortLife && (
                                                        <p className="text-[11.5px] text-amber-700 dark:text-amber-400">
                                                            {daysLeft} {daysLeft === 1 ? 'day' : 'days'} left: short shelf life, allowed (the product
                                                            warns below {warnBelow}).
                                                        </p>
                                                    )}
                                                    <InputError message={expiryError} />
                                                </div>
                                            </div>
                                            {!tracks && !entry.expiry_date && (
                                                <p className="text-muted-foreground mt-1 text-[11.5px]">
                                                    Leave the quantity at 0 if not in this delivery.
                                                </p>
                                            )}
                                        </td>
                                    ) : (
                                        <td className="text-muted-foreground pr-5! text-xs">No batch or label for a service.</td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Refusals about the receipt as a whole: over-receiving, an order not
                open, a closed period, a missing account mapping. */}
            {errors.lines && (
                <div className="px-5 pt-3">
                    <InputError message={errors.lines} />
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3 border-t px-5 py-3.5">
                <p className="text-muted-foreground text-xs">
                    {/* F16: a sum across kg and pcs means nothing, so it is only given in one unit. */}
                    Receiving {units.length === 1 ? `${formatQuantity(receivingTotal)} ${units[0]} on ` : ''}
                    {receiving.length} {receiving.length === 1 ? 'line' : 'lines'}. More than ordered is refused; a closed accounting period is
                    refused.
                </p>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" size="sm" disabled={processing}>
                        {t('grn.record')}
                    </Button>
                </div>
            </div>
        </form>
    );
}

function ReturnGoodsForm({ order, onClose }: { order: Order; onClose: () => void }) {
    const returnableLines = order.lines.filter((line) => returnable(line) > 0);
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        note: string;
        lines: { purchase_order_line_id: number; quantity: string }[];
    }>({
        note: '',
        lines: [],
    });

    // Untouched lines default to 0 (skipped) - computed here rather than
    // pre-seeded into `lines`, since this component can mount from before
    // anything was returnable and must not rely on stale defaults.
    transform((formData) => ({
        ...formData,
        lines: returnableLines.map((line) => ({
            purchase_order_line_id: line.id,
            quantity: formData.lines.find((entry) => entry.purchase_order_line_id === line.id)?.quantity ?? '0',
        })),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.purchase-orders.returns.store', order.id), { onSuccess: () => reset() });
    };

    const updateQuantity = (lineId: number, quantity: string) => {
        const exists = data.lines.some((entry) => entry.purchase_order_line_id === lineId);
        setData(
            'lines',
            exists
                ? data.lines.map((entry) => (entry.purchase_order_line_id === lineId ? { ...entry, quantity } : entry))
                : [...data.lines, { purchase_order_line_id: lineId, quantity }],
        );
    };

    if (returnableLines.length === 0) {
        return null;
    }

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-3 border-t pt-3">
            <p className="text-[13.5px] font-medium">Return goods from this order</p>
            {returnableLines.map((line) => {
                const entry = data.lines.find((e) => e.purchase_order_line_id === line.id);
                const unit = purchaseUnitOf(line);

                return (
                    <div key={line.id} className="flex items-center justify-between gap-2 text-[13px]">
                        <span className="min-w-0">
                            <span className="block truncate">{line.product.name}</span>
                            <span className="text-muted-foreground text-xs">
                                returnable {formatQuantity(returnable(line))}
                                {unit ? ` ${unit}` : ''}
                            </span>
                        </span>
                        <span className="flex shrink-0 items-center gap-1.5">
                            <input
                                aria-label={`Quantity to return, ${line.product.name}`}
                                aria-required
                                value={entry?.quantity ?? '0'}
                                onChange={(e) => updateQuantity(line.id, e.target.value)}
                                className={cn(inputClass, 'w-20 text-right tabular-nums')}
                            />
                            {unit && <span className="text-muted-foreground w-8 text-xs">{unit}</span>}
                        </span>
                    </div>
                );
            })}
            <InputError message={errors.lines} />
            <div className="grid gap-1.5">
                <Label htmlFor="return_note" className="text-xs">
                    Note (optional)
                </Label>
                <Input id="return_note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
            </div>
            <div className="flex gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing} variant="outline" size="sm">
                    Record return
                </Button>
            </div>
        </form>
    );
}

/** One step of the chain: a number in a ring, what happened, and a line under it. */
function Step({
    number,
    title,
    main,
    sub,
    state,
}: {
    number: number;
    title: string;
    main: React.ReactNode;
    sub: React.ReactNode;
    state: 'done' | 'here' | 'next' | 'off';
}) {
    return (
        <div
            className={cn(
                'flex min-w-32 flex-1 basis-36 flex-col gap-0.5 rounded-lg border px-3 py-2.5',
                state === 'done' && 'bg-primary/5 border-primary/20',
                state === 'here' && 'border-amber-500/40 bg-amber-500/10',
                state === 'off' && 'opacity-60',
            )}
        >
            <span
                className={cn('flex items-center gap-1.5 text-xs', state === 'here' ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground')}
            >
                <span
                    className={cn(
                        'grid size-[18px] place-items-center rounded-full text-[11px] font-semibold',
                        state === 'done' && 'bg-primary text-primary-foreground',
                        state === 'here' && 'bg-amber-600 text-white',
                        (state === 'next' || state === 'off') && 'text-muted-foreground border',
                    )}
                >
                    {state === 'done' ? <Check className="size-3" aria-hidden="true" /> : number}
                </span>
                {title}
            </span>
            <span className="text-[13px] font-medium">{main}</span>
            <span className="text-muted-foreground text-xs">{sub}</span>
        </div>
    );
}

export default function PurchaseOrderShow({
    timeline,
    order,
    receipts,
    bills,
    billing,
    last_costs,
    today,
    can_approve,
    can_receive,
    can_record_bill,
    can_record_payment,
    can_return,
    can_cancel,
}: {
    timeline?: TimelineEntry[];
    order: Order;
    receipts: Receipt[];
    bills: BillRow[];
    billing: Billing;
    last_costs: Record<number, LastCost>;
    today: string;
    can_approve: boolean;
    can_receive: boolean;
    can_record_bill: boolean;
    can_record_payment: boolean;
    can_return: boolean;
    can_cancel: boolean;
}) {
    const { canViewInventory } = usePage<SharedData>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
        { title: order.po_number, href: `/purchasing/purchase-orders/${order.id}` },
    ];

    const approve = () => router.post(route('purchasing.purchase-orders.approve', order.id));
    const reject = (review_note: string) => router.post(route('purchasing.purchase-orders.reject', order.id), { review_note });
    const cancel = () => router.post(route('purchasing.purchase-orders.cancel', order.id));

    const approved = order.approval_request.status === 'approved';
    const cancelled = order.status === 'cancelled';
    const ordered = order.lines.reduce((sum, line) => sum + parseFloat(line.quantity_ordered), 0);
    const received = order.lines.reduce((sum, line) => sum + parseFloat(line.quantity_received), 0);
    const stillToCome = order.lines.reduce((sum, line) => sum + remaining(line), 0);
    const anythingReceived = received > 0;
    const orderTotal = order.lines.reduce((sum, line) => sum + parseFloat(line.quantity_ordered) * parseFloat(line.unit_cost), 0);
    const toBillValue = order.lines.reduce((sum, line) => sum + billable(line) * parseFloat(line.unit_cost), 0);
    const toBillQty = order.lines.reduce((sum, line) => sum + billable(line), 0);
    const units = [...new Set(order.lines.map(purchaseUnitOf).filter(Boolean))];
    const unitWord = units.length === 1 ? ` ${units[0]}` : '';
    // F16 (practice run, 17 Oct 2026): 10 kg and 60 pcs are not "70". With more
    // than one unit on the order, progress is counted in lines instead.
    const mixedUnits = units.length > 1;
    const lineWord = (count: number) => `${count} ${count === 1 ? 'line' : 'lines'}`;
    const linesIn = order.lines.filter((line) => remaining(line) <= 0).length;
    const linesToCome = order.lines.length - linesIn;
    const linesToBill = order.lines.filter((line) => billable(line) > 0).length;
    const toBillText = mixedUnits ? lineWord(linesToBill) : `${formatQuantity(toBillQty)}${unitWord}`;
    const paid = Number(billing.billed.replace(/,/g, '')) - Number(billing.outstanding.replace(/,/g, ''));

    const receivable = can_receive && approved && !cancelled && stillToCome > 0;
    const [receiving, setReceiving] = useState(false);
    const [returning, setReturning] = useState(false);
    const returnableAny = can_return && !cancelled && order.lines.some((line) => returnable(line) > 0);

    const status = STATUS[order.status];
    const approval = APPROVAL[order.approval_request.status] ?? APPROVAL.pending;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.po_number} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-2xl font-semibold tracking-tight">{order.po_number}</p>
                            <Pill tone={status.tone}>{status.label}</Pill>
                            <Pill tone={approval.tone}>{approval.label}</Pill>
                        </div>
                        <p className="mt-1 text-[13.5px]">
                            {order.supplier.id ? (
                                <Link href={`/purchasing/suppliers/${order.supplier.id}`} className="hover:underline">
                                    {order.supplier.name}
                                </Link>
                            ) : (
                                order.supplier.name
                            )}{' '}
                            <span className="text-muted-foreground">to</span> {order.warehouse.name}
                            <span className="text-muted-foreground text-xs">
                                {order.expected_date && <> · expected {formatDate(order.expected_date)}</>}
                                {order.created_by && (
                                    <>
                                        {' '}
                                        · raised by {order.created_by.name}
                                        {order.created_at && `, ${formatDate(order.created_at)}`}
                                    </>
                                )}
                                {approved && order.approval_request.reviewed_by && <> · approved by {order.approval_request.reviewed_by.name}</>}
                                {order.supplier.payment_terms && <> · terms: {order.supplier.payment_terms}</>}
                            </span>
                        </p>
                        {order.note && <p className="text-muted-foreground mt-1 text-xs">{order.note}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/purchasing/purchase-orders/${order.id}/print`}>Print for supplier</Link>
                        </Button>
                        {can_cancel && (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline">
                                        Cancel order
                                    </Button>
                                }
                                title={`Cancel ${order.po_number}`}
                                description="Cancelling stops any further goods receipt against this order. This cannot be undone."
                                confirmLabel="Cancel order"
                                cancelLabel="Keep order"
                                destructive
                                onConfirm={() => cancel()}
                            />
                        )}
                        {/* PUR-006 (14 Oct 2026): never offered on a cancelled order. */}
                        {order.approval_request.status === 'pending' && !cancelled && can_approve && (
                            <>
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            Reject
                                        </Button>
                                    }
                                    title={`Reject ${order.po_number}`}
                                    description="The purchase order will be marked rejected. This cannot be undone."
                                    confirmLabel="Reject order"
                                    destructive
                                    withNote
                                    noteLabel="Reason for rejecting (optional)"
                                    notePlaceholder="Add a note for the creator"
                                    onConfirm={reject}
                                />
                                <Button size="sm" onClick={approve}>
                                    Approve
                                </Button>
                            </>
                        )}
                        {receivable && (
                            <Button size="sm" onClick={() => setReceiving((open) => !open)} aria-expanded={receiving}>
                                {receiving ? 'Close receive step' : 'Receive goods'}
                            </Button>
                        )}
                    </div>
                </div>

                {order.approval_request.status === 'rejected' && order.approval_request.review_note && (
                    <p className="text-destructive text-sm">Rejected: {order.approval_request.review_note}</p>
                )}

                {/* The chain, walked from the order: order, approve, receive, bill, pay.
                    Nothing here is typed by hand; every step reads a document. */}
                <section className="bg-card rounded-xl border p-3.5">
                    <div className="flex flex-wrap gap-2">
                        <Step
                            number={1}
                            title="Ordered"
                            main={`${formatDate(order.created_at)}, ${order.lines.length} ${order.lines.length === 1 ? 'line' : 'lines'}`}
                            sub={`Tk ${formatMoney(orderTotal)}`}
                            state="done"
                        />
                        <Step
                            number={2}
                            title="Approved"
                            main={
                                approved
                                    ? (order.approval_request.reviewed_by?.name ?? 'Approved')
                                    : order.approval_request.status === 'rejected'
                                      ? 'Rejected'
                                      : 'Waiting'
                            }
                            sub={
                                approved
                                    ? 'Approved, goods may be received'
                                    : order.approval_request.status === 'rejected'
                                      ? (order.approval_request.reviewed_by?.name ?? 'Not approved')
                                      : 'Needs somebody who may approve'
                            }
                            state={approved ? 'done' : order.approval_request.status === 'rejected' || cancelled ? 'off' : 'here'}
                        />
                        <Step
                            number={3}
                            title="Receive goods"
                            main={
                                cancelled
                                    ? 'Cancelled'
                                    : mixedUnits
                                      ? `${linesIn} of ${lineWord(order.lines.length)} received`
                                      : `${formatQuantity(received)} of ${formatQuantity(ordered)}${unitWord} in`
                            }
                            sub={
                                cancelled
                                    ? 'No more goods against this order'
                                    : stillToCome > 0
                                      ? `${mixedUnits ? lineWord(linesToCome) : `${formatQuantity(stillToCome)}${unitWord}`} still to come${approved ? ', you are here' : ''}`
                                      : 'Everything ordered has arrived'
                            }
                            state={cancelled ? 'off' : stillToCome === 0 && anythingReceived ? 'done' : approved ? 'here' : 'next'}
                        />
                        <Step
                            number={4}
                            title="Supplier bill"
                            main={billing.state === 'nothing_billed' ? 'None yet' : `Tk ${formatMoney(billing.billed)} billed`}
                            sub={
                                can_record_bill && toBillQty > 0 ? (
                                    <Link href={route('purchasing.bills.create', order.id)} className="text-primary hover:underline">
                                        Record bill for {toBillText}
                                    </Link>
                                ) : toBillQty > 0 ? (
                                    `${toBillText} received, not yet billed`
                                ) : billing.state === 'nothing_billed' ? (
                                    'Nothing received to bill yet'
                                ) : (
                                    `${bills.filter((b) => !b.is_reversed).length} ${bills.filter((b) => !b.is_reversed).length === 1 ? 'bill' : 'bills'}`
                                )
                            }
                            state={billing.state !== 'nothing_billed' && toBillQty === 0 ? 'done' : toBillQty > 0 ? 'here' : 'next'}
                        />
                        <Step
                            number={5}
                            title="Payment"
                            main={
                                billing.state === 'nothing_billed'
                                    ? 'Nothing owed yet'
                                    : billing.state === 'settled'
                                      ? 'Settled'
                                      : `Tk ${formatMoney(billing.outstanding)} owed`
                            }
                            sub={
                                billing.state === 'nothing_billed'
                                    ? 'A payment is made against a bill'
                                    : paid > 0
                                      ? `Tk ${formatMoney(paid)} paid so far`
                                      : 'Nothing paid yet'
                            }
                            state={billing.state === 'settled' ? 'done' : billing.state === 'owed' ? 'here' : 'next'}
                        />
                    </div>
                </section>

                <div className="flex flex-wrap items-start gap-4">
                    <div className="flex min-w-0 flex-[999_1_36rem] flex-col gap-4">
                        <section className="bg-card overflow-hidden rounded-xl border">
                            <div className="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-4 pb-2">
                                <p className="inline-flex items-center gap-1.5 text-[15px] font-semibold">
                                    Order lines
                                    <Hint text="Quantities are in the PURCHASE unit. The warehouse may hold a different unit; the ERP converts." />
                                </p>
                                <span className="text-muted-foreground text-xs">
                                    Agreed prices, beside the last price this item arrived at on another order
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[46rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3 [&_tbody_td]:py-2.5 [&_thead_th]:px-3 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                                    <thead className="text-muted-foreground">
                                        <tr>
                                            <th className="pl-4!">Item</th>
                                            <th className="text-right!">Ordered</th>
                                            <th className="text-right!">Received</th>
                                            <th className="text-right!">Returned</th>
                                            <th className="text-right!">Remaining</th>
                                            <th className="text-right!">Agreed cost</th>
                                            <th className="text-right!">Last cost elsewhere</th>
                                            <th className="pr-4! text-right!">Line total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {order.lines.map((line) => {
                                            const last = last_costs[line.product.id];
                                            const unit = purchaseUnitOf(line);
                                            const diff = last
                                                ? ((parseFloat(line.unit_cost) - parseFloat(last.unit_cost)) / parseFloat(last.unit_cost)) * 100
                                                : null;

                                            return (
                                                <tr key={line.id}>
                                                    <td className="pl-4!">
                                                        <span className="font-medium">{line.product.name}</span>
                                                        <div className="text-muted-foreground text-xs">
                                                            {line.product.sku}
                                                            {line.product.type === 'service'
                                                                ? ' · service'
                                                                : line.product.tracks_expiry
                                                                  ? ' · tracks expiry'
                                                                  : ' · no expiry tracking'}
                                                        </div>
                                                    </td>
                                                    <td className="text-right whitespace-nowrap tabular-nums">
                                                        {formatQuantity(line.quantity_ordered)}
                                                        {unit ? ` ${unit}` : ''}
                                                    </td>
                                                    <td className="text-right tabular-nums">{formatQuantity(line.quantity_received)}</td>
                                                    <td className="text-muted-foreground text-right tabular-nums">
                                                        {formatQuantity(line.quantity_returned)}
                                                    </td>
                                                    <td className="text-right font-medium tabular-nums">{formatQuantity(remaining(line))}</td>
                                                    <td className="text-right tabular-nums">{formatMoney(line.unit_cost)}</td>
                                                    <td className="text-right whitespace-nowrap tabular-nums">
                                                        {last ? (
                                                            <span
                                                                className={cn(
                                                                    diff !== null && diff > 0.05 && 'text-amber-700 dark:text-amber-400',
                                                                    diff !== null && diff < -0.05 && 'text-primary',
                                                                )}
                                                            >
                                                                {formatMoney(last.unit_cost)}{' '}
                                                                <span className="text-muted-foreground text-xs">
                                                                    {diff !== null && Math.abs(diff) >= 0.05
                                                                        ? `${diff > 0 ? '+' : ''}${diff.toFixed(1)}%`
                                                                        : 'same'}
                                                                    {last.po_number && `, ${last.po_number}`}
                                                                </span>
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">first time</span>
                                                        )}
                                                    </td>
                                                    <td className="pr-4! text-right tabular-nums">
                                                        {formatMoney(parseFloat(line.quantity_ordered) * parseFloat(line.unit_cost))}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colSpan={7} className="text-muted-foreground border-t px-3 py-2 text-right text-xs">
                                                Order total
                                            </td>
                                            <td className="border-t px-3 py-2 pr-4 text-right font-semibold tabular-nums">
                                                {formatMoney(orderTotal)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </section>

                        {receivable && receiving && <ReceiveGoodsStep order={order} today={today} onClose={() => setReceiving(false)} />}

                        {order.returns.length > 0 && (
                            <section className="bg-card rounded-xl border p-4">
                                <p className="text-[15px] font-semibold">Returns</p>
                                <ul className="mt-2 grid gap-2 text-[13px]">
                                    {order.returns.map((purchaseReturn) => (
                                        <li key={purchaseReturn.id} className="rounded-lg border p-3">
                                            <p className="text-muted-foreground text-xs">
                                                {purchaseReturn.returned_by.name}, {purchaseReturn.returned_at}
                                                {purchaseReturn.note && `, ${purchaseReturn.note}`}
                                            </p>
                                            {/* ⚠ Aggregated by ORDER LINE. A return large enough to span two
                                                deliveries writes one row per cost layer emptied, which is what
                                                the ledger needs, but a clerk who returned 70 kg of one product
                                                once should not read two unexplained bullets for it. */}
                                            {aggregateReturnLines(purchaseReturn.lines).map((entry) => (
                                                <p key={entry.key} className="mt-1">
                                                    {entry.name} <span className="text-muted-foreground">({entry.sku})</span>:{' '}
                                                    {formatQuantity(entry.quantity)}
                                                    {entry.batches > 1 && (
                                                        <span className="text-muted-foreground text-xs"> (across {entry.batches} cost layers)</span>
                                                    )}
                                                </p>
                                            ))}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>

                    <aside className="flex min-w-0 flex-[1_1_18rem] flex-col gap-4 lg:max-w-sm">
                        <section className="bg-card rounded-xl border p-4">
                            <p className="text-[15px] font-semibold">Deliveries so far</p>
                            {receipts.length === 0 ? (
                                <p className="text-muted-foreground mt-2 text-sm">Nothing received yet.</p>
                            ) : (
                                <ul className="mt-2 grid gap-2">
                                    {receipts.map((receipt) => (
                                        <li key={receipt.id} className="rounded-lg border p-3 text-[13px]">
                                            <div className="flex justify-between gap-2">
                                                <span className="font-medium">
                                                    {receipt.received_at}
                                                    {receipt.supplier_challan_number && `, challan ${receipt.supplier_challan_number}`}
                                                </span>
                                                <span className="text-muted-foreground shrink-0 text-xs">{receipt.received_by}</span>
                                            </div>
                                            {receipt.lines.map((line) => (
                                                <div key={line.id} className="mt-1.5">
                                                    <div className="flex justify-between gap-2">
                                                        <span>{line.product}</span>
                                                        <span className="tabular-nums">{formatQuantity(line.quantity_received)}</span>
                                                    </div>
                                                    {(line.batch_number || line.manufactured_date || line.expiry_date) && (
                                                        <div className="text-muted-foreground text-xs">
                                                            {line.batch_number && `Batch ${line.batch_number}`}
                                                            {/* ⭐ Traceability (17 Oct 2026): where this batch went. */}
                                                            {line.batch_id && canViewInventory && (
                                                                <>
                                                                    {' · '}
                                                                    <Link
                                                                        href={`/inventory/batches/${line.batch_id}`}
                                                                        className="text-primary hover:underline"
                                                                    >
                                                                        trace
                                                                    </Link>
                                                                </>
                                                            )}
                                                            {line.manufactured_date && ` · made ${formatDate(line.manufactured_date)}`}
                                                            {line.expiry_date && (
                                                                <>
                                                                    {' '}
                                                                    ·{' '}
                                                                    <span
                                                                        className={cn(
                                                                            daysBetween(today, line.expiry_date) < 30 &&
                                                                                'font-medium text-amber-700 dark:text-amber-400',
                                                                        )}
                                                                    >
                                                                        expires {formatDate(line.expiry_date)}
                                                                    </span>
                                                                </>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                            {receipt.note && <p className="text-muted-foreground mt-1 text-xs">{receipt.note}</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {returnableAny && !returning && (
                                <button type="button" onClick={() => setReturning(true)} className="text-primary mt-2.5 text-xs hover:underline">
                                    Return goods from this order
                                </button>
                            )}
                            {returnableAny && returning && <ReturnGoodsForm order={order} onClose={() => setReturning(false)} />}
                        </section>

                        <section className="bg-card rounded-xl border p-4">
                            <p className="inline-flex items-center gap-1.5 text-[15px] font-semibold">
                                Billing on this order
                                <Hint text="Derived from the supplier bills recorded against this order, never typed by hand." />
                            </p>
                            {/* ⚠⚠ The "Payment: Unpaid / Partially paid / Paid" dropdown that
                                sat on this page was removed on 21 Sep 2026 (Sales UX audit #11).
                                It set a status on the order that moved no money and reached no
                                ledger. Everything here is read from the bills. */}
                            <dl className="mt-2 grid gap-1.5 text-[13px]">
                                <div className="flex justify-between gap-2">
                                    <dt className="text-muted-foreground">Received, not billed</dt>
                                    <dd className={cn('tabular-nums', toBillQty > 0 && 'font-medium text-amber-700 dark:text-amber-400')}>
                                        {toBillQty > 0 ? `${toBillText} · Tk ${formatMoney(toBillValue)}` : 'Nothing'}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-muted-foreground">Billed</dt>
                                    <dd className="tabular-nums">{formatMoney(billing.billed)}</dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-muted-foreground">Paid</dt>
                                    <dd className="tabular-nums">{formatMoney(Math.max(0, paid))}</dd>
                                </div>
                                <div className="flex justify-between gap-2">
                                    <dt className="text-muted-foreground">Still owed</dt>
                                    <dd className={cn('font-medium tabular-nums', billing.state === 'owed' && 'text-destructive')}>
                                        {formatMoney(billing.outstanding)}
                                    </dd>
                                </div>
                            </dl>
                            {bills.length > 0 && (
                                <ul className="mt-3 grid gap-1.5 border-t pt-3 text-[13px]">
                                    {bills.map((bill) => (
                                        <li key={bill.id} className="flex items-center justify-between gap-2">
                                            <span className="min-w-0">
                                                <Link href={route('purchasing.bills.show', bill.id)} className="font-medium hover:underline">
                                                    {bill.bill_number}
                                                </Link>
                                                {bill.is_reversed && <span className="text-destructive ml-1.5 text-xs">reversed</span>}
                                                <span className="text-muted-foreground block text-xs">
                                                    {formatDate(bill.bill_date)} · {formatMoney(bill.total)}
                                                    {!bill.is_reversed &&
                                                        parseFloat(bill.outstanding) > 0 &&
                                                        `, ${formatMoney(bill.outstanding)} owed`}
                                                </span>
                                            </span>
                                            {can_record_payment && !bill.is_reversed && parseFloat(bill.outstanding) > 0 && (
                                                <Button size="sm" variant="outline" asChild>
                                                    <Link href={route('purchasing.payments.create', { bill_id: bill.id })}>Pay</Link>
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {can_record_bill && toBillQty > 0 && (
                                <Button size="sm" className="mt-3" asChild>
                                    <Link href={route('purchasing.bills.create', order.id)}>Record supplier bill</Link>
                                </Button>
                            )}
                        </section>

                        <section className="bg-card rounded-xl border p-4">
                            <p className="text-[15px] font-semibold">Timeline</p>
                            <div className="mt-2">
                                <RecordTimeline entries={timeline ?? []} />
                            </div>
                        </section>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
