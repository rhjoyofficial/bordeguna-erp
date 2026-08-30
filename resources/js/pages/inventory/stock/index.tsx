import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface StockRow {
    product_id: number;
    warehouse_id: number;
    quantity: string;
    product: { id: number; name: string; sku: string; low_stock_threshold: string | null };
    warehouse: { id: number; name: string };
}

interface Option {
    id: number;
    name: string;
    sku?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock', href: '/inventory/stock' },
];

function ProductWarehouseSelects({
    products,
    warehouses,
    productId,
    warehouseId,
    onProductChange,
    onWarehouseChange,
    warehouseLabel = 'Warehouse',
}: {
    products: Option[];
    warehouses: Option[];
    productId: string;
    warehouseId: string;
    onProductChange: (v: string) => void;
    onWarehouseChange: (v: string) => void;
    warehouseLabel?: string;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label>Product</Label>
                <SearchableSelect
                    value={productId}
                    onChange={onProductChange}
                    options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                    placeholder="Select product"
                    searchPlaceholder="Search products…"
                />
            </div>
            <div className="grid gap-2">
                <Label>{warehouseLabel}</Label>
                <SearchableSelect
                    value={warehouseId}
                    onChange={onWarehouseChange}
                    options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                    placeholder="Select warehouse"
                    searchPlaceholder="Search warehouses…"
                />
            </div>
        </>
    );
}

function ReasonSelect({ reasons, value, onChange, error }: { reasons: Option[]; value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <div className="grid gap-2">
            <Label>Reason</Label>
            <SearchSelect value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">Select reason</option>
                {reasons.map((r) => (
                    <option key={r.id} value={r.id}>
                        {r.name}
                    </option>
                ))}
            </SearchSelect>
            <InputError message={error} />
        </div>
    );
}

/*
 * ⛔ ReceiptForm and UsageForm were REMOVED on 2026-08-04 (C-FLOW-5).
 *
 * They posted to `stock.receipts.store` and `stock.usages.store`, which moved
 * stock and wrote NOTHING to the ledger - value entered and left the warehouse
 * without ever reaching the books, and the outgoing direction lost value with
 * nothing on the profit and loss.
 *
 * Stock now arrives through a goods receipt or the opening stock document, and
 * leaves through a sale or an inventory adjustment. Each of those knows its own
 * accounting consequence. See INVENTORY-ENTRY-POINTS-DESIGN.md.
 */
function DirectAdjustmentForm({ products, warehouses, reasons }: { products: Option[]; warehouses: Option[]; reasons: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        warehouse_id: '',
        direction: 'decrease',
        quantity: '',
        unit_cost: '',
        reason_id: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock.adjustments.store'), { onSuccess: () => reset() });
    };

    return (
        <form
            onSubmit={submit}
            className="border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 gap-3 rounded-xl border p-4 sm:grid-cols-3"
        >
            <h3 className="col-span-full font-medium">Direct adjustment</h3>
            <ProductWarehouseSelects
                products={products}
                warehouses={warehouses}
                productId={data.product_id}
                warehouseId={data.warehouse_id}
                onProductChange={(v) => setData('product_id', v)}
                onWarehouseChange={(v) => setData('warehouse_id', v)}
            />
            <div className="grid gap-2">
                <Label>Direction</Label>
                <SearchSelect value={data.direction} onChange={(e) => setData('direction', e.target.value)}>
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </SearchSelect>
            </div>
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            {data.direction === 'increase' && (
                <div className="grid gap-2">
                    <Label>Unit cost</Label>
                    <Input value={data.unit_cost} onChange={(e) => setData('unit_cost', e.target.value)} />
                    <p className="text-muted-foreground text-xs">Amount paid per unit, including any taxes charged.</p>
                    <InputError message={errors.unit_cost} />
                </div>
            )}
            <ReasonSelect reasons={reasons} value={data.reason_id} onChange={(v) => setData('reason_id', v)} error={errors.reason_id} />
            <div className="col-span-full grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="any extra detail" />
                <InputError message={errors.note} />
            </div>
            {/* C-INV-E1. Where a refused stock movement says why. */}
            <InputError message={(errors as Record<string, string>).stock} />

            <Button disabled={processing} className="col-span-full w-fit">
                Apply adjustment
            </Button>
        </form>
    );
}

