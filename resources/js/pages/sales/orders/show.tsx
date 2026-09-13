import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Line {
    id: number;
    product: {
        id: number;
        name: string;
        sku: string;
        unit_of_measure?: { abbreviation: string } | null;
    };
    quantity_ordered: string;
    quantity_delivered: string;
    unit_price: string;
    // Website slice 2.5a: a discount agreed when the order was taken, and the
    // bundle the line was sold in. Zero and null on everything typed in here.
    line_discount: string;
    bundle_label: string | null;
    price_note: string | null;
    delivery_lines: {
        id: number;
        quantity: string;
        delivery: { id: number; delivery_number: string; delivered_at: string };
    }[];
}

interface Order {
    id: number;
    order_number: string;
    status: 'open' | 'partially_delivered' | 'delivered' | 'closed' | 'cancelled';
    ordered_at: string;
    expected_date: string | null;
    note: string | null;
    customer: { id: number; name: string; credit_limit: string | null; payment_terms: string | null };
    warehouse: { id: number; name: string } | null;
    // Website Phase 2, slice 2.4. All nullable, because an order typed into the
    // ERP has none of them - which is every order before the shops went live.
    channel: 'internal' | 'website' | null;
    shop_reference: string | null;
    delivery_charge: string | null;
    payment_method: string | null;
    store: { id: number; name: string } | null;
    address: {
        recipient_name: string;
        recipient_phone: string;
        district: string;
        thana: string;
        area: string | null;
        postcode: string | null;
        address_line: string;
        landmark: string | null;
    } | null;
    created_by: { name: string };
    approval_request: {
        status: 'pending' | 'approved' | 'rejected';
        requested_by: { name: string };
        reviewed_by: { name: string } | null;
        review_note: string | null;
    };
    lines: Line[];
}

interface Exposure {
    credit_limit: string | null;
    open_invoices: string;
    undelivered_orders: string;
    total: string;
}

const statusVariant: Record<Order['status'], 'secondary' | 'default' | 'outline' | 'destructive'> = {
    open: 'secondary',
    partially_delivered: 'outline',
    delivered: 'default',
    closed: 'outline',
    cancelled: 'destructive',
};

const approvalVariant: Record<Order['approval_request']['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

function remaining(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_ordered) - parseFloat(line.quantity_delivered));
}

