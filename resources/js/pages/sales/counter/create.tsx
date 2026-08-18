import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Option {
    id: number;
    name: string;
    code?: string | null;
    sku?: string | null;
    is_default?: boolean;
    is_system?: boolean;
}

// The index signature is what lets Inertia's useForm accept an array of these
// as form data; without it the whole line array is not FormDataConvertible.
interface Line {
    [key: string]: string | undefined;
    product_id: string;
    quantity: string;
    unit_price: string;
    discount: string;
    explanation?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Counter', href: '/sales/counter' },
];

const emptyLine = (): Line => ({ product_id: '', quantity: '1', unit_price: '', discount: '0' });

const money = (value: number) =>
    value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CounterSale({
    warehouses,
    products,
    customers,
    moneyAccounts,
    walkInId,
}: {
    warehouses: Option[];
    products: Option[];
    customers: Option[];
    moneyAccounts: Option[];
    walkInId: number | null;
}) {
    const { data, setData, post, errors, processing } = useForm({
        customer_id: walkInId ? String(walkInId) : '',
        warehouse_id: String(warehouses.find((w) => w.is_default)?.id ?? warehouses[0]?.id ?? ''),
        money_account_id: String(moneyAccounts[0]?.id ?? ''),
        invoice_discount: '0',
        reference: '',
        note: '',
        lines: [emptyLine()] as Line[],
    });

    const setLine = (index: number, patch: Partial<Line>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    // ⚠ The price comes from the SAME resolver the sale will use. A screen that
    // works out its own price is a second pricing engine, and it disagrees with
    // the invoice the moment a rule changes.
    const fetchPrice = async (index: number, productId: string, quantity: string) => {
        if (!productId || !quantity) return;

        const response = await fetch(route('sales.counter.price'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                product_id: productId,
                quantity,
                customer_id: data.customer_id || null,
            }),
        });

        if (!response.ok) return;

        const body = await response.json();
        setLine(index, {
            unit_price: body.unit_price ?? '',
            explanation: body.unit_price ? body.explanation : 'No price is set for this product.',
        });
    };

    const subtotal = data.lines.reduce((sum, line) => {
        const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
        return sum + gross - (Number(line.discount) || 0);
    }, 0);
    const total = Math.max(0, subtotal - (Number(data.invoice_discount) || 0));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.counter.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Counter Sale" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div className="grid gap-4 md:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="customer">Customer</Label>
                        <select
                            id="customer"
                            className={selectClass}
                            value={data.customer_id}
                            onChange={(e) => setData('customer_id', e.target.value)}
                        >
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                    {c.is_system ? ' (default)' : ''}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.customer_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="warehouse">Sold from</Label>
                        <select
                            id="warehouse"
                            className={selectClass}
                            value={data.warehouse_id}
                            onChange={(e) => setData('warehouse_id', e.target.value)}
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.warehouse_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money">Paid into</Label>
                        {/* ⚠ "On account" is a real choice, not a missing value: the
                            invoice then stands open as a receivable. */}
                        <select
                            id="money"
                            className={selectClass}
                            value={data.money_account_id}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">On account (unpaid)</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.money_account_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                            placeholder="bKash trxID, cheque no."
                        />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 w-28">Quantity</th>
                                <th className="p-3 w-32">Unit price</th>
                                <th className="p-3 w-28">Discount</th>
                                <th className="p-3 w-32 text-right">Line total</th>
                                <th className="p-3 w-16"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.lines.map((line, index) => {
                                const lineTotal =
                                    (Number(line.quantity) || 0) * (Number(line.unit_price) || 0) -
                                    (Number(line.discount) || 0);

                                return (
                                    <tr key={index} className="border-t align-top">
                                        <td className="p-3">
                                            <select
                                                className={`${selectClass} w-full`}
                                                value={line.product_id}
                                                onChange={(e) => {
                                                    setLine(index, { product_id: e.target.value });
                                                    void fetchPrice(index, e.target.value, line.quantity);
                                                }}
                                            >
                                                <option value="">Choose a product</option>
                                                {products.map((p) => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} ({p.sku})
                                                    </option>
                                                ))}
                                            </select>
                                            {line.explanation && (
                                                <p className="mt-1 text-xs text-muted-foreground">{line.explanation}</p>
                                            )}
                                            <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                value={line.quantity}
                                                onChange={(e) => setLine(index, { quantity: e.target.value })}
                                                onBlur={() => void fetchPrice(index, line.product_id, line.quantity)}
                                            />
                                            <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                value={line.unit_price}
                                                onChange={(e) => setLine(index, { unit_price: e.target.value })}
                                            />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                value={line.discount}
                                                onChange={(e) => setLine(index, { discount: e.target.value })}
                                            />
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{money(lineTotal)}</td>
                                        <td className="p-3">
                                            {data.lines.length > 1 && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setData(
                                                            'lines',
                                                            data.lines.filter((_, i) => i !== index),
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                        Add line
                    </Button>

                    <div className="grid gap-2 text-right">
                        <div className="flex items-center justify-end gap-3">
                            <Label htmlFor="invoice_discount" className="text-muted-foreground">
                                Discount on the whole bill
                            </Label>
                            <Input
                                id="invoice_discount"
                                className="w-32"
                                value={data.invoice_discount}
                                onChange={(e) => setData('invoice_discount', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.invoice_discount} />
                        <p className="text-sm text-muted-foreground">Subtotal {money(subtotal)}</p>
                        <p className="text-2xl font-semibold tabular-nums">{money(total)}</p>
                    </div>
                </div>

                <div className="space-y-2">
                    {/* C-SLS-E1. Where a refused sale says why: no price in force,
                        not enough stock, a closed month, a missing mapping. The
                        till is the one screen where all four can happen, and
                        before this every one of them arrived as a blank error. */}
                    <InputError message={errors.lines} />
                    <Button type="submit" disabled={processing} size="lg">
                        {data.money_account_id ? 'Take payment and finish' : 'Finish on account'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
