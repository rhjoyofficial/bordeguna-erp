import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import RecordTimeline, { type TimelineEntry } from '@/components/record-timeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/**
 * ⚠⚠ The unit a purchase quantity is TYPED IN. For a product bought in one unit
 * and stocked in another this is the PURCHASE unit, because the order, the
 * receipt, the supplier bill and the return all stay in the unit the supplier
 * invoices. Typing the stocked figure instead is a ~9% error that balances
 * everywhere downstream, so the box has to say which one it wants.
 */
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

const purchaseUnitOf = (line: Line): string | null => line.product.purchase_unit?.abbreviation ?? line.product.unit_of_measure?.abbreviation ?? null;

interface Line {
    id: number;
    product: {
        id: number;
        name: string;
        sku: string;
        type: 'raw_material' | 'finished_good' | 'service';
        tracks_expiry?: boolean;
        unit_of_measure?: { abbreviation: string } | null;
        purchase_unit?: { abbreviation: string } | null;
    };
    quantity_ordered: string;
    quantity_received: string;
    quantity_returned: string;
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
    supplier: { name: string; contact_name: string | null; phone: string | null; payment_terms: string | null };
    warehouse: { name: string };
    created_by: { name: string };
    approval_request: {
        status: 'pending' | 'approved' | 'rejected';
        requested_by: { name: string };
        reviewed_by: { name: string } | null;
        review_note: string | null;
    };
    lines: Line[];
    returns: Return[];
}

const statusVariant: Record<Order['status'], 'secondary' | 'default' | 'outline' | 'destructive'> = {
    open: 'secondary',
    partially_received: 'outline',
    received: 'default',
    cancelled: 'destructive',
};

const approvalVariant: Record<Order['approval_request']['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

function remaining(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_ordered) - parseFloat(line.quantity_received));
}

function returnable(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_received) - parseFloat(line.quantity_returned));
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

