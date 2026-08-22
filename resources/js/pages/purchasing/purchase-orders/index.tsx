import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Option {
    id: number;
    name: string;
    sku?: string;
}

interface OrderRow {
    id: number;
    po_number: string;
    status: 'open' | 'partially_received' | 'received' | 'cancelled';
    payment_status: 'unpaid' | 'partially_paid' | 'paid';
    total_ordered: string;
    supplier: { name: string };
    warehouse: { name: string };
    approval_request: { status: 'pending' | 'approved' | 'rejected' };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Purchase orders', href: '/purchasing/purchase-orders' }];

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

type LineDraft = { product_id: string; quantity: string; unit_cost: string };

function CreateOrderForm({ suppliers, warehouses, products }: { suppliers: Option[]; warehouses: Option[]; products: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm<{
        supplier_id: string;
        warehouse_id: string;
        expected_date: string;
        note: string;
        lines: LineDraft[];
    }>({
        supplier_id: '',
        warehouse_id: '',
        expected_date: '',
        note: '',
        lines: [{ product_id: '', quantity: '', unit_cost: '' }],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.purchase-orders.store'), {
            onSuccess: () => reset(),
        });
    };

    const updateLine = (index: number, patch: Partial<LineDraft>) => {
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    const addLine = () => setData('lines', [...data.lines, { product_id: '', quantity: '', unit_cost: '' }]);
    const removeLine = (index: number) => setData('lines', data.lines.filter((_, i) => i !== index));

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 className="font-medium">New purchase order</h3>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label>Supplier</Label>
                    <SearchableSelect
                        value={data.supplier_id}
                        onChange={(v) => setData('supplier_id', v)}
                        options={suppliers.map((s) => ({ value: String(s.id), label: s.name }))}
                        placeholder="Select supplier"
                        searchPlaceholder="Search suppliers…"
                    />
                    <InputError message={errors.supplier_id} />
                </div>
                <div className="grid gap-2">
                    <Label>Receiving warehouse</Label>
                    <SearchableSelect
                        value={data.warehouse_id}
                        onChange={(v) => setData('warehouse_id', v)}
                        options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                        placeholder="Select warehouse"
                        searchPlaceholder="Search warehouses…"
                    />
                    <InputError message={errors.warehouse_id} />
                </div>
                <div className="grid gap-2">
                    <Label>Expected date (optional)</Label>
                    <Input type="date" value={data.expected_date} onChange={(e) => setData('expected_date', e.target.value)} />
                </div>
                <div className="grid gap-2">
                    <Label>Note (optional)</Label>
                    <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>
            </div>

            <div className="flex flex-col gap-2">
                <Label>Line items</Label>
                {data.lines.map((line, index) => (
                    <div key={index} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]">
                        <div className="grid gap-1">
                            <SearchableSelect
                                value={line.product_id}
                                onChange={(v) => updateLine(index, { product_id: v })}
                                options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                                placeholder="Select product"
                                searchPlaceholder="Search products…"
                            />
                            <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                placeholder="Quantity"
                                value={line.quantity}
                                onChange={(e) => updateLine(index, { quantity: e.target.value })}
                            />
                            <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                placeholder="Unit cost"
                                value={line.unit_cost}
                                onChange={(e) => updateLine(index, { unit_cost: e.target.value })}
                            />
                            <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                        </div>
                        <Button type="button" variant="ghost" size="sm" onClick={() => removeLine(index)} disabled={data.lines.length === 1}>
                            Remove
                        </Button>
                    </div>
                ))}
                <Button type="button" variant="outline" size="sm" className="w-fit" onClick={addLine}>
                    + Add line
                </Button>
            </div>

            {/* C-PUR-E1. Where a refused order says why. */}
            <InputError message={errors.lines} />

            <Button disabled={processing} className="w-fit">
                Create purchase order
            </Button>
        </form>
    );
}

export default function PurchaseOrdersIndex({
    orders,
    suppliers,
    warehouses,
    products,
    can_create,
    filters: initialFilters,
}: {
    orders: Paginated<OrderRow>;
    suppliers: Option[];
    warehouses: Option[];
    products: Option[];
    can_create: boolean;
    filters: { search: string; status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/purchase-orders', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase orders" />
            <div className="flex flex-col gap-6 p-4">
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search PO #" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search purchase order number…"
                        />
                    </FilterField>
                    <FilterField label="Status" htmlFor="filter-status">
                        <select id="filter-status" className={selectClass} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="open">Open</option>
                            <option value="partially_received">Partially received</option>
                            <option value="received">Received</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">PO #</th>
                            <th className="py-2">Supplier</th>
                            <th className="py-2">Warehouse</th>
                            <th className="py-2">Qty ordered</th>
                            <th className="py-2">Approval</th>
                            <th className="py-2">Status</th>
                            <th className="py-2">Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.map((order) => (
                            <tr key={order.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <Link href={route('purchasing.purchase-orders.show', order.id)} className="underline">
                                        {order.po_number}
                                    </Link>
                                </td>
                                <td className="py-2 text-muted-foreground">{order.supplier.name}</td>
                                <td className="py-2 text-muted-foreground">{order.warehouse.name}</td>
                                <td className="py-2">{order.total_ordered}</td>
                                <td className="py-2">
                                    <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                                        {order.approval_request.status}
                                    </Badge>
                                </td>
                                <td className="py-2">
                                    <Badge variant={statusVariant[order.status]} className="capitalize">
                                        {order.status.replace('_', ' ')}
                                    </Badge>
                                </td>
                                <td className="py-2 text-muted-foreground capitalize">{order.payment_status.replace('_', ' ')}</td>
                            </tr>
                        ))}
                        {orders.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="py-4 text-center text-muted-foreground">
                                    {hasActiveFilters ? 'No purchase orders match these filters.' : 'No purchase orders yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={orders} />

                {can_create && <CreateOrderForm suppliers={suppliers} warehouses={warehouses} products={products} />}
            </div>
        </AppLayout>
    );
}
