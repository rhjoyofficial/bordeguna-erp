import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface SalesReturnRow {
    id: number;
    return_number: string;
    /** ⚠ A plain Y-m-d Dhaka date from the server; the raw cast serialises in UTC. */
    returned_at: string;
    credit_total: string;
    refunded_total: string;
    customer: { id: number; name: string } | null;
    delivery: { id: number; delivery_number: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Returns', href: '/sales/returns' },
];

export default function ReturnsIndex({
    returns,
    filters: initialFilters,
    can_record,
}: {
    returns: Paginated<SalesReturnRow>;
    filters: { search: string };
    can_record: boolean;
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/returns', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales Returns" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                        <Input
                            placeholder="Search return or customer"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            className="max-w-xs"
                        />
                    </FilterBar>
                    {can_record && (
                        <Button asChild>
                            <Link href="/sales/returns/create">Record return</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Return</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Customer</th>
                                <th className="p-3">Against challan</th>
                                <th className="p-3 text-right">Customer credit</th>
                                <th className="p-3 text-right">Refunded</th>
                            </tr>
                        </thead>
                        <tbody>
                            {returns.data.map((row) => (
                                <tr key={row.id} className="border-t">
                                    <td className="p-3 font-medium">
                                        <Link href={`/sales/returns/${row.id}`} className="hover:underline">
                                            {row.return_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">{row.returned_at}</td>
                                    <td className="p-3">{row.customer?.name ?? '-'}</td>
                                    <td className="p-3">{row.delivery?.delivery_number ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{parseFloat(row.credit_total).toFixed(2)}</td>
                                    <td className="p-3 text-right tabular-nums">{parseFloat(row.refunded_total).toFixed(2)}</td>
                                </tr>
                            ))}
                            {returns.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        No returns yet. Goods coming back are recorded against the challan they left on.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={returns} />
            </div>
        </AppLayout>
    );
}
