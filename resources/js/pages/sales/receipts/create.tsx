import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface OpenInvoice {
    id: number;
    number: string;
    date: string | null;
    due: string | null;
    is_overdue: boolean;
    total: string;
    outstanding: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/orders' },
    { title: 'Receipts', href: '/sales/receipts' },
    { title: 'Receive payment', href: '/sales/receipts/new' },
];

// Whole paisa, so the split adds up exactly to what was typed.
const toPaisa = (value: string) => Math.round((parseFloat(value) || 0) * 100);
const fromPaisa = (paisa: number) => (paisa / 100).toFixed(2);
const money = (paisa: number) => (paisa / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * ⭐ Receive payment (21 Sep 2026): pick the customer, type what they paid, and
 * it is spread over their oldest invoices first. Every figure stays editable
 * before anything is posted, and nothing is posted that does not add up.
 */
export default function ReceiptCreate({
    customers,
    customer,
    invoices,
    moneyAccounts,
    today,
}: {
    customers: { id: number; name: string; phone: string | null }[];
    customer: { id: number; name: string } | null;
    invoices: OpenInvoice[];
    moneyAccounts: { id: number; name: string; type: string }[];
    today: string;
}) {
    const [received, setReceived] = useState('');
    const [split, setSplit] = useState<Record<number, string>>({});
    const form = useForm({
        customer_id: customer ? String(customer.id) : '',
        money_account_id: moneyAccounts.length === 1 ? String(moneyAccounts[0].id) : '',
        received_on: today,
        reference: '',
        note: '',
        allocations: [] as { invoice_id: number; amount: string }[],
    });

    const owed = useMemo(() => invoices.reduce((sum, invoice) => sum + toPaisa(invoice.outstanding), 0), [invoices]);
    const allocated = invoices.reduce((sum, invoice) => sum + toPaisa(split[invoice.id] ?? '0'), 0);
    const receivedPaisa = toPaisa(received);

    const chooseCustomer = (id: string) => {
        router.get(route('sales.receipts.create'), id ? { customer_id: id } : {}, { preserveScroll: true });
    };

    // Oldest first, each invoice up to what it still owes.
    const spread = (value: string) => {
        setReceived(value);
        let left = toPaisa(value);
        const next: Record<number, string> = {};
        for (const invoice of invoices) {
            const take = Math.min(left, toPaisa(invoice.outstanding));
            next[invoice.id] = take > 0 ? fromPaisa(take) : '';
            left -= Math.max(take, 0);
        }
        setSplit(next);
    };

    const rows = invoices.map((invoice) => ({ invoice_id: invoice.id, amount: split[invoice.id] ?? '' })).filter((row) => toPaisa(row.amount) > 0);

    // The server reports errors by position among the rows it was sent.
    const errorFor = (invoiceId: number) => {
        const index = rows.findIndex((row) => row.invoice_id === invoiceId);
        return index < 0 ? undefined : form.errors[`allocations.${index}.amount` as keyof typeof form.errors];
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, allocations: rows }));
        form.post(route('sales.receipts.store'));
    };

    const mismatch = receivedPaisa > 0 && allocated !== receivedPaisa;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Receive payment" />
            <form onSubmit={submit} className="flex max-w-5xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Receive payment</h1>
                    <p className="text-muted-foreground text-sm">
                        Money a customer paid. It settles their oldest invoices first; change the split below before you post if they said otherwise.
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="customer" required>
                            Customer
                        </Label>
                        <SearchSelect id="customer" value={form.data.customer_id} onChange={(e) => chooseCustomer(e.target.value)}>
                            <option value="">Choose a customer</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.phone ? `${c.name} (${c.phone})` : c.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={form.errors.customer_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="received" required>
                            Amount received (taka)
                        </Label>
                        <Input
                            id="received"
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={received}
                            onChange={(e) => spread(e.target.value)}
                            disabled={invoices.length === 0}
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="received_on" required>
                            Date received
                        </Label>
                        <Input
                            id="received_on"
                            type="date"
                            max={today}
                            value={form.data.received_on}
                            onChange={(e) => form.setData('received_on', e.target.value)}
                        />
                        <InputError message={form.errors.received_on} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="account" required>
                            Received into
                        </Label>
                        <SearchSelect
                            id="account"
                            value={form.data.money_account_id}
                            onChange={(e) => form.setData('money_account_id', e.target.value)}
                        >
                            <option value="">Cash, bank or wallet</option>
                            {moneyAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {moneyAccounts.length === 0 && (
                            <p className="text-xs text-amber-600 dark:text-amber-500">
                                No cash, bank or wallet account yet. Add one under{' '}
                                <Link href="/accounting/money-accounts" className="underline">
                                    Accounting &gt; Bank &amp; cash
                                </Link>
                                .
                            </p>
                        )}
                        <InputError message={form.errors.money_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={form.data.reference}
                            onChange={(e) => form.setData('reference', e.target.value)}
                            placeholder="cheque no., bKash TrxID"
                        />
                        <InputError message={form.errors.reference} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="optional" />
                    </div>
                </div>

                {customer && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-semibold">Unpaid invoices of {customer.name}</h2>
                        {invoices.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing is owed: every invoice of this customer is paid.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem] text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left">
                                            <th className="py-2">Invoice</th>
                                            <th className="py-2">Date</th>
                                            <th className="py-2">Due</th>
                                            <th className="py-2 text-right">Total</th>
                                            <th className="py-2 text-right">Still owed</th>
                                            <th className="py-2 text-right">Pay now</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.map((invoice) => (
                                            <tr key={invoice.id} className="border-b">
                                                <td className="py-2">
                                                    <Link href={`/sales/invoices/${invoice.id}`} className="underline-offset-2 hover:underline">
                                                        {invoice.number}
                                                    </Link>
                                                </td>
                                                <td className="text-muted-foreground py-2">{invoice.date}</td>
                                                <td className="py-2">
                                                    {invoice.due ?? '-'}
                                                    {invoice.is_overdue && (
                                                        <Badge variant="destructive" className="ml-2">
                                                            overdue
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="text-muted-foreground py-2 text-right tabular-nums">{invoice.total}</td>
                                                <td className="py-2 text-right tabular-nums">{money(toPaisa(invoice.outstanding))}</td>
                                                <td className="py-2 text-right">
                                                    <Input
                                                        aria-label={`Pay now on ${invoice.number}`}
                                                        type="number"
                                                        inputMode="decimal"
                                                        min={0}
                                                        step="0.01"
                                                        className="ml-auto h-9 w-32 text-right"
                                                        value={split[invoice.id] ?? ''}
                                                        onChange={(e) => setSplit((current) => ({ ...current, [invoice.id]: e.target.value }))}
                                                    />
                                                    <InputError message={errorFor(invoice.id)} />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="font-medium">
                                            <td className="py-2" colSpan={4}>
                                                Owed in all
                                            </td>
                                            <td className="py-2 text-right tabular-nums">{money(owed)}</td>
                                            <td className="py-2 text-right tabular-nums">{money(allocated)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {receivedPaisa > owed && owed > 0 && (
                    <p className="text-sm text-amber-600 dark:text-amber-500">
                        They owe only {money(owed)}. Record {money(owed)} here; money paid ahead of an order is taken on that order's page.
                    </p>
                )}
                {mismatch && receivedPaisa <= owed && (
                    <p className="text-sm text-amber-600 dark:text-amber-500">
                        The split adds up to {money(allocated)}, not the {money(receivedPaisa)} received. Change a figure so they agree.
                    </p>
                )}
                <InputError message={form.errors.allocations} />

                <div className="flex items-center gap-3">
                    <Button disabled={form.processing || rows.length === 0 || mismatch || !form.data.money_account_id}>
                        {allocated > 0 ? `Receive ${money(allocated)}` : 'Receive payment'}
                    </Button>
                    <Link href="/sales/receipts" className="text-muted-foreground text-sm hover:underline">
                        Cancel
                    </Link>
                </div>
            </form>
        </AppLayout>
    );
}
