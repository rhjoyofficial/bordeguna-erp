import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Batch {
    id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    batch_number: string | null;
    expiry_date: string | null;
    quantity: string;
    bin_id: number | null;
    bin: string | null;
}

interface Bin {
    id: number;
    code: string;
    name: string | null;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Bin placement', href: '/inventory/bin-placement' },
];

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * Where each batch physically sits. Every change saves on its own row, so a
 * person walking the warehouse with a phone can record shelf by shelf; the
 * pick list prints whatever is recorded here beside the batch it guides to.
 */
export default function BinPlacementIndex({
    warehouses,
    warehouseId,
    batches,
    bins,
}: {
    warehouses: Warehouse[];
    warehouseId: number | null;
    batches: Batch[];
    bins: Bin[];
}) {
    const changeWarehouse = (id: string) => router.get('/inventory/bin-placement', { warehouse: id }, { preserveState: false });

    const place = (batch: Batch, binId: string) =>
        router.patch(
            route('inventory.stock.batches.bin', batch.id),
            { bin_id: binId === '' ? null : Number(binId) },
            { preserveScroll: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bin placement" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Bin placement"
                    description="Record which shelf each batch sits on. Placement changes no quantity and posts nothing; it only teaches the pick list where to send people."
                />

                <FormRow className="gap-3">
                    <Field label="Warehouse" htmlFor="warehouse" size="md">
                        <SearchSelect id="warehouse" value={warehouseId === null ? '' : String(warehouseId)} onChange={(e) => changeWarehouse(e.target.value)}>
                            {warehouses.map((warehouse) => (
                                <option key={warehouse.id} value={warehouse.id}>
                                    {warehouse.name} ({warehouse.code})
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                </FormRow>

                {bins.length === 0 && (
                    <p className="text-muted-foreground text-sm">
                        This warehouse has no active bins yet. Create them on the{' '}
                        <Link href="/inventory/bins" className="underline">
                            Bins
                        </Link>{' '}
                        screen first.
                    </p>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3">Batch</th>
                                <th className="p-3">Expiry</th>
                                <th className="p-3 text-right">On hand</th>
                                <th className="p-3">Bin</th>
                            </tr>
                        </thead>
                        <tbody>
                            {batches.map((batch) => (
                                <tr key={batch.id} className="border-b">
                                    <td className="p-3">
                                        <p>{batch.product}</p>
                                        {batch.sku && <p className="text-muted-foreground text-xs">{batch.sku}</p>}
                                    </td>
                                    <td className="text-muted-foreground p-3 font-mono">{batch.batch_number ?? 'no batch number'}</td>
                                    <td className="text-muted-foreground p-3">{batch.expiry_date}</td>
                                    <td className="p-3 text-right">
                                        {quantity(batch.quantity)}
                                        {batch.unit && <span className="text-muted-foreground"> {batch.unit}</span>}
                                    </td>
                                    <td className="p-2">
                                        <SearchSelect
                                            value={batch.bin_id === null ? '' : String(batch.bin_id)}
                                            onChange={(e) => place(batch, e.target.value)}
                                            disabled={bins.length === 0}
                                        >
                                            <option value="">Not placed</option>
                                            {bins.map((bin) => (
                                                <option key={bin.id} value={bin.id}>
                                                    {bin.code}
                                                    {bin.name ? ` (${bin.name})` : ''}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </td>
                                </tr>
                            ))}
                            {batches.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                        No stock in this warehouse.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
