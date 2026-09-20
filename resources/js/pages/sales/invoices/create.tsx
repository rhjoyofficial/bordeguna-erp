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
    // The discount the sales order agreed for this whole uninvoiced quantity,
    // or null when it agreed none (website slice 2.5a).
    agreed_discount: string | null;
    price_source: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Invoices', href: '/sales/invoices' },
    { title: 'New invoice', href: '/sales/invoices/create' },
];

type LineDraft = { delivery_line_id: number; quantity: string; unit_price: string; discount: string };

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
    agreed_order_discounts,
    agreed_delivery_charges,
    vat,
}: {
    customers: { id: number; name: string }[];
    customerId: number | null;
    billable: BillableLine[];
    // Website slice 2.5b: what each order agreed off the WHOLE order (a coupon),
    // previewed for billing everything shown. The server bills it automatically.
    agreed_order_discounts: { order_number: string; coupon_code: string | null; amount: string }[];
    // The delivery charge each website order agreed, not yet billed. The server
    // adds it as its own line on the first invoice billing that order's goods.
    agreed_delivery_charges: { order_number: string; amount: string }[];
    // VAT plan D2. `applies` is false while VAT is off, and nothing is shown.
    vat: { applies: boolean; default_inclusive: boolean; bin: string | null };
}) {
    const { data, setData, post, errors, processing } = useForm<{
        customer_id: string;
        invoice_date: string;
        due_date: string;
        invoice_discount: string;
        prices_include_tax: boolean;
        note: string;
        lines: LineDraft[];
    }>({
        customer_id: customerId ? String(customerId) : '',
        invoice_date: '',
        due_date: '',
        invoice_discount: '',
        prices_include_tax: vat.default_inclusive,
        note: '',
        // Every billable line starts included at its full uninvoiced quantity
        // and its server-supplied price. Set a quantity to 0 to leave it off.
        lines: billable.map((line) => ({
            delivery_line_id: line.delivery_line_id,
            quantity: line.quantity_uninvoiced,
            unit_price: line.unit_price ?? '',
            // ⚠ EMPTY where the order agreed a discount, so the SERVER decides
            // it for the quantity actually billed. Prefilling the full figure
            // would carry a whole line's discount onto a partial bill.
            discount: line.agreed_discount !== null ? '' : '0',
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
    const billableById = new Map(billable.map((line) => [line.delivery_line_id, line]));
    // A PREVIEW of the agreed discount for the quantity typed. The figure billed
    // is decided by the server, where the last portion of a line takes the
    // remainder rather than another rounded share.
    const discountOf = (line: LineDraft) => {
        if (line.discount !== '') {
            return parseFloat(line.discount || '0');
        }
        const source = billableById.get(line.delivery_line_id);
        const whole = parseFloat(source?.quantity_uninvoiced || '0');
        if (!source?.agreed_discount || whole <= 0) {
            return 0;
        }
        return (parseFloat(source.agreed_discount) * parseFloat(line.quantity || '0')) / whole;
    };
    // The subtotal is net of LINE discounts, because a line discount reduces the
    // line and revenue is credited net of it. The whole-invoice discount below
    // is a different animal: contra-revenue, posted to 4210 Discounts Allowed.
    const subtotal = included.reduce(
        (sum, line) => sum + parseFloat(line.quantity || '0') * parseFloat(line.unit_price || '0') - discountOf(line),
        0,
    );
    const agreedOrderDiscount = agreed_order_discounts.reduce((sum, row) => sum + parseFloat(row.amount), 0);
    const deliveryCharges = agreed_delivery_charges.reduce((sum, row) => sum + parseFloat(row.amount), 0);
    const total = subtotal + deliveryCharges - agreedOrderDiscount - parseFloat(data.invoice_discount || '0');
    const showTotal = deliveryCharges > 0 || agreedOrderDiscount > 0 || parseFloat(data.invoice_discount || '0') > 0;

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
                    <p className="text-muted-foreground text-sm">Nothing to bill - this customer has no uninvoiced challan lines.</p>
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
                                        <th className="p-3">Discount</th>
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
                                                    <span className="text-muted-foreground block text-xs">{line.delivered_at}</span>
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
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-28"
                                                        value={draft?.discount ?? ''}
                                                        placeholder={line.agreed_discount !== null ? `Agreed ${line.agreed_discount}` : undefined}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { discount: e.target.value })}
                                                    />
                                                    {line.agreed_discount !== null && (
                                                        <p className="text-muted-foreground mt-1 text-xs">
                                                            Leave empty to bill the discount the order agreed.
                                                        </p>
                                                    )}
                                                    <InputError message={errors[`lines.${index}.discount` as keyof typeof errors]} />
                                                </td>
                                                <td className="text-muted-foreground p-3 text-xs">{line.price_source}</td>
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
                            {vat.applies && (
                                <div className="grid gap-2">
                                    <Label required>The prices on this invoice</Label>
                                    <select
                                        className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                        value={data.prices_include_tax ? 'inclusive' : 'exclusive'}
                                        onChange={(e) => setData('prices_include_tax', e.target.value === 'inclusive')}
                                    >
                                        <option value="inclusive">already include VAT</option>
                                        <option value="exclusive">have VAT added on top</option>
                                    </select>
                                    <p className="text-muted-foreground text-xs">
                                        {data.prices_include_tax
                                            ? 'The customer pays exactly the total shown here. The VAT inside it is worked out when the invoice is saved.'
                                            : 'VAT is added to the total shown here when the invoice is saved, and the customer pays the larger figure.'}
                                    </p>
                                    <InputError message={errors.prices_include_tax} />
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label>Whole-invoice discount (optional)</Label>
                                <Input value={data.invoice_discount} onChange={(e) => setData('invoice_discount', e.target.value)} />
                                <InputError message={errors.invoice_discount} />
                                {/*
                                    C-DSC-2. The two discounts are not the same thing and putting one
                                    in the other's box makes every per-product margin report wrong
                                    while every total still balances.
                                */}
                                <p className="text-muted-foreground text-xs">
                                    Money off one product goes in that product's Discount box above. Use this one only for money off the whole bill.
                                </p>
                                {/* ⚠ Slice 2.5b. An order's agreed coupon discount is billed
                                    whatever is typed here; anything typed is added to it. */}
                                {agreed_order_discounts.map((row) => (
                                    <p key={row.order_number} className="text-xs">
                                        Also billed automatically: {row.amount} agreed on {row.order_number}
                                        {row.coupon_code ? ` (code ${row.coupon_code})` : ''}, if everything above is billed.
                                    </p>
                                ))}
                            </div>
                            <div className="grid gap-2">
                                <Label>Note (optional)</Label>
                                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </div>
                        </div>

                        {agreed_delivery_charges.length > 0 && (
                            <div className="text-sm">
                                {agreed_delivery_charges.map((row) => (
                                    <p key={row.order_number}>
                                        Delivery charge {row.amount} agreed on {row.order_number} is added as its own line, once, on the first invoice
                                        that bills goods from that order.
                                    </p>
                                ))}
                            </div>
                        )}

                        <InputError message={errors.lines} />

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || included.length === 0}>Raise invoice</Button>
                            <p className="text-muted-foreground text-sm tabular-nums">
                                {included.length} line{included.length === 1 ? '' : 's'} - goods {subtotal.toFixed(2)}
                                {deliveryCharges > 0 && <> - delivery {deliveryCharges.toFixed(2)}</>}
                                {showTotal && <> - total {total.toFixed(2)}</>}
                            </p>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
