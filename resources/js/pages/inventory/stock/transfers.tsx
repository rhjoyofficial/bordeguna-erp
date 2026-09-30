import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';

interface TransferRow {
    id: number;
    quantity: string;
    status: 'in_transit' | 'received';
    note: string | null;
    product: { name: string; sku: string };
    source_warehouse: { name: string };
    target_warehouse: { name: string };
    reason: { name: string };
    dispatched_by: { name: string };
    dispatched_at: string;
    received_by: { name: string } | null;
    received_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock', href: '/inventory/stock' },
    { title: 'In-transit transfers', href: '/inventory/stock/transfers' },
];

const statusVariant: Record<TransferRow['status'], 'secondary' | 'default'> = {
    in_transit: 'secondary',
    received: 'default',
};

export default function StockTransfersIndex({
    transfers,
    can_receive,
    filters: initialFilters,
}: {
    transfers: Paginated<TransferRow>;
    can_receive: boolean;
    filters: { status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock/transfers', initialFilters);

    const receive = (transfer: TransferRow) => {
        router.post(route('inventory.stock.transfers.receive', transfer.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="In-transit transfers" />
            <div className="flex flex-col gap-4 p-4">
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Status" htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="in_transit">In transit</option>
                            <option value="received">Received</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Product</th>
                            <th className="py-2">Route</th>
                            <th className="py-2">Qty</th>
                            <th className="py-2">Reason</th>
                            <th className="py-2">Dispatched</th>
                            <th className="py-2">Status</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transfers.data.map((transfer) => (
                            <tr key={transfer.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                <td className="py-2">
                                    {transfer.product.name} <span className="text-muted-foreground">({transfer.product.sku})</span>
                                </td>
                                <td className="text-muted-foreground py-2">
                                    {transfer.source_warehouse.name} → {transfer.target_warehouse.name}
                                </td>
                                <td className="py-2">{formatQuantity(transfer.quantity)}</td>
                                <td className="text-muted-foreground max-w-xs py-2">
                                    {transfer.reason.name}
                                    {transfer.note && <div className="text-xs">{transfer.note}</div>}
                                </td>
                                <td className="text-muted-foreground py-2">
                                    {transfer.dispatched_by.name}
                                    <div className="text-xs">{transfer.dispatched_at}</div>
                                </td>
                                <td className="py-2">
                                    <Badge variant={statusVariant[transfer.status]} className="capitalize">
                                        {transfer.status.replace('_', ' ')}
                                    </Badge>
                                </td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    {transfer.status === 'in_transit' && can_receive && (
                                        <Button variant="ghost" size="sm" onClick={() => receive(transfer)}>
                                            Mark received
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {transfers.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No transfers match this filter.' : 'No transfers yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={transfers} />
            </div>
        </AppLayout>
    );
}