function DeliverForm({ order, warehouses }: { order: Order; warehouses: { id: number; name: string; is_default?: boolean }[] }) {
    const deliverableLines = order.lines.filter((line) => remaining(line) > 0);
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        warehouse_id: string;
        note: string;
        lines: { sales_order_line_id: number; quantity: string }[];
    }>({
        // ⚠ The order's warehouse is a DEFAULT, never a commitment (§4.1) -
        // which warehouse actually fulfils is decided here, at the delivery.
        warehouse_id: order.warehouse ? String(order.warehouse.id) : '',
        note: '',
        lines: [],
    });

    // Untouched lines default to the full remaining quantity - computed at
    // submit so it always reflects the current order prop (the PO screen's
    // stale-mount lesson).
    transform((formData) => ({
        ...formData,
        lines: deliverableLines.map((line) => ({
            sales_order_line_id: line.id,
            quantity: formData.lines.find((entry) => entry.sales_order_line_id === line.id)?.quantity ?? String(remaining(line)),
        })),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.deliver', order.id), { onSuccess: () => reset() });
    };

    const updateQuantity = (lineId: number, quantity: string) => {
        const exists = data.lines.some((entry) => entry.sales_order_line_id === lineId);
        setData(
            'lines',
            exists
                ? data.lines.map((entry) => (entry.sales_order_line_id === lineId ? { ...entry, quantity } : entry))
                : [...data.lines, { sales_order_line_id: lineId, quantity }],
        );
    };

    if (deliverableLines.length === 0) {
        return null;
    }

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-xl border p-4">
            <h3 className="font-medium">Record delivery against this order</h3>
            <p className="text-muted-foreground text-sm">
                Defaults to the full remaining quantity - reduce a line for a partial challan. Set a line to 0 to skip it. More than the remaining
                quantity is refused; record any extra as a direct delivery line on the Deliveries screen.
            </p>
            <div className="grid max-w-sm gap-2">
                <Label>Warehouse</Label>
                <SearchableSelect
                    value={data.warehouse_id}
                    onChange={(v) => setData('warehouse_id', v)}
                    options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                    placeholder="Select warehouse"
                    searchPlaceholder="Search warehouses…"
                />
                <InputError message={errors.warehouse_id} />
            </div>
            {deliverableLines.map((line) => {
                const entry = data.lines.find((e) => e.sales_order_line_id === line.id);
                const unit = line.product.unit_of_measure?.abbreviation;

                return (
                    <div key={line.id} className="grid grid-cols-[2fr_1fr] items-center gap-2">
                        <span>
                            {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>{' '}
                            <span className="text-muted-foreground text-xs">
                                remaining {remaining(line)}
                                {unit ? ` ${unit}` : ''}
                            </span>
                        </span>
                        <div className="flex items-center gap-2">
                            <Input value={entry?.quantity ?? String(remaining(line))} onChange={(e) => updateQuantity(line.id, e.target.value)} />
                            {unit && <span className="text-muted-foreground w-10 shrink-0 text-sm">{unit}</span>}
                        </div>
                    </div>
                );
            })}
            <InputError message={errors.lines} />
            <div className="grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
            </div>
            <Button disabled={processing} className="w-fit">
                Record challan
            </Button>
        </form>
    );
}

export default function SalesOrderShow({
    order,
    exposure,
    can_approve,
    can_deliver,
    can_cancel,
    warehouses,
}: {
    order: Order;
    exposure: Exposure;
    can_approve: boolean;
    can_deliver: boolean;
    can_cancel: boolean;
    warehouses: { id: number; name: string; is_default?: boolean }[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales orders', href: '/sales/orders' },
        { title: order.order_number, href: `/sales/orders/${order.id}` },
    ];

    const approve = () => router.post(route('sales.orders.approve', order.id));
    const reject = (review_note: string) => router.post(route('sales.orders.reject', order.id), { review_note });
    const cancel = () => router.post(route('sales.orders.cancel', order.id));
    const closeShort = (reason: string) => router.post(route('sales.orders.close', order.id), { reason });

    // Every challan that served this order, deduplicated across lines.
    const challans = [
        ...new Map(
            order.lines.flatMap((line) => line.delivery_lines).map((deliveryLine) => [deliveryLine.delivery.id, deliveryLine.delivery]),
        ).values(),
    ];

    const overLimit = exposure.credit_limit !== null && parseFloat(exposure.total) > parseFloat(exposure.credit_limit);
    // Only a column when some line carries one: an order typed into the ERP never does.
    const hasDiscount = order.lines.some((line) => parseFloat(line.line_discount) > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.order_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-lg font-medium">{order.order_number}</h2>
                        <p className="text-muted-foreground text-sm">
                            {order.customer.name}
                            {order.warehouse ? ` - preferred warehouse ${order.warehouse.name}` : ''} - ordered {order.ordered_at}
                            {order.expected_date ? `, expected ${order.expected_date}` : ''}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                            {order.approval_request.status}
                        </Badge>
                        <Badge variant={statusVariant[order.status]} className="capitalize">
                            {order.status.replace('_', ' ')}
                        </Badge>
                        {/* The one route to the picking sheet. An engine with no
                            door is the mistake this project has made five times. */}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/orders/${order.id}/pick-list`}>Pick list</Link>
                        </Button>
                    </div>
                </div>

                {/* ⭐⭐ A WEBSITE ORDER, AND WHAT SOMEBODY NEEDS TO ACT ON IT.
                    Slice 2.4 lands these in this approval queue deliberately -
                    a customer placed it themselves and nobody has spoken to
                    them - so the phone number and the destination have to be ON
                    THIS SCREEN. Without them the confirmation call the whole
                    design depends on cannot be made, and an order that cannot
                    be confirmed cannot be dispatched. */}
                {order.channel === 'website' && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                        <p className="font-medium">
                            Placed by the customer on {order.store?.name ?? 'a shop'}
                            {order.shop_reference ? ` - they will quote ${order.shop_reference}` : ''}
                        </p>
                        {order.address && (
                            <p className="text-muted-foreground mt-1">
                                {/* The recipient, who is not always the buyer: a
                                    gift is an ordinary order on these shops. */}
                                Call {order.address.recipient_name} on{' '}
                                <a href={`tel:${order.address.recipient_phone}`} className="text-foreground font-medium underline">
                                    {order.address.recipient_phone}
                                </a>
                                {' - '}
                                {[
                                    order.address.address_line,
                                    order.address.landmark,
                                    order.address.area,
                                    order.address.thana,
                                    order.address.district,
                                    order.address.postcode,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                            </p>
                        )}
                        <p className="text-muted-foreground mt-1">
                            {order.payment_method === 'cod' ? 'Cash on delivery - nothing has been paid.' : 'Payment method not recorded.'}
                            {order.delivery_charge !== null && ` Delivery agreed at ${order.delivery_charge}.`}
                        </p>
                    </div>
                )}

                {/* ⚠ C-SO-3: shown beside the limit, enforced by nobody. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                    <p className="text-muted-foreground">
                        Customer exposure: open invoices {exposure.open_invoices} + undelivered orders {exposure.undelivered_orders} ={' '}
                        <span className="text-foreground font-medium">{exposure.total}</span>
                        {exposure.credit_limit !== null && <> against a credit limit of {exposure.credit_limit}</>}
                    </p>
                    {overLimit && (
                        <p className="text-destructive mt-1 font-medium">Over the credit limit. Shown for your judgement - nothing is blocked.</p>
                    )}
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
                            title={`Reject ${order.order_number}`}
                            description="The sales order will be marked rejected. This cannot be undone."
                            confirmLabel="Reject order"
                            destructive
                            withNote
                            noteLabel="Reason for rejecting (optional)"
                            notePlaceholder="Add a note for the creator…"
                            onConfirm={reject}
                        />
                    </div>
                )}

                {order.approval_request.status === 'pending' && !can_approve && (
                    <p className="text-muted-foreground text-sm">Waiting for a manager to approve - goods cannot leave against a pending order.</p>
                )}

                {order.approval_request.status === 'rejected' && order.approval_request.review_note && (
                    <p className="text-destructive text-sm">Rejected: {order.approval_request.review_note}</p>
                )}

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Product</th>
                            <th className="py-2">Ordered</th>
                            <th className="py-2">Delivered</th>
                            <th className="py-2">Remaining</th>
                            <th className="py-2">Agreed price</th>
                            {hasDiscount && <th className="py-2">Agreed discount</th>}
                            <th className="py-2">Why this price</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.lines.map((line) => (
                            <tr key={line.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    {line.product.name} <span className="text-muted-foreground">({line.product.sku})</span>
                                    {line.bundle_label && <div className="text-muted-foreground text-xs">Sold in {line.bundle_label}</div>}
                                </td>
                                <td className="py-2">{line.quantity_ordered}</td>
                                <td className="py-2">{line.quantity_delivered}</td>
                                <td className="py-2">{remaining(line)}</td>
                                <td className="py-2">{line.unit_price}</td>
                                {hasDiscount && <td className="py-2">{parseFloat(line.line_discount) > 0 ? line.line_discount : ''}</td>}
                                <td className="text-muted-foreground py-2">{line.price_note}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {order.status === 'closed' && (
                    <p className="text-muted-foreground text-sm">
                        Closed short - the remaining quantities above are the recorded shortfall and are no longer owed.
                    </p>
                )}

                {can_cancel && order.status === 'open' && (
                    <div className="flex flex-wrap gap-2">
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="ghost">
                                    Cancel order
                                </Button>
                            }
                            title={`Cancel ${order.order_number}`}
                            description="Nothing has been delivered; the order dies untouched. This cannot be undone."
                            confirmLabel="Cancel order"
                            cancelLabel="Keep order"
                            destructive
                            onConfirm={() => cancel()}
                        />
                    </div>
                )}

                {can_cancel && order.status === 'partially_delivered' && (
                    <div className="flex flex-wrap gap-2">
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" variant="ghost">
                                    Close short
                                </Button>
                            }
                            title={`Close ${order.order_number} short`}
                            description="The customer will not take the rest. The undelivered remainder stops being owed and stays visible as the shortfall. This cannot be undone."
                            confirmLabel="Close short"
                            cancelLabel="Keep order open"
                            destructive
                            withNote
                            noteLabel="Reason (required)"
                            notePlaceholder="Why is the rest not being taken?"
                            onConfirm={closeShort}
                        />
                    </div>
                )}

                {can_deliver && order.approval_request.status === 'approved' && ['open', 'partially_delivered'].includes(order.status) && (
                    <DeliverForm order={order} warehouses={warehouses} />
                )}

                {challans.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">Challans against this order</h3>
                        {challans.map((challan) => (
                            <div key={challan.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                                <Link href={route('sales.deliveries.index', { search: challan.delivery_number })} className="underline">
                                    {challan.delivery_number}
                                </Link>{' '}
                                <span className="text-muted-foreground">- {challan.delivered_at}</span>
                            </div>
                        ))}
                    </div>
                )}

                {order.note && <p className="text-muted-foreground text-sm whitespace-pre-line">{order.note}</p>}
            </div>
        </AppLayout>
    );
}
