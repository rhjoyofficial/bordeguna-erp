import { CompactTable, inventoryCrumbs, Pill, Toolbar } from '@/components/inventory/inventory-ui';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatQuantity } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';

interface BatchRow {
    id: number;
    batch_number: string | null;
    product: string | null;
    sku: string | null;
    warehouse: string | null;
    on_hand: string;
    received_at: string | null;
    manufactured_date: string | null;
    expiry_date: string | null;
    held: boolean;
}

const breadcrumbs = inventoryCrumbs('Stock', 'Batches');

/**
 * ⭐ Find a batch (traceability, 17 Oct 2026). Type the number printed on the
 * jar, the supplier's batch on the tin, or a product: each row opens that
 * batch's whole trail, where it came from and where it went.
 */
export default function BatchesIndex({ batches, search }: { batches: BatchRow[]; search: string }) {
    const { filters, setFilter } = useFilters('/inventory/batches', { search });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Batches" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar note="One row per batch per warehouse. Open a batch to see where it came from, where it went, and to put it on hold." />

                <label className="border-input bg-background flex h-10 w-full max-w-md items-center gap-2 rounded-md border px-2.5">
                    <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                    <Input
                        autoFocus
                        aria-label="Find a batch"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        placeholder="Batch number (e.g. PRN-2026-27-0001), product or SKU"
                        className="h-8 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                    />
                </label>

                <div className="bg-card text-card-foreground rounded-xl border">
                    <CompactTable minWidth="48rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>Batch</th>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th className="text-right">On hand</th>
                                <th>Made / received</th>
                                <th>Expiry</th>
                            </tr>
                        </thead>
                        <tbody>
                            {batches.map((b) => (
                                <tr key={b.id}>
                                    <td className="whitespace-nowrap">
                                        <Link
                                            href={`/inventory/batches/${b.id}`}
                                            className="font-mono font-medium underline-offset-2 hover:underline"
                                        >
                                            {b.batch_number ?? 'No number'}
                                        </Link>
                                        {b.held && (
                                            <Pill tone="warning" className="ml-2">
                                                On hold
                                            </Pill>
                                        )}
                                    </td>
                                    <td>
                                        {b.product} <span className="text-muted-foreground font-mono text-xs">{b.sku}</span>
                                    </td>
                                    <td className="text-muted-foreground">{b.warehouse}</td>
                                    <td className="text-right tabular-nums">{formatQuantity(b.on_hand)}</td>
                                    <td className="text-muted-foreground whitespace-nowrap">{b.manufactured_date ?? b.received_at ?? '-'}</td>
                                    <td className="whitespace-nowrap">{b.expiry_date ?? '-'}</td>
                                </tr>
                            ))}
                            {batches.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                        {search
                                            ? 'No batch matches that.'
                                            : 'No numbered batch yet. Batches get a number at the goods receipt or from a production run.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                </div>
                {batches.length === 50 && (
                    <p className="text-muted-foreground text-xs">Showing the newest 50. Type more of the number to narrow it.</p>
                )}
            </div>
        </AppLayout>
    );
}
