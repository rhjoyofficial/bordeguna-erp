import { ConfirmDialog } from '@/components/confirm-dialog';
import { Pagination } from '@/components/data-table/pagination';
import { CompactControls } from '@/components/form/compact-controls';
import { Chip, CompactTable, Hint, inventoryCrumbs, Pill, Toolbar } from '@/components/inventory/inventory-ui';
import { ProductDialog } from '@/components/inventory/product-dialog';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { useState } from 'react';

interface Product {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    mrp: string | null;
    /** Phase 2 / 1B (17 Oct 2026): fixed_mrp or market, and this SKU's rule overrides. */
    pricing_mode: 'fixed_mrp' | 'market';
    market_markup_percent: string | null;
    market_min_margin_percent: string | null;
    market_rounding_step: string | null;
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
    expired_batches_count: number;
    has_stock_history: boolean;
}

interface Option {
    id: number;
    name: string;
    /** A category's market pricing defaults (Phase 2 / 1B), for the dialog's "inherits" hints. */
    market_markup_percent?: string | null;
    market_min_margin_percent?: string | null;
    market_rounding_step?: string | null;
}

interface BrandOption extends Option {
    code: string;
}

interface UnitOption extends Option {
    abbreviation: string;
}

interface Counts {
    all: number;
    low_stock: number;
    weight_missing: number;
    expired: number;
    inactive: number;
    service: number;
    no_brand: number;
}

const TYPE_LABEL: Record<string, string> = {
    finished_good: 'Finished good',
    raw_material: 'Raw material',
    service: 'Service',
};

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The Catalogue (redesign, 15 Oct 2026): Products and Raw materials are two
 * tabs of one screen over one model, and the page reads which it is from the
 * ROUTE (`/inventory/raw-materials` fixes the kind), never from a query
 * string. Status chips with their counts sit above the list and narrow it;
 * the list's columns follow the kind: a raw material is never sold, so its
 * list has no MRP, no Type and no weight warning.
 */
