import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
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
    discount_reason: string | null;
    discount_given_by: { id: number; name: string } | null;
    tax_total: string;
    total: string;
    note: string | null;
    customer: { id: number; name: string } | null;
    lines: {
        id: number;
        description: string | null;
        quantity: string;
        unit_price: string;
        // The counter's price trail (21 Sep 2026): null on a line sold at its list price.
        list_price: string | null;
        price_overridden: boolean;
        override_reason: string | null;
        overridden_by: { id: number; name: string } | null;
        line_discount: string;
        line_net: string;
        cost_released?: string;
        product: { id: number; name: string; sku: string } | null;
        delivery_line: { id: number; delivery: { id: number; delivery_number: string } | null } | null;
    }[];
    allocations: {
        id: number;
        amount: string;
        receipt: { id: number; received_on: string; reference: string | null; money_account: { name: string } | null } | null;
    }[];
}

const taka = (minor: number) => formatMoney(minor / 100);

export default function InvoiceShow({
    invoice,
    outstanding,
    received,
    costReleased,
    isSettled,
    journal,
    moneyAccounts,
    returnDeliveryId = null,
}: {
    invoice: Invoice;
    outstanding: string;
    received: string;
    // SAL-004 (14 Oct 2026): null for a viewer without sales.reports.costs.
    costReleased: string | null;
    isSettled: boolean;
    journal: { id: number; journal_date: string; memo: string | null; lines: JournalLine[] } | null;
    moneyAccounts: { id: number; name: string }[];
    returnDeliveryId: number | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
    ];

    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: String(moneyAccounts[0]?.id ?? ''),
        amount: outstanding,
        // The local date: toISOString() is UTC, which before 6am in Dhaka is yesterday.
        received_on: new Date().toLocaleDateString('en-CA'),
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
                            {invoice.customer?.name} · {formatDate(invoice.invoice_date)}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Badge variant={isSettled ? 'secondary' : 'outline'}>
                            {isSettled ? 'Paid' : parseFloat(received) > 0 ? 'Part paid' : 'Unpaid'}
                        </Badge>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/invoices/${invoice.id}/print`}>Print / send</Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/invoices/${invoice.id}/slip`}>Thermal slip</Link>
                        </Button>
                        {/* A counter return starts where the receipt points. */}
                        {returnDeliveryId !== null && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/sales/returns/create?delivery_id=${returnDeliveryId}`}>Record a return</Link>
                            </Button>
                        )}
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
                                    <td className="p-3">
                                        {line.product?.name ?? line.description ?? '-'}
                                        {/* ⚠ The counter's price trail: a changed price or a
                                            discount always says what the list said, why and who. */}
                                        {line.list_price !== null && (
                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                {line.price_overridden ? `List price ${formatMoney(line.list_price)}, changed` : 'Discounted'}
                                                {line.overridden_by ? ` by ${line.overridden_by.name}` : ''}
                                                {line.override_reason ? `: ${line.override_reason}` : ''}
                                            </p>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground p-3">{line.delivery_line?.delivery?.delivery_number ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(line.quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(line.unit_price)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(line.line_discount)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(line.line_net)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    <div className="grid gap-1 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Subtotal</span>
                            <span className="tabular-nums">{formatMoney(invoice.subtotal)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Discount on the bill</span>
                            <span className="tabular-nums">{formatMoney(invoice.invoice_discount)}</span>
                        </div>
                        {invoice.discount_reason && (
                            <p className="text-muted-foreground text-xs">
                                Given{invoice.discount_given_by ? ` by ${invoice.discount_given_by.name}` : ''}: {invoice.discount_reason}
                            </p>
                        )}
                        {/* Shown only on a taxed invoice; every invoice raised while VAT is off has none. */}
                        {Number(invoice.tax_total) > 0 && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">VAT</span>
                                <span className="tabular-nums">{formatMoney(invoice.tax_total)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-semibold">
                            <span>Total</span>
                            <span className="tabular-nums">{formatMoney(invoice.total)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Received</span>
                            <span className="tabular-nums">{formatMoney(received)}</span>
                        </div>
                        <div className="flex justify-between font-semibold">
                            <span>Outstanding</span>
                            <span className="tabular-nums">{formatMoney(outstanding)}</span>
                        </div>
                        {costReleased !== null && (
                            <div className="text-muted-foreground mt-2 flex justify-between">
                                <span>Cost of these goods</span>
                                <span className="tabular-nums">{formatMoney(costReleased)}</span>
                            </div>
                        )}
                    </div>

                    {!isSettled && moneyAccounts.length > 0 && (
                        <form onSubmit={submit} className="grid gap-3 rounded-lg border p-4">
                            <h2 className="font-medium">Record a receipt</h2>
                            <div className="grid gap-2">
                                <Label htmlFor="money_account_id" required>
                                    Into
                                </Label>
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
                                {moneyAccounts.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        No bank, cash or wallet account can take money yet. Set one up under Finance &amp; Accounts &gt; Bank &amp;
                                        cash.
                                    </p>
                                )}
                                <InputError message={errors.money_account_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="amount" required>
                                    Amount
                                </Label>
                                <Input id="amount" required aria-required value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                                {/* ⚠⚠ The engine refuses an over-receipt on
                                    `allocations.0.amount` ("has only 1,200.00
                                    outstanding"), a key this form never rendered,
                                    so Record receipt looked like it did nothing
                                    (audit #1, 21 Sep 2026). It is this box's
                                    error, so it is shown under this box. */}
                                <InputError
                                    message={
                                        errors.amount ??
                                        (errors as Record<string, string | undefined>)['allocations.0.amount'] ??
                                        (errors as Record<string, string | undefined>).allocations
                                    }
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="received_on" required>
                                    Received on
                                </Label>
                                <Input
                                    id="received_on"
                                    type="date"
                                    required
                                    aria-required
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
                                        <td className="p-3">{formatDate(allocation.receipt?.received_on)}</td>
                                        <td className="p-3">{allocation.receipt?.money_account?.name}</td>
                                        <td className="text-muted-foreground p-3">{allocation.receipt?.reference}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(allocation.amount)}</td>
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
