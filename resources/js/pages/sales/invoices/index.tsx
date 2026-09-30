import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Invoice {
    id: number;
    invoice_number: string;
    /** ⚠ A plain Y-m-d Dhaka date from the server; the raw cast serialises in UTC. */
    invoice_date: string;
    total: string;
    outstanding: string;
    is_settled: boolean;
    is_part_paid: boolean;
    customer: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Invoices', href: '/sales/invoices' },
];

export default function InvoicesIndex({
    invoices,
    can_create,
    filters: initialFilters,
}: {
    invoices: Paginated<Invoice>;
    can_create?: boolean;
    filters: { search: string; status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/invoices', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales Invoices" />
            <div className="flex flex-col gap-6 p-4">
                {/* ⚠ Billing a challan - the month-end half of the distributor
                    rhythm. This button is the fix for F1: the engine existed
                    with no way for a human to reach it. It sits in the heading
                    row, not on a row of its own (30 Aug 2026 audit). */}
                <ReportHeading
                    title="Sales invoices"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button asChild size="sm" variant="outline">
                                <Link href="/hrm/reports/sales_by_brand">Sales by brand</Link>
                            </Button>
                            {can_create && (
                                <Button asChild size="sm">
                                    <Link href={route('sales.invoices.create')}>New invoice from challans</Link>
                                </Button>
                            )}
                        </div>
                    }
                />
                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search invoice number or customer"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All</option>
                        <option value="open">Unpaid</option>
                        <option value="settled">Paid</option>
                    </SearchSelect>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Invoice</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Customer</th>
                                <th className="p-3 text-right">Total</th>
                                <th className="p-3 text-right">Outstanding</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.data.map((invoice) => (
                                <tr key={invoice.id} className="border-t">
                                    <td className="p-3 font-medium">
                                        <Link href={route('sales.invoices.show', invoice.id)} className="hover:underline">
                                            {invoice.invoice_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">{invoice.invoice_date}</td>
                                    <td className="p-3">{invoice.customer?.name ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(invoice.total)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(invoice.outstanding)}</td>
                                    <td className="p-3">
                                        <Badge variant={invoice.is_settled ? 'secondary' : 'outline'}>
                                            {invoice.is_settled ? 'Paid' : invoice.is_part_paid ? 'Part paid' : 'Unpaid'}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                            {invoices.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        No invoices yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={invoices} />
            </div>
        </AppLayout>
    );
}
