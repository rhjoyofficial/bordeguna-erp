import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Consignment {
    id: number;
    courier: string | null;
    consignment_number: string;
    delivery_number: string;
    sent_on: string;
    customer: string | null;
    order_id: number | null;
    cod_amount: string;
    status: 'booked' | 'delivered' | 'partially_delivered' | 'returned';
    status_label: string;
    outcome_on: string | null;
    collected_amount: string | null;
    invoice: { id: number; invoice_number: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Parcels', href: '/sales/consignments' },
];

/*
 * Parcels handed to couriers. It opens on what is still out, oldest first,
 * because that is where the courier's answers are waiting to be entered and a
 * parcel out for days is the one somebody should be chasing.
 */
export default function ConsignmentsIndex({
    consignments,
    filters: initialFilters,
    statuses,
}: {
    consignments: Paginated<Consignment>;
    filters: { search: string; status: string };
    statuses: { value: string; label: string }[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/consignments', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Parcels" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Parcels with couriers" />
                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search consignment, challan or customer"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                        <option value="all">All</option>
                    </SearchSelect>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Consignment</th>
                                <th className="p-3">Challan</th>
                                <th className="p-3">Sent</th>
                                <th className="p-3">Customer</th>
                                <th className="p-3 text-right">To collect</th>
                                <th className="p-3"></th>
                                <th className="p-3 text-right">Collected</th>
                                <th className="p-3">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            {consignments.data.map((consignment) => (
                                <tr key={consignment.id} className="border-t">
                                    <td className="p-3">
                                        <span className="font-medium">{consignment.consignment_number}</span>
                                        <span className="text-muted-foreground block text-xs">{consignment.courier}</span>
                                    </td>
                                    <td className="p-3">
                                        {consignment.order_id ? (
                                            <Link href={route('sales.orders.show', consignment.order_id)} className="underline underline-offset-4">
                                                {consignment.delivery_number}
                                            </Link>
                                        ) : (
                                            consignment.delivery_number
                                        )}
                                    </td>
                                    <td className="p-3">{consignment.sent_on}</td>
                                    <td className="p-3">{consignment.customer ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{consignment.cod_amount}</td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                consignment.status === 'booked'
                                                    ? 'secondary'
                                                    : consignment.status === 'returned'
                                                      ? 'destructive'
                                                      : 'default'
                                            }
                                        >
                                            {consignment.status_label}
                                        </Badge>
                                        {consignment.outcome_on && (
                                            <span className="text-muted-foreground block text-xs">{consignment.outcome_on}</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{consignment.collected_amount ?? '-'}</td>
                                    <td className="p-3">
                                        {consignment.invoice ? (
                                            <Link
                                                href={route('sales.invoices.show', consignment.invoice.id)}
                                                className="underline underline-offset-4"
                                            >
                                                {consignment.invoice.invoice_number}
                                            </Link>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {consignments.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                        No parcels here. A challan is handed to a courier from its order.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={consignments} />
            </div>
        </AppLayout>
    );
}
