import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    ApiBookParcelForm,
    CourierStatusPanel,
    SettleBookingPanel,
    type CourierAccountOption,
    type CourierBooking,
    type CourierDraft,
    type CourierInfo,
    type CourierPrompt,
} from '@/components/couriers/courier-parcel';
import {
    ClosedPeriodHint,
    DoorResolutionFields,
    ParcelSettlementPanel,
    ReportLossForm,
    type DoorFields,
    type ParcelSettlement,
} from '@/components/couriers/parcel-settlement';
import { RelatedDocumentsPanel } from '@/components/documents/related-documents-panel';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

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
    // Owner, 17 Oct 2026: "gift wrap", "extra sealed".
    description: string | null;
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
    // Owner, 17 Oct 2026: for the packer and the rider.
    special_instructions: string | null;
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
    // Joy, 11 Oct 2026: the brand the order is sold under. Null on older orders.
    brand: { id: number; code: string; name: string } | null;
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
    // ⚠ Null for an online order still waiting for its payment (SSLCommerz slice
    // P2c): it is sent for approval once the gateway's payment is booked.
    approval_request: {
        status: 'pending' | 'approved' | 'rejected';
        requested_by: { name: string };
        reviewed_by: { name: string } | null;
        review_note: string | null;
    } | null;
    lines: Line[];
}

interface Exposure {
    credit_limit: string | null;
    open_invoices: string;
    // F29: goods out on a challan that no invoice has billed yet.
    delivered_not_invoiced: string;
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
    // SSLCommerz slice P2c: every attempt to pay online.
    online: {
        id: number;
        tran_id: string;
        status: 'initiated' | 'proven' | 'paid' | 'failed' | 'cancelled' | 'expired';
        status_label: string;
        is_live: boolean;
        amount: string;
        amount_paid: string | null;
        fee: string | null;
        bank_tran_id: string | null;
        card_type: string | null;
        risky: boolean;
        risk_title: string | null;
        problem: string | null;
        started_at: string | null;
        // Slice P4: what it can still give back through SSLCommerz.
        refundable: string | null;
    }[];
    // SSLCommerz slice P4: every refund sent through SSLCommerz, and what is on
    // its way back now (already taken out of `held`).
    gateway_refunds: GatewayRefundRow[];
    refunding: string;
}

interface GatewayRefundRow {
    id: number;
    refund_trans_id: string;
    tran_id: string | null;
    amount: string;
    remarks: string;
    status: 'requested' | 'processing' | 'confirmed' | 'refunded' | 'failed' | 'cancelled';
    status_label: string;
    in_flight: boolean;
    refund_ref_id: string | null;
    problem: string | null;
    requested_by: string | null;
    requested_at: string | null;
    refunded_at: string | null;
    last_checked_at: string | null;
}

interface MoneyAccountOption {
    id: number;
    name: string;
    type: string;
}

interface ParcelWeightInfo {
    known_grams: number;
    auto_grams: number | null;
    missing_count: number;
    missing: { product_id: number; sku: string; name: string; quantity: string }[];
}

interface OrderWeight {
    auto_grams: number | null;
    typed_grams: number | null;
    effective_grams: number | null;
    now: ParcelWeightInfo;
}

const grams = (value: number) => `${value.toLocaleString('en-IN')} g`;

// A challan served this order, with what a courier needs: its lines, its parcel,
// and what an invoice for all of it would come to today.
interface Challan {
    id: number;
    delivery_number: string;
    delivered_at: string;
    can_consign: boolean;
    cod_preview: string | null;
    // Courier hub slice 3: what the challan weighs and the weight to offer.
    weight: ParcelWeightInfo;
    weight_suggested: number | null;
    lines: { id: number; product: string | null; sku: string | null; quantity: string }[];
    // Courier hub slice 4: what an API booking would send, and a booking whose
    // answer is still open (or was refused).
    courier_draft: CourierDraft | null;
    courier_booking: CourierBooking | null;
    consignment: {
        id: number;
        courier_info: CourierInfo | null;
        courier: string | null;
        consignment_number: string;
        cod_amount: string;
        declared_weight_grams: number | null;
        declared_weight_auto_grams: number | null;
        status: 'booked' | 'delivered' | 'partially_delivered' | 'returned' | 'paid_return' | 'lost' | 'damaged';
        status_label: string;
        outcome_on: string | null;
        // Courier hub slice 8.
        settlement: ParcelSettlement;
        collected_amount: string | null;
        invoice: { id: number; invoice_number: string } | null;
        sales_return: { id: number; return_number: string } | null;
    } | null;
}

const today = () => dhakaToday();

