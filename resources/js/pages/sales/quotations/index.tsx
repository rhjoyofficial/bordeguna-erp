import { CustomerQuickCreate } from '@/components/customer-quick-create';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Quotations (Module 4, Slice 2): a promise of a price with an expiry.
 * Reserves nothing, posts nothing - accepting is the event that creates the
 * sales order at the quoted prices, verbatim.
 */
interface QuoteRow {
    id: number;
    quotation_number: string;
    party: string;
    is_lead: boolean;
    valid_until: string;
    status: string;
    total: number;
    sales_order_id: number | null;
}

interface Option {
    id: number;
    name: string;
    sku?: string | null;
}

interface QuotationsProps {
    quotations: { data: QuoteRow[]; links: { url: string | null; label: string; active: boolean }[] };
    customers: Option[];
    leads: Option[];
    products: Option[];
    warehouses: (Option & { is_default?: boolean })[];
}

interface DraftLine {
    [key: string]: string;
    product_id: string;
    quantity: string;
    unit_price: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Quotations', href: '/sales/quotations' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

function NewQuoteForm({ customers, leads, products }: Pick<QuotationsProps, 'customers' | 'leads' | 'products'>) {
    const form = useForm<{
        party_kind: string;
        customer_id: string;
        lead_id: string;
        valid_until: string;
        note: string;
        lines: DraftLine[];
    }>({
        party_kind: 'customer',
        customer_id: '',
        lead_id: '',
        valid_until: '',
        note: '',
        lines: [{ product_id: '', quantity: '', unit_price: '' }],
    });

    const [addingCustomer, setAddingCustomer] = useState(false);
    const [typedCustomer, setTypedCustomer] = useState('');

    const patch = (index: number, key: keyof DraftLine, value: string) =>
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line)),
        );

    const isFilled = (line: DraftLine) => line.product_id !== '' && line.quantity !== '';

    // ⚠ Blank rows are dropped before the form is sent (see transform below),
    // so the server's `lines.N` counts filled rows only. This finds the error
    // for an on-screen row by the position it was actually sent at.
    const lineError = (index: number): string | undefined => {
        const line = form.data.lines[index];
        if (!isFilled(line)) {
            return undefined;
        }
        const sentAt = form.data.lines.slice(0, index).filter(isFilled).length;
        const errors = form.errors as Record<string, string | undefined>;

        return errors[`lines.${sentAt}.product_id`] ?? errors[`lines.${sentAt}.quantity`] ?? errors[`lines.${sentAt}.unit_price`];
    };

    return (
        <form
            className="border-border bg-card space-y-3 rounded-lg border p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    customer_id: data.party_kind === 'customer' ? data.customer_id || null : null,
                    lead_id: data.party_kind === 'lead' ? data.lead_id || null : null,
                    valid_until: data.valid_until,
                    note: data.note || null,
                    lines: data.lines.filter(isFilled),
                }));
                form.post('/sales/quotations', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <h2 className="font-semibold">New quotation</h2>
            <div className="grid gap-3 sm:grid-cols-4">
                <div className="space-y-1">
                    <Label className="text-xs" required>
                        Quote for
                    </Label>
                    <Select value={form.data.party_kind} onValueChange={(value) => form.setData('party_kind', value)}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="customer">A customer</SelectItem>
                            <SelectItem value="lead">A lead</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1 sm:col-span-2">
                    <Label className="text-xs" required>
                        {form.data.party_kind === 'customer' ? 'Customer' : 'Lead'}
                    </Label>
                    {form.data.party_kind === 'customer' ? (
                        <>
                            <SearchableSelect
                                value={form.data.customer_id}
                                onChange={(value) => form.setData('customer_id', value)}
                                options={customers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
                                placeholder="Pick the customer"
                                searchPlaceholder="Search customers…"
                                onCreate={(typed) => {
                                    setTypedCustomer(typed);
                                    setAddingCustomer(true);
                                }}
                                createLabel="New customer"
                            />
                            <CustomerQuickCreate
                                open={addingCustomer}
                                onOpenChange={setAddingCustomer}
                                initialName={typedCustomer}
                                onCreated={(row) =>
                                    router.reload({ only: ['customers'], onSuccess: () => form.setData('customer_id', String(row.id)) })
                                }
                            />
                        </>
                    ) : (
                        <SearchableSelect
                            value={form.data.lead_id}
                            onChange={(value) => form.setData('lead_id', value)}
                            options={leads.map((lead) => ({ value: String(lead.id), label: lead.name }))}
                            placeholder="Pick the lead"
                            searchPlaceholder="Search leads…"
                        />
                    )}
                    <InputError message={form.errors.customer_id ?? form.errors.lead_id} />
                </div>
                <div className="space-y-1">
                    <Label className="text-xs" required>
                        Valid until
                    </Label>
                    <Input type="date" value={form.data.valid_until} onChange={(e) => form.setData('valid_until', e.target.value)} />
                    <InputError message={form.errors.valid_until} />
                </div>
            </div>

            <div className="space-y-2">
                {form.data.lines.map((line, index) => (
                    <div key={index} className="flex flex-wrap items-end gap-2">
                        <div className="max-w-md min-w-56 flex-1 space-y-1">
                            <Label className="text-xs" required>
                                Product
                            </Label>
                            <SearchableSelect
                                value={line.product_id}
                                onChange={(value) => patch(index, 'product_id', value)}
                                options={products.map((product) => ({ value: String(product.id), label: product.name }))}
                                placeholder="Product"
                                searchPlaceholder="Search products…"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                Quantity
                            </Label>
                            <Input
                                className="w-24"
                                type="number"
                                step="0.0001"
                                min="0"
                                value={line.quantity}
                                onChange={(e) => patch(index, 'quantity', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs">Quoted price</Label>
                            <Input
                                className="w-40"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="from price list"
                                value={line.unit_price}
                                onChange={(e) => patch(index, 'unit_price', e.target.value)}
                            />
                        </div>
                        {form.data.lines.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData(
                                        'lines',
                                        form.data.lines.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                Remove
                            </Button>
                        )}
                        <InputError message={lineError(index)} className="basis-full" />
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => form.setData('lines', [...form.data.lines, { product_id: '', quantity: '', unit_price: '' }])}
                >
                    Add line
                </Button>
                <InputError message={form.errors.lines} />
            </div>

            <div className="flex items-end gap-3">
                <div className="max-w-md flex-1 space-y-1">
                    <Label className="text-xs">Note (optional)</Label>
                    <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                </div>
                <Button type="submit" disabled={form.processing}>
                    Save draft
                </Button>
            </div>
        </form>
    );
}

const statusColor: Record<string, string> = {
    draft: 'text-muted-foreground',
    sent: 'text-blue-600',
    accepted: 'text-emerald-600',
    rejected: 'text-destructive',
};

export default function Quotations({ quotations, customers, leads, products, warehouses }: QuotationsProps) {
    // ⚠⚠ Send, Accepted and Rejected are bare buttons, and every one of their
    // refusals ("expired on ...", "went to a lead - convert it first", "is
    // accepted and cannot change now") comes back on `quotation` - which this
    // page never rendered, so the buttons simply did nothing (audit #1,
    // 21 Sep 2026). Shown above the list, where the button was pressed.
    const quotationError = usePage<{ errors: Record<string, string | undefined> }>().props.errors?.quotation;

    const accept = (quote: QuoteRow) => {
        // The default warehouse, not whichever sorts first (21 Sep 2026).
        const warehouse = warehouses.find((w) => w.is_default) ?? warehouses[0];
        router.post(`/sales/quotations/${quote.id}/accept`, { warehouse_id: warehouse?.id ?? null }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Quotations" />
            <div className="space-y-6 p-4">
                <h1 className="text-xl font-semibold">Quotations</h1>

                <NewQuoteForm customers={customers} leads={leads} products={products} />

                {quotationError && (
                    <p role="alert" className="border-destructive/50 bg-destructive/10 text-destructive rounded-md border p-3 text-sm">
                        {quotationError}
                    </p>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Number</th>
                                <th className="px-3 py-2 font-medium">For</th>
                                <th className="px-3 py-2 text-right font-medium">Total</th>
                                <th className="px-3 py-2 font-medium">Valid until</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {quotations.data.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-4 text-center">
                                        No quotations yet.
                                    </td>
                                </tr>
                            ) : (
                                quotations.data.map((quote) => (
                                    <tr key={quote.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{quote.quotation_number}</td>
                                        <td className="px-3 py-2">
                                            {quote.party}
                                            {quote.is_lead && <span className="text-muted-foreground text-xs"> (lead)</span>}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{money(quote.total)}</td>
                                        <td className="px-3 py-2">{quote.valid_until}</td>
                                        <td className={'px-3 py-2 font-medium capitalize ' + (statusColor[quote.status] ?? '')}>{quote.status}</td>
                                        <td className="space-x-1 px-3 py-2 text-right whitespace-nowrap">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/sales/quotations/${quote.id}/print`}>Print</Link>
                                            </Button>
                                            {quote.status === 'draft' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => router.post(`/sales/quotations/${quote.id}/send`, {}, { preserveScroll: true })}
                                                >
                                                    Mark sent
                                                </Button>
                                            )}
                                            {(quote.status === 'draft' || quote.status === 'sent') && (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        className="bg-emerald-600 text-white hover:bg-emerald-700"
                                                        onClick={() => accept(quote)}
                                                    >
                                                        Accepted
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive"
                                                        onClick={() =>
                                                            router.post(`/sales/quotations/${quote.id}/reject`, {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Rejected
                                                    </Button>
                                                </>
                                            )}
                                            {quote.sales_order_id && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={`/sales/orders/${quote.sales_order_id}`}>Order</Link>
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
