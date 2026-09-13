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
    // Website slice 2.5b: a coupon's discount on the whole order, and its code.
    order_discount: string;
    coupon_code: string | null;
    // D24: the delivery window this order was promised, frozen on the order.
    delivery_promise_min_hours: number | null;
    delivery_promise_max_hours: number | null;
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
    // SSLCommerz slice P1: money paid for orders that no invoice has taken yet.
    paid_in_advance: string;
    total: string;
}

// SSLCommerz slice P1: money paid for this order, before or after its invoice,
// and anything paid back.
interface Payments {
    agreed_total: string;
    paid: string;
    still_to_pay: string;
    held: string;
    refund_owed: string | null;
    can_take_payment: boolean;
    receipts: {
        id: number;
        received_on: string;
        money_account: string | null;
        reference: string | null;
        amount: string;
        invoices: { id: number; invoice_number: string | null; amount: string }[];
    }[];
    refunds: { id: number; refunded_on: string; money_account: string | null; reference: string | null; amount: string }[];
}

interface MoneyAccountOption {
    id: number;
    name: string;
    type: string;
}

// A challan served this order, with what a courier needs: its lines, its parcel,
// and what an invoice for all of it would come to today.
interface Challan {
    id: number;
    delivery_number: string;
    delivered_at: string;
    can_consign: boolean;
    cod_preview: string | null;
    lines: { id: number; product: string | null; sku: string | null; quantity: string }[];
    consignment: {
        id: number;
        courier: string | null;
        consignment_number: string;
        cod_amount: string;
        status: 'booked' | 'delivered' | 'partially_delivered' | 'returned';
        status_label: string;
        outcome_on: string | null;
        collected_amount: string | null;
        invoice: { id: number; invoice_number: string } | null;
        sales_return: { id: number; return_number: string } | null;
    } | null;
}

const today = () => new Date().toLocaleDateString('en-CA');