export default function ProductsIndex({
    products,
    counts,
    categories,
    brands,
    units,
    taxClasses,
    filters: initialFilters,
    kind = 'products',
    can_manage_weights = false,
}: {
    products: Paginated<Product>;
    counts: Counts;
    categories: Option[];
    brands: BrandOption[];
    units: UnitOption[];
    taxClasses: Option[];
    filters: { search: string; type: string; category_id: string; brand_id: string; weight: string; flag: string };
    kind?: string;
    can_manage_weights?: boolean;
}) {
    const { canImportCatalogue } = usePage<SharedData>().props;
    // ⚠ INV-005 (14 Oct 2026): New, Edit and Deactivate need
    // inventory.catalog.manage, like every other catalogue page. Staff and
    // Finance were offered them and met a 403 dressed as "reload the page".
    const { manageCatalog } = useInventoryAbilities();
    const { t } = useTranslation();
    const isRaw = kind === 'raw';
    const base = isRaw ? '/inventory/raw-materials' : '/inventory/products';
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters(base, initialFilters);
    // ⭐ One dialog for the product and its prices (21 Sep 2026); the form that
    // used to sit open above the list, with fourteen fields, is gone.
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Product | null>(null);
    // ⚠ 17 Oct 2026 (practice run F6): a press on "New raw material" while the
    // popup was still closing and the list reloading after a save went nowhere.
    // The button now says it is busy for that moment instead of eating the press.
    const [reloading, setReloading] = useState(false);

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

    const activate = (product: Product) => {
        router.post(route('inventory.products.activate', product.id), {}, { preserveScroll: true });
    };

    const noun = isRaw ? 'raw material' : 'product';
    const plural = isRaw ? 'raw materials' : 'products';
    const chip = (flag: string) => ({ active: filters.flag === flag, onClick: () => setFilter('flag', filters.flag === flag ? '' : flag) });

    const flags = (product: Product) => (
        <>
            {product.is_below_threshold && product.current_quantity !== null && product.is_active && <Pill tone="warning">low stock</Pill>}
            {!isRaw && product.type !== 'service' && product.weight_grams === null && product.is_active && <Pill tone="danger">weight missing</Pill>}
            {product.expired_batches_count > 0 && (
                <Pill tone="danger">
                    {product.expired_batches_count} expired batch{product.expired_batches_count === 1 ? '' : 'es'}
                </Pill>
            )}
            {!product.is_active && <Pill tone="muted">inactive</Pill>}
        </>
    );

    // ⚠ 17 Oct 2026 (practice run F9): the buttons may stack. Kept on one line
    // they made the table need a sideways scroll at 1278 px and cut Deactivate off.
    const actions = (product: Product) => (
        <span className="inline-flex flex-wrap justify-end gap-1">
            {manageCatalog && (
                <Button variant="ghost" size="sm" onClick={() => startEdit(product)}>
                    Edit
                </Button>
            )}
            {manageCatalog && product.is_active && (
                <ConfirmDialog
                    trigger={
                        <Button variant="ghost" size="sm">
                            Deactivate
                        </Button>
                    }
                    title={`Deactivate ${product.name}`}
                    description={`It stays on old documents and is hidden from Sales and Purchases. You can reactivate it from this list. (${noun === 'product' ? 'Product' : 'Raw material'} ${product.sku})`}
                    confirmLabel="Deactivate"
                    destructive
                    onConfirm={() => deactivate(product)}
                />
            )}
            {manageCatalog && !product.is_active && (
                <Button variant="ghost" size="sm" onClick={() => activate(product)}>
                    Reactivate
                </Button>
            )}
        </span>
    );

    const empty = (
        <td colSpan={10} className="text-muted-foreground py-6 text-center">
            {hasActiveFilters
                ? `No ${plural} match these filters.`
                : manageCatalog
                  ? `No ${plural} yet. Press New ${noun} to add the first one.`
                  : t('common.none_yet')}
        </td>
    );

    return (
        <AppLayout breadcrumbs={inventoryCrumbs('Catalogue', isRaw ? 'Raw materials' : 'Products')}>
            <Head title={isRaw ? 'Raw materials' : 'Products'} />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar
                    note={
                        isRaw
                            ? `${counts.all} raw material${counts.all === 1 ? '' : 's'} and packaging item${counts.all === 1 ? '' : 's'}, ${counts.all - counts.inactive} active`
                            : `${counts.all} product${counts.all === 1 ? '' : 's'} we sell, ${counts.all - counts.inactive} active`
                    }
                    actions={
                        <>
                            {/* Import and labels were sidebar rows until 29 Sep 2026; they belong with the list. */}
                            {!isRaw && can_manage_weights && (
                                <Button asChild variant="outline">
                                    <Link href="/inventory/products/weights">Weights sheet</Link>
                                </Button>
                            )}
                            {!isRaw && canImportCatalogue && (
                                <Button asChild variant="outline">
                                    <Link href="/inventory/labels">Barcode labels</Link>
                                </Button>
                            )}
                            {canImportCatalogue && (
                                <Button asChild variant="outline">
                                    <Link href="/inventory/products/import">Import</Link>
                                </Button>
                            )}
                            <Button asChild variant="outline">
                                <a href={route('inventory.products.export')}>Export</a>
                            </Button>
                            {manageCatalog && (
                                <Button onClick={openNew} disabled={reloading} aria-busy={reloading || undefined}>
                                    {reloading && <Loader2 className="animate-spin" aria-hidden="true" />}
                                    {isRaw ? 'New raw material' : 'New product'}
                                </Button>
                            )}
                        </>
                    }
                />

                <CompactControls>
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="border-input bg-background flex h-9 w-72 max-w-full items-center gap-2 rounded-md border px-2.5">
                            <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                            <Input
                                aria-label={isRaw ? 'Search SKU or name' : 'Search SKU, barcode or name'}
                                value={filters.search}
                                onChange={(e) => setFilter('search', e.target.value)}
                                placeholder={isRaw ? 'Search SKU or name' : 'Search SKU, barcode or name'}
                                className="h-7 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                            />
                        </label>
                        <SearchSelect id="filter-category" value={filters.category_id} onChange={(e) => setFilter('category_id', e.target.value)}>
                            <option value="">All categories</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {/* The raw-materials view has no brands (D-5): raw materials carry none. */}
                        {!isRaw && (
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
                        )}
                        <span className="bg-border mx-1 hidden h-6 w-px sm:block" aria-hidden="true" />
                        <Chip count={counts.all} {...chip('')}>
                            All
                        </Chip>
                        <Chip count={counts.low_stock} tone="warning" {...chip('low')}>
                            Low stock
                        </Chip>
                        {!isRaw && (
                            <Chip count={counts.weight_missing} tone="danger" {...chip('weight_missing')}>
                                Weight missing
                            </Chip>
                        )}
                        <Chip count={counts.expired} tone="danger" {...chip('expired')}>
                            Expired batches
                        </Chip>
                        <Chip count={counts.inactive} tone="muted" {...chip('inactive')}>
                            Inactive
                        </Chip>
                        {!isRaw && (
                            <Chip count={counts.service} tone="muted" {...chip('service')}>
                                Service
                            </Chip>
                        )}
                        {!isRaw && (
                            <Chip count={counts.no_brand} tone="muted" {...chip('no_brand')}>
                                No brand
                            </Chip>
                        )}
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2"
                            >
                                Clear
                            </button>
                        )}
                        <span className="text-muted-foreground ml-auto text-xs tabular-nums">
                            {products.total === 0 ? '0' : `${products.from ?? 0} to ${products.to ?? 0}`} of {products.total} shown
                        </span>
                    </div>
                </CompactControls>

                <div className="bg-card text-card-foreground rounded-xl border">
                    {isRaw ? (
                        <CompactTable minWidth="48rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>SKU</th>
                                    <th>Raw material</th>
                                    <th>Category</th>
                                    <th>Stocked in</th>
                                    <th>
                                        <span className="inline-flex items-center gap-1">
                                            Bought in
                                            <Hint text="From Setup: Purchase units. Blank means bought and stocked in the same unit." />
                                        </span>
                                    </th>
                                    <th className="text-right">On hand</th>
                                    <th className="text-right">Reorder at</th>
                                    <th className="text-right">Avg. cost</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.data.map((product) => (
                                    <tr key={product.id} className={cn(!product.is_active && 'text-muted-foreground')}>
                                        <td className="font-mono text-xs">{product.sku}</td>
                                        <td>
                                            <span className="inline-flex flex-wrap items-center gap-1.5">
                                                <span className="font-medium">{product.name}</span>
                                                {flags(product)}
                                            </span>
                                        </td>
                                        <td className="text-muted-foreground">{product.category ?? '-'}</td>
                                        <td className="text-muted-foreground">{product.unit}</td>
                                        <td className="text-muted-foreground">{product.purchase_unit ?? '-'}</td>
                                        <td
                                            className={cn(
                                                'text-right tabular-nums',
                                                product.is_below_threshold &&
                                                    product.current_quantity !== null &&
                                                    'font-semibold text-amber-700 dark:text-amber-400',
                                            )}
                                        >
                                            {product.current_quantity !== null ? `${qty(product.current_quantity)} ${product.unit}` : '-'}
                                        </td>
                                        <td className="text-muted-foreground text-right tabular-nums">
                                            {product.low_stock_threshold !== null ? qty(product.low_stock_threshold) : '-'}
                                        </td>
                                        <td className="text-muted-foreground text-right tabular-nums">
                                            {product.average_unit_cost !== null ? money(product.average_unit_cost) : '-'}
                                        </td>
                                        <td className="text-right">{actions(product)}</td>
                                    </tr>
                                ))}
                                {products.data.length === 0 && <tr>{empty}</tr>}
                            </tbody>
                        </CompactTable>
                    ) : (
                        <CompactTable minWidth="52rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>SKU</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th>Brand</th>
                                    <th>Category</th>
                                    <th className="text-right">MRP</th>
                                    <th className="text-right">Weight</th>
                                    <th className="text-right">On hand</th>
                                    <th className="text-right">Avg. cost</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.data.map((product) => (
                                    <tr key={product.id} className={cn(!product.is_active && 'text-muted-foreground')}>
                                        <td className="font-mono text-xs">{product.sku}</td>
                                        <td>
                                            <span className="inline-flex flex-wrap items-center gap-1.5">
                                                <span className="font-medium">{product.name}</span>
                                                {flags(product)}
                                            </span>
                                        </td>
                                        <td className="text-muted-foreground">{TYPE_LABEL[product.type] ?? product.type}</td>
                                        <td className="text-muted-foreground">{product.brand ?? '-'}</td>
                                        <td className="text-muted-foreground">{product.category ?? '-'}</td>
                                        {/* ⭐ Phase 2 / 1B: a market product has no MRP by design; say so, not "-". */}
                                        <td className="text-right tabular-nums">
                                            {product.pricing_mode === 'market' ? (
                                                <span className="text-muted-foreground text-xs">{t('market.todays_price')}</span>
                                            ) : product.mrp === null ? (
                                                '-'
                                            ) : (
                                                money(product.mrp)
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {product.weight_grams !== null ? (
                                                <span title={product.weight_source === 'from_net_content' ? 'Taken from the net content' : undefined}>
                                                    {product.weight_grams.toLocaleString('en-BD')} g
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
                                            )}
                                        </td>
                                        <td
                                            className={cn(
                                                'text-right tabular-nums',
                                                product.is_below_threshold &&
                                                    product.current_quantity !== null &&
                                                    'font-semibold text-amber-700 dark:text-amber-400',
                                            )}
                                        >
                                            {product.current_quantity !== null ? `${qty(product.current_quantity)} ${product.unit}` : '-'}
                                        </td>
                                        <td className="text-muted-foreground text-right tabular-nums">
                                            {product.average_unit_cost !== null ? money(product.average_unit_cost) : '-'}
                                        </td>
                                        <td className="text-right">{actions(product)}</td>
                                    </tr>
                                ))}
                                {products.data.length === 0 && <tr>{empty}</tr>}
                            </tbody>
                        </CompactTable>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-muted-foreground text-xs">Inactive items stay on old documents and are hidden from Sales and Purchases.</p>
                    <Pagination meta={products} />
                </div>

                <ProductDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    product={editing}
                    categories={categories}
                    brands={brands}
                    units={units}
                    taxClasses={taxClasses}
                    kind={isRaw ? 'raw' : 'products'}
                    onReloading={setReloading}
                />
            </div>
        </AppLayout>
    );
}
