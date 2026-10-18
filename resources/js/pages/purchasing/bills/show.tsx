import { showDate } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import { ActionDialog } from '@/components/action-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

type Bill = {
    id: number;
    bill_number: string;
    bill_date: string | null;
    due_date: string | null;
    note: string | null;
    total: string;
    tax_total: string;
    tax_claimed: string;
    paid: string;
    credited: string;
    outstanding: string;
    is_reversed: boolean;
    reversed_at: string | null;
    reversal_note: string | null;
    supplier: string | null;
    purchase_order_id: number | null;
    po_number: string | null;
    /** 18 Oct 2026: the landed cost this bill records, when it is one. */
    landed_cost: { number: string; url: string } | null;
    lines: Array<{
        id: number;
        product: string | null;
        sku: string | null;
        quantity: string;
        unit_cost: string;
        order_unit_cost: string | null;
        line_total: string;
    }>;
    /** ⭐ Supplier discounts (ruling P3, 18 Oct 2026). */
    discounted: string;
    discounts: Discount[];
};

interface Availability {
    action: string;
    allowed: boolean;
    requestable: boolean;
    blockers: { code: string; message: string }[];
    date: { rule: string } | null;
}

/** A trade or settlement discount on this bill: where its cost went, and how to reverse it. */
type Discount = {
    id: number;
    number: string;
    kind: 'trade' | 'settlement';
    kind_label: string;
    date: string | null;
    amount: string;
    percent: string | null;
    reference: string;
    reason: string | null;
    payment_id: number | null;
    on_hand: string;
    consumed: string;
    batches: Array<{ batch: string | null; on_hand: string; consumed: string; old_unit_cost: string; new_unit_cost: string | null }>;
    journal: string | null;
    reversal: { on: string | null; by: string | null; reason: string | null } | null;
    actions: Availability[];
};

type JournalView = {
    id: number;
    journal_number: string;
    is_reversal: boolean;
    lines: Array<{ account: string | null; amount: string }>;
};

const money = (value: string) => formatMoney(value);

