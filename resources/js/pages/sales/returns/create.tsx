import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface ReturnableLine {
    delivery_line_id: number;
    product_name: string;
    product_sku: string;
    quantity_delivered: string;
    quantity_returnable: string;
    quantity_uninvoiced: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Returns', href: '/sales/returns' },
    { title: 'Record return', href: '/sales/returns/create' },
];

type LineDraft = { delivery_line_id: number; quantity_to_stock: string; quantity_written_off: string };

/*
 * Goods coming back, recorded AFTER inspection in one step (C-SR-3, Joy): the
 * person who checked the goods says, per line, how much goes back on the shelf
 * and how much is destroyed. The engine derives everything else - which FIFO
 * layers restore, whether the money is a GDNI reversal or a customer credit,
 * at which invoiced price.
 *
 * ⚠ The caps shown here came from the SERVER and the engine re-checks them
 * under lock; this screen never computes its own truth.
 */
export default function ReturnCreate({
    deliveries,
    deliveryId,
    lines,
    can_refund,
    moneyAccounts,
    invoiceLookupError = null,
}: {
    deliveries: { id: number; delivery_number: string; delivered_at: string; customer: { id: number; name: string } | null }[];
    deliveryId: number | null;
    delivery: { id: number; delivery_number: string; customer: { id: number; name: string } } | null;
    lines: ReturnableLine[];
    can_refund: boolean;
    moneyAccounts: { id: number; name: string; type: string }[];
    invoiceLookupError: string | null;
}) {
    // The counter entry: the customer holds a receipt with an INVOICE number,
    // so let the cashier type that instead of hunting for the challan.
    const [invoiceNumber, setInvoiceNumber] = useState('');
    const findByInvoice = () => {
        if (invoiceNumber.trim() !== '') {
            router.get(route('sales.returns.create'), { invoice_number: invoiceNumber.trim() }, { preserveState: false });
        }
    };
    const { data, setData, post, errors, processing, transform } = useForm<{
        delivery_id: string;
        returned_at: string;
        note: string;
        lines: LineDraft[];
        refund_account: string;
        refund_amount: string;
    }>({
        delivery_id: deliveryId ? String(deliveryId) : '',
        returned_at: '',
        note: '',
        lines: lines.map((line) => ({
            delivery_line_id: line.delivery_line_id,
            quantity_to_stock: '',
            quantity_written_off: '',
        })),
        refund_account: '',
        refund_amount: '',
    });

    const pickDelivery = (id: string) => {
        router.get(route('sales.returns.create'), id ? { delivery_id: id } : {}, { preserveState: false });
    };

    const updateLine = (deliveryLineId: number, patch: Partial<LineDraft>) => {
        setData(
            'lines',
            data.lines.map((line) => (line.delivery_line_id === deliveryLineId ? { ...line, ...patch } : line)),
        );
    };

    // The optional cash refund travels as a nested object only when filled in;
    // an empty refund key would trip required_with on the server.
    transform((form) => ({
        delivery_id: form.delivery_id,
        returned_at: form.returned_at || null,
        note: form.note || null,
        lines: form.lines,
        ...(form.refund_amount && form.refund_account ? { refund: { money_account_id: form.refund_account, amount: form.refund_amount } } : {}),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.returns.store'));
    };

    const touched = data.lines.filter((line) => parseFloat(line.quantity_to_stock || '0') > 0 || parseFloat(line.quantity_written_off || '0') > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record return" />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid max-w-md gap-2">
                    <Label>Receipt / invoice number the customer is holding</Label>
                    <div className="flex gap-2">
                        <Input
                            placeholder="e.g. INV-0042"
                            value={invoiceNumber}
                            onChange={(e) => setInvoiceNumber(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    findByInvoice();
                                }
                            }}
                        />
                        <Button type="button" variant="outline" onClick={findByInvoice}>
                            Find
                        </Button>
                    </div>
                    {invoiceLookupError && <p className="text-destructive text-sm">{invoiceLookupError}</p>}
                </div>

                <div className="grid max-w-md gap-2">
                    <Label required>Challan the goods came back from</Label>
                    <SearchableSelect
                        value={data.delivery_id}
                        onChange={pickDelivery}
                        options={deliveries.map((d) => ({
                            value: String(d.id),
                            label: `${d.delivery_number} - ${d.customer?.name ?? ''} (${formatDate(d.delivered_at)})`,
                        }))}
                        placeholder="Select challan"
                        searchPlaceholder="Search challans…"
                    />
                    <InputError message={errors.delivery_id} />
                </div>

                {deliveryId !== null && lines.length > 0 && (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Product</th>
                                        <th className="p-3 text-right">Delivered</th>
                                        <th className="p-3 text-right">Can return</th>
                                        <th className="p-3 text-right">Not yet billed</th>
                                        <th className="p-3">Back to stock</th>
                                        <th className="p-3">Write off</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((line) => {
                                        const draft = data.lines.find((entry) => entry.delivery_line_id === line.delivery_line_id);
                                        const index = data.lines.findIndex((entry) => entry.delivery_line_id === line.delivery_line_id);

                                        return (
                                            <tr key={line.delivery_line_id} className="border-t">
                                                <td className="p-3">
                                                    {line.product_name} <span className="text-muted-foreground">({line.product_sku})</span>
                                                </td>
                                                <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_delivered)}</td>
                                                <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_returnable)}</td>
                                                {/* Returned goods relieve the unbilled portion first
                                                    (C-SR-4); anything beyond it credits the customer at
                                                    the price they were invoiced. */}
                                                <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity_uninvoiced)}</td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-28"
                                                        value={draft?.quantity_to_stock ?? ''}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { quantity_to_stock: e.target.value })}
                                                    />
                                                    <InputError message={errors[`lines.${index}.quantity_to_stock` as keyof typeof errors]} />
                                                </td>
                                                <td className="p-3">
                                                    <Input
                                                        className="max-w-28"
                                                        value={draft?.quantity_written_off ?? ''}
                                                        onChange={(e) => updateLine(line.delivery_line_id, { quantity_written_off: e.target.value })}
                                                    />
                                                    <InputError message={errors[`lines.${index}.quantity_written_off` as keyof typeof errors]} />
                                                    <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div className="grid gap-2">
                                <Label>Return date (optional, defaults to today)</Label>
                                <Input type="date" value={data.returned_at} onChange={(e) => setData('returned_at', e.target.value)} />
                                <InputError message={errors.returned_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label>Note (optional)</Label>
                                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </div>
                            {can_refund && (
                                <>
                                    <div className="grid gap-2">
                                        <Label>Cash refund from (optional)</Label>
                                        <SearchSelect value={data.refund_account} onChange={(e) => setData('refund_account', e.target.value)}>
                                            <option value="">No cash refund - credit the account</option>
                                            {moneyAccounts.map((account) => (
                                                <option key={account.id} value={String(account.id)}>
                                                    {account.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        <InputError message={errors['refund.money_account_id' as keyof typeof errors]} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label required={!!data.refund_account}>Cash refund amount</Label>
                                        <Input
                                            aria-required={!!data.refund_account}
                                            value={data.refund_amount}
                                            onChange={(e) => setData('refund_amount', e.target.value)}
                                            disabled={!data.refund_account}
                                        />
                                        <InputError message={errors['refund.amount' as keyof typeof errors]} />
                                    </div>
                                </>
                            )}
                        </div>

                        <InputError message={errors.lines} />

                        <div className="flex items-center gap-4">
                            <Button disabled={processing || touched.length === 0}>Record return</Button>
                            <p className="text-muted-foreground text-sm">
                                {touched.length} line{touched.length === 1 ? '' : 's'} with quantities.
                            </p>
                        </div>
                    </form>
                )}

                {deliveryId !== null && lines.length === 0 && (
                    <p className="text-muted-foreground text-sm">This challan has nothing left to return.</p>
                )}
            </div>
        </AppLayout>
    );
}
