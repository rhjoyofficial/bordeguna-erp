import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
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

export default function StockTransfersIndex({ transfers, can_receive }: { transfers: TransferRow[]; can_receive: boolean }) {
    const receive = (transfer: TransferRow) => {
        router.post(route('inventory.stock.transfers.receive', transfer.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="In-transit transfers" />
            <div className="flex flex-col gap-4 p-4">
                <p className="text-sm text-muted-foreground">
                    Stock dispatched from a source warehouse sits here until someone confirms it arrived — it does not count as destination
                    stock until received.
                </p>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
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
                        {transfers.map((transfer) => (
                            <tr key={transfer.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                <td className="py-2">
                                    {transfer.product.name} <span className="text-muted-foreground">({transfer.product.sku})</span>
                                </td>
                                <td className="py-2 text-muted-foreground">
                                    {transfer.source_warehouse.name} → {transfer.target_warehouse.name}
                                </td>
                                <td className="py-2">{transfer.quantity}</td>
                                <td className="py-2 max-w-xs text-muted-foreground">
                                    {transfer.reason.name}
                                    {transfer.note && <div className="text-xs">{transfer.note}</div>}
                                </td>
                                <td className="py-2 text-muted-foreground">
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
                        {transfers.length === 0 && (
                            <tr>
                                <td colSpan={7} className="py-4 text-center text-muted-foreground">
                                    No transfers yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
