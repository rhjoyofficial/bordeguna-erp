import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface BillableLine {
    delivery_line_id: number;
    delivery_number: string;
    delivered_at: string;
    product_name: string;
    product_sku: string;
    quantity_uninvoiced: string;
    unit_price: string | null;
    price_source: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/sales/invoices' },
    { title: 'New invoice', href: '/sales/invoices/create' },
];

type LineDraft = { delivery_line_id: number; quantity: string; unit_price: string };

/*
 * Billing what left on challans - one invoice may cover several challans, and
 * a challan may be billed in parts (both engine-supported since Phase 1; this
 * screen is what finally makes them reachable - F1).
 *
 * ⚠⚠ Every default price on this screen came from the SERVER: the order price
 * where the goods trace to a sales order (C-SO-4 - that is what "agreed"
 * means), the resolver at today's date where they do not. Editing a price here
 * is a manual override, the counter's rule.
 */
export default function InvoiceCreate({
    customers,
    customerId,
    billable,
}: {
    customers: { id: number; name: string }[];
    customerId: number | null;
    billable: BillableLine[];
}) {
    const { data, setData, post, errors, processing } = useForm<{
        customer_id: string;
        invoice_date: string;
        due_date: string;
        invoice_discount: string;
        note: string;
        lines: LineDraft[];
    }>({
        customer_id: customerId ? String(customerId) : '',
        invoice_date: '',
        due_date: '',
        invoice_discount: '',
        note: '',
        // Every billable line starts included at its full uninvoiced quantity
        // and its server-supplied price. Set a quantity to 0 to leave it off.
        lines: billable.map((line) => ({
            delivery_line_id: line.delivery_line_id,
            quantity: line.quantity_uninvoiced,
            unit_price: line.unit_price ?? '',
        })),
    });

    const pickCustomer = (id: string) => {
        // The billable list is server-built per customer; changing customer
        // reloads the page with the new list.
        router.get(route('sales.invoices.create'), id ? { customer_id: id } : {}, { preserveState: false });
    };

    const updateLine = (deliveryLineId: number, patch: Partial<LineDraft>) => {
        setData(
            'lines',
            data.lines.map((line) => (line.delivery_line_id === deliveryLineId ? { ...line, ...patch } : line)),
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.invoices.store'));
    };

    const included = data.lines.filter((line) => parseFloat(line.quantity || '0') > 0);
    const subtotal = included.reduce((sum, line) => sum + parseFloat(line.quantity || '0') * parseFloat(line.unit_price || '0'), 0);
    const total = subtotal - parseFloat(data.invoice_discount || '0');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New invoice" />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid max-w-sm gap-2">
                    <Label>Customer</Label>
                    <SearchableSelect
                        value={data.customer_id}
                        onChange={pickCustomer}
                        options={customers.map((c) => ({ value: String(c.id), label: c.name }))}
                        placeholder="Select customer"
                        searchPlaceholder="Search customers…"
                    />
                    <InputError message={errors.customer_id} />
                </div>

                {customerId !== null && billable.length === 0 && (
                    <p className="text-sm text-muted-foreground">Nothing to bill - this customer has no uninvoiced challan lines.</p>
                )}

                {billable.length > 0 && (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Challan</th>
                                        <th className="p-3">Product</th>
                                        <th className="p-3">Uninvoiced</th>
                                        <th className="p-3">Bill quantity</th>
                                        <th className="p-3">Unit price</th>
                                        <th className="p-3">Why this price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {billable.map((line) => {
                                        const draft = data.lines.find((entry) => entry.delivery_line_id === line.delivery_line_id);
                                        const index = data.lines.findIndex((entry) => entry.delivery_line_id === line.delivery_line_id);

                                        return (
                                            <tr key={line.delivery_line_id} className="border-t">
                                                <td className="p-3">
                                                    {line.delivery_number}
                                                    <span className="block text-xs text-muted-foreground">{line.delivered_at}</span>
                                                </td>
                                                <td className="p-3">
                                                    {line.product_name} <span className="text-muted-foreground">({line.product_sku})</span>
                                                </td>
                                                <td className="p-3 tabular-nums">{line.quantity_uninvoiced}</td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-28"
                                                        value={draft?.quantity ?? ''}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { quantity: e.target.value })}
                                                    />
                                                    <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                                </td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-32"
                                                        value={draft?.unit_price ?? ''}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { unit_price: e.target.value })}
                                                    />
                                                    <InputError message={errors[`lines.${index}.unit_price` as keyof typeof errors]} />
                                                </td>
                                                <td className="p-3 text-xs text-muted-foreground">{line.price_source}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label>Invoice date (optional, defaults to today)</Label>
                                <Input type="date" value={data.invoice_date} onChange={(e) => setData('invoice_date', e.target.value)} />
                                <InputError message={errors.invoice_date} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Due date (optional)</Label>
                                <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Whole-invoice discount (optional)</Label>
                                <Input value={data.invoice_discount} onChange={(e) => setData('invoice_discount', e.target.value)} />
                                <InputError message={errors.invoice_discount} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Note (optional)</Label>
                                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </div>
                        </div>

                        <InputError message={errors.lines} />

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || included.length === 0}>Raise invoice</Button>
                            <p className="text-sm text-muted-foreground tabular-nums">
                                {included.length} line{included.length === 1 ? '' : 's'} - subtotal {subtotal.toFixed(2)}
                                {parseFloat(data.invoice_discount || '0') > 0 && <> - total after discount {total.toFixed(2)}</>}
                            </p>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
