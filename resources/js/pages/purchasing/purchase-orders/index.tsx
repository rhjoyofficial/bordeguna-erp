import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { EntityQuickCreate } from '@/components/entity-quick-create';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Option {
    id: number;
    name: string;
}

/**
 * A product as the order form needs it: the unit its quantity and cost are
 * typed in (the PURCHASE unit when it is bought in another one), and what it
 * last arrived at, so a price is typed against the last real one.
 */
interface ProductOption extends Option {
    sku: string;
    unit: string | null;
    last_cost: { unit_cost: string; po_number: string | null; received_on: string | null } | null;
}

interface OrderRow {
    id: number;
    po_number: string;
    status: 'open' | 'partially_received' | 'received' | 'cancelled';
    // Derived from the order's supplier bills (PurchaseOrderBilling), never typed.
    billing: { state: 'nothing_billed' | 'owed' | 'settled'; billed: string; outstanding: string };
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

function CreateOrderForm({ suppliers, warehouses, products }: { suppliers: Option[]; warehouses: Option[]; products: ProductOption[] }) {
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

    // ⭐ 27 Sep 2026 (Joy): add a supplier without leaving the order.
    const [creatingSupplier, setCreatingSupplier] = useState(false);
    const [typedSupplier, setTypedSupplier] = useState('');

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
    const removeLine = (index: number) =>
        setData(
            'lines',
            data.lines.filter((_, i) => i !== index),
        );

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4">
            <h3 className="font-medium">New purchase order</h3>
            {/* Header fields as wide as what they hold (30 Aug 2026 audit): the
                two pickers fit their names, the date sizes itself, the note
                takes what is left. */}
            <FormRow className="gap-3">
                <Field label="Supplier" required error={errors.supplier_id}>
                    <SearchableSelect
                        value={data.supplier_id}
                        onChange={(v) => setData('supplier_id', v)}
                        options={suppliers.map((s) => ({ value: String(s.id), label: s.name }))}
                        placeholder="Select supplier"
                        searchPlaceholder="Search suppliers…"
                        onCreate={(typed) => {
                            setTypedSupplier(typed);
                            setCreatingSupplier(true);
                        }}
                        createLabel="New supplier"
                    />
                    <EntityQuickCreate
                        kind="supplier"
                        open={creatingSupplier}
                        onOpenChange={setCreatingSupplier}
                        initialName={typedSupplier}
                        onCreated={(row) => router.reload({ only: ['suppliers'], onSuccess: () => setData('supplier_id', String(row.id)) })}
                    />
                </Field>
                <Field label="Receiving warehouse" required error={errors.warehouse_id}>
                    <SearchableSelect
                        value={data.warehouse_id}
                        onChange={(v) => setData('warehouse_id', v)}
                        options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                        placeholder="Select warehouse"
                        searchPlaceholder="Search warehouses…"
                    />
                </Field>
                <Field label="Expected date (optional)">
                    <Input type="date" value={data.expected_date} onChange={(e) => setData('expected_date', e.target.value)} />
                </Field>
                <Field label="Note (optional)" size="grow">
                    <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </Field>
            </FormRow>

            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium">Line items</p>
                {data.lines.map((line, index) => {
                    const chosen = products.find((p) => String(p.id) === line.product_id);

                    return (
                        <div key={index} className="flex flex-wrap items-start gap-2">
                            <div className="grid min-w-64 flex-1 gap-1">
                                <SearchableSelect
                                    value={line.product_id}
                                    onChange={(v) => updateLine(index, { product_id: v })}
                                    options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                                    placeholder="Select product"
                                    searchPlaceholder="Search products…"
                                />
                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                            </div>
                            <div className="grid w-32 gap-1">
                                {/* ⚠ The unit beside the box: "100" of mustard oil is
                                kilograms or litres, and a supplier sends what the
                                order says. */}
                                <div className="flex items-center gap-1.5">
                                    <Input
                                        placeholder="Quantity"
                                        value={line.quantity}
                                        onChange={(e) => updateLine(index, { quantity: e.target.value })}
                                    />
                                    {chosen?.unit && <span className="text-muted-foreground shrink-0 text-xs">{chosen.unit}</span>}
                                </div>
                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                            </div>
                            <div className="grid w-44 gap-1">
                                <Input
                                    placeholder={chosen?.unit ? `Unit cost per ${chosen.unit}` : 'Unit cost'}
                                    value={line.unit_cost}
                                    onChange={(e) => updateLine(index, { unit_cost: e.target.value })}
                                />
                                {chosen?.last_cost && (
                                    <p className="text-muted-foreground text-xs">
                                        Last received at {chosen.last_cost.unit_cost}
                                        {chosen.last_cost.po_number &&
                                            ` (${chosen.last_cost.po_number}${chosen.last_cost.received_on ? `, ${chosen.last_cost.received_on}` : ''})`}
                                    </p>
                                )}
                                <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-10"
                                onClick={() => removeLine(index)}
                                disabled={data.lines.length === 1}
                            >
                                Remove
                            </Button>
                        </div>
                    );
                })}
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
    products: ProductOption[];
    can_create: boolean;
    filters: { search: string; status: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/purchase-orders', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase orders" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Purchase orders" />
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
                                    <td className="text-muted-foreground p-3">{order.supplier.name}</td>
                                    <td className="text-muted-foreground p-3">{order.warehouse.name}</td>
                                    <td className="p-3 text-right tabular-nums">{order.total_ordered}</td>
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
                                    <td colSpan={7} className="text-muted-foreground p-4 text-center">
                                        {hasActiveFilters ? 'No purchase orders match these filters.' : 'No purchase orders yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={orders} />

                {can_create && <CreateOrderForm suppliers={suppliers} warehouses={warehouses} products={products} />}
            </div>
        </AppLayout>
    );
}
