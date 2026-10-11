import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ProductDialog } from '@/components/inventory/product-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
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
    weight_grams: number | null;
    weight_source: string | null;
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
    kind = 'products',
    can_manage_weights = false,
}: {
    products: Paginated<Product>;
    categories: Option[];
    brands: BrandOption[];
    units: UnitOption[];
    taxClasses: Option[];
    filters: { search: string; type: string; category_id: string; brand_id: string; weight: string };
    kind?: string;
    can_manage_weights?: boolean;
}) {
    const { canImportCatalogue } = usePage<SharedData>().props;
    // ⭐ 27 Sep 2026 (Joy): raw materials and products are separate screens over
    // one model. `kind=raw` is the raw-materials view; everything else is what
    // the shop sells. The kind rides in the base URL so it survives every filter
    // change and the Clear button.
    const isRaw = kind === 'raw';
    const {
        filters,
        setFilter,
        reset: resetFilters,
        hasActiveFilters,
    } = useFilters(isRaw ? '/inventory/products?kind=raw' : '/inventory/products', initialFilters);
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
            <Head title={isRaw ? 'Raw materials' : 'Products'} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{isRaw ? 'Raw materials' : 'Products'}</h1>
                    </div>
                    {/* Import and labels were sidebar rows until 29 Sep 2026; they belong with the list. */}
                    <div className="flex flex-wrap items-center gap-2">
                        {can_manage_weights && (
                            <Button asChild variant="outline">
                                <Link href="/inventory/products/weights">Weights sheet</Link>
                            </Button>
                        )}
                        {canImportCatalogue && (
                            <>
                                <Button asChild variant="outline">
                                    <Link href="/inventory/products/import">Import</Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href="/inventory/labels">Barcode labels</Link>
                                </Button>
                            </>
                        )}
                        <Button onClick={openNew}>{isRaw ? 'New raw material' : 'New product'}</Button>
                    </div>
                </div>

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="w-80">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search SKU, barcode or name…"
                        />
                    </FilterField>
                    {/* The raw-materials view is all one type, so it needs no type
                        filter; the products view offers only what it sells. */}
                    {!isRaw && (
                        <FilterField label="Type" htmlFor="filter-type">
                            <SearchSelect id="filter-type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                                <option value="">All types</option>
                                {PRODUCT_TYPES.filter((t) => t.value !== 'raw_material').map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </FilterField>
                    )}
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
                    {/* ⭐ Courier hub slice 3: a parcel weight cannot be worked out
                        for a product with no weight, so this finds them. */}
                    <FilterField label="Weight" htmlFor="filter-weight">
                        <SearchSelect id="filter-weight" value={filters.weight} onChange={(e) => setFilter('weight', e.target.value)}>
                            <option value="">Any weight</option>
                            <option value="missing">Weight missing</option>
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[58rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">SKU</th>
                                <th className="py-2">Barcode</th>
                                <th className="py-2">Name</th>
                                <th className="py-2">Type</th>
                                <th className="py-2">Category</th>
                                <th className="py-2">Brand</th>
                                <th className="py-2 pr-6 text-right">MRP</th>
                                <th className="py-2 pr-6 text-right">Weight</th>
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
                                    <td className="py-2 pr-6 text-right tabular-nums">
                                        {product.weight_grams !== null ? (
                                            <span title={product.weight_source === 'from_net_content' ? 'Taken from the net content' : undefined}>
                                                {product.weight_grams.toLocaleString('en-IN')} g
                                            </span>
                                        ) : product.type === 'service' ? (
                                            '-'
                                        ) : (
                                            <Badge variant="outline" className="text-amber-700 dark:text-amber-400">
                                                Weight missing
                                            </Badge>
                                        )}
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
                                    <td colSpan={11} className="text-muted-foreground py-6 text-center">
                                        {hasActiveFilters
                                            ? `No ${isRaw ? 'raw materials' : 'products'} match these filters.`
                                            : `No ${isRaw ? 'raw materials' : 'products'} yet. Press ${isRaw ? 'New raw material' : 'New product'} to add the first one.`}
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
                    defaultType={isRaw ? 'raw_material' : 'finished_good'}
                />
            </div>
        </AppLayout>
    );
}
