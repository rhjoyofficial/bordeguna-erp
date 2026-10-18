import { Chip } from '@/components/assets/assets-ui';
import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

interface OrderRow {
    id: number;
    po_number: string;
    status: 'open' | 'partially_received' | 'received' | 'cancelled';
    // ⭐ D-G, 18 Oct 2026: the day the order was placed (Y-m-d).
    order_date: string;
    // Derived from the order's supplier bills (PurchaseOrderBilling), never typed.
    billing: { state: 'nothing_billed' | 'owed' | 'settled'; billed: string; outstanding: string };
    total_ordered: string;
    supplier: { name: string };
    warehouse: { name: string };
    approval_request: { status: 'pending' | 'approved' | 'rejected' };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
];

const statusVariant: Record<OrderRow['status'], 'secondary' | 'default' | 'outline' | 'destructive'> = {
    open: 'secondary',
    partially_received: 'outline',
    received: 'default',
    cancelled: 'destructive',
};

const approvalVariant: Record<OrderRow['approval_request']['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

export default function PurchaseOrdersIndex({
    orders,
    can_create,
    filters: initialFilters,
}: {
    orders: Paginated<OrderRow>;
    can_create: boolean;
    filters: { search: string; status: string; approval: string; stage: string };
}) {
    const { t } = useTranslation();
    const { filters, setFilter, setMany, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/purchase-orders', initialFilters);
    // The overview's chain steps land here with one of these set (PurchaseOrderController@index).
    const stage =
        filters.approval === 'pending' ? 'approval' : filters.stage === 'awaiting_goods' ? 'goods' : filters.stage === 'to_bill' ? 'bill' : 'all';
    const pickStage = (key: typeof stage) =>
        setMany({
            approval: key === 'approval' ? 'pending' : '',
            stage: key === 'goods' ? 'awaiting_goods' : key === 'bill' ? 'to_bill' : '',
        });

    // ⭐ Purchase redesign PR1 (18 Oct 2026): "New purchase order" opens its
    // own full page; the form that sat under this list is gone. An old link
    // ending in #new-order (a bookmark) is sent to the page too.
    useEffect(() => {
        if (can_create && window.location.hash === '#new-order') {
            router.visit('/purchasing/purchase-orders/new');
        }
    }, [can_create]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase orders" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Show orders" className="flex flex-wrap gap-1.5">
                        <Chip active={stage === 'all'} onClick={() => pickStage('all')}>
                            All
                        </Chip>
                        <Chip active={stage === 'approval'} onClick={() => pickStage('approval')}>
                            Awaiting approval
                        </Chip>
                        <Chip active={stage === 'goods'} onClick={() => pickStage('goods')}>
                            Awaiting goods
                        </Chip>
                        <Chip active={stage === 'bill'} onClick={() => pickStage('bill')}>
                            Received, not billed
                        </Chip>
                    </div>
                    {can_create && (
                        <Button size="sm" asChild>
                            <Link href="/purchasing/purchase-orders/new">New purchase order</Link>
                        </Button>
                    )}
                </div>
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search PO #" htmlFor="filter-search" className="w-72">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search purchase order number…"
                        />
                    </FilterField>
                    <FilterField label="Status" htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="open">Open</option>
                            <option value="partially_received">Partially received</option>
                            <option value="received">Received</option>
                            <option value="cancelled">Cancelled</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">PO #</th>
                                <th className="p-3">{t('po.ordered')}</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Warehouse</th>
                                <th className="p-3 text-right">Qty ordered</th>
                                <th className="p-3">Approval</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Billing</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.map((order) => (
                                <tr key={order.id} className="border-t">
                                    <td className="p-3">
                                        <Link href={route('purchasing.purchase-orders.show', order.id)} className="font-medium hover:underline">
                                            {order.po_number}
                                        </Link>
                                    </td>
                                    <td className="text-muted-foreground p-3 whitespace-nowrap tabular-nums">{formatDate(order.order_date)}</td>
                                    <td className="text-muted-foreground p-3">{order.supplier.name}</td>
                                    <td className="text-muted-foreground p-3">{order.warehouse.name}</td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(order.total_ordered)}</td>
                                    <td className="p-3">
                                        <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                                            {order.approval_request.status}
                                        </Badge>
                                    </td>
                                    <td className="p-3">
                                        <Badge variant={statusVariant[order.status]} className="capitalize">
                                            {order.status.replace('_', ' ')}
                                        </Badge>
                                    </td>
                                    {/* ⚠ Read from the supplier bills, replacing a hand-set
                                        "Paid" that nothing tied to money (audit #11). */}
                                    <td className="text-muted-foreground p-3 whitespace-nowrap">
                                        {order.billing.state === 'nothing_billed' && 'Nothing billed'}
                                        {order.billing.state === 'owed' && (
                                            <span className="text-foreground tabular-nums">{order.billing.outstanding} owed</span>
                                        )}
                                        {order.billing.state === 'settled' && 'Settled'}
                                    </td>
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-4 text-center">
                                        {hasActiveFilters ? 'No purchase orders match these filters.' : 'No purchase orders yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={orders} />
            </div>
        </AppLayout>
    );
}
