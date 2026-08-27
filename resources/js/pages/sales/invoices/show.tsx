import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    subtotal: string;
    invoice_discount: string;
    tax_total: string;
    total: string;
    note: string | null;
    customer: { id: number; name: string } | null;
    lines: {
        id: number;
        description: string | null;
        quantity: string;
        unit_price: string;
        line_discount: string;
        line_net: string;
        cost_released: string;
        product: { id: number; name: string; sku: string } | null;
        delivery_line: { id: number; delivery: { id: number; delivery_number: string } | null } | null;
    }[];
    allocations: {
        id: number;
        amount: string;
        receipt: { id: number; received_on: string; reference: string | null; money_account: { name: string } | null } | null;
    }[];
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function InvoiceShow({
    invoice,
    outstanding,
    received,
    costReleased,
    isSettled,
    journal,
    moneyAccounts,
}: {
    invoice: Invoice;
    outstanding: string;
    received: string;
    costReleased: string;
    isSettled: boolean;
    journal: { id: number; journal_date: string; memo: string | null; lines: JournalLine[] } | null;
    moneyAccounts: { id: number; name: string }[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/invoices' },
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
    ];

    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: String(moneyAccounts[0]?.id ?? ''),
        amount: outstanding,
        received_on: new Date().toISOString().slice(0, 10),
        reference: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.invoices.receipts.store', invoice.id), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={invoice.invoice_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold">{invoice.invoice_number}</h1>
                        <p className="text-muted-foreground">
                            {invoice.customer?.name} · {invoice.invoice_date?.slice(0, 10)}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge variant={isSettled ? 'secondary' : 'outline'}>{isSettled ? 'Paid' : 'Unpaid'}</Badge>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/invoices/${invoice.id}/print`}>Print / send</Link>
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Item</th>
                                <th className="p-3">From challan</th>
                                <th className="p-3 text-right">Qty</th>
                                <th className="p-3 text-right">Price</th>
                                <th className="p-3 text-right">Discount</th>
                                <th className="p-3 text-right">Net</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.lines.map((line) => (
                                <tr key={line.id} className="border-t">
                                    <td className="p-3">{line.product?.name ?? line.description ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">{line.delivery_line?.delivery?.delivery_number ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{line.quantity}</td>
                                    <td className="p-3 text-right tabular-nums">{line.unit_price}</td>
                                    <td className="p-3 text-right tabular-nums">{line.line_discount}</td>
                                    <td className="p-3 text-right tabular-nums">{line.line_net}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="grid gap-1 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Subtotal</span>
                            <span className="tabular-nums">{invoice.subtotal}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Discount on the bill</span>
                            <span className="tabular-nums">{invoice.invoice_discount}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{invoice.total}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Received</span>
                            <span className="tabular-nums">{received}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Outstanding</span>
                            <span className="tabular-nums">{outstanding}</span>
                        </div>
                        <div className="text-muted-foreground mt-2 flex justify-between">
                            <span>Cost of these goods</span>
                            <span className="tabular-nums">{costReleased}</span>
                        </div>
                    </div>

                    {!isSettled && moneyAccounts.length > 0 && (
                        <form onSubmit={submit} className="grid gap-3 rounded-lg border p-4">
                            <h2 className="font-medium">Record a receipt</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="money_account_id">Into</Label>
                                <SearchSelect
                                    id="money_account_id"
                                    value={data.money_account_id}
                                    onChange={(e) => setData('money_account_id', e.target.value)}
                                >
                                    {moneyAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.money_account_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="amount">Amount</Label>
                                <Input id="amount" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="received_on">Received on</Label>
                                <Input
                                    id="received_on"
                                    type="date"
                                    value={data.received_on}
                                    onChange={(e) => setData('received_on', e.target.value)}
                                />
                                <InputError message={errors.received_on} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="reference">Reference</Label>
                                <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Record receipt
                            </Button>
                        </form>
                    )}
                </div>

                {invoice.allocations.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Payments received</h2>
                        <table className="w-full text-sm">
                            <tbody>
                                {invoice.allocations.map((allocation) => (
                                    <tr key={allocation.id} className="border-t">
                                        <td className="p-3">{allocation.receipt?.received_on?.slice(0, 10)}</td>
                                        <td className="p-3">{allocation.receipt?.money_account?.name}</td>
                                        <td className="text-muted-foreground p-3">{allocation.receipt?.reference}</td>
                                        <td className="p-3 text-right tabular-nums">{allocation.amount}</td>
                                        <td className="p-3 text-right">
                                            {allocation.receipt && (
                                                <Link
                                                    href={`/sales/receipts/${allocation.receipt.id}/print`}
                                                    className="text-sm font-medium underline underline-offset-4"
                                                >
                                                    Receipt
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* The journal is shown here on purpose. "What did this do to the
                    books" is the question that makes an accountant trust the
                    system, and hiding it is how people stop checking. */}
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
