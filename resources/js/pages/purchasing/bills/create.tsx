import { formatMoney, formatQuantity } from '@/lib/utils';
import { Head, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import type { BreadcrumbItem } from '@/types';

type Line = {
    id: number;
    product: string | null;
    sku: string | null;
    quantity_ordered: string;
    quantity_received: string;
    quantity_returned: string;
    quantity_invoiced: string;
    billable: string;
    order_unit_cost: string;
};

type Order = {
    id: number;
    po_number: string;
    supplier: { id: number; name: string; payment_terms: string | null };
    lines: Line[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Bills', href: '/purchasing/bills' },
    { title: 'Record', href: '#' },
];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function RecordBill({ order, vat }: { order: Order; vat: { applies: boolean } }) {
    const { data, setData, post, errors, processing } = useForm({
        purchase_order_id: order.id,
        bill_number: '',
        bill_date: dhakaToday(),
        due_date: '',
        note: '',
        // VAT plan D7. Sent always, read by the server only while VAT is on.
        prices_include_tax: false as boolean,
        tax_recoverable: true as boolean,
        lines: order.lines.map((line) => ({
            purchase_order_line_id: line.id,
            // Defaulted to what is still billable and to the AGREED price, so
            // the common case is one click and any deviation is deliberate.
            // Plain numbers in the boxes, not the stored "100.0000".
            quantity: String(Number(line.billable)),
            unit_cost: String(Number(line.order_unit_cost)),
        })),
    });

    const setLine = (index: number, patch: Partial<(typeof data.lines)[number]>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    // ⚠⚠ D-SB-2 made visible BEFORE saving. The variance is the gap between the
    // agreed order price and what the supplier has invoiced, and somebody
    // holding the paper should see it at the moment they type it, not discover
    // it in a management report next month.
    const variance = data.lines.reduce((carry, line, index) => {
        const orderCost = Number(order.lines[index]?.order_unit_cost ?? 0);
        const quantity = Number(line.quantity) || 0;
        const billed = Number(line.unit_cost) || 0;

        return carry + quantity * (billed - orderCost);
    }, 0);

    const billTotal = data.lines.reduce((carry, line) => carry + (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0), 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/purchasing/bills');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill against ${order.po_number}`} />

            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Record supplier bill against {order.po_number}</h1>
                    <p className="text-muted-foreground text-sm">{order.supplier.name}</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <Label htmlFor="bill_number" required>
                            Supplier's invoice number
                        </Label>
                        <Input id="bill_number" value={data.bill_number} onChange={(e) => setData('bill_number', e.target.value)} />
                        <InputError message={errors.bill_number} />
                    </div>
                    <div>
                        <Label htmlFor="bill_date" required>
                            Bill date
                        </Label>
                        <Input id="bill_date" type="date" value={data.bill_date} onChange={(e) => setData('bill_date', e.target.value)} />
                        <InputError message={errors.bill_date} />
                    </div>
                    <div>
                        <Label htmlFor="due_date">Due date</Label>
                        <Input id="due_date" type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                        {/* D-SB-7: guidance only. The terms are free text and are
                            deliberately never parsed into a date. */}
                        {order.supplier.payment_terms && (
                            <p className="text-muted-foreground mt-1 text-xs">Supplier's terms: {order.supplier.payment_terms}</p>
                        )}
                        <InputError message={errors.due_date} />
                    </div>
                    <div>
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                </div>

                {vat.applies && (
                    <div className="bg-muted/30 grid gap-4 rounded-lg border p-4 md:grid-cols-2">
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

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Received</th>
                                <th className="p-3 text-right">Returned</th>
                                <th className="p-3 text-right">Billed</th>
                                <th className="p-3 text-right">Billable</th>
                                <th className="p-3 text-right">Order price</th>
                                <th className="w-28 p-3">
                                    Quantity
                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                        *
                                    </span>
                                </th>
                                <th className="w-28 p-3">
                                    Unit cost
                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                        *
                                    </span>
                                </th>
                                <th className="p-3 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {order.lines.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                        Nothing on this order is still billable. Receive goods first, or the goods received have already been billed
                                        or returned.
                                    </td>
                                </tr>
                            )}
                            {order.lines.map((line, index) => {
                                const entry = data.lines[index];
                                const lineVariance =
                                    (Number(entry?.quantity) || 0) * ((Number(entry?.unit_cost) || 0) - Number(line.order_unit_cost));

                                return (
                                    <tr key={line.id} className="border-t align-top">
                                        <td className="p-3">
                                            <div className="font-medium">{line.product}</div>
                                            <div className="text-muted-foreground text-xs">{line.sku}</div>
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_received)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_returned)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_invoiced)}</td>
                                        <td className="p-3 text-right font-medium tabular-nums">{formatQuantity(line.billable)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(line.order_unit_cost)}</td>
                                        <td className="p-3">
                                            <Input
                                                className="w-28 text-right"
                                                value={entry?.quantity ?? ''}
                                                onChange={(e) => setLine(index, { quantity: e.target.value })}
                                            />
                                            <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                className="w-28 text-right"
                                                value={entry?.unit_cost ?? ''}
                                                onChange={(e) => setLine(index, { unit_cost: e.target.value })}
                                            />
                                            <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                                            {lineVariance !== 0 && (
                                                <p className="mt-1 text-xs text-amber-600">
                                                    {lineVariance > 0 ? '+' : ''}
                                                    {money(lineVariance)} vs order
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {money((Number(entry?.quantity) || 0) * (Number(entry?.unit_cost) || 0))}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-col gap-2 rounded-lg border p-4">
                    <div className="flex justify-between text-sm">
                        <span>Bill total</span>
                        <span className="font-semibold tabular-nums">{money(billTotal)}</span>
                    </div>
                    {variance !== 0 && (
                        <div className="flex justify-between text-sm">
                            <span className="text-amber-600">Purchase price variance ({variance > 0 ? 'unfavourable' : 'favourable'})</span>
                            <span className="font-semibold text-amber-600 tabular-nums">{money(variance)}</span>
                        </div>
                    )}
                </div>

                {/* C-PUR-E1: where a refused bill says why. */}
                <InputError message={errors.lines} />

                <div>
                    <Button type="submit" disabled={processing || order.lines.length === 0} size="lg">
                        Record bill
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
