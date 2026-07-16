import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface StockRequestRow {
    id: number;
    type: 'adjustment' | 'transfer';
    direction: 'increase' | 'decrease' | null;
    quantity: string;
    reason_note: string;
    status: 'pending' | 'approved' | 'rejected';
    product: { name: string; sku: string };
    warehouse: { name: string };
    target_warehouse: { name: string } | null;
    requested_by: { name: string };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock requests', href: '/inventory/stock-requests' },
];

const statusVariant: Record<StockRequestRow['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

export default function StockRequestsIndex({ requests }: { requests: StockRequestRow[] }) {
    const approve = (request: StockRequestRow) => {
        router.post(route('inventory.stock-requests.approve', request.id));
    };

    const reject = (request: StockRequestRow) => {
        const review_note = window.prompt('Reason for rejecting (optional):') ?? '';
        router.post(route('inventory.stock-requests.reject', request.id), { review_note });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock requests" />
            <div className="flex flex-col gap-4 p-4">
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
                        {requests.map((request) => (
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
                                <td className="py-2 text-muted-foreground">{request.requested_by.name}</td>
                                <td className="py-2 max-w-xs text-muted-foreground">{request.reason_note}</td>
                                <td className="py-2">
                                    <Badge variant={statusVariant[request.status]} className="capitalize">
                                        {request.status}
                                    </Badge>
                                </td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    {request.status === 'pending' && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => approve(request)}>
                                                Approve
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => reject(request)}>
                                                Reject
                                            </Button>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {requests.length === 0 && (
                            <tr>
                                <td colSpan={8} className="py-4 text-center text-muted-foreground">
                                    No stock requests yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
