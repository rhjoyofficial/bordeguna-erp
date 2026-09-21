import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ProductDialog } from '@/components/inventory/product-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Product {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    mrp: string | null;
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
    // ⭐ One dialog for the product and its prices (21 Sep 2026); the form that
    // used to sit open above the list, with fourteen fields, is gone.
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Product | null>(null);

    const openNew = () => {
        setEditing(null);
        setDialogOpen(true);
    };

    const startEdit = (product: Product) => {
        setEditing(product);
        setDialogOpen(true);
    };

    const deactivate = (product: Product) => {
        router.delete(route('inventory.products.destroy', product.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Products" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Products</h1>
                        <p className="text-muted-foreground text-sm">
                            Everything you buy, make or sell. Prices for each kind of buyer are set in the same place.
                        </p>
                    </div>
                    <Button onClick={openNew}>New product</Button>
                </div>

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

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">SKU</th>
                                <th className="py-2">Barcode</th>
                                <th className="py-2">Name</th>
                                <th className="py-2">Type</th>
                                <th className="py-2">Category</th>
                                <th className="py-2">Brand</th>
                                <th className="py-2 pr-6 text-right">MRP</th>
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
                                    <td className="py-2 pr-6 text-right tabular-nums">
                                        {product.mrp === null
                                            ? '-'
                                            : Number(product.mrp).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                    </td>
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
                                    <td colSpan={10} className="text-muted-foreground py-6 text-center">
                                        {hasActiveFilters
                                            ? 'No products match these filters.'
                                            : 'No products yet. Press New product to add the first one.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={products} />

                <ProductDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    product={editing}
                    categories={categories}
                    brands={brands}
                    units={units}
                    taxClasses={taxClasses}
                />
            </div>
        </AppLayout>
    );
}
