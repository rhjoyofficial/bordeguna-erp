import { CustomerQuickCreate } from '@/components/customer-quick-create';
import InputError from '@/components/input-error';
import { CreditOverridePanel, NO_OVERRIDE, type CreditAuthoriser, type CreditOverrideFields } from '@/components/sales/credit-override';
import { LineDiscount } from '@/components/sales/line-discount';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { decimalInput, formatMoney, formatQuantity, plainNumber } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

interface BillableLine {
    delivery_line_id: number;
    delivery_id: number;
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
    { title: 'Sales', href: '/sales/overview' },
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
    onlyDeliveryId,
    agreed_order_discounts,
    agreed_delivery_charges,
    vat,
    discount_cap_percent = null,
    credit_authorisers = [],
}: {
    customers: { id: number; name: string }[];
    customerId: number | null;
    billable: BillableLine[];
    onlyDeliveryId: number | null;
    // Website slice 2.5b: what each order agreed off the WHOLE order (a coupon),
    // previewed for billing everything shown. The server bills it automatically.
    agreed_order_discounts: { order_number: string; coupon_code: string | null; amount: string }[];
    // The delivery charge each website order agreed, not yet billed. The server
    // adds it as its own line on the first invoice billing that order's goods.
    agreed_delivery_charges: { order_number: string; amount: string }[];
    // VAT plan D2. `applies` is false while VAT is off, and nothing is shown.
    vat: { applies: boolean; default_inclusive: boolean; bin: string | null };
    /** ⭐ 25 Oct 2026 (Q12 = A): this person's Discount % cap; null = the Owner. */
    discount_cap_percent?: number | null;
    /** ⭐ 25 Oct 2026 (Q11 = A): who may be chosen to override a credit limit. */
    credit_authorisers?: CreditAuthoriser[];
}) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing } = useForm<
        CreditOverrideFields & {
            floor_reason: string;
            customer_id: string;
            invoice_date: string;
            due_date: string;
            invoice_discount: string;
            prices_include_tax: boolean;
            note: string;
            lines: LineDraft[];
        }
    >({
        ...NO_OVERRIDE,
        // ⭐ 25 Oct 2026 (Q16 = A): why a market product goes below its minimum price.
        floor_reason: '',
        customer_id: customerId ? String(customerId) : '',
        // ⭐ Joy, 19 Oct 2026: invoicing one back-dated challan starts on that
        // challan's date, so an order entered late is billed on the day it left.
        // Blank (= today) for anything else; the box stays editable.
        invoice_date: (() => {
            const from = onlyDeliveryId === null ? null : billable.find((line) => line.delivery_id === onlyDeliveryId)?.delivered_at;
            return from && from < dhakaToday() ? from : '';
        })(),
        due_date: '',
        invoice_discount: '',
        prices_include_tax: vat.default_inclusive,
        note: '',
        // Every billable line starts included at its full uninvoiced quantity
        // and its server-supplied price. Set a quantity to 0 to leave it off.
        lines: billable.map((line) => ({
            delivery_line_id: line.delivery_line_id,
            // From "Invoice this challan": only that challan's lines start included.
            // ⚠ Practice run 18 Oct 2026: the boxes showed "3.0000" and "801.0000",
            // the stored scale. A box holds the plain number ("3", "801").
            quantity: onlyDeliveryId === null || line.delivery_id === onlyDeliveryId ? plainNumber(line.quantity_uninvoiced) : '0',
            unit_price: plainNumber(line.unit_price),
            // ⚠ EMPTY where the order agreed a discount, so the SERVER decides
            // it for the quantity actually billed. Prefilling the full figure
            // would carry a whole line's discount onto a partial bill.
            discount: line.agreed_discount !== null ? '' : '0',
        })),
    });

    const [addingCustomer, setAddingCustomer] = useState(false);
    const [typedCustomer, setTypedCustomer] = useState('');

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

    // ⭐ 25 Oct 2026 (Q11 = A): the credit refusal stays with its Override box;
    // the password is cleared after every try.
    const [creditMessage, setCreditMessage] = useState<string | null>(null);
    const creditError = (errors as Record<string, string | undefined>).credit;
    useEffect(() => {
        if (creditError) setCreditMessage(creditError);
    }, [creditError]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.invoices.store'), { onFinish: () => setData('override_password', '') });
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
                    <Label required>Customer</Label>
                    <SearchableSelect
                        value={data.customer_id}
                        onChange={pickCustomer}
                        options={customers.map((c) => ({ value: String(c.id), label: c.name }))}
                        placeholder="Select customer"
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
                        onCreated={(row) => pickCustomer(String(row.id))}
                    />
                    <InputError message={errors.customer_id} />
                </div>

                {customerId !== null && billable.length === 0 && (
                    <p className="text-muted-foreground text-sm">Nothing to bill - this customer has no uninvoiced challan lines.</p>
                )}

                {billable.length > 0 && (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        {/* Joy, 19 Oct 2026: the dates come first, before the lines. */}
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid w-full gap-2 sm:w-56">
                                <Label>Invoice date (blank = today)</Label>
                                <Input type="date" value={data.invoice_date} onChange={(e) => setData('invoice_date', e.target.value)} />
                                <InputError message={errors.invoice_date} />
                            </div>
                            <div className="grid w-full gap-2 sm:w-56">
                                <Label>Due date (optional)</Label>
                                <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                                <InputError message={errors.due_date} />
                            </div>
                        </div>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Challan</th>
                                        <th className="p-3">Product</th>
                                        <th className="p-3">Uninvoiced</th>
                                        <th className="p-3">
                                            Bill quantity
                                            <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                *
                                            </span>
                                        </th>
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
                                                    <span className="text-muted-foreground block text-xs">{showDate(line.delivered_at)}</span>
                                                </td>
                                                <td className="p-3">
                                                    {line.product_name} <span className="text-muted-foreground">({line.product_sku})</span>
                                                </td>
                                                <td className="p-3 tabular-nums">{formatQuantity(line.quantity_uninvoiced)}</td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-28"
                                                        aria-required
                                                        inputMode="decimal"
                                                        value={draft?.quantity ?? ''}
                                                        onChange={(e) =>
                                                            updateLine(line.delivery_line_id, { quantity: decimalInput(e.target.value) })
                                                        }
                                                    />
                                                    <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                                </td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-32"
                                                        inputMode="decimal"
                                                        value={draft?.unit_price ?? ''}
                                                        onChange={(e) =>
                                                            updateLine(line.delivery_line_id, { unit_price: decimalInput(e.target.value) })
                                                        }
                                                    />
                                                    <InputError message={errors[`lines.${index}.unit_price` as keyof typeof errors]} />
                                                </td>
                                                <td className="p-3">
                                                    {/* ⭐ 25 Oct 2026 (Q12 = A): typed as a percent, kept in taka. */}
                                                    <LineDiscount
                                                        gross={parseFloat(draft?.quantity || '0') * parseFloat(draft?.unit_price || '0')}
                                                        value={draft?.discount ?? ''}
                                                        onChange={(taka) => updateLine(line.delivery_line_id, { discount: decimalInput(taka) })}
                                                        capPercent={discount_cap_percent}
                                                        takaPlaceholder={
                                                            line.agreed_discount !== null ? `Agreed ${formatMoney(line.agreed_discount)}` : undefined
                                                        }
                                                        error={errors[`lines.${index}.discount` as keyof typeof errors]}
                                                    />
                                                </td>
                                                <td className="text-muted-foreground p-3 text-xs">{line.price_source}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {vat.applies && (
                                <div className="grid gap-2">
                                    <Label>The prices on this invoice</Label>
                                    <select
                                        className="border-input bg-background h-9 w-auto rounded-md border px-3 text-sm"
                                        value={data.prices_include_tax ? 'inclusive' : 'exclusive'}
                                        onChange={(e) => setData('prices_include_tax', e.target.value === 'inclusive')}
                                    >
                                        <option value="inclusive">already include VAT</option>
                                        <option value="exclusive">have VAT added on top</option>
                                    </select>
                                    <InputError message={errors.prices_include_tax} />
                                </div>
                            )}
                            <div className="grid gap-2">
                                <Label>Whole-invoice discount (optional)</Label>
                                <Input
                                    inputMode="decimal"
                                    value={data.invoice_discount}
                                    onChange={(e) => setData('invoice_discount', decimalInput(e.target.value))}
                                />
                                <InputError message={errors.invoice_discount} />
                                {/* ⚠ Slice 2.5b. An order's agreed coupon discount is billed
                                    whatever is typed here; anything typed is added to it. */}
                                {agreed_order_discounts.map((row) => (
                                    <p key={row.order_number} className="text-xs">
                                        Also billed automatically: {formatMoney(row.amount)} agreed on {row.order_number}
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
                                        Delivery charge {formatMoney(row.amount)} agreed on {row.order_number} is added as its own line, once, on the
                                        first invoice that bills goods from that order.
                                    </p>
                                ))}
                            </div>
                        )}

                        <InputError message={errors.lines} />

                        {/* ⭐ 25 Oct 2026 (Q16 = A): asked only once the server says a line is below its minimum. */}
                        {(errors.floor_reason !== undefined || data.floor_reason !== '') && (
                            <div className="grid w-fit gap-1">
                                <Label htmlFor="floor_reason" required>
                                    {t('salesctl.floor.reason')}
                                </Label>
                                <Input
                                    id="floor_reason"
                                    className="w-80 max-w-full"
                                    maxLength={500}
                                    value={data.floor_reason}
                                    onChange={(e) => setData('floor_reason', e.target.value)}
                                />
                                <InputError message={errors.floor_reason} />
                            </div>
                        )}

                        {creditMessage !== null && (
                            <CreditOverridePanel
                                message={creditMessage}
                                authorisers={credit_authorisers}
                                value={{
                                    override_by: data.override_by,
                                    override_password: data.override_password,
                                    override_reason: data.override_reason,
                                }}
                                onChange={(patch) => setData((current) => ({ ...current, ...patch }))}
                                errors={errors}
                            />
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || included.length === 0}>Raise invoice</Button>
                            <p className="text-muted-foreground text-sm tabular-nums">
                                {included.length} line{included.length === 1 ? '' : 's'} - goods {formatMoney(subtotal)}
                                {deliveryCharges > 0 && <> - delivery {formatMoney(deliveryCharges)}</>}
                                {showTotal && <> - total {formatMoney(total)}</>}
                            </p>
                        </div>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
