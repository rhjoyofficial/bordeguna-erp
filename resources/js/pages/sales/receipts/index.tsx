import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Receipt {
    id: number;
    received_on: string | null;
    customer: string | null;
    customer_id: number;
    money_account: string | null;
    amount: string;
    reference: string | null;
    invoices: { id: number; number: string | null; amount: string }[];
    recorded_by: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Receipts', href: '/sales/receipts' },
];

/**
 * Every receipt, with the invoices it paid (21 Sep 2026). ⚠ There was no list
 * of money received anywhere before: a receipt could be found only from the
 * invoice it paid.
 */
export default function ReceiptsIndex({
    receipts,
    total,
    filters: initialFilters,
    customers,
    moneyAccounts,
    canReceive,
}: {
    receipts: Paginated<Receipt>;
    total: string;
    filters: { customer_id: string; money_account_id: string; from: string; to: string };
    customers: { id: number; name: string }[];
    moneyAccounts: { id: number; name: string }[];
    canReceive: boolean;
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/sales/receipts', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Receipts" />
            <div className="flex flex-col gap-4 p-4">
                {canReceive && (
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        <Button asChild size="sm">
                            <Link href="/sales/receipts/new">Receive payment</Link>
                        </Button>
                    </div>
                )}

                <FilterBar onClear={reset} showClear={hasActiveFilters}>
                    <FilterField label="Customer" htmlFor="filter-customer" className="min-w-56">
                        <SearchSelect id="filter-customer" value={filters.customer_id} onChange={(e) => setFilter('customer_id', e.target.value)}>
                            <option value="">All customers</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                    <FilterField label="Received into" htmlFor="filter-account">
                        <SearchSelect
                            id="filter-account"
                            value={filters.money_account_id}
                            onChange={(e) => setFilter('money_account_id', e.target.value)}
                        >
                            <option value="">All accounts</option>
                            {moneyAccounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                    <FilterField label="From" htmlFor="filter-from">
                        <Input id="filter-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                    </FilterField>
                    <FilterField label="To" htmlFor="filter-to">
                        <Input id="filter-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                    </FilterField>
                </FilterBar>

                <p className="text-sm">
                    <span className="text-muted-foreground">{hasActiveFilters ? 'Received in this selection: ' : 'Received in all: '}</span>
                    <span className="font-semibold tabular-nums">{total}</span>
                </p>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[50rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-2">Date</th>
                                <th className="py-2">Customer</th>
                                <th className="py-2">Received into</th>
                                <th className="py-2">Paid invoices</th>
                                <th className="py-2">Reference</th>
                                <th className="py-2 text-right">Amount</th>
                                <th className="py-2 text-right">Print</th>
                            </tr>
                        </thead>
                        <tbody>
                            {receipts.data.map((receipt) => (
                                <tr key={receipt.id} className="border-b align-top">
                                    <td className="py-2 whitespace-nowrap">{receipt.received_on}</td>
                                    <td className="py-2">
                                        <Link href={`/sales/customers/${receipt.customer_id}`} className="underline-offset-2 hover:underline">
                                            {receipt.customer}
                                        </Link>
                                    </td>
                                    <td className="text-muted-foreground py-2">{receipt.money_account}</td>
                                    <td className="py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {receipt.invoices.map((invoice) => (
                                                <Link key={invoice.id} href={`/sales/invoices/${invoice.id}`}>
                                                    <Badge variant="outline">
                                                        {invoice.number} · {formatMoney(invoice.amount)}
                                                    </Badge>
                                                </Link>
                                            ))}
                                            {receipt.invoices.length === 0 && <span className="text-muted-foreground">held for an order</span>}
                                        </div>
                                    </td>
                                    <td className="text-muted-foreground py-2">{receipt.reference ?? '-'}</td>
                                    <td className="py-2 text-right font-medium tabular-nums">{formatMoney(receipt.amount)}</td>
                                    <td className="py-2 text-right">
                                        <a
                                            href={`/sales/receipts/${receipt.id}/print`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-muted-foreground hover:underline"
                                        >
                                            Print
                                        </a>
                                    </td>
                                </tr>
                            ))}
                            {receipts.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground py-6 text-center">
                                        {hasActiveFilters ? 'No receipts match these filters.' : 'No money received yet.'}
                                        {canReceive && !hasActiveFilters && (
                                            <>
                                                {' '}
                                                <Link href="/sales/receipts/new" className="underline">
                                                    Receive a payment
                                                </Link>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={receipts} />
            </div>
        </AppLayout>
    );
}