function BookParcelForm({ challan, couriers }: { challan: Challan; couriers: { id: number; name: string }[] }) {
    const { data, setData, post, errors, processing } = useForm({
        money_account_id: couriers.length === 1 ? String(couriers[0].id) : '',
        consignment_number: '',
        cod_amount: challan.cod_preview ?? '',
        // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): prefilled, changed freely.
        declared_weight_grams: challan.weight_suggested === null ? '' : String(challan.weight_suggested),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.consignments.store', challan.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label required>Courier</Label>
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
                <Label required>Consignment number</Label>
                <Input
                    className="w-48"
                    value={data.consignment_number}
                    onChange={(e) => setData('consignment_number', e.target.value)}
                    aria-required
                />
                <InputError message={errors.consignment_number} />
            </div>
            <div className="grid gap-1">
                <Label required>Amount to collect</Label>
                <Input
                    className="w-32"
                    placeholder="0 if already paid"
                    value={data.cod_amount}
                    onChange={(e) => setData('cod_amount', e.target.value)}
                    aria-required
                />
                <InputError message={errors.cod_amount} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`parcel-weight-${challan.id}`}>Weight (g)</Label>
                <Input
                    id={`parcel-weight-${challan.id}`}
                    type="number"
                    inputMode="numeric"
                    min={1}
                    step={1}
                    className="w-32"
                    value={data.declared_weight_grams}
                    onChange={(e) => setData('declared_weight_grams', e.target.value)}
                    placeholder={challan.weight.missing_count > 0 ? `at least ${challan.weight.known_grams}` : ''}
                />
                <InputError message={errors.declared_weight_grams} />
            </div>
            <Button size="sm" disabled={processing}>
                Hand to courier
            </Button>
            {challan.cod_preview === null && (
                <p className="text-muted-foreground w-full text-xs">
                    A line on this challan has no price to bill at, so the amount could not be worked out.
                </p>
            )}
            {challan.weight.missing_count > 0 && (
                <p className="w-full text-xs text-amber-700 dark:text-amber-400">
                    {challan.weight.missing_count} item(s) without weight ({challan.weight.missing.map((line) => line.sku || line.name).join(', ')}),
                    so the weight could not be worked out in full. Weigh the parcel and type it.
                </p>
            )}
        </form>
    );
}