export default function BillShow({
    bill,
    journals,
    can_pay,
    can_reverse,
    reversalReasons = [],
}: {
    bill: Bill;
    journals: JournalView[];
    can_pay: boolean;
    can_reverse: boolean;
    reversalReasons?: { id: number; name: string }[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Bills & payments', href: '/purchasing/bills' },
        { title: bill.bill_number, href: '#' },
    ];

    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const { data, setData, post, processing } = useForm({ note: '' });

    const reverse: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/purchasing/bills/${bill.id}/reverse`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill ${bill.bill_number}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Bill {bill.bill_number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {bill.supplier}
                            {bill.po_number && bill.purchase_order_id ? (
                                <>
                                    {' · against '}
                                    <Link href={route('purchasing.purchase-orders.show', bill.purchase_order_id)} className="hover:underline">
                                        {bill.po_number}
                                    </Link>
                                </>
                            ) : (
                                ''
                            )}
                            {bill.landed_cost && (
                                <>
                                    {' · records landed cost '}
                                    <Link href={bill.landed_cost.url} className="hover:underline">
                                        {bill.landed_cost.number}
                                    </Link>
                                </>
                            )}
                            {bill.bill_date ? ` · dated ${showDate(bill.bill_date)}` : ''}
                            {bill.due_date ? ` · due ${showDate(bill.due_date)}` : ''}
                        </p>
                    </div>

                    {/* The next step of the purchase chain. Opens the payment form
                        with this supplier chosen and this bill's outstanding typed
                        in; the account and the button are still the clerk's. */}
                    {can_pay && (
                        <Button asChild>
                            <Link href={route('purchasing.payments.create', { bill_id: bill.id })}>Pay this bill</Link>
                        </Button>
                    )}

                    {bill.is_reversed && (
                        <div className="border-destructive/40 bg-destructive/10 rounded border px-3 py-2 text-sm">
                            <div className="text-destructive font-semibold">Reversed {showDate(bill.reversed_at)}</div>
                            {bill.reversal_note && <div className="text-muted-foreground">{bill.reversal_note}</div>}
                        </div>
                    )}
                </div>

                {Number(bill.tax_total) > 0 && (
                    <p className="text-muted-foreground text-sm">
                        The total includes VAT of {money(bill.tax_total)}, of which {money(bill.tax_claimed)} is claimed as input VAT.
                    </p>
                )}

                <div className={`grid gap-4 ${Number(bill.discounted) > 0 ? 'sm:grid-cols-5' : 'sm:grid-cols-4'}`}>
                    {[
                        ['Total', bill.total],
                        ...(Number(bill.discounted) > 0 ? [['Discounts', bill.discounted]] : []),
                        ['Paid', bill.paid],
                        ['Credited', bill.credited],
                        ['Outstanding', bill.outstanding],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-4">
                            <div className="text-muted-foreground text-sm">{label}</div>
                            <div className="text-lg font-semibold tabular-nums">{money(value)}</div>
                        </div>
                    ))}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Quantity</th>
                                <th className="p-3 text-right">Order price</th>
                                <th className="p-3 text-right">Billed price</th>
                                <th className="p-3 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bill.lines.map((line) => (
                                <tr key={line.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">{line.product}</div>
                                        <div className="text-muted-foreground text-xs">{line.sku}</div>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{line.order_unit_cost ? money(line.order_unit_cost) : '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.unit_cost)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* ⭐ Supplier discounts (ruling P3, 18 Oct 2026): each one lowered what
                    the goods cost, on hand and already sold, never other income. */}
                {bill.discounts.length > 0 && (
                    <div className="rounded-lg border">
                        <div className="bg-muted/50 border-b p-3 text-sm font-semibold">Supplier discounts</div>
                        <ul className="divide-y">
                            {bill.discounts.map((discount) => {
                                const reverse = discount.actions.find((a) => a.action === 'reverse');
                                const usable = reverse && (reverse.allowed || reverse.requestable);

                                return (
                                    <li key={discount.id} className="flex flex-wrap items-start justify-between gap-4 p-3 text-sm">
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {discount.number} · {discount.kind_label}
                                                {discount.reversal && (
                                                    <span className="text-destructive ml-2 text-xs">reversed {showDate(discount.reversal.on)}</span>
                                                )}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {showDate(discount.date)} · {discount.kind === 'trade' ? "Supplier's document" : 'Evidence'}:{' '}
                                                {discount.reference}
                                                {discount.percent ? ` · ${discount.percent}%` : ''}
                                                {discount.payment_id ? (
                                                    <>
                                                        {' · '}
                                                        <Link href={route('purchasing.payments.show', discount.payment_id)} className="underline">
                                                            with payment #{discount.payment_id}
                                                        </Link>
                                                    </>
                                                ) : null}
                                                {discount.journal ? ` · ${discount.journal}` : ''}
                                            </div>
                                            {discount.reason && <div className="text-muted-foreground text-xs">{discount.reason}</div>}
                                            <div className="text-muted-foreground mt-1 text-xs">
                                                Off stock on hand {money(discount.on_hand)} · off cost of goods already sold or used{' '}
                                                {money(discount.consumed)}
                                            </div>
                                            {discount.batches.map((batch, i) => (
                                                <div key={i} className="text-muted-foreground text-xs tabular-nums">
                                                    Batch {batch.batch ?? '-'}: unit cost {money(batch.old_unit_cost)}
                                                    {batch.new_unit_cost ? ` to ${money(batch.new_unit_cost)}` : ''}
                                                </div>
                                            ))}
                                            {discount.reversal?.reason && (
                                                <div className="text-muted-foreground text-xs">{discount.reversal.reason}</div>
                                            )}
                                        </div>
                                        <div className="flex flex-col items-end gap-2">
                                            <span className="font-semibold tabular-nums">{money(discount.amount)}</span>
                                            {reverse &&
                                                !discount.reversal &&
                                                (usable ? (
                                                    <ActionDialog
                                                        action="reverse"
                                                        recordLabel={discount.number}
                                                        previewUrl={`/records/purchasing.supplier_discount/${discount.id}/reverse/preview`}
                                                        submitUrl={`/records/purchasing.supplier_discount/${discount.id}/reverse`}
                                                        reasons={reversalReasons}
                                                        allowToday={reverse.allowed && reverse.date?.rule === 'original'}
                                                        trigger={
                                                            <Button size="sm" variant="outline">
                                                                Reverse discount
                                                            </Button>
                                                        }
                                                    />
                                                ) : (
                                                    <span className="text-muted-foreground block max-w-56 text-right text-xs">
                                                        {reverse.blockers[0]?.message}
                                                    </span>
                                                ))}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <div>
                    <h2 className="mb-2 text-sm font-semibold">Accounting</h2>
                    <div className="flex flex-col gap-3">
                        {journals.length === 0 && (
                            <p className="text-muted-foreground text-sm">No journal was posted. Account mappings are not configured yet.</p>
                        )}
                        {journals.map((entry) => (
                            <div key={entry.id} className="overflow-x-auto rounded-lg border">
                                <div className="bg-muted/50 border-b p-3 text-sm font-medium">
                                    {entry.journal_number}
                                    {entry.is_reversal && <span className="text-destructive ml-2 text-xs">reversal</span>}
                                </div>
                                {/* ⚠ 17 Oct 2026 (practice run F19): Debit and Credit columns, as
                                    on the payment page, instead of one column of signed amounts.
                                    The amount is signed: positive debits, negative credits. */}
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left">
                                        <tr>
                                            <th className="p-3 font-medium">Account</th>
                                            <th className="p-3 text-right font-medium">Debit</th>
                                            <th className="p-3 text-right font-medium">Credit</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {entry.lines.map((line, i) => (
                                            <tr key={i} className="border-t">
                                                <td className="p-3">{line.account}</td>
                                                <td className="p-3 text-right tabular-nums">{Number(line.amount) > 0 ? money(line.amount) : ''}</td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {Number(line.amount) < 0 ? money(String(-Number(line.amount))) : ''}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ))}
                    </div>
                </div>

                {/* ⚠ D-SB-6: a posted bill is immutable. There is no edit and no
                    delete anywhere on this page, deliberately. Correction is a
                    reversal, and it is refused once the bill has been settled. */}
                {can_reverse && (
                    <form onSubmit={reverse} className="flex flex-col gap-2 rounded-lg border p-4">
                        <h2 className="text-sm font-semibold">Reverse this bill</h2>
                        <p className="text-muted-foreground text-sm">
                            The bill is kept and a reversing journal is posted. The goods become billable again. Once a bill has been paid or credited
                            it can no longer be reversed.
                        </p>
                        <Input
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            placeholder="Why is it being reversed?"
                            className="max-w-md"
                        />
                        <InputError message={errors.note} />
                        <Button type="submit" variant="outline" disabled={processing} className="w-fit">
                            Reverse bill
                        </Button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
