import { ConfirmDialog } from '@/components/confirm-dialog';
import { CustomerQuickCreate } from '@/components/customer-quick-create';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
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
    // Rule #6 (14 Oct 2026): a line is priced at the retail list because the buyer's group has none.
    retail_fallback: boolean;
    // ⭐ B13 (24 Oct 2026): what the Edit popup needs, and the copy a revision replaced.
    customer_id: number | null;
    lead_id: number | null;
    terms: string | null;
    note: string | null;
    revision_of: string | null;
    lines: DraftLine[];
}

interface Option {
    id: number;
    name: string;
    sku?: string | null;
    /** ⭐ B17: products only, available to sell across the warehouses. */
    available?: string;
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Quotations', href: '/sales/quotations' },
];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

/**
 * ⭐ B13 (24 Oct 2026, gap S21): New and Edit in ONE popup (Joy: add and edit
 * in a popup, never a form above the list), the date first, a Terms box that
 * prints for the customer, and an internal note that never prints. Editing a
 * sent quote saves a revision (R1) and keeps the sent copy. No <form>: Enter
 * never submits, only Save does.
 */
function QuotePopup({ customers, leads, products, quote }: Pick<QuotationsProps, 'customers' | 'leads' | 'products'> & { quote?: QuoteRow }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const initial = {
        party_kind: quote?.lead_id && !quote.customer_id ? 'lead' : 'customer',
        customer_id: quote?.customer_id ? String(quote.customer_id) : '',
        lead_id: quote?.lead_id ? String(quote.lead_id) : '',
        valid_until: quote?.valid_until ?? '',
        terms: quote?.terms ?? '',
        note: quote?.note ?? '',
        lines: quote?.lines?.length ? quote.lines.map((line) => ({ ...line })) : [{ product_id: '', quantity: '', unit_price: '' }],
    };
    const form = useForm<{
        party_kind: string;
        customer_id: string;
        lead_id: string;
        valid_until: string;
        terms: string;
        note: string;
        lines: DraftLine[];
    }>(initial);

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

    const save = () => {
        form.transform((data) => ({
            customer_id: data.party_kind === 'customer' ? data.customer_id || null : null,
            lead_id: data.party_kind === 'lead' ? data.lead_id || null : null,
            valid_until: data.valid_until,
            terms: data.terms || null,
            note: data.note || null,
            lines: data.lines.filter(isFilled),
        }));
        const done = {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                if (!quote) form.reset();
            },
        };
        if (quote) {
            form.put(`/sales/quotations/${quote.id}`, done);
        } else {
            form.post('/sales/quotations', done);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next && quote) form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                {quote ? (
                    <Button variant="outline" size="sm">
                        {t('quotes.edit')}
                    </Button>
                ) : (
                    <Button size="sm">{t('quotes.new')}</Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{quote ? t('quotes.edit_title', { number: quote.quotation_number }) : t('quotes.new')}</DialogTitle>
                    <DialogDescription>{quote?.status === 'sent' ? t('quotes.edit_sent_hint') : t('quotes.form_hint')}</DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="flex flex-wrap items-start gap-3">
                        <div className="space-y-1">
                            <Label htmlFor={`quote-valid-${quote?.id ?? 'new'}`} className="text-xs" required>
                                {t('quotes.valid_until')}
                            </Label>
                            <Input
                                id={`quote-valid-${quote?.id ?? 'new'}`}
                                type="date"
                                className="w-44"
                                aria-required
                                value={form.data.valid_until}
                                onChange={(e) => form.setData('valid_until', e.target.value)}
                            />
                            <InputError message={form.errors.valid_until} />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-xs" required>
                                {t('quotes.quote_for')}
                            </Label>
                            <Select value={form.data.party_kind} onValueChange={(value) => form.setData('party_kind', value)}>
                                <SelectTrigger aria-required className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="customer">{t('quotes.a_customer')}</SelectItem>
                                    <SelectItem value="lead">{t('quotes.a_lead')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="min-w-56 space-y-1">
                            <Label className="text-xs" required>
                                {form.data.party_kind === 'customer' ? t('quotes.customer') : t('quotes.lead')}
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
                                        aria-required
                                        step="0.0001"
                                        min="0"
                                        value={line.quantity}
                                        onChange={(e) => patch(index, 'quantity', e.target.value)}
                                    />
                                    {(() => {
                                        const available = products.find((p) => String(p.id) === line.product_id)?.available;
                                        if (available === undefined) return null;
                                        const short = line.quantity !== '' && Number(line.quantity) > Number(available);

                                        return (
                                            <span
                                                className={
                                                    short ? 'block text-xs text-amber-700 dark:text-amber-400' : 'text-muted-foreground block text-xs'
                                                }
                                            >
                                                {short
                                                    ? t('rsv.line_short', { quantity: formatQuantity(available) })
                                                    : t('rsv.line_available', { quantity: formatQuantity(available) })}
                                            </span>
                                        );
                                    })()}
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

                    <div className="space-y-1">
                        <Label htmlFor={`quote-terms-${quote?.id ?? 'new'}`} className="text-xs">
                            {t('quotes.terms')}
                        </Label>
                        <textarea
                            id={`quote-terms-${quote?.id ?? 'new'}`}
                            value={form.data.terms}
                            onChange={(e) => form.setData('terms', e.target.value)}
                            placeholder={t('quotes.terms_placeholder')}
                            className="border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError message={form.errors.terms} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor={`quote-note-${quote?.id ?? 'new'}`} className="text-xs">
                            {t('quotes.internal_note')}
                        </Label>
                        <textarea
                            id={`quote-note-${quote?.id ?? 'new'}`}
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <p className="text-muted-foreground text-xs">{t('quotes.internal_note_hint')}</p>
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('quotes.cancel')}
                    </Button>
                    <Button type="button" disabled={form.processing} onClick={save}>
                        {t('quotes.save_draft')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const statusColor: Record<string, string> = {
    draft: 'text-muted-foreground',
    sent: 'text-blue-600',
    accepted: 'text-emerald-600',
    rejected: 'text-destructive',
    revised: 'text-muted-foreground line-through',
};

export default function Quotations({ quotations, customers, leads, products, warehouses }: QuotationsProps) {
    const { t } = useTranslation();
    // ⚠⚠ Send, Accepted and Rejected are bare buttons, and every one of their
    // refusals ("expired on ...", "went to a lead - convert it first", "is
    // accepted and cannot change now") comes back on `quotation` - which this
    // page never rendered, so the buttons simply did nothing (audit #1,
    // 21 Sep 2026). Shown above the list, where the button was pressed.
    const quotationError = usePage<{ errors: Record<string, string | undefined> }>().props.errors?.quotation;

    // SAL-003 (14 Oct 2026): the quote being accepted, so a double click
    // cannot send a second request while the first is still creating the order.
    const [accepting, setAccepting] = useState<number | null>(null);

    const accept = (quote: QuoteRow) => {
        if (accepting !== null) return;
        // The default warehouse, not whichever sorts first (21 Sep 2026).
        const warehouse = warehouses.find((w) => w.is_default) ?? warehouses[0];
        setAccepting(quote.id);
        router.post(
            `/sales/quotations/${quote.id}/accept`,
            { warehouse_id: warehouse?.id ?? null },
            { preserveScroll: true, onFinish: () => setAccepting(null) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Quotations" />
            <div className="space-y-4 p-4">
                <div className="flex justify-end">
                    <QuotePopup customers={customers} leads={leads} products={products} />
                </div>

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
                                        <td className="px-3 py-2 font-medium">
                                            {quote.quotation_number}
                                            {quote.revision_of && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {t('quotes.revision_of', { number: quote.revision_of })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {quote.party}
                                            {quote.is_lead && <span className="text-muted-foreground text-xs"> (lead)</span>}
                                            {quote.retail_fallback && (
                                                <span
                                                    className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                                                    title={t('quotes.retail_fallback_hint')}
                                                >
                                                    {t('quotes.retail_fallback_badge')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{money(quote.total)}</td>
                                        <td className="px-3 py-2">{showDate(quote.valid_until)}</td>
                                        <td className={'px-3 py-2 font-medium capitalize ' + (statusColor[quote.status] ?? '')}>{quote.status}</td>
                                        <td className="space-x-1 px-3 py-2 text-right whitespace-nowrap">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/sales/quotations/${quote.id}/print`}>Print</Link>
                                            </Button>
                                            {(quote.status === 'draft' || quote.status === 'sent') && (
                                                <QuotePopup customers={customers} leads={leads} products={products} quote={quote} />
                                            )}
                                            {quote.status === 'draft' && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button variant="ghost" size="sm" className="text-destructive">
                                                            {t('quotes.delete')}
                                                        </Button>
                                                    }
                                                    title={t('quotes.delete_title', { number: quote.quotation_number })}
                                                    description={t('quotes.delete_text')}
                                                    confirmLabel={t('quotes.delete')}
                                                    destructive
                                                    onConfirm={() => router.delete(`/sales/quotations/${quote.id}`, { preserveScroll: true })}
                                                />
                                            )}
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
                                                        disabled={accepting !== null}
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
