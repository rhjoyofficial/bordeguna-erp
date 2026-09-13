import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Product {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    type: string;
    category: string | null;
    category_id: number | null;
    brand: string | null;
    brand_id: number | null;
    unit_of_measure_id: number;
    tax_class_id: number | null;
    description: string | null;
    unit: string;
    purchase_unit: string | null;
    low_stock_threshold: string | null;
    content_quantity: string | null;
    content_unit_id: number | null;
    is_active: boolean;
    availability_override: string | null;
    lead_time_days: number | null;
    current_quantity: string | null;
    average_unit_cost: string | null;
    is_below_threshold: boolean;
}

// ⚠⚠ Every field the catalogue import can set, the screen can set. A product
// created by hand must not be structurally different from an imported one.
const EMPTY = {
    sku: '',
    barcode: '',
    name: '',
    type: 'raw_material',
    category_id: '',
    brand_id: '',
    unit_of_measure_id: '',
    tax_class_id: '',
    description: '',
    low_stock_threshold: '',
    content_quantity: '',
    content_unit_id: '',
    availability_override: '',
    lead_time_days: '',
};

interface Option {
    id: number;
    name: string;
}

interface BrandOption extends Option {
    code: string;
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

export default function ProductsIndex({
    products,
    categories,
    brands,
    units,
    taxClasses,
    filters: initialFilters,
}: {
    products: Paginated<Product>;
    categories: Option[];
    brands: BrandOption[];
    units: UnitOption[];
    taxClasses: Option[];
    filters: { search: string; type: string; category_id: string; brand_id: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/products', initialFilters);
    const [editing, setEditing] = useState<Product | null>(null);
    const [proposing, setProposing] = useState(false);
    const [skuHint, setSkuHint] = useState<string | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    const startEdit = (product: Product) => {
        clearErrors();
        setEditing(product);
        setData({
            sku: product.sku,
            barcode: product.barcode ?? '',
            name: product.name,
            type: product.type,
            category_id: product.category_id ? String(product.category_id) : '',
            brand_id: product.brand_id ? String(product.brand_id) : '',
            unit_of_measure_id: String(product.unit_of_measure_id),
            tax_class_id: product.tax_class_id === null ? '' : String(product.tax_class_id),
            description: product.description ?? '',
            low_stock_threshold: product.low_stock_threshold ?? '',
            content_quantity: product.content_quantity ?? '',
            content_unit_id: product.content_unit_id === null ? '' : String(product.content_unit_id),
            availability_override: product.availability_override ?? '',
            lead_time_days: product.lead_time_days === null ? '' : String(product.lead_time_days),
        });
    };

    const cancel = () => {
        setEditing(null);
        clearErrors();
        reset();
        setSkuHint(null);
    };

    /**
     * C-SKU-2. Ask the server what this product's SKU would be, and drop the
     * answer into the field.
     *
     * ⚠⚠ A PROPOSAL, NEVER AN ASSIGNMENT. Nothing here fires on its own, the
     * field stays editable afterwards, and on an existing product the hint says
     * out loud what changing a SKU costs - it is the identity every importer
     * matches on.
     */
    const proposeSku = async () => {
        setProposing(true);
        setSkuHint(null);

        try {
            const query = new URLSearchParams({ name: data.name, type: data.type });

            if (data.brand_id) query.set('brand_id', data.brand_id);
            if (data.category_id) query.set('category_id', data.category_id);
            if (editing) query.set('product_id', String(editing.id));

            const response = await fetch(`${route('inventory.products.sku-proposal')}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                setSkuHint('Could not build a SKU just now. Type one in.');
                return;
            }

            const result: { sku: string | null; reason: string | null; note: string | null } = await response.json();

            if (result.sku === null) {
                setSkuHint(result.reason);
                return;
            }

            setData('sku', result.sku);

            const notes = [result.note];

            if (editing) {
                notes.push('This product already exists. Its SKU is what imports and price lists match on, so change it only if you mean to.');
            }

            setSkuHint(notes.filter(Boolean).join(' ') || null);
        } catch {
            setSkuHint('Could not build a SKU just now. Type one in.');
        } finally {
            setProposing(false);
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('inventory.products.update', editing.id), { onSuccess: cancel });
            return;
        }

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
                        <div className="flex items-baseline justify-between gap-2">
                            <Label htmlFor="sku" required>
                                SKU
                            </Label>
                            <button
                                type="button"
                                onClick={proposeSku}
                                disabled={proposing}
                                className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2 disabled:opacity-50"
                            >
                                {proposing ? 'working' : 'build it for me'}
                            </button>
                        </div>
                        <Input id="sku" value={data.sku} onChange={(e) => setData('sku', e.target.value)} className="font-mono" />
                        <InputError message={errors.sku} />
                        {skuHint && <p className="text-muted-foreground text-xs">{skuHint}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="barcode">Barcode</Label>
                        <Input id="barcode" value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} placeholder="optional" />
                        <InputError message={errors.barcode} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name" required>
                            Name
                        </Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="type" required>
                            Type
                        </Label>
                        <SearchSelect id="type" value={data.type} onChange={(e) => setData('type', e.target.value)}>
                            {PRODUCT_TYPES.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="category_id">Category</Label>
                        <SearchSelect id="category_id" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)}>
                            <option value="">None</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="brand_id">Brand</Label>
                        <SearchSelect id="brand_id" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                            {/* ⚠ "None" is a legitimate answer, not a blank. Raw
                                materials have no brand (D-5). */}
                            <option value="">None</option>
                            {brands.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.brand_id} />
                        {data.type === 'finished_good' && data.brand_id === '' && (
                            <p className="text-xs text-amber-600 dark:text-amber-500">Finished goods usually carry a brand.</p>
                        )}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="unit_of_measure_id" required>
                            Unit
                        </Label>
                        <SearchSelect
                            id="unit_of_measure_id"
                            value={data.unit_of_measure_id}
                            onChange={(e) => setData('unit_of_measure_id', e.target.value)}
                        >
                            <option value="">Select unit</option>
                            {units.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} ({u.abbreviation})
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.unit_of_measure_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="tax_class_id">Tax class</Label>
                        <SearchSelect id="tax_class_id" value={data.tax_class_id} onChange={(e) => setData('tax_class_id', e.target.value)}>
                            {/* ⚠⚠ "Not classified" is NOT zero-rated. It means nobody
                                has decided yet, and it falls back to the business
                                default when VAT is switched on. The two are wrong in
                                opposite directions and only one of them is a refund. */}
                            <option value="">Not classified</option>
                            {taxClasses.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <p className="text-muted-foreground text-xs">VAT is off. This is recorded for later and changes nothing now.</p>
                        <InputError message={errors.tax_class_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="availability_override">Sell when out of stock</Label>
                        <SearchSelect
                            id="availability_override"
                            value={data.availability_override}
                            onChange={(e) => setData('availability_override', e.target.value)}
                        >
                            {/* ⚠ The override only ever NARROWS. A website keeps
                                selling at zero because the goods are made to
                                order; this is for the product that genuinely
                                cannot be - imported goods, seasonal fruit. */}
                            <option value="">As the channel decides (websites keep selling, the counter does not)</option>
                            <option value="strict">Never - refuse the sale at zero everywhere</option>
                        </SearchSelect>
                        <InputError message={errors.availability_override} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="lead_time_days">Made-to-order lead time (days)</Label>
                        <Input
                            id="lead_time_days"
                            type="number"
                            min={1}
                            max={365}
                            value={data.lead_time_days}
                            onChange={(e) => setData('lead_time_days', e.target.value)}
                            placeholder="not used by the shops"
                        />
                        {/* ⚠ D24 (Joy, confirmed 13 September): each shop makes ONE delivery
                            promise, set on the shop's own screen. This field is kept for the
                            day a product genuinely needs an exception, and nothing reads it. */}
                        <p className="text-muted-foreground text-xs">
                            Not shown on the shops: each shop promises one delivery window, set on the shop screen.
                        </p>
                        <InputError message={errors.lead_time_days} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="low_stock_threshold">Low-stock threshold</Label>
                        <Input
                            id="low_stock_threshold"
                            value={data.low_stock_threshold}
                            onChange={(e) => setData('low_stock_threshold', e.target.value)}
                            placeholder="optional"
                        />
                        <InputError message={errors.low_stock_threshold} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="content_quantity">Net content per unit</Label>
                        <Input
                            id="content_quantity"
                            value={data.content_quantity}
                            onChange={(e) => setData('content_quantity', e.target.value)}
                            placeholder="e.g. 5 for a 5 L pack"
                        />
                        <InputError message={errors.content_quantity} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="content_unit_id">Content unit</Label>
                        <SearchSelect id="content_unit_id" value={data.content_unit_id} onChange={(e) => setData('content_unit_id', e.target.value)}>
                            <option value="">Not declared</option>
                            {units.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name} ({u.abbreviation})
                                </option>
                            ))}
                        </SearchSelect>
                        <p className="text-muted-foreground text-xs">Only powers the production yield check. Nothing converts with it.</p>
                        <InputError message={errors.content_unit_id} />
                    </div>
                    <div className="col-span-2 grid gap-2 sm:col-span-3 lg:col-span-6">
                        <Label htmlFor="description">Description</Label>
                        <Input
                            id="description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="optional"
                        />
                        <InputError message={errors.description} />
                    </div>
                    <div className="col-span-2 flex items-center gap-2 sm:col-span-3 lg:col-span-6">
                        <Button disabled={processing} className="w-fit">
                            {editing ? 'Save changes' : 'Add product'}
                        </Button>
                        {editing && (
                            <>
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                                <span className="text-muted-foreground text-sm">Editing {editing.name}</span>
                            </>
                        )}
                    </div>
                </form>

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search SKU, barcode or name…"
                        />
                    </FilterField>
                    <FilterField label="Type" htmlFor="filter-type">
                        <SearchSelect id="filter-type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                            <option value="">All types</option>
                            {PRODUCT_TYPES.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                    <FilterField label="Category" htmlFor="filter-category">
                        <SearchSelect id="filter-category" value={filters.category_id} onChange={(e) => setFilter('category_id', e.target.value)}>
                            <option value="">All categories</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                    <FilterField label="Brand" htmlFor="filter-brand">
                        <SearchSelect id="filter-brand" value={filters.brand_id} onChange={(e) => setFilter('brand_id', e.target.value)}>
                            <option value="">All brands</option>
                            {brands.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                            {/* ⚠ A real filter, not an empty one: "which finished
                                goods still have no brand" is the question the
                                warning in D-3 leaves open. */}
                            <option value="none">No brand</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">SKU</th>
                            <th className="py-2">Barcode</th>
                            <th className="py-2">Name</th>
                            <th className="py-2">Type</th>
                            <th className="py-2">Category</th>
                            <th className="py-2">Brand</th>
                            <th className="py-2">Stock</th>
                            <th className="py-2">Avg. cost</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.data.map((product) => (
                            <tr key={product.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2 font-mono text-xs">{product.sku}</td>
                                <td className="text-muted-foreground py-2 font-mono text-xs">{product.barcode ?? '-'}</td>
                                <td className="py-2">
                                    {product.name}
                                    {!product.is_active && (
                                        <Badge variant="secondary" className="ml-2">
                                            Inactive
                                        </Badge>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{product.type.replace('_', ' ')}</td>
                                <td className="text-muted-foreground py-2">{product.category ?? '-'}</td>
                                <td className="text-muted-foreground py-2">{product.brand ?? '-'}</td>
                                <td className="py-2">
                                    {product.current_quantity !== null ? (
                                        <span className={product.is_below_threshold ? 'text-destructive font-medium' : ''}>
                                            {product.current_quantity} {product.unit}
                                            {product.is_below_threshold && ' (low)'}
                                        </span>
                                    ) : (
                                        '-'
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{product.average_unit_cost ?? '-'}</td>
                                <td className="py-2 text-right">
                                    <Button variant="ghost" size="sm" onClick={() => startEdit(product)}>
                                        Edit
                                    </Button>
                                    {product.is_active && (
                                        <Button variant="ghost" size="sm" onClick={() => deactivate(product)}>
                                            Deactivate
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {products.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No products match these filters.' : 'No products yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={products} />
            </div>
        </AppLayout>
    );
}
