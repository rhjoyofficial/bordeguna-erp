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
    // Courier slice C3: the payout statement that settled it, or how long the
    // courier has owed it since answering.
    paid_out_on: { id: number; statement_reference: string } | null;
    waiting_days: number | null;
}

interface CourierHolding {
    id: number;
    name: string;
    held_in_books: string;
    awaiting_payout: string;
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
    couriers,
}: {
    consignments: Paginated<Consignment>;
    filters: { search: string; status: string };
    statuses: { value: string; label: string }[];
    couriers: CourierHolding[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/consignments', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Parcels" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Parcels with couriers" />
                {couriers.length > 0 && (
                    <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        {couriers.map((courier) => (
                            <div key={courier.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-3">
                                <p className="font-medium">{courier.name}</p>
                                <p className="tabular-nums">
                                    Parcels not yet paid out: {courier.awaiting_payout}
                                    <span className="text-muted-foreground"> · in the books: {courier.held_in_books}</span>
                                </p>
                                {courier.awaiting_payout !== courier.held_in_books && (
                                    <p className="text-muted-foreground text-xs">
                                        The difference is fees the courier took on a statement that paid nothing out, owed to it until its next
                                        payout.
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
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
                        <option value="awaiting_payout">Awaiting payout</option>
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
                                <th className="p-3">Paid out</th>
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
                                    <td className="p-3">
                                        {consignment.paid_out_on ? (
                                            <Link
                                                href={route('sales.courier-statements.show', consignment.paid_out_on.id)}
                                                className="underline underline-offset-4"
                                            >
                                                {consignment.paid_out_on.statement_reference}
                                            </Link>
                                        ) : consignment.waiting_days !== null ? (
                                            <span className="text-muted-foreground">
                                                waiting {consignment.waiting_days} day{consignment.waiting_days === 1 ? '' : 's'}
                                            </span>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {consignments.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
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