function ReceiveGoodsForm({ order, today }: { order: Order; today: string }) {
    const { t } = useTranslation();
    const receivableLines = order.lines.filter((line) => remaining(line) > 0);
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
        lines: receivableLines.map((line) => formData.lines.find((entry) => entry.purchase_order_line_id === line.id) ?? blankReceiveLine(line)),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.purchase-orders.receive', order.id), { onSuccess: () => reset() });
    };

    const entryFor = (line: Line): ReceiveLine => data.lines.find((entry) => entry.purchase_order_line_id === line.id) ?? blankReceiveLine(line);

    // Upserts rather than only mapping existing entries - this component can
    // stay mounted (returning null) from before anything was receivable, so
    // useForm's initial `lines` must not be relied on to already contain a
    // row for every current line.
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

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4">
            <h3 className="font-medium">{t('grn.title')}</h3>

            <FormRow className="gap-3">
                <Field label={t('grn.supplier_challan')} htmlFor="supplier_challan_number" size="md" error={errors.supplier_challan_number}>
                    <Input
                        id="supplier_challan_number"
                        value={data.supplier_challan_number}
                        placeholder={t('grn.supplier_challan_placeholder')}
                        onChange={(e) => setData('supplier_challan_number', e.target.value)}
                    />
                </Field>
                <Field label={t('grn.received_on')} htmlFor="received_on" size="sm" error={errors.received_on}>
                    <Input
                        id="received_on"
                        type="date"
                        max={today}
                        value={data.received_on}
                        onChange={(e) => setData('received_on', e.target.value)}
                    />
                </Field>
                <Field label={t('grn.note')} htmlFor="receipt_note" size="grow" error={errors.note}>
                    <Input id="receipt_note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </Field>
            </FormRow>
            {/* ⭐ 26 Sep 2026 (Joy, Q11): an earlier date needs its reason, kept on the receipt. */}
            {data.received_on !== '' && data.received_on < today && (
                <Field label={t('grn.backdate_reason')} htmlFor="backdate_reason" size="grow" required error={errors.backdate_reason}>
                    <Input
                        id="backdate_reason"
                        value={data.backdate_reason}
                        placeholder={t('grn.backdate_reason_placeholder')}
                        onChange={(e) => setData('backdate_reason', e.target.value)}
                    />
                </Field>
            )}

            {receivableLines.map((line, index) => {
                const entry = entryFor(line);
                const unit = purchaseUnitOf(line);
                // A service has no stock, so it has no batch and no label.
                const stocked = line.product.type !== 'service';

                return (
                    <div key={line.id} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-2 border-t pt-3">
                        <p className="text-sm">
                            <span className="font-medium">{line.product.name}</span>{' '}
                            <span className="text-muted-foreground">({line.product.sku})</span>{' '}
                            <span className="text-muted-foreground text-xs">
                                {t('grn.remaining', { quantity: remaining(line) })}
                                {unit ? ` ${unit}` : ''}
                            </span>
                        </p>
                        <FormRow className="gap-3">
                            <Field
                                label={t('grn.quantity')}
                                htmlFor={`receive-qty-${line.id}`}
                                size="sm"
                                required
                                error={lineError(index, 'quantity')}
                            >
                                <div className="flex items-center gap-2">
                                    <Input
                                        id={`receive-qty-${line.id}`}
                                        inputMode="decimal"
                                        required
                                        value={entry.quantity}
                                        onChange={(e) => updateLine(line, { quantity: e.target.value })}
                                    />
                                    {unit && <span className="text-muted-foreground shrink-0 text-sm">{unit}</span>}
                                </div>
                            </Field>
                            {stocked && (
                                <>
                                    <Field
                                        label={t('grn.batch_no')}
                                        htmlFor={`receive-batch-${line.id}`}
                                        size="sm"
                                        required={Boolean(line.product.tracks_expiry)}
                                        error={lineError(index, 'batch_number')}
                                    >
                                        <Input
                                            id={`receive-batch-${line.id}`}
                                            value={entry.batch_number}
                                            placeholder={t('grn.batch_placeholder')}
                                            onChange={(e) => updateLine(line, { batch_number: e.target.value })}
                                        />
                                    </Field>
                                    <Field label={t('grn.made_on')} htmlFor={`receive-mfg-${line.id}`} error={lineError(index, 'manufactured_date')}>
                                        <Input
                                            id={`receive-mfg-${line.id}`}
                                            type="date"
                                            max={today}
                                            value={entry.manufactured_date}
                                            onChange={(e) => updateLine(line, { manufactured_date: e.target.value })}
                                        />
                                    </Field>
                                    {/* ⚠ `min` only saves a round trip. The server refuses an
                                        expiry before today whatever the browser allows. */}
                                    <Field
                                        label={t('grn.expires_on')}
                                        htmlFor={`receive-exp-${line.id}`}
                                        required={Boolean(line.product.tracks_expiry)}
                                        error={lineError(index, 'expiry_date')}
                                    >
                                        <Input
                                            id={`receive-exp-${line.id}`}
                                            type="date"
                                            min={today}
                                            value={entry.expiry_date}
                                            onChange={(e) => updateLine(line, { expiry_date: e.target.value })}
                                        />
                                    </Field>
                                </>
                            )}
                        </FormRow>
                        <InputError message={lineError(index, 'purchase_order_line_id')} />
                    </div>
                );
            })}

            {/* Refusals about the receipt as a whole: over-receiving, an order not
                open, a closed period, a missing account mapping. */}
            <InputError message={errors.lines} />

            <Button disabled={processing} className="w-fit">
                {t('grn.record')}
            </Button>
        </form>
    );
}

function ReturnGoodsForm({ order }: { order: Order }) {
    const returnableLines = order.lines.filter((line) => returnable(line) > 0);
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        note: string;
        lines: { purchase_order_line_id: number; quantity: string }[];
    }>({
        note: '',
        lines: [],
    });

    // Untouched lines default to 0 (skipped) - computed here rather than
    // pre-seeded into `lines`, since this component can stay mounted from
    // before anything was returnable and must not rely on stale defaults
    // captured at that earlier mount.
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
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
            <h3 className="font-medium">Return goods</h3>
            {returnableLines.map((line) => {
                const entry = data.lines.find((e) => e.purchase_order_line_id === line.id);

                return (
                    <div key={line.id} className="grid grid-cols-[2fr_1fr] items-center gap-2">
                        <span>
                            {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>{' '}
                            <span className="text-muted-foreground text-xs">
                                returnable {returnable(line)}
                                {purchaseUnitOf(line) ? ` ${purchaseUnitOf(line)}` : ''}
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <Input value={entry?.quantity ?? '0'} onChange={(e) => updateQuantity(line.id, e.target.value)} />
                            {purchaseUnitOf(line) && <span className="text-muted-foreground w-10 shrink-0 text-sm">{purchaseUnitOf(line)}</span>}
                        </div>
                    </div>
                );
            })}
            <InputError message={errors.lines} />
            <div className="grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
            </div>
            <Button disabled={processing} variant="outline" className="w-fit">
                Record return
            </Button>
        </form>
    );
}

