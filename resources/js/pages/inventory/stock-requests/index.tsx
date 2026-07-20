import { ConfirmDialog } from '@/components/confirm-dialog';
import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface ApprovalRequest {
    status: 'pending' | 'approved' | 'rejected';
    requested_by: { name: string };
    reviewed_by: { name: string } | null;
    review_note: string | null;
}

interface StockRequestRow {
    id: number;
    type: 'adjustment' | 'transfer';
    direction: 'increase' | 'decrease' | null;
    quantity: string;
    reason: { name: string };
    note: string | null;
    product: { name: string; sku: string };
    warehouse: { name: string };
    target_warehouse: { name: string } | null;
    approval_request: ApprovalRequest;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock requests', href: '/inventory/stock-requests' },
];

const statusVariant: Record<ApprovalRequest['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

export default function StockRequestsIndex({
    requests,
    filters: initialFilters,
}: {
    requests: Paginated<StockRequestRow>;
    filters: { status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock-requests', initialFilters);

    const approve = (request: StockRequestRow) => {
        router.post(route('inventory.stock-requests.approve', request.id));
    };

    const reject = (request: StockRequestRow, review_note: string) => {
        router.post(route('inventory.stock-requests.reject', request.id), { review_note });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock requests" />
            <div className="flex flex-col gap-4 p-4">
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Status" htmlFor="filter-status">
                        <select id="filter-status" className={selectClass} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Type</th>
                            <th className="py-2">Product</th>
                            <th className="py-2">Warehouse</th>
                            <th className="py-2">Qty</th>
                            <th className="py-2">Requested by</th>
                            <th className="py-2">Reason</th>
                            <th className="py-2">Status</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {requests.data.map((request) => (
                            <tr key={request.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                <td className="py-2 capitalize">
                                    {request.type}
                                    {request.direction && <span className="text-muted-foreground"> ({request.direction})</span>}
                                </td>
                                <td className="py-2">
                                    {request.product.name} <span className="text-muted-foreground">({request.product.sku})</span>
                                </td>
                                <td className="py-2 text-muted-foreground">
                                    {request.warehouse.name}
                                    {request.target_warehouse && ` → ${request.target_warehouse.name}`}
                                </td>
                                <td className="py-2">{request.quantity}</td>
                                <td className="py-2 text-muted-foreground">{request.approval_request.requested_by.name}</td>
                                <td className="py-2 max-w-xs text-muted-foreground">
                                    {request.reason.name}
                                    {request.note && <div className="text-xs">{request.note}</div>}
                                </td>
                                <td className="py-2">
                                    <Badge variant={statusVariant[request.approval_request.status]} className="capitalize">
                                        {request.approval_request.status}
                                    </Badge>
                                </td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    {request.approval_request.status === 'pending' && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => approve(request)}>
                                                Approve
                                            </Button>
                                            <ConfirmDialog
                                                trigger={
                                                    <Button variant="ghost" size="sm">
                                                        Reject
                                                    </Button>
                                                }
                                                title="Reject stock request"
                                                description={`${request.product.name} — ${request.quantity} at ${request.warehouse.name}. This cannot be undone.`}
                                                confirmLabel="Reject request"
                                                destructive
                                                withNote
                                                noteLabel="Reason for rejecting (optional)"
                                                notePlaceholder="Add a note for the requester…"
                                                onConfirm={(note) => reject(request, note)}
                                            />
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {requests.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="py-4 text-center text-muted-foreground">
                                    {hasActiveFilters ? 'No stock requests match this filter.' : 'No stock requests yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={requests} />
            </div>
        </AppLayout>
    );
}
