import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

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
    lines: Array<{
        id: number;
        product: string | null;
        sku: string | null;
        quantity: string;
        unit_cost: string;
        order_unit_cost: string | null;
        line_total: string;
    }>;
};

type JournalView = {
    id: number;
    journal_number: string;
    is_reversal: boolean;
    lines: Array<{ account: string | null; amount: string }>;
};

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function BillShow({
    bill,
    journals,
    can_pay,
    can_reverse,
}: {
    bill: Bill;
    journals: JournalView[];
    can_pay: boolean;
    can_reverse: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchasing', href: '/purchasing/purchase-orders' },
        { title: 'Bills', href: '/purchasing/bills' },
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
                            {bill.bill_date ? ` · dated ${bill.bill_date}` : ''}
                            {bill.due_date ? ` · due ${bill.due_date}` : ''}
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
                            <div className="text-destructive font-semibold">Reversed {bill.reversed_at}</div>
                            {bill.reversal_note && <div className="text-muted-foreground">{bill.reversal_note}</div>}
                        </div>
                    )}
                </div>

                {Number(bill.tax_total) > 0 && (
                    <p className="text-muted-foreground text-sm">
                        The total includes VAT of {bill.tax_total}, of which {bill.tax_claimed} is claimed as input VAT.
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-4">
                    {[
                        ['Total', bill.total],
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
                                    <td className="p-3 text-right tabular-nums">{line.quantity}</td>
                                    <td className="p-3 text-right tabular-nums">{line.order_unit_cost ? money(line.order_unit_cost) : '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.unit_cost)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(line.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

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
                                <table className="w-full text-sm">
                                    <tbody>
                                        {entry.lines.map((line, i) => (
                                            <tr key={i} className="border-t">
                                                <td className="p-3">{line.account}</td>
                                                <td className="p-3 text-right tabular-nums">{money(line.amount)}</td>
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
