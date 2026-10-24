import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import { DateWords } from '@/components/form/date-words';
import { filledSplitLines, SplitPaymentLines, type SplitLine } from '@/components/purchasing/split-payment-lines';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

interface SupplierRow {
    id: number;
    name: string;
    outstanding: string;
}

interface BillRow {
    id: number;
    bill_number: string;
    bill_date: string | null;
    due_date: string | null;
    total: string;
    outstanding: string;
}

interface MoneyAccountRow {
    id: number;
    name: string;
    type: string;
    /** F20: what is in it now, from the ledger. */
    balance?: string | null;
}

interface WithholdingRateRow {
    id: number;
    kind: 'tds' | 'vds';
    label: string;
    rate: number;
    supplier_type: string | null;
    from: string | null;
    to: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Payments', href: '/purchasing/payments' },
    { title: 'New', href: '/purchasing/payments/new' },
];

/** Money in this form is paisa. Two decimal places, added as integers. */
const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

export default function PaymentCreate({
    suppliers,
    selectedSupplierId,
    bills,
    moneyAccounts,
    today,
    prefill,
    withholdingRates = [],
    supplierType = null,
    canSettleDiscount = false,
}: {
    suppliers: SupplierRow[];
    selectedSupplierId: number | null;
    bills: BillRow[];
    moneyAccounts: MoneyAccountRow[];
    today: string;
    // Set when the form was opened from a bill's "Pay this bill" button.
    prefill?: { bill_id: number; amount: string } | null;
    withholdingRates?: WithholdingRateRow[];
    supplierType?: string | null;
    /** ⭐ Supplier discounts (ruling P3): may this user take a settlement discount? */
    canSettleDiscount?: boolean;
}) {
    const { data, setData } = useForm({
        supplier_id: selectedSupplierId ?? '',
        money_account_id: '',
        paid_on: today,
        reference: '',
        note: '',
        tds_rate_id: '',
        vds_rate_id: '',
        // bill id -> amount typed by the clerk. ⚠ A prefill is only a typed
        // number: it can be changed or cleared, and the server re-checks it
        // against what is outstanding exactly as if the clerk had typed it.
        amounts: (prefill ? { [prefill.bill_id]: prefill.amount } : {}) as Record<number, string>,
        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): bill id -> settlement
        // discount, with the evidence of the agreement and why.
        discounts: {} as Record<number, string>,
        discount_evidence: '',
        discount_reason: '',
    });

    const [processing, setProcessing] = useState(false);
    // ⭐ Initial plan B2 (R57): the other accounts; "Paid from" pays the rest.
    const [splitLines, setSplitLines] = useState<SplitLine[]>([]);

    // ⚠ Server errors come from the SHARED page props, not from useForm. The
    // submission is a router.post of a reshaped payload - allocations are built
    // at submit time from `amounts` - so useForm never sees the keys the server
    // validates, and `allocations.0.amount` could not be typed against it.
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    // Choosing a supplier reloads the page so the server decides which bills
    // are open. Working that out in the browser would be a second copy of the
    // rule, and the outstanding figure is derived from allocations server-side.
    const chooseSupplier = (id: string) => {
        setData('supplier_id', id);
        router.get('/purchasing/payments/new', id ? { supplier_id: id } : {}, {
            preserveState: true,
            replace: true,
        });
    };

    const total = useMemo(() => bills.reduce((sum, bill) => sum + toPaisa(data.amounts[bill.id] ?? '0'), 0), [bills, data.amounts]);

    // Finance decision C4: only the rates in force on the payment date that fit
    // this supplier's type. The server re-checks and computes the tax itself.
    const ratesFor = (kind: 'tds' | 'vds') =>
        withholdingRates.filter(
            (r) =>
                r.kind === kind &&
                (r.supplier_type === null || r.supplier_type === supplierType) &&
                (r.from === null || r.from <= data.paid_on) &&
                (r.to === null || r.to >= data.paid_on),
        );
    const heldBy = (id: string) => {
        const rate = withholdingRates.find((r) => String(r.id) === id);
        return rate ? Math.round((total * rate.rate) / 100) : 0;
    };
    const held = heldBy(data.tds_rate_id) + heldBy(data.vds_rate_id);

    // ⚠ The server refuses overpayment and is the authority. This only stops the
    // clerk submitting something it already knows will be rejected.
    const discountOf = (bill: BillRow) => (canSettleDiscount ? toPaisa(data.discounts[bill.id] ?? '0') : 0);
    const overpaid = bills.filter((bill) => toPaisa(data.amounts[bill.id] ?? '0') + discountOf(bill) > toPaisa(bill.outstanding));
    const discountTotal = bills.reduce((sum, bill) => sum + discountOf(bill), 0);

    // ── Supplier advances (ruling P2): an overpayment becomes an advance only
    // after the payer says so. The server caps each bill and keeps the rest.
    // ⚠ A line that also takes a settlement discount can never become an
    // advance: the payment and the discount must fit inside the bill.
    const discountedOver = overpaid.filter((bill) => discountOf(bill) > 0);
    const excess = overpaid
        .filter((bill) => discountOf(bill) === 0)
        .reduce((sum, bill) => sum + toPaisa(data.amounts[bill.id] ?? '0') - toPaisa(bill.outstanding), 0);
    const [excessToAdvance, setExcessToAdvance] = useState(false);
    // ── end supplier advances

    // Corrections wave 2 (24 Oct 2026): "Save anyway" sends the same payment
    // with the server's ack token for a possible duplicate.
    const send = (ack: string | null) => {
        const allocations = bills
            .map((bill) => ({
                supplier_bill_id: bill.id,
                amount: data.amounts[bill.id] ?? '0',
                ...(canSettleDiscount && toPaisa(data.discounts[bill.id] ?? '0') > 0 ? { discount: data.discounts[bill.id] } : {}),
            }))
            .filter((row) => toPaisa(row.amount) > 0 || ('discount' in row && toPaisa(row.discount ?? '0') > 0));

        router.post(
            '/purchasing/payments',
            {
                supplier_id: data.supplier_id,
                money_account_id: data.money_account_id,
                paid_on: data.paid_on,
                reference: data.reference,
                note: data.note,
                tds_rate_id: data.tds_rate_id || null,
                vds_rate_id: data.vds_rate_id || null,
                allocations,
                excess_to_advance: excess > 0 && excessToAdvance,
                ...(discountTotal > 0 ? { discount_evidence: data.discount_evidence, discount_reason: data.discount_reason } : {}),
                duplicate_ack: ack,
                split_lines: filledSplitLines(splitLines),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        send(null);
    };

    const payAllOf = (bill: BillRow) => setData('amounts', { ...data.amounts, [bill.id]: bill.outstanding });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pay a supplier" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                {/* ── Supplier advances (ruling P2): the other kind of payment. ── */}
                <div className="flex flex-wrap gap-2 text-sm" role="tablist" aria-label="Kind of payment">
                    <span role="tab" aria-selected="true" className="bg-primary text-primary-foreground rounded-full px-3 py-1">
                        Against bills
                    </span>
                    <Link
                        role="tab"
                        aria-selected="false"
                        href={`/purchasing/advances/new${data.supplier_id ? `?supplier_id=${data.supplier_id}` : ''}`}
                        className="hover:bg-muted rounded-full border px-3 py-1"
                    >
                        Advance (no bill yet)
                    </Link>
                </div>
                {/* ── end supplier advances ── */}

                <div className="flex flex-wrap items-start gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id" required>
                            Supplier
                        </Label>
                        <SearchSelect fit id="supplier_id" value={String(data.supplier_id)} onChange={(e) => chooseSupplier(e.target.value)}>
                            <option value="">Select a supplier</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name} - {formatMoney(supplier.outstanding)} owed
                                </option>
                            ))}
                        </SearchSelect>
                        {suppliers.length === 0 && <p className="text-muted-foreground text-sm">No supplier is owed anything right now.</p>}
                        {errors.supplier_id && <p className="text-destructive text-sm">{errors.supplier_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id" required>
                            Paid from
                        </Label>
                        <SearchSelect
                            fit
                            id="money_account_id"
                            value={String(data.money_account_id)}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">Select an account</option>
                            {moneyAccounts.map((account) => (
                                <option
                                    key={account.id}
                                    value={account.id}
                                    data-hint={account.balance != null ? `Balance now ${formatMoney(account.balance)}` : undefined}
                                >
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-sm">{errors.money_account_id}</p>}
                        <SplitPaymentLines
                            accounts={moneyAccounts}
                            primaryAccountId={String(data.money_account_id)}
                            rows={splitLines}
                            onChange={setSplitLines}
                            totalPaisa={total > 0 ? total - held : null}
                            errors={errors}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="paid_on" required>
                            Payment date
                        </Label>
                        <Input
                            id="paid_on"
                            type="date"
                            required
                            aria-required
                            className="w-auto"
                            value={data.paid_on}
                            onChange={(e) => setData('paid_on', e.target.value)}
                        />
                        <DateWords value={data.paid_on} />
                        {errors.paid_on && <p className="text-destructive text-sm">{errors.paid_on}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            className="w-80"
                            value={data.reference}
                            placeholder="Cheque number, bKash trx id, slip number"
                            onChange={(e) => setData('reference', e.target.value)}
                        />
                        {errors.reference && <p className="text-destructive text-sm">{errors.reference}</p>}
                    </div>
                </div>

                {data.supplier_id !== '' && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Bill</th>
                                    <th className="p-3">Date</th>
                                    <th className="p-3">Due</th>
                                    <th className="p-3 text-right">Total</th>
                                    <th className="p-3 text-right">Outstanding</th>
                                    <th className="p-3 text-right">
                                        Paying now
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    {canSettleDiscount && <th className="p-3 text-right">Discount received</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {bills.map((bill, index) => {
                                    const typed = toPaisa(data.amounts[bill.id] ?? '0') + discountOf(bill);
                                    const isOver = typed > toPaisa(bill.outstanding);

                                    return (
                                        <tr key={bill.id} className="border-t">
                                            <td className="p-3 font-medium">{bill.bill_number}</td>
                                            <td className="p-3">{showDate(bill.bill_date) || '-'}</td>
                                            <td className="p-3">{showDate(bill.due_date) || '-'}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(bill.total)}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                <button
                                                    type="button"
                                                    className="tabular-nums hover:underline"
                                                    onClick={() => payAllOf(bill)}
                                                    title="Pay all of this bill"
                                                >
                                                    {formatMoney(bill.outstanding)}
                                                </button>
                                            </td>
                                            <td className="p-3 text-right">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    className={`ml-auto max-w-32 text-right tabular-nums ${isOver ? 'border-destructive' : ''}`}
                                                    value={data.amounts[bill.id] ?? ''}
                                                    onChange={(e) => setData('amounts', { ...data.amounts, [bill.id]: e.target.value })}
                                                />
                                                {isOver && (
                                                    <p className="text-destructive mt-1 text-xs">
                                                        Only {formatMoney(bill.outstanding)} is outstanding.
                                                    </p>
                                                )}
                                                {errors[`allocations.${index}.amount`] && (
                                                    <p className="text-destructive mt-1 text-xs">{errors[`allocations.${index}.amount`]}</p>
                                                )}
                                            </td>
                                            {canSettleDiscount && (
                                                <td className="p-3 text-right">
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        aria-label={`Discount received on ${bill.bill_number}`}
                                                        className="ml-auto max-w-28 text-right tabular-nums"
                                                        value={data.discounts[bill.id] ?? ''}
                                                        onChange={(e) => setData('discounts', { ...data.discounts, [bill.id]: e.target.value })}
                                                    />
                                                    {errors[`allocations.${index}.discount`] && (
                                                        <p className="text-destructive mt-1 text-xs">{errors[`allocations.${index}.discount`]}</p>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    );
                                })}
                                {bills.length === 0 && (
                                    <tr>
                                        <td colSpan={canSettleDiscount ? 7 : 6} className="text-muted-foreground p-6 text-center">
                                            This supplier has no open bills.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {bills.length > 0 && (
                                <tfoot>
                                    <tr className="bg-muted/30 border-t font-medium">
                                        <td className="p-3" colSpan={5}>
                                            Total payment
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(total / 100)}</td>
                                        {canSettleDiscount && (
                                            <td className="p-3 text-right tabular-nums">
                                                {discountTotal > 0 ? formatMoney(discountTotal / 100) : ''}
                                            </td>
                                        )}
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                )}

                {errors.allocations && <p className="text-destructive text-sm">{errors.allocations}</p>}

                {/* ⭐ Supplier discounts (ruling P3, 18 Oct 2026): a discount for paying
                    early lowers what the goods cost, so it needs the supplier's agreement
                    on record. Paying less with no discount typed simply leaves the rest
                    of the bill open. */}
                {canSettleDiscount && discountTotal > 0 && (
                    <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="discount_evidence" required>
                                Evidence of the supplier's agreement
                            </Label>
                            <Input
                                id="discount_evidence"
                                placeholder="Their letter, message or document number"
                                value={data.discount_evidence}
                                onChange={(e) => setData('discount_evidence', e.target.value)}
                            />
                            {errors.discount_evidence && <p className="text-destructive text-sm">{errors.discount_evidence}</p>}
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="discount_reason" required>
                                Why the supplier allowed it
                            </Label>
                            <Input id="discount_reason" value={data.discount_reason} onChange={(e) => setData('discount_reason', e.target.value)} />
                            {errors.discount_reason && <p className="text-destructive text-sm">{errors.discount_reason}</p>}
                        </div>
                        <p className="text-muted-foreground text-xs md:col-span-2">
                            {formatMoney(discountTotal / 100)} comes off the cost of the goods on these bills (stock on hand, or cost of goods already
                            sold).
                        </p>
                        {errors.discount && <p className="text-destructive text-sm md:col-span-2">{errors.discount}</p>}
                    </div>
                )}

                {data.supplier_id !== '' && (ratesFor('tds').length > 0 || ratesFor('vds').length > 0) && (
                    <div className="flex flex-wrap items-start gap-4">
                        {(['tds', 'vds'] as const).map((kind) =>
                            ratesFor(kind).length === 0 ? null : (
                                <div key={kind} className="grid gap-2">
                                    <Label htmlFor={`${kind}_rate_id`}>{kind === 'tds' ? 'Hold back TDS' : 'Hold back VDS'}</Label>
                                    <SearchSelect
                                        fit
                                        id={`${kind}_rate_id`}
                                        value={data[`${kind}_rate_id`]}
                                        onChange={(e) => setData(`${kind}_rate_id`, e.target.value)}
                                    >
                                        <option value="">None</option>
                                        {ratesFor(kind).map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    {errors[`${kind}_rate_id`] && <p className="text-destructive text-sm">{errors[`${kind}_rate_id`]}</p>}
                                </div>
                            ),
                        )}
                        {held > 0 && (
                            <p className="text-muted-foreground self-end pb-2 text-sm">
                                Tax held back {formatMoney(held / 100)}. Leaves the account:{' '}
                                <span className="font-medium">{formatMoney((total - held) / 100)}</span>
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="note">Note</Label>
                    <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>

                {/* ── Supplier advances (ruling P2) ── */}
                {excess > 0 && (
                    <label className="flex max-w-xl items-start gap-2 rounded-lg border border-amber-500/50 p-3 text-sm">
                        <input type="checkbox" className="mt-1" checked={excessToAdvance} onChange={(e) => setExcessToAdvance(e.target.checked)} />
                        <span>
                            Tk {formatMoney(excess / 100)} more than the bills owe becomes an advance to this supplier. Each bill is paid what it
                            owes; the rest can be applied to a later bill or refunded.
                        </span>
                    </label>
                )}
                {/* ── end supplier advances ── */}

                <DuplicateWarning errors={errors} busy={processing} onSaveAnyway={(ack) => send(ack)} />

                <div className="flex items-center gap-3">
                    <Button
                        type="submit"
                        disabled={processing || total <= 0 || discountedOver.length > 0 || (overpaid.length > 0 && !excessToAdvance)}
                    >
                        {held > 0
                            ? `Settle ${formatMoney(total / 100)}, pay ${formatMoney((total - held) / 100)}`
                            : `Record payment of ${formatMoney(total / 100)}`}
                    </Button>
                    {discountedOver.length > 0 && (
                        <span className="text-destructive text-sm">
                            On a bill with a settlement discount, the payment and the discount together cannot be more than is outstanding.
                        </span>
                    )}
                    {discountedOver.length === 0 && overpaid.length > 0 && !excessToAdvance && (
                        <span className="text-destructive text-sm">
                            One or more lines pay more than is outstanding. Confirm the advance above, or lower them.
                        </span>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
