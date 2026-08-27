import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Option {
    id: number;
    name: string;
    sku?: string;
    is_default?: boolean;
    credit_limit?: string | null;
}

interface OrderRow {
    id: number;
    order_number: string;
    status: 'open' | 'partially_delivered' | 'delivered' | 'closed' | 'cancelled';
    ordered_at: string;
    expected_date: string | null;
    total_ordered: string;
    total_delivered: string;
    customer: { name: string };
    warehouse: { name: string } | null;
    approval_request: { status: 'pending' | 'approved' | 'rejected' };
}

interface Exposure {
    credit_limit: string | null;
    open_invoices: string;
    undelivered_orders: string;
    total: string;
}

interface CommittedRow {
    product_id: number;
    product_name: string;
    product_sku: string;
    committed: string;
    on_hand: string;
    short: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales orders', href: '/sales/orders' }];

const statusVariant: Record<OrderRow['status'], 'secondary' | 'default' | 'outline' | 'destructive'> = {
    open: 'secondary',
    partially_delivered: 'outline',
    delivered: 'default',
    closed: 'outline',
    cancelled: 'destructive',
};

const approvalVariant: Record<OrderRow['approval_request']['status'], 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

type LineDraft = { product_id: string; quantity: string };

/*
 * ⚠ C-SO-3 (shown, never enforced): the moment a customer is picked, the
 * SERVER answers what they already stand at - open invoices plus approved
 * undelivered orders - next to their credit limit. Nothing blocks; a human
 * decides. The number comes from the same CustomerExposure the order screen
 * shows, never computed here.
 */
function ExposureBox({ customerId }: { customerId: string }) {
    const [exposure, setExposure] = useState<Exposure | null>(null);

    useEffect(() => {
        if (!customerId) {
            setExposure(null);
            return;
        }

        let cancelled = false;

        fetch(route('sales.orders.exposure'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ customer_id: Number(customerId) }),
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!cancelled) setExposure(data);
            })
            .catch(() => {
                if (!cancelled) setExposure(null);
            });

        return () => {
            cancelled = true;
        };
    }, [customerId]);

    if (!exposure) return null;

    const overLimit = exposure.credit_limit !== null && parseFloat(exposure.total) > parseFloat(exposure.credit_limit);

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-3 text-sm">
            <p className="text-muted-foreground">
                Exposure: open invoices {exposure.open_invoices} + undelivered orders {exposure.undelivered_orders} ={' '}
                <span className="text-foreground font-medium">{exposure.total}</span>
                {exposure.credit_limit !== null && <> against a credit limit of {exposure.credit_limit}</>}
            </p>
            {overLimit && <p className="text-destructive mt-1 font-medium">Over the credit limit. Shown for your judgement - nothing is blocked.</p>}
        </div>
    );
}

function CreateOrderForm({ customers, warehouses, products }: { customers: Option[]; warehouses: Option[]; products: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm<{
        customer_id: string;
        warehouse_id: string;
        expected_date: string;
        note: string;
        lines: LineDraft[];
    }>({
        customer_id: '',
        warehouse_id: '',
        expected_date: '',
        note: '',
        lines: [{ product_id: '', quantity: '' }],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.store'), { onSuccess: () => reset() });
    };

    const updateLine = (index: number, patch: Partial<LineDraft>) => {
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    };

    const addLine = () => setData('lines', [...data.lines, { product_id: '', quantity: '' }]);
    const removeLine = (index: number) =>
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4">
            <h3 className="font-medium">New sales order</h3>
            <p className="text-muted-foreground text-sm">
                Prices are agreed at order time: the server resolves each line's price for this customer on this date and writes it on the order,
                where it stays - a later invoice bills the order price, not the rule of the day.
            </p>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="grid gap-2">
                    <Label>Customer</Label>
                    <SearchableSelect
                        value={data.customer_id}
                        onChange={(v) => setData('customer_id', v)}
                        options={customers.map((c) => ({ value: String(c.id), label: c.name }))}
                        placeholder="Select customer"
                        searchPlaceholder="Search customers…"
                    />
                    <InputError message={errors.customer_id} />
                </div>
                <div className="grid gap-2">
                    <Label>Preferred warehouse (optional)</Label>
                    <SearchableSelect
                        value={data.warehouse_id}
                        onChange={(v) => setData('warehouse_id', v)}
                        options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                        placeholder="Decided at delivery"
                        clearable
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

            <ExposureBox customerId={data.customer_id} />

            <div className="flex flex-col gap-2">
                <Label>Line items</Label>
                {data.lines.map((line, index) => (
                    <div key={index} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[3fr_1fr_auto]">
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
                            <Input placeholder="Quantity" value={line.quantity} onChange={(e) => updateLine(index, { quantity: e.target.value })} />
                            <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
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

            <InputError message={errors.lines} />

            <Button disabled={processing} className="w-fit">
                Record sales order
            </Button>
        </form>
    );
}

export default function SalesOrdersIndex({
    orders,
    committed,
    customers,
    warehouses,
    products,
    can_create,
    filters: initialFilters,
}: {
    orders: Paginated<OrderRow>;
    committed: CommittedRow[];
    customers: Option[];
    warehouses: Option[];
    products: Option[];
    can_create: boolean;
    filters: { search: string; status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/orders', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales orders" />
            <div className="flex flex-col gap-6 p-4">
                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Order number or customer…"
                        />
                    </FilterField>
                    <FilterField label="Status" htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">All statuses</option>
                            <option value="open">Open</option>
                            <option value="partially_delivered">Partially delivered</option>
                            <option value="delivered">Delivered</option>
                            <option value="closed">Closed short</option>
                            <option value="cancelled">Cancelled</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Order #</th>
                            <th className="py-2">Customer</th>
                            <th className="py-2">Ordered</th>
                            <th className="py-2">Delivered</th>
                            <th className="py-2">Expected</th>
                            <th className="py-2">Approval</th>
                            <th className="py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orders.data.map((order) => (
                            <tr key={order.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <Link href={route('sales.orders.show', order.id)} className="underline">
                                        {order.order_number}
                                    </Link>
                                </td>
                                <td className="text-muted-foreground py-2">{order.customer.name}</td>
                                <td className="py-2">{order.total_ordered}</td>
                                <td className="py-2">{order.total_delivered}</td>
                                <td className="text-muted-foreground py-2">{order.expected_date ?? '-'}</td>
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
                            </tr>
                        ))}
                        {orders.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No sales orders match these filters.' : 'No sales orders yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={orders} />

                {committed.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">Committed vs on hand</h3>
                        {/* ⚠ C-SO-2: no reservation. This is the honest substitute -
                            the promise per product against the shelf, for a human
                            deciding whether to take the next order. Nothing blocks. */}
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Product</th>
                                        <th className="p-3 text-right">Committed on open orders</th>
                                        <th className="p-3 text-right">On hand</th>
                                        <th className="p-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {committed.map((row) => (
                                        <tr key={row.product_id} className="border-t">
                                            <td className="p-3">
                                                {row.product_name} <span className="text-muted-foreground">({row.product_sku})</span>
                                            </td>
                                            <td className="p-3 text-right tabular-nums">{row.committed}</td>
                                            <td className="p-3 text-right tabular-nums">{row.on_hand}</td>
                                            <td className="p-3">{row.short && <Badge variant="destructive">Short</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {can_create && <CreateOrderForm customers={customers} warehouses={warehouses} products={products} />}
            </div>
        </AppLayout>
    );
}
