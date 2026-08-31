import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Line {
    id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
}

interface Count {
    id: number;
    count_number: string;
    warehouse: string | null;
    created_at: string | null;
}

/**
 * The blind sheet (C-CNT-7): product identity and a blank column, never the
 * expected quantity. A counter who can see the book number writes the book
 * number - the oldest failure in stock counting.
 */
export default function StockCountSheet({ count, lines }: { count: Count; lines: Line[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inventory', href: '/inventory' },
        { title: 'Stock counts', href: '/inventory/stock-counts' },
        { title: count.count_number, href: `/inventory/stock-counts/${count.id}` },
        { title: 'Sheet', href: `/inventory/stock-counts/${count.id}/sheet` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Count sheet ${count.count_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/inventory/stock-counts/${count.id}`}>Back to the count</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <div className="flex items-baseline justify-between">
                        <h1 className="text-lg font-semibold">Stock count sheet</h1>
                        <p className="font-mono text-sm">{count.count_number}</p>
                    </div>
                    <p className="mt-1 text-sm text-black/60">
                        {count.warehouse} · {count.created_at}
                    </p>

                    <table className="mt-6 w-full text-sm">
                        <thead>
                            <tr className="border-y border-black/20 text-left">
                                <th className="py-2 pr-2 font-medium">#</th>
                                <th className="py-2 pr-2 font-medium">Item</th>
                                <th className="py-2 pr-2 font-medium">Unit</th>
                                <th className="py-2 text-right font-medium">Counted</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line, index) => (
                                <tr key={line.id} className="border-b border-black/10">
                                    <td className="py-2 pr-2 text-black/60">{index + 1}</td>
                                    <td className="py-2 pr-2">
                                        <p>{line.product}</p>
                                        {line.sku && <p className="text-xs text-black/50">{line.sku}</p>}
                                    </td>
                                    <td className="py-2 pr-2 text-black/60">{line.unit}</td>
                                    {/* Blank on purpose: the counter writes what the
                                        shelf actually holds, uninfluenced. */}
                                    <td className="py-2 text-right text-black/30">__________</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <p className="mt-6 text-xs text-black/50">
                        Count what is physically present, including anything damaged or past its date. Leave a line blank if it was not counted;
                        a blank is never treated as zero. Items found that are not on this sheet: note them at the bottom and add them on the
                        entry screen.
                    </p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Counted by, with date</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Checked by</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
