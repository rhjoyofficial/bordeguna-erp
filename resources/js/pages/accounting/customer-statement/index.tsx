import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface CustomerOption {
    id: number;
    label: string;
    is_active: boolean;
}

interface Line {
    date: string;
    kind: 'invoice' | 'return' | 'receipt' | 'refund';
    label: string;
    reference: string | null;
    charge: string | null;
    settlement: string | null;
    balance: string;
    balance_side: 'debit' | 'credit';
}

interface Summary {
    opening: string;
    opening_side: 'debit' | 'credit';
    charges: string;
    settlements: string;
    closing: string;
    closing_side: 'debit' | 'credit';
    checked: boolean;
    consistent: boolean;
    ledger_closing: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Customer statement', href: '/accounting/customer-statement' },
];

/* A credit balance means the business owes the customer (advance or
 * over-payment), and it is the one state that must not read as a debt. */
const side = (amount: string, sideOf: 'debit' | 'credit') => (sideOf === 'credit' ? `${amount} Cr` : amount);

export default function CustomerStatementIndex({
    from,
    to,
    customers,
    customer,
    lines,
    summary,
}: {
    from: string;
    to: string;
    customers: CustomerOption[];
    customer: { id: number; name: string; code: string | null } | null;
    lines: Line[];
    summary: Summary | null;
}) {
    const [filters, setFilters] = useState({ customer: customer ? String(customer.id) : '', from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.customer-statement'), filters, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customer statement" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Customer statement</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        One customer&apos;s account for the period: every invoice, return, payment and refund in the order it happened, with the
                        balance after each one.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="customer">Customer</Label>
                        <select
                            id="customer"
                            value={filters.customer}
                            onChange={(e) => setFilters({ ...filters, customer: e.target.value })}
                            className="border-input bg-background h-9 w-80 rounded-md border px-3 text-sm"
                        >
                            <option value="">Choose a customer</option>
                            {customers.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                    {option.is_active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from}
                            onChange={(e) => setFilters({ ...filters, from: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to}
                            onChange={(e) => setFilters({ ...filters, to: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    <Button variant="outline">Show</Button>
                </form>

                {customer === null && <p className="text-muted-foreground py-6 text-center text-sm">Choose a customer to see their statement.</p>}

                {customer !== null && summary !== null && (
                    <>
                        {/* C-CST-1: the tie-out, shown only when there was something to check against. */}
                        {summary.checked && !summary.consistent && (
                            <div className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-4 py-3 text-sm font-medium">
                                This statement does not agree with the ledger: the documents close at {side(summary.closing, summary.closing_side)}{' '}
                                but the customer&apos;s share of Accounts Receivable is {summary.ledger_closing}. A posting is missing a document or a
                                document is missing a posting. Run accounting:reconcile to find which.
                            </div>
                        )}

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[56rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                        <th className="py-2">Date</th>
                                        <th className="py-2">Detail</th>
                                        <th className="py-2">Reference</th>
                                        <th className="py-2 text-right">Charges</th>
                                        <th className="py-2 text-right">Payments &amp; credits</th>
                                        <th className="py-2 text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="text-muted-foreground py-2">{from}</td>
                                        <td className="py-2 font-medium" colSpan={4}>
                                            Opening balance
                                        </td>
                                        <td className="py-2 text-right font-medium tabular-nums">{side(summary.opening, summary.opening_side)}</td>
                                    </tr>
                                    {lines.map((line, index) => (
                                        <tr key={index} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="text-muted-foreground py-2">{line.date}</td>
                                            <td className="py-2">{line.label}</td>
                                            <td className="text-muted-foreground py-2">{line.reference}</td>
                                            <td className="py-2 text-right tabular-nums">{line.charge}</td>
                                            <td className="py-2 text-right tabular-nums">{line.settlement}</td>
                                            <td className="py-2 text-right tabular-nums">{side(line.balance, line.balance_side)}</td>
                                        </tr>
                                    ))}
                                    {lines.length === 0 && (
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                                No transactions in this period.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="font-medium">
                                        <td className="py-2" colSpan={3}>
                                            Closing balance
                                        </td>
                                        <td className="py-2 text-right tabular-nums">{summary.charges}</td>
                                        <td className="py-2 text-right tabular-nums">{summary.settlements}</td>
                                        <td className="py-2 text-right tabular-nums">{side(summary.closing, summary.closing_side)}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <p className="text-muted-foreground max-w-2xl text-sm">
                            {summary.closing_side === 'credit'
                                ? 'A balance marked Cr means the business owes this customer, usually an advance or an over-payment.'
                                : 'The closing balance is what this customer owes as at the end of the period.'}{' '}
                            Which individual invoices remain unpaid is the receivables ageing&apos;s question.
                        </p>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
