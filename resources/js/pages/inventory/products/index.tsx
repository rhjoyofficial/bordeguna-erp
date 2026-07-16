import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Product {
    id: number;
    sku: string;
    name: string;
    type: string;
    category: string | null;
    unit: string;
    low_stock_threshold: string | null;
    is_active: boolean;
    current_quantity: string | null;
    average_unit_cost: string | null;
    is_below_threshold: boolean;
}

interface Option {
    id: number;
    name: string;
}

interface UnitOption extends Option {
    abbreviation: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Products', href: '/inventory/products' },
];

const PRODUCT_TYPES = [
    { value: 'raw_material', label: 'Raw material' },
    { value: 'finished_good', label: 'Finished good' },
    { value: 'service', label: 'Service' },
];

export default function ProductsIndex({ products, categories, units }: { products: Product[]; categories: Option[]; units: UnitOption[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({
        sku: '',
        name: '',
        type: 'raw_material',
        category_id: '',
        unit_of_measure_id: '',
        low_stock_threshold: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.products.store'), { onSuccess: () => reset() });
    };

    const deactivate = (product: Product) => {
        router.delete(route('inventory.products.destroy', product.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Products" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div className="grid gap-2">
                        <Label htmlFor="sku">SKU</Label>
                        <Input id="sku" value={data.sku} onChange={(e) => setData('sku', e.target.value)} />
                        <InputError message={errors.sku} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="type">Type</Label>
                        <select
                            id="type"
                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                        >
                            {PRODUCT_TYPES.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="category_id">Category</Label>
                        <select
                            id="category_id"
                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            value={data.category_id}
                            onChange={(e) => setData('category_id', e.target.value)}
                        >
                            <option value="">None</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="unit_of_measure_id">Unit</Label>
                        <select
                            id="unit_of_measure_id"
                            className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                            value={data.unit_of_measure_id}
                            onChange={(e) => setData('unit_of_measure_id', e.target.value)}
                        >
                            <option value="">Select unit</option>
                            {units.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} ({u.abbreviation})
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.unit_of_measure_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="low_stock_threshold">Low-stock threshold</Label>
                        <Input
                            id="low_stock_threshold"
                            value={data.low_stock_threshold}
                            onChange={(e) => setData('low_stock_threshold', e.target.value)}
                            placeholder="optional"
                        />
                    </div>
                    <Button disabled={processing} className="col-span-2 w-fit sm:col-span-3 lg:col-span-6">
                        Add product
                    </Button>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">SKU</th>
                            <th className="py-2">Name</th>
                            <th className="py-2">Type</th>
                            <th className="py-2">Category</th>
                            <th className="py-2">Stock</th>
                            <th className="py-2">Avg. cost</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.map((product) => (
                            <tr key={product.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2 font-mono text-xs">{product.sku}</td>
                                <td className="py-2">
                                    {product.name}
                                    {!product.is_active && (
                                        <Badge variant="secondary" className="ml-2">
                                            Inactive
                                        </Badge>
                                    )}
                                </td>
                                <td className="py-2 text-muted-foreground">{product.type.replace('_', ' ')}</td>
                                <td className="py-2 text-muted-foreground">{product.category ?? '—'}</td>
                                <td className="py-2">
                                    {product.current_quantity !== null ? (
                                        <span className={product.is_below_threshold ? 'font-medium text-destructive' : ''}>
                                            {product.current_quantity} {product.unit}
                                            {product.is_below_threshold && ' (low)'}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="py-2 text-muted-foreground">{product.average_unit_cost ?? '—'}</td>
                                <td className="py-2 text-right">
                                    {product.is_active && (
                                        <Button variant="ghost" size="sm" onClick={() => deactivate(product)}>
                                            Deactivate
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {products.length === 0 && (
                            <tr>
                                <td colSpan={7} className="py-4 text-center text-muted-foreground">
                                    No products yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