const billingSentence = (billing: Billing): string => {
    switch (billing.state) {
        case 'owed':
            return `Billed ${billing.billed}; ${billing.outstanding} is still owed to the supplier.`;
        case 'settled':
            return `Billed ${billing.billed}; every bill against this order is settled.`;
        default:
            return 'No supplier bill has been recorded against this order yet.';
    }
};

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
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
        { title: order.po_number, href: `/purchasing/purchase-orders/${order.id}` },
    ];

    const approve = () => router.post(route('purchasing.purchase-orders.approve', order.id));
    const reject = (review_note: string) => router.post(route('purchasing.purchase-orders.reject', order.id), { review_note });
    const cancel = () => router.post(route('purchasing.purchase-orders.cancel', order.id));
    const anythingReceived = order.lines.some((line) => parseFloat(line.quantity_received) > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.po_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{order.po_number}</h2>
                        <p className="text-muted-foreground text-sm">
                            {order.supplier.name} → {order.warehouse.name}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                            {order.approval_request.status}
                        </Badge>
                        <Badge variant={statusVariant[order.status]} className="capitalize">
                            {order.status.replace('_', ' ')}
                        </Badge>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/purchasing/purchase-orders/${order.id}/print`}>Print / send</Link>
                        </Button>
                    </div>
                </div>

                {order.approval_request.status === 'pending' && can_approve && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex gap-2 rounded-xl border p-4">
                        <Button size="sm" onClick={approve}>
                            Approve
                        </Button>
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="ghost">
                                    Reject
                                </Button>
                            }
                            title={`Reject ${order.po_number}`}
                            description="The purchase order will be marked rejected. This cannot be undone."
                            confirmLabel="Reject order"
                            destructive
                            withNote
                            noteLabel="Reason for rejecting (optional)"
                            notePlaceholder="Add a note for the creator…"
                            onConfirm={reject}
                        />
                    </div>
                )}

                {order.approval_request.status === 'rejected' && order.approval_request.review_note && (
                    <p className="text-destructive text-sm">Rejected: {order.approval_request.review_note}</p>
                )}

                {/* ⚠ Wrapped: seven columns do not fit a phone (Sales UX audit). The
                    UNIT column is the purchase unit, the one every quantity and
                    cost on this order is in (see purchaseUnitOf). */}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2 pr-3">Product</th>
                                <th className="py-2 pr-3">Unit</th>
                                <th className="py-2 pr-3 text-right">Ordered</th>
                                <th className="py-2 pr-3 text-right">Received</th>
                                <th className="py-2 pr-3 text-right">Returned</th>
                                <th className="py-2 pr-3 text-right">Unit cost</th>
                                <th className="py-2">Last price paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.lines.map((line) => {
                                const last = last_costs[line.product.id];

                                return (
                                    <tr key={line.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 pr-3">
                                            {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>
                                            {line.product.type === 'service' && <span className="text-muted-foreground"> - service</span>}
                                        </td>
                                        <td className="text-muted-foreground py-2 pr-3">{purchaseUnitOf(line) ?? '-'}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatQuantity(line.quantity_ordered)}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatQuantity(line.quantity_received)}</td>
                                        <td className="text-muted-foreground py-2 pr-3 text-right tabular-nums">
                                            {formatQuantity(line.quantity_returned)}
                                        </td>
                                        <td className="text-muted-foreground py-2 pr-3 text-right tabular-nums">{formatMoney(line.unit_cost)}</td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {last ? (
                                                <>
                                                    <span className="text-foreground text-sm tabular-nums">{formatMoney(last.unit_cost)}</span>
                                                    {last.po_number && ` on ${last.po_number}`}
                                                    {last.received_on && `, ${last.received_on}`}
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap gap-2">
                    {can_cancel && (
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="ghost">
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
                    {/* ⚠⚠ The "Payment: Unpaid / Partially paid / Paid" dropdown that
                        sat here was removed on 21 Sep 2026 (Sales UX audit #11). It
                        set a status on the order that moved no money and reached no
                        ledger, so the order could read "Paid" while the supplier was
                        owed in full. Payment now shows below, read from the bills. */}
                </div>

                {/* The purchase chain, walked from the order: receive -> bill -> pay.
                    Each button opens the next screen already filled in. */}
                {order.approval_request.status === 'approved' && (anythingReceived || bills.length > 0) && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 className="font-medium">Supplier bills and payment</h3>
                                <p className="text-muted-foreground text-sm">{billingSentence(billing)}</p>
                            </div>
                            {can_record_bill && (
                                <Button size="sm" asChild>
                                    <Link href={route('purchasing.bills.create', order.id)}>Record the supplier bill</Link>
                                </Button>
                            )}
                        </div>
                        {bills.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left">
                                            <th className="py-2 pr-3">Bill</th>
                                            <th className="py-2 pr-3">Date</th>
                                            <th className="py-2 pr-3 text-right">Total</th>
                                            <th className="py-2 pr-3 text-right">Outstanding</th>
                                            <th className="py-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {bills.map((bill) => (
                                            <tr key={bill.id} className="border-b last:border-b-0">
                                                <td className="py-2 pr-3">
                                                    <Link href={route('purchasing.bills.show', bill.id)} className="font-medium hover:underline">
                                                        {bill.bill_number}
                                                    </Link>
                                                    {bill.is_reversed && <span className="text-destructive ml-2 text-xs">reversed</span>}
                                                </td>
                                                <td className="py-2 pr-3">{bill.bill_date ?? '-'}</td>
                                                <td className="py-2 pr-3 text-right tabular-nums">{bill.total}</td>
                                                <td className="py-2 pr-3 text-right tabular-nums">{bill.outstanding}</td>
                                                <td className="py-2 text-right">
                                                    {can_record_payment && !bill.is_reversed && parseFloat(bill.outstanding) > 0 && (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={route('purchasing.payments.create', { bill_id: bill.id })}>
                                                                Pay this bill
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {can_receive && order.approval_request.status === 'approved' && order.status !== 'cancelled' && (
                    <ReceiveGoodsForm order={order} today={today} />
                )}

                {receipts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">Receipt history</h3>
                        {receipts.map((receipt) => (
                            <div key={receipt.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                                <p className="text-muted-foreground">
                                    {receipt.received_by} - {receipt.received_at}
                                    {receipt.supplier_challan_number && ` - challan ${receipt.supplier_challan_number}`}
                                    {receipt.note && ` - ${receipt.note}`}
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {receipt.lines.map((line) => (
                                        <li key={line.id}>
                                            {line.product} ({line.sku}) - {formatQuantity(line.quantity_received)}
                                            {(line.batch_number || line.manufactured_date || line.expiry_date) && (
                                                <span className="text-muted-foreground text-xs">
                                                    {line.batch_number && ` · batch ${line.batch_number}`}
                                                    {line.manufactured_date && ` · made ${line.manufactured_date}`}
                                                    {line.expiry_date && ` · expires ${line.expiry_date}`}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}

                {can_return && order.status !== 'cancelled' && <ReturnGoodsForm order={order} />}

                {order.returns.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">Return history</h3>
                        {order.returns.map((purchaseReturn) => (
                            <div
                                key={purchaseReturn.id}
                                className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm"
                            >
                                <p className="text-muted-foreground">
                                    {purchaseReturn.returned_by.name} - {purchaseReturn.returned_at}
                                    {purchaseReturn.note && ` - ${purchaseReturn.note}`}
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {/*
                                     * ⚠ Aggregated by ORDER LINE. A return large enough to span two
                                     * deliveries writes one row per cost layer emptied, which is what
                                     * the ledger needs, but a clerk who returned 70 kg of one product
                                     * once should not read two unexplained bullets for it.
                                     */}
                                    {aggregateReturnLines(purchaseReturn.lines).map((entry) => (
                                        <li key={entry.key}>
                                            {entry.name} ({entry.sku}) - {entry.quantity}
                                            {entry.batches > 1 && (
                                                <span className="text-muted-foreground text-xs"> (across {entry.batches} cost layers)</span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </div>
            <div className="border-border bg-card mx-4 mb-4 rounded-lg border p-4">
                <h2 className="mb-3 text-sm font-semibold">Timeline</h2>
                <RecordTimeline entries={timeline ?? []} />
            </div>
        </AppLayout>
    );
}
