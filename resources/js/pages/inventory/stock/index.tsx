import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
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
                <select
                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    value={productId}
                    onChange={(e) => onProductChange(e.target.value)}
                >
                    <option value="">Select product</option>
                    {products.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.sku ? `${p.sku} — ${p.name}` : p.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="grid gap-2">
                <Label>{warehouseLabel}</Label>
                <select
                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    value={warehouseId}
                    onChange={(e) => onWarehouseChange(e.target.value)}
                >
                    <option value="">Select warehouse</option>
                    {warehouses.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.name}
                        </option>
                    ))}
                </select>
            </div>
        </>
    );
}

function ReasonSelect({ reasons, value, onChange, error }: { reasons: Option[]; value: string; onChange: (v: string) => void; error?: string }) {
    return (
        <div className="grid gap-2">
            <Label>Reason</Label>
            <select className="h-10 rounded-md border border-input bg-background px-3 text-sm" value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">Select reason</option>
                {reasons.map((r) => (
                    <option key={r.id} value={r.id}>
                        {r.name}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

function ReceiptForm({ products, warehouses }: { products: Option[]; warehouses: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        warehouse_id: '',
        quantity: '',
        unit_cost: '',
        batch_number: '',
        expiry_date: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock.receipts.store'), { onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-3 dark:border-sidebar-border">
            <h3 className="col-span-full font-medium">Record stock in (receipt)</h3>
            <ProductWarehouseSelects
                products={products}
                warehouses={warehouses}
                productId={data.product_id}
                warehouseId={data.warehouse_id}
                onProductChange={(v) => setData('product_id', v)}
                onWarehouseChange={(v) => setData('warehouse_id', v)}
            />
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            <div className="grid gap-2">
                <Label>Unit cost</Label>
                <Input value={data.unit_cost} onChange={(e) => setData('unit_cost', e.target.value)} />
                <InputError message={errors.unit_cost} />
            </div>
            <div className="grid gap-2">
                <Label>Batch # (optional)</Label>
                <Input value={data.batch_number} onChange={(e) => setData('batch_number', e.target.value)} />
            </div>
            <div className="grid gap-2">
                <Label>Expiry date (optional)</Label>
                <Input type="date" value={data.expiry_date} onChange={(e) => setData('expiry_date', e.target.value)} />
            </div>
            <Button disabled={processing} className="col-span-full w-fit">
                Record receipt
            </Button>
        </form>
    );
}

function UsageForm({ products, warehouses }: { products: Option[]; warehouses: Option[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ product_id: '', warehouse_id: '', quantity: '', note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock.usages.store'), { onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-3 dark:border-sidebar-border">
            <h3 className="col-span-full font-medium">Record stock out (usage)</h3>
            <ProductWarehouseSelects
                products={products}
                warehouses={warehouses}
                productId={data.product_id}
                warehouseId={data.warehouse_id}
                onProductChange={(v) => setData('product_id', v)}
                onWarehouseChange={(v) => setData('warehouse_id', v)}
            />
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            <Button disabled={processing} className="col-span-full w-fit">
                Record usage
            </Button>
        </form>
    );
}

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
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-3 dark:border-sidebar-border">
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
                <select
                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    value={data.direction}
                    onChange={(e) => setData('direction', e.target.value)}
                >
                    <option value="increase">Increase</option>
                    <option value="decrease">Decrease</option>
                </select>
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
                    <InputError message={errors.unit_cost} />
                </div>
            )}
            <ReasonSelect reasons={reasons} value={data.reason_id} onChange={(v) => setData('reason_id', v)} error={errors.reason_id} />
            <div className="col-span-full grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="any extra detail" />
                <InputError message={errors.note} />
            </div>
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
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-3 dark:border-sidebar-border">
            <h3 className="col-span-full font-medium">Dispatch transfer</h3>
            <p className="col-span-full text-sm text-muted-foreground">
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
                <select
                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    value={data.target_warehouse_id}
                    onChange={(e) => setData('target_warehouse_id', e.target.value)}
                >
                    <option value="">Select warehouse</option>
                    {warehouses.map((w) => (
                        <option key={w.id} value={w.id}>
                            {w.name}
                        </option>
                    ))}
                </select>
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
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock-requests.store'), { onSuccess: () => reset() });
    };

    const reasons = data.type === 'transfer' ? transferReasons : adjustmentReasons;

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 rounded-xl border border-sidebar-border/70 p-4 sm:grid-cols-3 dark:border-sidebar-border">
            <h3 className="col-span-full font-medium">Request adjustment or transfer</h3>
            <p className="col-span-full text-sm text-muted-foreground">Needs Manager approval before stock actually changes.</p>
            <div className="grid gap-2">
                <Label>Request type</Label>
                <select
                    className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                    value={data.type}
                    onChange={(e) => setData({ ...data, type: e.target.value, reason_id: '' })}
                >
                    <option value="adjustment">Adjustment</option>
                    <option value="transfer">Transfer</option>
                </select>
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
                    <select
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        value={data.target_warehouse_id}
                        onChange={(e) => setData('target_warehouse_id', e.target.value)}
                    >
                        <option value="">Select warehouse</option>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.target_warehouse_id} />
                </div>
            )}
            {data.type === 'adjustment' && (
                <div className="grid gap-2">
                    <Label>Direction</Label>
                    <select
                        className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                        value={data.direction}
                        onChange={(e) => setData('direction', e.target.value)}
                    >
                        <option value="increase">Increase</option>
                        <option value="decrease">Decrease</option>
                    </select>
                </div>
            )}
            <div className="grid gap-2">
                <Label>Quantity</Label>
                <Input value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                <InputError message={errors.quantity} />
            </div>
            <ReasonSelect reasons={reasons} value={data.reason_id} onChange={(v) => setData('reason_id', v)} error={errors.reason_id} />
            <div className="col-span-full grid gap-2">
                <Label>Note (optional)</Label>
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="any extra detail" />
                <InputError message={errors.note} />
            </div>
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
}: {
    stock: StockRow[];
    products: Option[];
    warehouses: Option[];
    adjustment_reasons: Option[];
    transfer_reasons: Option[];
    can_adjust_directly: boolean;
    in_transit_count: number;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <h2 className="text-lg font-medium">Current stock</h2>
                    <Link href={route('inventory.stock.transfers.index')} className="text-sm underline text-muted-foreground">
                        In-transit transfers{in_transit_count > 0 ? ` (${in_transit_count})` : ''}
                    </Link>
                </div>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Product</th>
                            <th className="py-2">Warehouse</th>
                            <th className="py-2">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        {stock.map((row) => (
                            <tr key={`${row.product_id}-${row.warehouse_id}`} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    {row.product.name} <span className="text-muted-foreground">({row.product.sku})</span>
                                </td>
                                <td className="py-2 text-muted-foreground">{row.warehouse.name}</td>
                                <td className="py-2">{row.quantity}</td>
                            </tr>
                        ))}
                        {stock.length === 0 && (
                            <tr>
                                <td colSpan={3} className="py-4 text-center text-muted-foreground">
                                    No stock recorded yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ReceiptForm products={products} warehouses={warehouses} />
                    <UsageForm products={products} warehouses={warehouses} />
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
