import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Line {
    id: number;
    product: { id: number; name: string; sku: string; type: 'raw_material' | 'finished_good' | 'service' };
    quantity_ordered: string;
    quantity_received: string;
    quantity_returned: string;
    unit_cost: string;
}

interface Receipt {
    id: number;
    received_by: { name: string };
    received_at: string;
    note: string | null;
    lines: { id: number; quantity_received: string; purchase_order_line: { id: number; product: { name: string; sku: string } } }[];
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
    payment_status: 'unpaid' | 'partially_paid' | 'paid';
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
    goods_receipts: Receipt[];
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

function ReceiveGoodsForm({ order }: { order: Order }) {
    const receivableLines = order.lines.filter((line) => remaining(line) > 0);
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        note: string;
        lines: { purchase_order_line_id: number; quantity: string }[];
    }>({
        note: '',
        lines: [],
    });

    // Untouched lines default to the full remaining quantity — computed here
    // (not pre-seeded into `lines`) so it always reflects the current order
    // prop rather than whatever it was when this component first mounted.
    transform((formData) => ({
        ...formData,
        lines: receivableLines.map((line) => ({
            purchase_order_line_id: line.id,
            quantity: formData.lines.find((entry) => entry.purchase_order_line_id === line.id)?.quantity ?? String(remaining(line)),
        })),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.purchase-orders.receive', order.id), { onSuccess: () => reset() });
    };

    // Upserts rather than only mapping existing entries — this component can
    // stay mounted (returning null) from before anything was receivable, so
    // useForm's initial `lines` must not be relied on to already contain a
    // row for every current line.
    const updateQuantity = (lineId: number, quantity: string) => {
        const exists = data.lines.some((entry) => entry.purchase_order_line_id === lineId);
        setData(
            'lines',
            exists
                ? data.lines.map((entry) => (entry.purchase_order_line_id === lineId ? { ...entry, quantity } : entry))
                : [...data.lines, { purchase_order_line_id: lineId, quantity }],
        );
    };

    if (receivableLines.length === 0) {
        return null;
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 className="font-medium">Record goods receipt</h3>
            <p className="text-sm text-muted-foreground">Defaults to the full remaining quantity — reduce a line for a partial shipment.</p>
            {receivableLines.map((line) => {
                const entry = data.lines.find((e) => e.purchase_order_line_id === line.id);

                return (
                    <div key={line.id} className="grid grid-cols-[2fr_1fr] items-center gap-2">
                        <span>
                            {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>{' '}
                            <span className="text-xs text-muted-foreground">remaining {remaining(line)}</span>
                        </span>
                        <Input
                            value={entry?.quantity ?? String(remaining(line))}
                            onChange={(e) => updateQuantity(line.id, e.target.value)}
                        />
                    </div>
                );
            })}
            <InputError message={errors.lines} />
            <div className="grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
            </div>
            <Button disabled={processing} className="w-fit">
                Record receipt
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

    // Untouched lines default to 0 (skipped) — computed here rather than
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
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 className="font-medium">Return goods</h3>
            <p className="text-sm text-muted-foreground">Reverses stock for whatever quantity is entered — leave a line at 0 to skip it.</p>
            {returnableLines.map((line) => {
                const entry = data.lines.find((e) => e.purchase_order_line_id === line.id);

                return (
                    <div key={line.id} className="grid grid-cols-[2fr_1fr] items-center gap-2">
                        <span>
                            {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>{' '}
                            <span className="text-xs text-muted-foreground">returnable {returnable(line)}</span>
                        </span>
                        <Input value={entry?.quantity ?? '0'} onChange={(e) => updateQuantity(line.id, e.target.value)} />
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

export default function PurchaseOrderShow({
    order,
    can_approve,
    can_receive,
    can_update_payment,
    can_return,
    can_cancel,
}: {
    order: Order;
    can_approve: boolean;
    can_receive: boolean;
    can_update_payment: boolean;
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
    const setPaymentStatus = (payment_status: string) =>
        router.post(route('purchasing.purchase-orders.payment-status', order.id), { payment_status });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.po_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{order.po_number}</h2>
                        <p className="text-sm text-muted-foreground">
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
                    </div>
                </div>

                {order.approval_request.status === 'pending' && can_approve && (
                    <div className="flex gap-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
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
                    <p className="text-sm text-destructive">Rejected: {order.approval_request.review_note}</p>
                )}

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Product</th>
                            <th className="py-2">Ordered</th>
                            <th className="py-2">Received</th>
                            <th className="py-2">Returned</th>
                            <th className="py-2">Unit cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.lines.map((line) => (
                            <tr key={line.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>
                                    {line.product.type === 'service' && <span className="text-muted-foreground"> — service</span>}
                                </td>
                                <td className="py-2">{line.quantity_ordered}</td>
                                <td className="py-2">{line.quantity_received}</td>
                                <td className="py-2 text-muted-foreground">{line.quantity_returned}</td>
                                <td className="py-2 text-muted-foreground">{line.unit_cost}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

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
                    {can_update_payment && order.approval_request.status === 'approved' && (
                        <div className="flex items-center gap-2">
                            <Label className="text-xs text-muted-foreground">Payment:</Label>
                            <select
                                className="h-9 rounded-md border border-input bg-background px-2 text-sm capitalize"
                                value={order.payment_status}
                                onChange={(e) => setPaymentStatus(e.target.value)}
                            >
                                <option value="unpaid">Unpaid</option>
                                <option value="partially_paid">Partially paid</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    )}
                </div>

                {can_receive && order.approval_request.status === 'approved' && order.status !== 'cancelled' && <ReceiveGoodsForm order={order} />}

                {order.goods_receipts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">Receipt history</h3>
                        {order.goods_receipts.map((receipt) => (
                            <div key={receipt.id} className="rounded-xl border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                                <p className="text-muted-foreground">
                                    {receipt.received_by.name} — {receipt.received_at}
                                    {receipt.note && ` — ${receipt.note}`}
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {receipt.lines.map((line) => (
                                        <li key={line.id}>
                                            {line.purchase_order_line.product.name} ({line.purchase_order_line.product.sku}) —{' '}
                                            {line.quantity_received}
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
                                className="rounded-xl border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                            >
                                <p className="text-muted-foreground">
                                    {purchaseReturn.returned_by.name} — {purchaseReturn.returned_at}
                                    {purchaseReturn.note && ` — ${purchaseReturn.note}`}
                                </p>
                                <ul className="mt-1 list-disc pl-5">
                                    {purchaseReturn.lines.map((line) => (
                                        <li key={line.id}>
                                            {line.purchase_order_line.product.name} ({line.purchase_order_line.product.sku}) —{' '}
                                            {line.quantity_returned}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
