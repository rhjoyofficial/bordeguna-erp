import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

interface ReturnLine {
    id: number;
    quantity: string;
    quantity_to_stock: string;
    quantity_written_off: string;
    credit_net: string;
    delivery_line: { id: number; product: { id: number; name: string; sku: string } | null } | null;
}

interface SalesReturnDetail {
    id: number;
    return_number: string;
    returned_at: string;
    credit_total: string;
    note: string | null;
    customer: { id: number; name: string } | null;
    delivery: { id: number; delivery_number: string; delivered_at: string } | null;
    lines: ReturnLine[];
    allocations: { id: number; amount: string; invoice: { id: number; invoice_number: string } | null }[];
    refunds: { id: number; amount: string; refunded_on: string; money_account: { id: number; name: string } | null }[];
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function ReturnShow({
    salesReturn,
    refundedTotal,
    refundable,
    journal,
    can_refund,
    moneyAccounts,
}: {
    salesReturn: SalesReturnDetail;
    refundedTotal: string;
    refundable: string;
    journal: { id: number; journal_date: string; memo: string | null; lines: JournalLine[] } | null;
    can_refund: boolean;
    moneyAccounts: { id: number; name: string }[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Returns', href: '/sales/returns' },
        { title: salesReturn.return_number, href: `/sales/returns/${salesReturn.id}` },
    ];

    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: '',
        amount: refundable,
        refunded_on: dhakaToday(),
        reference: '',
    });

    const submitRefund: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/sales/returns/${salesReturn.id}/refunds`, { onSuccess: () => reset() });
    };

    const canStillRefund = parseFloat(refundable) > 0.005;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={salesReturn.return_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Customer</p>
                        <p className="font-medium">{salesReturn.customer?.name ?? '-'}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Against challan</p>
                        <p className="font-medium">{salesReturn.delivery?.delivery_number ?? '-'}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Customer credit</p>
                        <p className="font-medium tabular-nums">{parseFloat(salesReturn.credit_total).toFixed(2)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Refunded / still refundable</p>
                        <p className="font-medium tabular-nums">
                            {parseFloat(refundedTotal).toFixed(2)} / {parseFloat(refundable).toFixed(2)}
                        </p>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Returned</th>
                                <th className="p-3 text-right">Back to stock</th>
                                <th className="p-3 text-right">Written off</th>
                                <th className="p-3 text-right">Customer credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {salesReturn.lines.map((line) => (
                                <tr key={line.id} className="border-t">
                                    <td className="p-3">
                                        {line.delivery_line?.product?.name ?? '-'}{' '}
                                        <span className="text-muted-foreground">({line.delivery_line?.product?.sku})</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{line.quantity_to_stock}</td>
                                    <td className="p-3 text-right tabular-nums">{line.quantity_written_off}</td>
                                    <td className="p-3 text-right tabular-nums">{parseFloat(line.credit_net).toFixed(2)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {salesReturn.allocations.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Credit applied to invoices</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {salesReturn.allocations.map((allocation) => (
                                    <tr key={allocation.id} className="border-t first:border-t-0">
                                        <td className="p-3">{allocation.invoice?.invoice_number ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{parseFloat(allocation.amount).toFixed(2)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {salesReturn.refunds.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Refunds paid</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {salesReturn.refunds.map((refund) => (
                                    <tr key={refund.id} className="border-t first:border-t-0">
                                        <td className="p-3">{refund.refunded_on?.slice(0, 10)}</td>
                                        <td className="p-3">{refund.money_account?.name ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{parseFloat(refund.amount).toFixed(2)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {can_refund && canStillRefund && (
                    <form onSubmit={submitRefund} className="flex flex-wrap items-end gap-3 rounded-lg border p-3">
                        <div className="grid gap-2">
                            <Label required>Refund from</Label>
                            <SearchSelect fit value={data.money_account_id} onChange={(e) => setData('money_account_id', e.target.value)}>
                                <option value="">Select account</option>
                                {moneyAccounts.map((account) => (
                                    <option key={account.id} value={String(account.id)}>
                                        {account.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.money_account_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label required>Amount</Label>
                            <Input className="max-w-32" aria-required value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label required>Date</Label>
                            <Input type="date" aria-required value={data.refunded_on} onChange={(e) => setData('refunded_on', e.target.value)} />
                            <InputError message={errors.refunded_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Reference (optional)</Label>
                            <Input value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                        </div>
                        <Button disabled={processing || !data.money_account_id}>Pay refund</Button>
                    </form>
                )}

                {/* The journal on the document, as everywhere else: what this
                    return did to the books, in the books' own words. */}
                {journal && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">What this did to the books</h2>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
                                    <th className="p-3">Memo</th>
                                    <th className="p-3 text-right">Debit</th>
                                    <th className="p-3 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.account?.code} {line.account?.name}
                                        </td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