function DirectTransferForm({ products, warehouses, reasons }: { products: Option[]; warehouses: Option[]; reasons: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        warehouse_id: '',
        target_warehouse_id: '',
        quantity: '',
        reason_id: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock.transfers.store'), { onSuccess: () => reset() });
    };

    return (
        <form
            onSubmit={submit}
            className="border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 gap-3 rounded-xl border p-4 sm:grid-cols-3"
        >
            <h3 className="col-span-full font-medium">Dispatch transfer</h3>
            <p className="text-muted-foreground col-span-full text-sm">
                Stock leaves the source warehouse now but only lands at the destination once someone confirms receipt on the{' '}
                <Link href={route('inventory.stock.transfers.index')} className="underline">
                    in-transit transfers
                </Link>{' '}
                page.
            </p>
            <ProductWarehouseSelects
                products={products}
                warehouses={warehouses}
                productId={data.product_id}
                warehouseId={data.warehouse_id}
                onProductChange={(v) => setData('product_id', v)}
                onWarehouseChange={(v) => setData('warehouse_id', v)}
                warehouseLabel="From warehouse"
            />
            <div className="grid gap-2">
                <Label>To warehouse</Label>
                <SearchSelect value={data.target_warehouse_id} onChange={(e) => setData('target_warehouse_id', e.target.value)}>
                    <option value="">Select warehouse</option>
                    {warehouses.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.name}
                        </option>
                    ))}
                </SearchSelect>
                <InputError message={errors.target_warehouse_id} />
            </div>
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            <ReasonSelect reasons={reasons} value={data.reason_id} onChange={(v) => setData('reason_id', v)} error={errors.reason_id} />
            <div className="col-span-full grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="any extra detail" />
            </div>
            {/* C-INV-E1. Where a refused stock movement says why. */}
            <InputError message={(errors as Record<string, string>).stock} />

            <Button disabled={processing} className="col-span-full w-fit">
                Dispatch transfer
            </Button>
        </form>
    );
}

function RequestAdjustmentForm({
    products,
    warehouses,
    adjustmentReasons,
    transferReasons,
}: {
    products: Option[];
    warehouses: Option[];
    adjustmentReasons: Option[];
    transferReasons: Option[];
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        type: 'adjustment',
        product_id: '',
        warehouse_id: '',
        target_warehouse_id: '',
        direction: 'decrease',
        quantity: '',
        reason_id: '',
        unit_cost: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock-requests.store'), { onSuccess: () => reset() });
    };

    const reasons = data.type === 'transfer' ? transferReasons : adjustmentReasons;

    return (
        <form
            onSubmit={submit}
            className="border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 gap-3 rounded-xl border p-4 sm:grid-cols-3"
        >
            <h3 className="col-span-full font-medium">Request adjustment or transfer</h3>
            <p className="text-muted-foreground col-span-full text-sm">Needs Manager approval before stock actually changes.</p>
            <div className="grid gap-2">
                <Label>Request type</Label>
                <SearchSelect value={data.type} onChange={(e) => setData({ ...data, type: e.target.value, reason_id: '' })}>
                    <option value="adjustment">Adjustment</option>
                    <option value="transfer">Transfer</option>
                </SearchSelect>
            </div>
            <ProductWarehouseSelects
                products={products}
                warehouses={warehouses}
                productId={data.product_id}
                warehouseId={data.warehouse_id}
                onProductChange={(v) => setData('product_id', v)}
                onWarehouseChange={(v) => setData('warehouse_id', v)}
                warehouseLabel={data.type === 'transfer' ? 'From warehouse' : 'Warehouse'}
            />
            {data.type === 'transfer' && (
                <div className="grid gap-2">
                    <Label>To warehouse</Label>
                    <SearchSelect value={data.target_warehouse_id} onChange={(e) => setData('target_warehouse_id', e.target.value)}>
                        <option value="">Select warehouse</option>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={errors.target_warehouse_id} />
                </div>
            )}
            {data.type === 'adjustment' && (
                <div className="grid gap-2">
                    <Label>Direction</Label>
                    <SearchSelect value={data.direction} onChange={(e) => setData('direction', e.target.value)}>
                        <option value="increase">Increase</option>
                        <option value="decrease">Decrease</option>
                    </SearchSelect>
                </div>
            )}
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            {data.type === 'adjustment' && data.direction === 'increase' && (
                <div className="grid gap-2">
                    <Label>Unit cost</Label>
                    <Input value={data.unit_cost} onChange={(e) => setData('unit_cost', e.target.value)} />
                    <p className="text-muted-foreground text-xs">Amount paid per unit, including any taxes charged.</p>
                    <InputError message={errors.unit_cost} />
                </div>
            )}
            <ReasonSelect reasons={reasons} value={data.reason_id} onChange={(v) => setData('reason_id', v)} error={errors.reason_id} />
            <div className="col-span-full grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="any extra detail" />
                <InputError message={errors.note} />
            </div>
            {/* C-INV-E1. Where a refused stock movement says why. */}
            <InputError message={(errors as Record<string, string>).stock} />

            <Button disabled={processing} className="col-span-full w-fit">
                Submit request
            </Button>
        </form>
    );
}