function BookParcelForm({ challan, couriers }: { challan: Challan; couriers: { id: number; name: string }[] }) {
    const { data, setData, post, errors, processing } = useForm({
        money_account_id: couriers.length === 1 ? String(couriers[0].id) : '',
        consignment_number: '',
        cod_amount: challan.cod_preview ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.consignments.store', challan.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label>Courier</Label>
                <SearchableSelect
                    value={data.money_account_id}
                    onChange={(v) => setData('money_account_id', v)}
                    options={couriers.map((c) => ({ value: String(c.id), label: c.name }))}
                    placeholder="Select courier"
                    searchPlaceholder="Search couriers…"
                />
                <InputError message={errors.money_account_id} />
            </div>
            <div className="grid gap-1">
                <Label>Consignment number</Label>
                <Input className="w-48" value={data.consignment_number} onChange={(e) => setData('consignment_number', e.target.value)} />
                <InputError message={errors.consignment_number} />
            </div>
            <div className="grid gap-1">
                <Label>Amount to collect</Label>
                <Input className="w-32" value={data.cod_amount} onChange={(e) => setData('cod_amount', e.target.value)} />
                <InputError message={errors.cod_amount} />
            </div>
            <Button size="sm" disabled={processing}>
                Hand to courier
            </Button>
            <p className="text-muted-foreground w-full text-xs">
                {challan.cod_preview !== null
                    ? `${challan.cod_preview} is what is still to collect for this whole challan: its invoice today, delivery charge and any discount included, less anything paid in advance. `
                    : 'A line on this challan has no price to bill at, so the amount could not be worked out. '}
                Enter 0 for a parcel already paid for. The invoice is raised when the courier confirms delivery.
            </p>
        </form>
    );
}

function ParcelOutcomeForm({ challan }: { challan: Challan }) {
    const consignment = challan.consignment!;
    const { data, setData, post, errors, processing } = useForm<{
        outcome: 'delivered' | 'partially_delivered' | 'returned';
        outcome_on: string;
        collected_amount: string;
        note: string;
        lines: { delivery_line_id: number; taken: string; written_off: string }[];
    }>({
        outcome: 'delivered',
        outcome_on: today(),
        collected_amount: consignment.cod_amount,
        note: '',
        lines: challan.lines.map((line) => ({ delivery_line_id: line.id, taken: line.quantity, written_off: '0' })),
    });

    const setOutcome = (outcome: typeof data.outcome) => {
        setData((current) => ({
            ...current,
            outcome,
            collected_amount: outcome === 'returned' ? '0' : consignment.cod_amount,
            lines: challan.lines.map((line) => ({
                delivery_line_id: line.id,
                taken: outcome === 'returned' ? '0' : line.quantity,
                written_off: '0',
            })),
        }));
    };

    const updateLine = (id: number, patch: { taken?: string; written_off?: string }) =>
        setData(
            'lines',
            data.lines.map((line) => (line.delivery_line_id === id ? { ...line, ...patch } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.consignments.outcome', consignment.id), { preserveScroll: true });
    };

    const lineErrors = errors as Record<string, string | undefined>;

    return (
        <form onSubmit={submit} className="mt-3 flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label>What the courier reported</Label>
                    <select
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={data.outcome}
                        onChange={(e) => setOutcome(e.target.value as typeof data.outcome)}
                    >
                        <option value="delivered">Delivered</option>
                        <option value="partially_delivered">Partly delivered</option>
                        <option value="returned">Returned</option>
                    </select>
                    <InputError message={errors.outcome} />
                </div>
                <div className="grid gap-1">
                    <Label>On</Label>
                    <Input type="date" className="w-40" value={data.outcome_on} onChange={(e) => setData('outcome_on', e.target.value)} />
                    <InputError message={errors.outcome_on} />
                </div>
                <div className="grid gap-1">
                    <Label>Collected</Label>
                    <Input
                        className="w-32"
                        value={data.collected_amount}
                        disabled={data.outcome === 'returned'}
                        onChange={(e) => setData('collected_amount', e.target.value)}
                    />
                </div>
            </div>
            <InputError message={errors.collected_amount} />

            {data.outcome !== 'delivered' && (
                <table className="w-full max-w-2xl text-sm">
                    <thead>
                        <tr className="text-muted-foreground text-left">
                            <th className="py-1">Product</th>
                            <th className="py-1">Sent</th>
                            {data.outcome === 'partially_delivered' && <th className="py-1">Customer took</th>}
                            <th className="py-1">Came back damaged</th>
                        </tr>
                    </thead>
                    <tbody>
                        {challan.lines.map((line) => {
                            const draft = data.lines.find((entry) => entry.delivery_line_id === line.id)!;

                            return (
                                <tr key={line.id}>
                                    <td className="py-1">
                                        {line.product} <span className="text-muted-foreground">({line.sku})</span>
                                    </td>
                                    <td className="py-1 tabular-nums">{line.quantity}</td>
                                    {data.outcome === 'partially_delivered' && (
                                        <td className="py-1">
                                            <Input
                                                className="w-24"
                                                value={draft.taken}
                                                onChange={(e) => updateLine(line.id, { taken: e.target.value })}
                                            />
                                            <InputError message={lineErrors[`lines.${line.id}.taken`]} />
                                        </td>
                                    )}
                                    <td className="py-1">
                                        <Input
                                            className="w-24"
                                            value={draft.written_off}
                                            onChange={(e) => updateLine(line.id, { written_off: e.target.value })}
                                        />
                                        <InputError message={lineErrors[`lines.${line.id}.written_off`]} />
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}
            <InputError message={errors.lines} />

            <p className="text-muted-foreground text-xs">
                {data.outcome === 'delivered' &&
                    'Raises the invoice for this challan and records what the courier collected as the customer’s payment, held by the courier until its payout.'}
                {data.outcome === 'partially_delivered' &&
                    'Invoices what the customer took. Everything else comes back to the warehouse, except what you mark as damaged, which is written off.'}
                {data.outcome === 'returned' &&
                    'No invoice. The goods come back to the warehouse, except what you mark as damaged, which is written off.'}
            </p>
            <div className="flex items-center gap-2">
                <Input className="max-w-sm" placeholder="Note (optional)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                <Button size="sm" disabled={processing}>
                    Record
                </Button>
            </div>
        </form>
    );
}

function ChallanCard({
    challan,
    couriers,
    canDeliver,
    canRecordOutcome,
}: {
    challan: Challan;
    couriers: { id: number; name: string }[];
    canDeliver: boolean;
    canRecordOutcome: boolean;
}) {
    const consignment = challan.consignment;

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={route('sales.deliveries.index', { search: challan.delivery_number })} className="underline">
                    {challan.delivery_number}
                </Link>
                <span className="text-muted-foreground">- {challan.delivered_at}</span>
                {consignment && (
                    <Badge variant={consignment.status === 'booked' ? 'secondary' : consignment.status === 'returned' ? 'destructive' : 'default'}>
                        {consignment.status_label}
                    </Badge>
                )}
            </div>

            {consignment && (
                <p className="text-muted-foreground mt-1">
                    {consignment.courier} consignment <span className="text-foreground font-medium">{consignment.consignment_number}</span>, to
                    collect {consignment.cod_amount}
                    {consignment.outcome_on && ` - reported ${consignment.outcome_on}`}
                    {consignment.collected_amount !== null && consignment.status !== 'booked' && `, collected ${consignment.collected_amount}`}
                    {consignment.invoice && (
                        <>
                            {', '}
                            <Link href={route('sales.invoices.show', consignment.invoice.id)} className="text-foreground underline">
                                {consignment.invoice.invoice_number}
                            </Link>
                        </>
                    )}
                    {consignment.sales_return && (
                        <>
                            {', '}
                            <Link href={route('sales.returns.show', consignment.sales_return.id)} className="text-foreground underline">
                                {consignment.sales_return.return_number}
                            </Link>
                        </>
                    )}
                </p>
            )}

            {consignment?.status === 'booked' && canRecordOutcome && <ParcelOutcomeForm challan={challan} />}

            {challan.can_consign && canDeliver && couriers.length > 0 && <BookParcelForm challan={challan} couriers={couriers} />}

            {challan.can_consign && canDeliver && couriers.length === 0 && (
                <p className="text-muted-foreground mt-1 text-xs">
                    To send this with a courier, add the courier under Accounting, Bank &amp; cash, as a Courier account.
                </p>
            )}
        </div>
    );
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

function accountOptions(accounts: MoneyAccountOption[]) {
    return accounts.map((account) => ({ value: String(account.id), label: `${account.name} (${account.type})` }));
}

function RecordPaymentForm({ order, accounts, stillToPay }: { order: Order; accounts: MoneyAccountOption[]; stillToPay: string }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: accounts.length === 1 ? String(accounts[0].id) : '',
        amount: stillToPay,
        received_on: today(),
        reference: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.payments.store', order.id), { preserveScroll: true, onSuccess: () => reset('reference') });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label>Received into</Label>
                <SearchableSelect
                    value={data.money_account_id}
                    onChange={(v) => setData('money_account_id', v)}
                    options={accountOptions(accounts)}
                    placeholder="Select account"
                    searchPlaceholder="Search accounts…"
                />
                <InputError message={errors.money_account_id} />
            </div>
            <div className="grid gap-1">
                <Label>Amount</Label>
                <Input className="w-32" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                <InputError message={errors.amount} />
            </div>
            <div className="grid gap-1">
                <Label>Received on</Label>
                <Input type="date" className="w-40" value={data.received_on} onChange={(e) => setData('received_on', e.target.value)} />
                <InputError message={errors.received_on} />
            </div>
            <div className="grid gap-1">
                <Label>Reference</Label>
                <Input
                    className="w-44"
                    placeholder="bKash TrxID, bank ref…"
                    value={data.reference}
                    onChange={(e) => setData('reference', e.target.value)}
                />
                <InputError message={errors.reference} />
            </div>
            <Button size="sm" disabled={processing}>
                Record payment
            </Button>
            <p className="text-muted-foreground w-full text-xs">
                Settles any invoice of this order that is still open; the rest is held for the order and its next invoice takes it.
            </p>
        </form>
    );
}

function RefundForm({ order, accounts, held }: { order: Order; accounts: MoneyAccountOption[]; held: string }) {
    const { data, setData, post, errors, processing } = useForm({
        money_account_id: accounts.length === 1 ? String(accounts[0].id) : '',
        amount: held,
        refunded_on: today(),
        reference: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.refunds.store', order.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label>Paid back from</Label>
                <SearchableSelect
                    value={data.money_account_id}
                    onChange={(v) => setData('money_account_id', v)}
                    options={accountOptions(accounts)}
                    placeholder="Select account"
                    searchPlaceholder="Search accounts…"
                />
                <InputError message={errors.money_account_id} />
            </div>
            <div className="grid gap-1">
                <Label>Amount</Label>
                <Input className="w-32" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                <InputError message={errors.amount} />
            </div>
            <div className="grid gap-1">
                <Label>Refunded on</Label>
                <Input type="date" className="w-40" value={data.refunded_on} onChange={(e) => setData('refunded_on', e.target.value)} />
                <InputError message={errors.refunded_on} />
            </div>
            <div className="grid gap-1">
                <Label>Reference</Label>
                <Input className="w-44" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                <InputError message={errors.reference} />
            </div>
            <Button size="sm" variant="outline" disabled={processing}>
                Record refund
            </Button>
        </form>
    );
}

function PaymentsCard({
    order,
    payments,
    accounts,
    canRecordPayment,
    canRefund,
}: {
    order: Order;
    payments: Payments;
    accounts: MoneyAccountOption[];
    canRecordPayment: boolean;
    canRefund: boolean;
}) {
    const held = parseFloat(payments.held);
    const takesPayment = canRecordPayment && payments.can_take_payment && accounts.length > 0;
    const hasHistory = payments.receipts.length > 0 || payments.refunds.length > 0;

    if (!hasHistory && !takesPayment) {
        return null;
    }

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="font-medium">Payments</h3>
                <p className="text-muted-foreground">
                    Order total {payments.agreed_total}, paid {payments.paid}, still to pay{' '}
                    <span className="text-foreground font-medium">{payments.still_to_pay}</span>
                </p>
            </div>

            {hasHistory && (
                <table className="mt-2 w-full">
                    <tbody>
                        {payments.receipts.map((receipt) => (
                            <tr key={`r${receipt.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-1.5">{receipt.received_on}</td>
                                <td className="py-1.5">
                                    Received into {receipt.money_account}
                                    {receipt.reference && <span className="text-muted-foreground"> ({receipt.reference})</span>}
                                </td>
                                <td className="text-muted-foreground py-1.5">
                                    {/* ⚠ Not "held": a refund comes out of the order
                                        as a whole, so a receipt no invoice took may
                                        already have been paid back. The lines below
                                        say what is held. */}
                                    {receipt.invoices.length === 0
                                        ? 'Paid for this order, no invoice yet'
                                        : receipt.invoices.map((invoice) => `${invoice.amount} to ${invoice.invoice_number}`).join(', ')}
                                </td>
                                <td className="py-1.5 text-right font-medium">{receipt.amount}</td>
                            </tr>
                        ))}
                        {payments.refunds.map((refund) => (
                            <tr key={`f${refund.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-1.5">{refund.refunded_on}</td>
                                <td className="py-1.5">
                                    Paid back from {refund.money_account}
                                    {refund.reference && <span className="text-muted-foreground"> ({refund.reference})</span>}
                                </td>
                                <td className="text-muted-foreground py-1.5">Refund</td>
                                <td className="py-1.5 text-right font-medium">-{refund.amount}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {held > 0 && !payments.refund_owed && (
                <p className="text-muted-foreground mt-2">
                    {payments.held} is held for this order. Its next invoice takes it, and the courier collects only the rest.
                </p>
            )}

            {/* ⚠ Money held by an order nothing more will be billed on is the
                customer's. Said here, where somebody can act on it. */}
            {payments.refund_owed && (
                <p className="text-destructive mt-2 font-medium">
                    Refund owed: {payments.refund_owed}. Nothing more will be billed on this order, so this money is the customer&apos;s.
                </p>
            )}

            {takesPayment && <RecordPaymentForm order={order} accounts={accounts} stillToPay={payments.still_to_pay} />}

            {/* ⚠ Offered only once a refund is OWED. On an order still being
                fulfilled the money is about to pay its invoice, and a refund
                button beside it is an accident waiting to happen. */}
            {canRefund && payments.refund_owed && accounts.length > 0 && <RefundForm order={order} accounts={accounts} held={payments.refund_owed} />}
        </div>
    );
}

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
    challans,
    couriers,
    exposure,
    payments,
    money_accounts,
    can_record_payment,
    can_refund,
    can_approve,
    can_deliver,
    can_cancel,
    can_record_outcome,
    warehouses,
}: {
    order: Order;
    challans: Challan[];
    couriers: { id: number; name: string }[];
    exposure: Exposure;
    payments: Payments;
    money_accounts: MoneyAccountOption[];
    can_record_payment: boolean;
    can_refund: boolean;
    can_approve: boolean;
    can_deliver: boolean;
    can_cancel: boolean;
    can_record_outcome: boolean;
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
                            {/* ⚠ Not "nothing has been paid": a COD customer can
                                still send money ahead, and the Payments panel
                                below is where that is told. */}
                            {order.payment_method === 'cod' ? 'Chose cash on delivery.' : 'Payment method not recorded.'}
                            {order.delivery_charge !== null && ` Delivery agreed at ${order.delivery_charge}.`}
                        </p>
                        {order.delivery_promise_max_hours !== null && (
                            <p className="mt-1">
                                Promised delivery within {order.delivery_promise_min_hours} to {order.delivery_promise_max_hours} hours after
                                confirmation
                                {order.expected_date
                                    ? `: expected by ${order.expected_date}.`
                                    : '. Approving this order confirms it and sets the date.'}
                            </p>
                        )}
                        {parseFloat(order.order_discount) > 0 && (
                            <p className="mt-1">
                                Code {order.coupon_code}: {order.order_discount} off the whole order, billed on the invoice as a discount allowed.
                            </p>
                        )}
                    </div>
                )}

                {/* ⚠ C-SO-3: shown beside the limit, enforced by nobody. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                    <p className="text-muted-foreground">
                        Customer exposure: open invoices {exposure.open_invoices} + undelivered orders {exposure.undelivered_orders}
                        {parseFloat(exposure.paid_in_advance) > 0 && <> - paid in advance {exposure.paid_in_advance}</>} ={' '}
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
                                    {line.bundle_label && <div className="text-muted-foreground text-xs">{line.bundle_label}</div>}
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

                <PaymentsCard
                    order={order}
                    payments={payments}
                    accounts={money_accounts}
                    canRecordPayment={can_record_payment}
                    canRefund={can_refund}
                />

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
                            <ChallanCard
                                key={challan.id}
                                challan={challan}
                                couriers={couriers}
                                canDeliver={can_deliver}
                                canRecordOutcome={can_record_outcome}
                            />
                        ))}
                    </div>
                )}

                {order.note && <p className="text-muted-foreground text-sm whitespace-pre-line">{order.note}</p>}
            </div>
        </AppLayout>
    );
}
