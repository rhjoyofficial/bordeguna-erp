import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Delivery {
    id: number;
    delivery_number: string;
    delivered_at: string;
    fully_invoiced: boolean;
    customer: { id: number; name: string } | null;
    warehouse: { id: number; name: string } | null;
    lines: { id: number; quantity: string; quantity_invoiced: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Delivery challans', href: '/sales/deliveries' },
];

export default function DeliveriesIndex({
    deliveries,
    filters: initialFilters,
}: {
    deliveries: Paginated<Delivery>;
    filters: { search: string; status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/deliveries', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Delivery Challans" />
            <div className="flex flex-col gap-6 p-4">
                {/* An uninvoiced challan is not an error. It is goods out that
                    nobody has billed yet, and its value is what sits in Goods
                    Delivered Not Invoiced. */}
                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search challan or customer"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <select className={selectClass} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All</option>
                        <option value="uninvoiced">Not fully invoiced</option>
                    </select>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Challan</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Customer</th>
                                <th className="p-3">From</th>
                                <th className="p-3 text-right">Lines</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {deliveries.data.map((delivery) => (
                                <tr key={delivery.id} className="border-t">
                                    <td className="p-3 font-medium">{delivery.delivery_number}</td>
                                    <td className="p-3">{delivery.delivered_at?.slice(0, 10)}</td>
                                    <td className="p-3">{delivery.customer?.name ?? '-'}</td>
                                    <td className="p-3">{delivery.warehouse?.name ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{delivery.lines.length}</td>
                                    <td className="p-3">
                                        <Badge variant={delivery.fully_invoiced ? 'secondary' : 'outline'}>
                                            {delivery.fully_invoiced ? 'Invoiced' : 'Awaiting invoice'}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                            {deliveries.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                        No delivery challans yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={deliveries} />
            </div>
        </AppLayout>
    );
}