function ParcelOutcomeForm({ challan, prompt }: { challan: Challan; prompt?: CourierPrompt | null }) {
    const consignment = challan.consignment!;
    // ⭐ Courier hub slice 4 (ruling 1): what the courier reported is offered,
    // never posted by itself. A person confirms it here.
    const startOutcome = prompt?.outcome ?? 'delivered';
    // Nothing taken by the customer: a return, paid or not (slice 8).
    const allBack = (outcome: string) => outcome === 'returned' || outcome === 'paid_return';
    const { data, setData, post, errors, processing } = useForm<
        {
            outcome: 'delivered' | 'partially_delivered' | 'returned' | 'paid_return';
            outcome_on: string;
            collected_amount: string;
            note: string;
            lines: { delivery_line_id: number; taken: string; written_off: string }[];
        } & DoorFields
    >({
        outcome: startOutcome,
        outcome_on: today(),
        collected_amount: startOutcome === 'returned' ? '0' : (prompt?.collected ?? consignment.cod_amount),
        note: '',
        lines: challan.lines.map((line) => ({
            delivery_line_id: line.id,
            taken: allBack(startOutcome) ? '0' : line.quantity,
            written_off: '0',
        })),
        resolution: '',
        door_reason: '',
        prices: [],
    });

    const setOutcome = (outcome: typeof data.outcome) => {
        setData((current) => ({
            ...current,
            outcome,
            collected_amount: outcome === 'returned' ? '0' : outcome === 'paid_return' ? '' : consignment.cod_amount,
            lines: challan.lines.map((line) => ({
                delivery_line_id: line.id,
                taken: allBack(outcome) ? '0' : line.quantity,
                written_off: '0',
            })),
            resolution: '',
            door_reason: '',
            prices: [],
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
            {prompt && (
                <p className="rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-xs font-medium dark:border-sky-800 dark:bg-sky-950/40">
                    {prompt.message}
                </p>
            )}
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label required htmlFor={`outcome-on-${challan.id}`}>
                        Date
                    </Label>
                    <Input
                        id={`outcome-on-${challan.id}`}
                        type="date"
                        className="w-40"
                        value={data.outcome_on}
                        onChange={(e) => setData('outcome_on', e.target.value)}
                        aria-required
                    />
                    <InputError message={errors.outcome_on} />
                </div>
                <div className="grid gap-1">
                    <Label required htmlFor={`outcome-${challan.id}`}>
                        What the courier reported
                    </Label>
                    <select
                        id={`outcome-${challan.id}`}
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        value={data.outcome}
                        onChange={(e) => setOutcome(e.target.value as typeof data.outcome)}
                        aria-required
                    >
                        <option value="delivered">Delivered</option>
                        <option value="partially_delivered">Partly delivered</option>
                        <option value="returned">Returned</option>
                        <option value="paid_return">Paid return (refused, delivery charge paid)</option>
                    </select>
                    <InputError message={errors.outcome} />
                </div>
                <div className="grid gap-1">
                    <Label required htmlFor={`outcome-collected-${challan.id}`}>
                        Collected
                    </Label>
                    <Input
                        id={`outcome-collected-${challan.id}`}
                        className="w-32"
                        value={data.collected_amount}
                        disabled={data.outcome === 'returned'}
                        onChange={(e) => setData('collected_amount', e.target.value)}
                        aria-required
                    />
                </div>
            </div>
            <InputError message={errors.collected_amount} />
            <ClosedPeriodHint date={data.outcome_on} />
            {data.outcome === 'paid_return' && (
                <p className="text-muted-foreground text-xs">
                    The goods come back and nothing is invoiced; what the customer paid the courier is delivery income the courier holds.
                </p>
            )}
            {!allBack(data.outcome) && (
                <DoorResolutionFields
                    data={data}
                    onChange={(patch) => setData((current) => ({ ...current, ...patch }))}
                    lines={challan.lines}
                    errors={lineErrors}
                />
            )}

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
                                    <td className="py-1 tabular-nums">{formatQuantity(line.quantity)}</td>
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
    courierAccounts,
    canBookCourier,
    canDeliver,
    canRecordOutcome,
    customerId,
}: {
    challan: Challan;
    couriers: { id: number; name: string }[];
    courierAccounts: CourierAccountOption[];
    canBookCourier: boolean;
    canDeliver: boolean;
    canRecordOutcome: boolean;
    customerId: number;
}) {
    const consignment = challan.consignment;
    const booking = challan.courier_booking;
    // A booking with no clear answer blocks both ways in: settle it first.
    const bookingOpen = booking !== null && booking.status !== 'failed';
    // ⭐ Only accounts that serve this order's brand can book (Joy, 11 Oct 2026).
    const apiReady = canBookCourier && courierAccounts.some((a) => a.available) && challan.courier_draft !== null;
    const [mode, setMode] = useState<'api' | 'manual'>(apiReady ? 'api' : 'manual');

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={route('sales.deliveries.index', { search: challan.delivery_number })} className="underline">
                    {challan.delivery_number}
                </Link>
                <span className="text-muted-foreground">- {showDate(challan.delivered_at)}</span>
                {/* ⭐ The next steps where the challan is (21 Sep 2026): print it for
                    the driver, then bill it, without hunting through two lists. */}
                <a href={route('sales.deliveries.print', challan.id)} target="_blank" rel="noreferrer" className="text-muted-foreground underline">
                    Print challan
                </a>
                {/* A parcel's challan is billed by the courier's answer, never by hand. */}
                {canDeliver && !consignment && (
                    <Link href={route('sales.invoices.create', { customer_id: customerId, delivery_id: challan.id })} className="underline">
                        Invoice this challan
                    </Link>
                )}
                {consignment && (
                    <Badge variant={consignment.status === 'booked' ? 'secondary' : consignment.status === 'returned' ? 'destructive' : 'default'}>
                        {consignment.status_label}
                    </Badge>
                )}
            </div>

            {consignment && (
                <p className="text-muted-foreground mt-1">
                    {consignment.courier} consignment <span className="text-foreground font-medium">{consignment.consignment_number}</span>, to
                    collect {formatMoney(consignment.cod_amount)}
                    {consignment.declared_weight_grams !== null && `, ${grams(consignment.declared_weight_grams)}`}
                    {consignment.outcome_on && ` - reported ${showDate(consignment.outcome_on)}`}
                    {consignment.collected_amount !== null &&
                        consignment.status !== 'booked' &&
                        `, collected ${formatMoney(consignment.collected_amount)}`}
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

            {consignment?.courier_info && (
                <CourierStatusPanel consignmentId={consignment.id} info={consignment.courier_info} canBook={canBookCourier} />
            )}

            {consignment && <ParcelSettlementPanel settlement={consignment.settlement} />}

            {consignment?.status === 'booked' && canRecordOutcome && !consignment.settlement.waiting_discount && (
                <>
                    <ParcelOutcomeForm challan={challan} prompt={consignment.courier_info?.prompt} />
                    <ReportLossForm consignmentId={consignment.id} codAmount={consignment.cod_amount} />
                </>
            )}

            {booking && <SettleBookingPanel booking={booking} canBook={canBookCourier} />}

            {challan.can_consign && canDeliver && !bookingOpen && apiReady && (
                <div className="mt-3 flex gap-3 text-xs">
                    <button
                        type="button"
                        className={mode === 'api' ? 'font-medium underline' : 'text-muted-foreground'}
                        onClick={() => setMode('api')}
                    >
                        Book through the courier
                    </button>
                    <button
                        type="button"
                        className={mode === 'manual' ? 'font-medium underline' : 'text-muted-foreground'}
                        onClick={() => setMode('manual')}
                    >
                        Type a consignment number
                    </button>
                </div>
            )}

            {challan.can_consign && canDeliver && !bookingOpen && apiReady && mode === 'api' && (
                <ApiBookParcelForm
                    challanId={challan.id}
                    accounts={courierAccounts}
                    draft={challan.courier_draft!}
                    weightSuggested={challan.weight_suggested}
                    missingWeight={challan.weight.missing_count}
                />
            )}

            {challan.can_consign && canDeliver && !bookingOpen && (!apiReady || mode === 'manual') && couriers.length > 0 && (
                <BookParcelForm challan={challan} couriers={couriers} />
            )}

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

const approvalVariant: Record<'pending' | 'approved' | 'rejected', 'secondary' | 'default' | 'destructive'> = {
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
        // A back-dated order (entered late) starts on its own date; still editable.
        received_on: order.ordered_at < today() ? order.ordered_at : today(),
        reference: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.payments.store', order.id), { preserveScroll: true, onSuccess: () => reset('reference') });
    };

    return (
        <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
            {/* Joy, 19 Oct 2026: the date comes first on every form. */}
            <div className="grid gap-1">
                <Label required>Received on</Label>
                <Input type="date" className="w-40" value={data.received_on} onChange={(e) => setData('received_on', e.target.value)} aria-required />
                <InputError message={errors.received_on} />
            </div>
            <div className="grid gap-1">
                <Label required>Received into</Label>
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
                <Label required>Amount</Label>
                <Input className="w-32" value={data.amount} onChange={(e) => setData('amount', e.target.value)} aria-required />
                <InputError message={errors.amount} />
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
        </form>
    );
}

function RefundForm({ order, accounts, held, titled = false }: { order: Order; accounts: MoneyAccountOption[]; held: string; titled?: boolean }) {
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
            {titled && <p className="w-full font-medium">Or record money you sent back by bank, cash or bKash</p>}
            <div className="grid gap-1">
                <Label required>Refunded on</Label>
                <Input type="date" className="w-40" value={data.refunded_on} onChange={(e) => setData('refunded_on', e.target.value)} aria-required />
                <InputError message={errors.refunded_on} />
            </div>
            <div className="grid gap-1">
                <Label required>Paid back from</Label>
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
                <Label required>Amount</Label>
                <Input className="w-32" value={data.amount} onChange={(e) => setData('amount', e.target.value)} aria-required />
                <InputError message={errors.amount} />
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

function lesser(a: string, b: string): string {
    return parseFloat(a) <= parseFloat(b) ? a : b;
}

// SSLCommerz slice P4: send the money back through SSLCommerz itself. The Owner
// only; the books move when SSLCommerz says the money is back.
function GatewayRefundForm({ order, owed, payments }: { order: Order; owed: string; payments: Payments['online'] }) {
    const refundable = payments.filter((attempt) => attempt.refundable !== null && parseFloat(attempt.refundable) > 0);
    const first = refundable[0];
    const { data, setData, post, errors, processing } = useForm({
        gateway_payment_id: first ? String(first.id) : '',
        amount: first?.refundable ? lesser(owed, first.refundable) : owed,
        remarks: '',
    });

    const choose = (id: string) => {
        const attempt = refundable.find((candidate) => String(candidate.id) === id);
        setData((current) => ({ ...current, gateway_payment_id: id, amount: attempt?.refundable ? lesser(owed, attempt.refundable) : owed }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.gateway-refunds.store', order.id), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-3 grid gap-3 rounded-md border border-dashed p-3">
            <p className="font-medium">Refund through SSLCommerz</p>
            <div className="flex flex-wrap items-end gap-3">
                {refundable.length > 1 && (
                    <div className="grid gap-1">
                        <Label required>Payment</Label>
                        <SearchableSelect
                            value={data.gateway_payment_id}
                            onChange={choose}
                            options={refundable.map((attempt) => ({
                                value: String(attempt.id),
                                label: `${attempt.tran_id} (${attempt.refundable} left)`,
                            }))}
                            placeholder="Select payment"
                            searchPlaceholder="Search payments…"
                        />
                    </div>
                )}
                <div className="grid gap-1">
                    <Label required>Amount</Label>
                    <Input className="w-32" value={data.amount} onChange={(e) => setData('amount', e.target.value)} aria-required />
                    <InputError message={errors.amount} />
                </div>
                {/* ⚠ Its own full row on a phone: beside the amount it pushed the
                    button over the field (found on the 390px walk). */}
                <div className="grid w-full gap-1 sm:w-auto sm:min-w-64 sm:flex-1">
                    <Label required>Reason</Label>
                    <Input
                        className="w-full"
                        maxLength={255}
                        placeholder="Order cancelled, parcel returned…"
                        value={data.remarks}
                        onChange={(e) => setData('remarks', e.target.value)}
                        aria-required
                    />
                    <InputError message={errors.remarks} />
                </div>
                <Button size="sm" variant="outline" disabled={processing}>
                    Send refund
                </Button>
            </div>
            <InputError message={errors.gateway_payment_id} />
        </form>
    );
}

function GatewayRefundList({ order, refunds, canCheck }: { order: Order; refunds: GatewayRefundRow[]; canCheck: boolean }) {
    return (
        <div className="mt-3 grid gap-1">
            <p className="font-medium">Refunds through SSLCommerz</p>
            {refunds.map((refund) => (
                <div key={refund.id} className="grid gap-0.5">
                    <p className="flex flex-wrap items-center gap-x-2">
                        <span>
                            {showDate(refund.requested_at)} - {refund.refund_trans_id} - {formatMoney(refund.amount)} -{' '}
                            <span className={refund.status === 'refunded' ? 'font-medium' : 'text-muted-foreground'}>{refund.status_label}</span>
                            {refund.refunded_at && refund.status === 'refunded' && (
                                <span className="text-muted-foreground"> on {showDate(refund.refunded_at)}</span>
                            )}
                        </span>
                        {canCheck && refund.in_flight && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 px-2"
                                onClick={() =>
                                    router.post(route('sales.orders.gateway-refunds.check', [order.id, refund.id]), {}, { preserveScroll: true })
                                }
                            >
                                Check now
                            </Button>
                        )}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {refund.remarks}
                        {refund.requested_by && ` - asked by ${refund.requested_by}`}
                        {refund.refund_ref_id && ` - SSLCommerz ref ${refund.refund_ref_id}`}
                        {refund.in_flight && refund.last_checked_at && ` - last asked ${showDate(refund.last_checked_at)}`}
                    </p>
                    {refund.problem && (
                        <p className={refund.in_flight ? 'text-amber-600 dark:text-amber-500' : 'text-muted-foreground'}>{refund.problem}</p>
                    )}
                </div>
            ))}
        </div>
    );
}

function PaymentsCard({
    order,
    payments,
    accounts,
    canRecordPayment,
    canRefund,
    canGatewayRefund,
}: {
    order: Order;
    payments: Payments;
    accounts: MoneyAccountOption[];
    canRecordPayment: boolean;
    canRefund: boolean;
    canGatewayRefund: boolean;
}) {
    const held = parseFloat(payments.held);
    const refunding = parseFloat(payments.refunding);
    const takesPayment = canRecordPayment && payments.can_take_payment && accounts.length > 0;
    const hasHistory =
        payments.receipts.length > 0 || payments.refunds.length > 0 || payments.online.length > 0 || payments.gateway_refunds.length > 0;
    const gatewayRefundable = payments.online.some((attempt) => attempt.refundable !== null && parseFloat(attempt.refundable) > 0);

    if (!hasHistory && !takesPayment) {
        return null;
    }

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="font-medium">Payments</h3>
                <p className="text-muted-foreground">
                    Order total {formatMoney(payments.agreed_total)}, paid {formatMoney(payments.paid)}, still to pay{' '}
                    <span className="text-foreground font-medium">{formatMoney(payments.still_to_pay)}</span>
                </p>
            </div>

            {hasHistory && (
                <table className="mt-2 w-full">
                    <tbody>
                        {payments.receipts.map((receipt) => (
                            <tr key={`r${receipt.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-1.5">{showDate(receipt.received_on)}</td>
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
                                        : receipt.invoices.map((invoice) => `${formatMoney(invoice.amount)} to ${invoice.invoice_number}`).join(', ')}
                                </td>
                                <td className="py-1.5 text-right font-medium">{formatMoney(receipt.amount)}</td>
                            </tr>
                        ))}
                        {payments.refunds.map((refund) => (
                            <tr key={`f${refund.id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-1.5">{showDate(refund.refunded_on)}</td>
                                <td className="py-1.5">
                                    Paid back from {refund.money_account}
                                    {refund.reference && <span className="text-muted-foreground"> ({refund.reference})</span>}
                                </td>
                                <td className="text-muted-foreground py-1.5">Refund</td>
                                <td className="py-1.5 text-right font-medium">-{formatMoney(refund.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {payments.online.length > 0 && (
                <div className="mt-3 grid gap-1">
                    {/* ⚠ Not "Paid online": most attempts listed here are not paid.
                        Found on the walk, above a failed attempt. */}
                    <p className="font-medium">Online payment attempts (SSLCommerz)</p>
                    {payments.online.map((attempt) => (
                        <div key={attempt.id} className="grid gap-0.5">
                            <p>
                                {showDate(attempt.started_at)} - {attempt.tran_id}
                                {!attempt.is_live && <span className="text-muted-foreground"> (sandbox)</span>}
                                {' - '}
                                <span className={attempt.status === 'paid' ? 'font-medium' : 'text-muted-foreground'}>{attempt.status_label}</span>
                                {attempt.amount_paid && ` - ${attempt.amount_paid}`}
                                {attempt.fee && parseFloat(attempt.fee) > 0 && (
                                    <span className="text-muted-foreground"> (gateway fee {attempt.fee})</span>
                                )}
                                {attempt.card_type && <span className="text-muted-foreground"> by {attempt.card_type}</span>}
                            </p>
                            {/* ⚠ Said where the order is confirmed, before the parcel
                                leaves: the gateway itself doubted this payment. */}
                            {attempt.risky && (
                                <p className="text-destructive font-medium">
                                    SSLCommerz flagged this payment as risky{attempt.risk_title ? `: ${attempt.risk_title}` : ''}. Check it before
                                    dispatch.
                                </p>
                            )}
                            {attempt.status === 'proven' && attempt.problem && (
                                <p className="text-amber-600 dark:text-amber-500">
                                    The customer has paid, and it is not in the books yet: {attempt.problem} It is retried every ten minutes.
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {payments.gateway_refunds.length > 0 && (
                <GatewayRefundList order={order} refunds={payments.gateway_refunds} canCheck={canGatewayRefund} />
            )}

            {held > 0 && !payments.refund_owed && (
                <p className="text-muted-foreground mt-2">
                    {payments.held} is held for this order. Its next invoice takes it, and the courier collects only the rest.
                </p>
            )}

            {/* ⚠ Said beside the refund owed, so money already on its way back is
                never paid back a second time by hand. */}
            {refunding > 0 && (
                <p className="mt-2 font-medium text-amber-600 dark:text-amber-500">
                    {payments.refunding} is on its way back through SSLCommerz and is set aside until SSLCommerz finishes.
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
            {canGatewayRefund && payments.refund_owed && gatewayRefundable && (
                <GatewayRefundForm order={order} owed={payments.refund_owed} payments={payments.online} />
            )}
            {canRefund && payments.refund_owed && accounts.length > 0 && (
                <RefundForm order={order} accounts={accounts} held={payments.refund_owed} titled={canGatewayRefund && gatewayRefundable} />
            )}
        </div>
    );
}

function remaining(line: Line): number {
    return Math.max(0, parseFloat(line.quantity_ordered) - parseFloat(line.quantity_delivered));
}

function DeliverForm({ order, warehouses }: { order: Order; warehouses: { id: number; name: string; is_default?: boolean }[] }) {
    const deliverableLines = order.lines.filter((line) => remaining(line) > 0);
    const today = dhakaToday();
    const { data, setData, post, errors, processing, reset, transform } = useForm<{
        warehouse_id: string;
        delivered_at: string;
        note: string;
        lines: { sales_order_line_id: number; quantity: string }[];
    }>({
        // ⚠ The order's warehouse is a DEFAULT, never a commitment (§4.1) -
        // which warehouse actually fulfils is decided here, at the delivery.
        warehouse_id: order.warehouse ? String(order.warehouse.id) : '',
        // ⭐ Joy, 19 Oct 2026: the challan date. A back-dated order (entered
        // late) starts on its own order date; anything else starts today.
        delivered_at: order.ordered_at < today ? order.ordered_at : today,
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
            <div className="flex flex-wrap items-start gap-3">
                <div className="grid w-full gap-2 sm:w-44">
                    <Label htmlFor="challan-date" required>
                        Challan date
                    </Label>
                    <Input
                        id="challan-date"
                        type="date"
                        min={order.ordered_at}
                        max={today}
                        value={data.delivered_at}
                        onChange={(e) => setData('delivered_at', e.target.value)}
                    />
                    <InputError message={errors.delivered_at} />
                </div>
                <div className="grid w-full gap-2 sm:w-72">
                    <Label required>Warehouse</Label>
                    <SearchableSelect
                        value={data.warehouse_id}
                        onChange={(v) => setData('warehouse_id', v)}
                        options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                        placeholder="Select warehouse"
                        searchPlaceholder="Search warehouses…"
                    />
                    <InputError message={errors.warehouse_id} />
                </div>
            </div>
            {data.delivered_at < today && (
                <p className="text-muted-foreground text-xs">
                    Back-dated: the goods leave stock and the cost of sales is booked on {showDate(data.delivered_at)}. Only stock that had arrived by
                    then can go out.
                </p>
            )}
            {deliverableLines.map((line, index) => {
                const entry = data.lines.find((e) => e.sales_order_line_id === line.id);
                const unit = line.product.unit_of_measure?.abbreviation;
                // ⚠⚠ Over-delivery ("only 20 remains on order ...") used to be
                // refused on a key this form never rendered, so Record challan
                // looked like it did nothing - the commonest warehouse mistake,
                // failing silently (audit #1, 21 Sep 2026). The server now puts
                // every per-line refusal on `lines.{row}.quantity`, where row is
                // this line's place in what the transform above sends.
                const lineError = (errors as Record<string, string | undefined>)[`lines.${index}.quantity`];

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
                            <Input
                                value={entry?.quantity ?? String(remaining(line))}
                                onChange={(e) => updateQuantity(line.id, e.target.value)}
                                aria-required
                            />
                            {unit && <span className="text-muted-foreground w-10 shrink-0 text-sm">{unit}</span>}
                        </div>
                        <InputError message={lineError} className="col-span-2" />
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

interface NextStep {
    kind: 'approve' | 'invoice' | 'parcel' | 'door_discount' | 'collect' | 'deliver' | 'done';
    title: string;
    detail: string;
    href: string | null;
    can: boolean;
}

// ⭐ What is left to do on this order, in order (21 Sep 2026): the order,
// challan, invoice and receipt are four screens, and nothing said which was
// next. The first step leads; any others are listed under it.
function NextSteps({ steps }: { steps: NextStep[] }) {
    if (steps.length === 0) {
        return null;
    }

    const [first, ...rest] = steps;

    if (first.kind === 'done') {
        return (
            <p className="text-muted-foreground text-sm">
                <span className="text-foreground font-medium">{first.title}.</span> {first.detail}
            </p>
        );
    }

    const action = (step: NextStep, primary: boolean) => {
        if (step.href === null || !step.can) {
            return null;
        }
        return (
            <Button size="sm" variant={primary ? 'default' : 'outline'} asChild>
                {step.href.startsWith('#') ? <a href={step.href}>{step.title}</a> : <Link href={step.href}>{step.title}</Link>}
            </Button>
        );
    };

    return (
        <div className="border-primary/40 bg-primary/5 flex flex-col gap-2 rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium">Next: {first.title}</p>
                    <p className="text-muted-foreground">{first.detail}</p>
                </div>
                {action(first, true)}
            </div>
            {rest.map((step) => (
                <div key={step.kind} className="border-primary/20 flex flex-wrap items-center justify-between gap-2 border-t pt-2">
                    <div>
                        <p>Also: {step.title}</p>
                        <p className="text-muted-foreground">{step.detail}</p>
                    </div>
                    {action(step, false)}
                </div>
            ))}
        </div>
    );
}

export default function SalesOrderShow({
    order,
    challans,
    next_steps,
    couriers,
    exposure,
    payments,
    money_accounts,
    can_record_payment,
    can_refund,
    can_gateway_refund,
    can_approve,
    can_deliver,
    can_cancel,
    can_record_outcome,
    warehouses,
    credit_refusal,
    can_override_credit,
    weight,
    courier_accounts,
    can_book_courier,
}: {
    order: Order;
    challans: Challan[];
    next_steps: NextStep[];
    couriers: { id: number; name: string }[];
    exposure: Exposure;
    payments: Payments;
    money_accounts: MoneyAccountOption[];
    can_record_payment: boolean;
    can_refund: boolean;
    can_gateway_refund: boolean;
    can_approve: boolean;
    can_deliver: boolean;
    can_cancel: boolean;
    can_record_outcome: boolean;
    warehouses: { id: number; name: string; is_default?: boolean }[];
    credit_refusal: string | null;
    can_override_credit: boolean;
    weight: OrderWeight;
    courier_accounts: CourierAccountOption[];
    can_book_courier: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Orders', href: '/sales/orders' },
        { title: order.order_number, href: `/sales/orders/${order.id}` },
    ];

    const approve = () => router.post(route('sales.orders.approve', order.id));
    // ⚠ Sets the credit rules aside, with the reason recorded for the accountant.
    const approveAnyway = (credit_override_reason: string) => router.post(route('sales.orders.approve', order.id), { credit_override_reason });
    const reject = (review_note: string) => router.post(route('sales.orders.reject', order.id), { review_note });
    const cancel = () => router.post(route('sales.orders.cancel', order.id));
    const closeShort = (reason: string) => router.post(route('sales.orders.close', order.id), { reason });
    // ⚠ The close-short reason is typed in a dialog that has closed by the time
    // the answer comes back, so its refusal is shown beside the button instead.
    const reasonError = usePage<{ errors: Record<string, string | undefined> }>().props.errors?.reason;

    const overLimit = exposure.credit_limit !== null && parseFloat(exposure.total) > parseFloat(exposure.credit_limit);
    // Only a column when some line carries one: an order typed into the ERP never does.
    const hasDiscount = order.lines.some((line) => parseFloat(line.line_discount) > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={order.order_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="flex items-center gap-2 text-lg font-medium">
                            {order.order_number}
                            {order.brand && (
                                <Badge variant="outline" title="Brand">
                                    {order.brand.name}
                                </Badge>
                            )}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {order.customer.name}
                            {order.warehouse ? ` - preferred warehouse ${order.warehouse.name}` : ''} - ordered {showDate(order.ordered_at)}
                            {order.expected_date ? `, expected ${showDate(order.expected_date)}` : ''}
                        </p>
                        {/* ⭐ Courier hub slice 3: the parcel weight kept on the order. */}
                        <p className="text-muted-foreground text-sm">
                            Parcel weight:{' '}
                            {weight.effective_grams !== null ? (
                                <span className="text-foreground tabular-nums">{grams(weight.effective_grams)}</span>
                            ) : (
                                'not known'
                            )}
                            {weight.typed_grams !== null && weight.auto_grams !== null && ` (typed; from the lines ${grams(weight.auto_grams)})`}
                            {weight.typed_grams !== null && weight.auto_grams === null && ' (typed)'}
                            {weight.now.missing_count > 0 && (
                                <span className="ml-2 text-amber-700 dark:text-amber-400">{weight.now.missing_count} item(s) without weight</span>
                            )}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {order.approval_request ? (
                            <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                                {order.approval_request.status}
                            </Badge>
                        ) : (
                            <Badge variant="outline">Awaiting online payment</Badge>
                        )}
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

                <NextSteps steps={next_steps} />

                {/* ⭐⭐ A WEBSITE ORDER, AND WHAT SOMEBODY NEEDS TO ACT ON IT.
                    Slice 2.4 lands these in this approval queue deliberately -
                    a customer placed it themselves and nobody has spoken to
                    them - so the phone number and the destination have to be ON
                    THIS SCREEN. Without them the confirmation call the whole
                    design depends on cannot be made, and an order that cannot
                    be confirmed cannot be dispatched. */}
                {/* ⭐ F36: an order typed in here with a delivery address, and any
                    order's special instructions. */}
                {(order.special_instructions || (order.channel !== 'website' && order.address)) && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                        {order.channel !== 'website' && order.address && (
                            <p className="text-muted-foreground">
                                Deliver to {order.address.recipient_name},{' '}
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
                                {order.delivery_charge !== null && `. Delivery charge ${formatMoney(order.delivery_charge)}.`}
                            </p>
                        )}
                        {order.special_instructions && (
                            <p className="mt-1 whitespace-pre-line">
                                <span className="font-medium">Special instructions: </span>
                                {order.special_instructions}
                            </p>
                        )}
                    </div>
                )}

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
                            {order.payment_method === 'cod'
                                ? 'Chose cash on delivery.'
                                : order.payment_method === 'sslcommerz'
                                  ? 'Chose to pay online through SSLCommerz - see Payments below.'
                                  : 'Payment method not recorded.'}
                            {order.delivery_charge !== null && ` Delivery agreed at ${order.delivery_charge}.`}
                        </p>
                        {order.delivery_promise_max_hours !== null && (
                            <p className="mt-1">
                                Promised delivery within {order.delivery_promise_min_hours} to {order.delivery_promise_max_hours} hours after
                                confirmation
                                {order.expected_date
                                    ? `: expected by ${showDate(order.expected_date)}.`
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

                {/* ⚠⚠ Enforced since 21 Sep 2026: an order over the limit, or a
                    distributor's before payment, is refused at approval. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3 text-sm">
                    <p className="text-muted-foreground">
                        Customer exposure: open invoices {formatMoney(exposure.open_invoices)} + delivered, not yet invoiced{' '}
                        {formatMoney(exposure.delivered_not_invoiced)} + undelivered orders {formatMoney(exposure.undelivered_orders)}
                        {parseFloat(exposure.paid_in_advance) > 0 && <> - paid in advance {formatMoney(exposure.paid_in_advance)}</>} ={' '}
                        <span className="text-foreground font-medium">{formatMoney(exposure.total)}</span>
                        {exposure.credit_limit !== null && <> against a credit limit of {formatMoney(exposure.credit_limit)}</>}
                    </p>
                    {credit_refusal ? (
                        <p className="text-destructive mt-1 font-medium">{credit_refusal}</p>
                    ) : (
                        overLimit && <p className="text-destructive mt-1 font-medium">Over the credit limit.</p>
                    )}
                </div>

                {order.approval_request?.status === 'pending' && can_approve && (
                    <div id="approval" className="border-sidebar-border/70 dark:border-sidebar-border flex scroll-mt-4 gap-2 rounded-xl border p-4">
                        {credit_refusal === null && (
                            <Button size="sm" onClick={approve}>
                                Approve
                            </Button>
                        )}
                        {credit_refusal !== null && can_override_credit && (
                            <ConfirmDialog
                                trigger={<Button size="sm">Approve anyway</Button>}
                                title={`Approve ${order.order_number} over the credit rules`}
                                description={`${credit_refusal} Your reason is kept with the order for the accountant.`}
                                confirmLabel="Approve with this reason"
                                withNote
                                noteRequired
                                noteLabel="Why approve it anyway"
                                notePlaceholder="e.g. a cheque for the balance is promised for the 25th"
                                onConfirm={approveAnyway}
                            />
                        )}
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

                {order.approval_request?.status === 'pending' && !can_approve && (
                    <p className="text-muted-foreground text-sm">Waiting for a manager to approve - goods cannot leave against a pending order.</p>
                )}

                {order.approval_request?.status === 'rejected' && order.approval_request.review_note && (
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
                                    {line.description && <div className="text-xs">{line.description}</div>}
                                </td>
                                <td className="py-2">{formatQuantity(line.quantity_ordered)}</td>
                                <td className="py-2">{formatQuantity(line.quantity_delivered)}</td>
                                <td className="py-2">{formatQuantity(remaining(line))}</td>
                                <td className="py-2">{formatMoney(line.unit_price)}</td>
                                {hasDiscount && <td className="py-2">{parseFloat(line.line_discount) > 0 ? formatMoney(line.line_discount) : ''}</td>}
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
                    canGatewayRefund={can_gateway_refund}
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
                            noteRequired
                            noteLabel="Reason (required)"
                            notePlaceholder="Why is the rest not being taken?"
                            onConfirm={closeShort}
                        />
                        <InputError message={reasonError} className="basis-full" />
                    </div>
                )}

                {can_deliver && order.approval_request?.status === 'approved' && ['open', 'partially_delivered'].includes(order.status) && (
                    <div id="deliver" className="scroll-mt-4">
                        <DeliverForm order={order} warehouses={warehouses} />
                    </div>
                )}

                {challans.length > 0 && (
                    <div id="challans" className="flex scroll-mt-4 flex-col gap-2">
                        <h3 className="font-medium">Challans against this order</h3>
                        {challans.map((challan) => (
                            <ChallanCard
                                key={challan.id}
                                challan={challan}
                                couriers={couriers}
                                courierAccounts={courier_accounts}
                                canBookCourier={can_book_courier}
                                canDeliver={can_deliver}
                                canRecordOutcome={can_record_outcome}
                                customerId={order.customer.id}
                            />
                        ))}
                    </div>
                )}

                {order.note && <p className="text-muted-foreground text-sm whitespace-pre-line">{order.note}</p>}
                {/* PR5 (18 Oct 2026): came from, led to, journals. */}
                <RelatedDocumentsPanel />
            </div>
        </AppLayout>
    );
}