export default function StockIndex({
    stock,
    products,
    warehouses,
    adjustment_reasons,
    transfer_reasons,
    can_adjust_directly,
    in_transit_count,
    filters: initialFilters,
}: {
    stock: Paginated<StockRow>;
    products: Option[];
    warehouses: Option[];
    adjustment_reasons: Option[];
    transfer_reasons: Option[];
    can_adjust_directly: boolean;
    in_transit_count: number;
    filters: { search: string; warehouse_id: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-medium">Current stock</h2>
                    <Link href={route('inventory.stock.transfers.index')} className="text-muted-foreground text-sm underline">
                        In-transit transfers{in_transit_count > 0 ? ` (${in_transit_count})` : ''}
                    </Link>
                </div>

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search product SKU or name…"
                        />
                    </FilterField>
                    <FilterField label="Warehouse" htmlFor="filter-warehouse">
                        <SearchSelect id="filter-warehouse" value={filters.warehouse_id} onChange={(e) => setFilter('warehouse_id', e.target.value)}>
                            <option value="">All warehouses</option>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Product</th>
                            <th className="py-2">Warehouse</th>
                            <th className="py-2">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        {stock.data.map((row) => (
                            <tr
                                key={`${row.product_id}-${row.warehouse_id}`}
                                className="border-sidebar-border/70 dark:border-sidebar-border border-b"
                            >
                                <td className="py-2">
                                    {row.product.name} <span className="text-muted-foreground">({row.product.sku})</span>
                                </td>
                                <td className="text-muted-foreground py-2">{row.warehouse.name}</td>
                                <td className="py-2">{row.quantity}</td>
                            </tr>
                        ))}
                        {stock.data.length === 0 && (
                            <tr>
                                <td colSpan={3} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No stock matches these filters.' : 'No stock recorded yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={stock} />

                <div className="grid gap-4 lg:grid-cols-2">
                    {can_adjust_directly ? (
                        <>
                            <DirectAdjustmentForm products={products} warehouses={warehouses} reasons={adjustment_reasons} />
                            <DirectTransferForm products={products} warehouses={warehouses} reasons={transfer_reasons} />
                        </>
                    ) : (
                        <RequestAdjustmentForm
                            products={products}
                            warehouses={warehouses}
                            adjustmentReasons={adjustment_reasons}
                            transferReasons={transfer_reasons}
                        />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
