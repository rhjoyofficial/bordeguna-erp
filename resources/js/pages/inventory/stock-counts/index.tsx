import { Pagination } from '@/components/data-table/pagination';
import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface CountRow {
    id: number;
    count_number: string;
    status: string;
    warehouse: string | null;
    category: string | null;
    brand: string | null;
    lines_count: number;
    counted_lines_count: number;
    created_at: string | null;
    completed_at: string | null;
}

interface Option {
    id: number;
    name: string;
    code?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock counts', href: '/inventory/stock-counts' },
];

const statusVariant = (status: string) => (status === 'completed' ? 'default' : status === 'cancelled' ? 'secondary' : 'outline');

export default function StockCountsIndex({
    counts,
    warehouses,
    defaultWarehouseId,
    categories,
    brands,
    canWrite,
}: {
    counts: Paginated<CountRow>;
    warehouses: Option[];
    defaultWarehouseId: number | null;
    categories: Option[];
    brands: Option[];
    canWrite: boolean;
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        // The default warehouse, like every other stock screen; a single
        // warehouse is the default whether or not anybody marked it.
        warehouse_id: defaultWarehouseId ? String(defaultWarehouseId) : warehouses.length === 1 ? String(warehouses[0].id) : '',
        category_id: '',
        brand_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock-counts.store'), { onSuccess: () => reset('category_id', 'brand_id') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock counts" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Stock counts" />

                {canWrite && warehouses.length === 0 && (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">
                        No warehouse yet, so there is nothing to count.{' '}
                        <Link href="/inventory/warehouses" className="text-foreground underline underline-offset-2">
                            Warehouses
                        </Link>{' '}
                        is where one is added.
                    </div>
                )}

                {canWrite && warehouses.length > 0 && (
                    <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                        <FormRow className="gap-3">
                            <Field label="Warehouse" htmlFor="warehouse_id" size="md" error={errors.warehouse_id} required>
                                <SearchSelect id="warehouse_id" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)}>
                                    <option value="">Choose a warehouse</option>
                                    {warehouses.map((warehouse) => (
                                        <option key={warehouse.id} value={warehouse.id}>
                                            {warehouse.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label="Category (optional)" htmlFor="category_id" size="md" error={errors.category_id}>
                                <SearchSelect id="category_id" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)}>
                                    <option value="">All categories</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label="Brand (optional)" htmlFor="brand_id" size="md" error={errors.brand_id}>
                                <SearchSelect id="brand_id" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                                    <option value="">All brands</option>
                                    {brands.map((brand) => (
                                        <option key={brand.id} value={brand.id}>
                                            {brand.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Button disabled={processing} className="self-end">
                                Start a count
                            </Button>
                        </FormRow>
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Number</th>
                                <th className="p-3">Warehouse</th>
                                <th className="p-3">Scope</th>
                                <th className="p-3">Progress</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Started</th>
                            </tr>
                        </thead>
                        <tbody>
                            {counts.data.map((count) => (
                                <tr key={count.id} className="border-b">
                                    <td className="p-3 font-mono">
                                        <Link href={`/inventory/stock-counts/${count.id}`} className="underline-offset-2 hover:underline">
                                            {count.count_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">{count.warehouse}</td>
                                    <td className="text-muted-foreground p-3">
                                        {[count.category, count.brand].filter(Boolean).join(', ') || 'Whole warehouse'}
                                    </td>
                                    <td className="text-muted-foreground p-3">
                                        {count.counted_lines_count} of {count.lines_count} lines counted
                                    </td>
                                    <td className="p-3">
                                        <Badge variant={statusVariant(count.status)}>{count.status}</Badge>
                                    </td>
                                    <td className="text-muted-foreground p-3">{count.created_at}</td>
                                </tr>
                            ))}
                            {counts.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                        No stock counts yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* ⚠ Paginated on the server (15 a page) with no control here, so the
                    sixteenth count was unreachable (21 Sep 2026 audit). */}
                <Pagination meta={counts} />
            </div>
        </AppLayout>
    );
}
