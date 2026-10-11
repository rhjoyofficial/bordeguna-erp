import { type CourierInfo } from '@/components/couriers/courier-parcel';
import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { BrandFilter, type BrandOption } from '@/components/sales/brand';
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
    // The order's brand, through the challan (Joy, 11 Oct 2026).
    brand: string | null;
    order_id: number | null;
    cod_amount: string;
    status: 'booked' | 'delivered' | 'partially_delivered' | 'returned' | 'paid_return' | 'lost' | 'damaged';
    status_label: string;
    // Courier hub slice 8.
    cutoff_note: string | null;
    door_resolution_label: string | null;
    claim_status_label: string | null;
    outcome_on: string | null;
    collected_amount: string | null;
    invoice: { id: number; invoice_number: string } | null;
    // Courier slice C3: the payout statement that settled it, or how long the
    // courier has owed it since answering.
    paid_out_on: { id: number; statement_reference: string } | null;
    waiting_days: number | null;
    // Courier hub slice 4: what the courier says, when it was last asked, its
    // quoted fees, and its timeline. Not money.
    courier_info: CourierInfo;
}

interface CourierHolding {
    id: number;
    name: string;
    held_in_books: string;
    awaiting_payout: string;
}

// The courier's own word for a parcel, last time it was heard, its quoted fees,
// and the latest few timeline entries.
function CourierCell({ info }: { info: CourierInfo }) {
    if (info.courier_status === null && info.booked_via === 'manual') {
        return <span className="text-muted-foreground text-xs">Typed by hand</span>;
    }

    return (
        <div className="text-xs">
            <Badge variant="outline">{info.courier_status_label ?? 'No word yet'}</Badge>
            <span className="text-muted-foreground block">{info.status_synced_at ? `heard ${info.status_synced_at}` : 'not asked yet'}</span>
            {(info.provider_fee_quoted !== null || info.provider_cod_fee_quoted !== null) && (
                <span className="text-muted-foreground block">
                    fee {info.provider_fee_quoted ?? '-'}
                    {info.provider_cod_fee_quoted !== null && ` + COD ${info.provider_cod_fee_quoted}`}
                </span>
            )}
            {info.prompt && <span className="block font-medium text-sky-700 dark:text-sky-400">{info.prompt.message}</span>}
            {info.events.length > 0 && (
                <ol className="text-muted-foreground mt-1">
                    {info.events.slice(0, 3).map((event) => (
                        <li key={event.id}>
                            {event.occurred_at} {event.status_label ?? event.raw_status ?? event.event}
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
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
    brands = [],
}: {
    consignments: Paginated<Consignment>;
    filters: { search: string; status: string; brand: string };
    brands?: BrandOption[];
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
                    <BrandFilter value={filters.brand ?? ''} onChange={(v) => setFilter('brand', v)} brands={brands} />
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
                                <th className="p-3">Courier says</th>
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
                                    <td className="p-3">
                                        {consignment.customer ?? '-'}
                                        {consignment.brand && (
                                            <Badge variant="outline" className="ml-2">
                                                {consignment.brand}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{consignment.cod_amount}</td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                consignment.status === 'booked'
                                                    ? 'secondary'
                                                    : ['returned', 'lost', 'damaged'].includes(consignment.status)
                                                      ? 'destructive'
                                                      : 'default'
                                            }
                                        >
                                            {consignment.status_label}
                                        </Badge>
                                        {consignment.outcome_on && (
                                            <span className="text-muted-foreground block text-xs">{consignment.outcome_on}</span>
                                        )}
                                        {consignment.door_resolution_label && (
                                            <span className="text-muted-foreground block text-xs">{consignment.door_resolution_label}</span>
                                        )}
                                        {consignment.claim_status_label && (
                                            <span className="block text-xs">Claim: {consignment.claim_status_label}</span>
                                        )}
                                        {consignment.cutoff_note && (
                                            <span className="block text-xs text-amber-700 dark:text-amber-400">{consignment.cutoff_note}</span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <CourierCell info={consignment.courier_info} />
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
                                    <td colSpan={10} className="text-muted-foreground p-6 text-center">
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
