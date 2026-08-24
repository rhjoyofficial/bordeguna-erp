import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    total: number;
    threshold: number | null;
    by_warehouse: { name: string; quantity: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Low stocks', href: '/inventory/low-stocks' },
];

const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * What needs reordering (at or below its threshold) and what is already gone.
 * A product with no threshold never appears in Low - the quiet line at the
 * bottom says how many such products exist rather than pretending a default.
 */
export default function LowStocksIndex({ low, out, without_threshold }: { low: Row[]; out: Row[]; without_threshold: number }) {
    const [tab, setTab] = useState<'low' | 'out'>('low');

    const rows = tab === 'low' ? low : out;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Low stocks" />
            <div className="flex flex-col gap-4 p-4">
                <h2 className="text-lg font-medium">Low stocks</h2>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
                    <div className="border-border flex gap-1 border-b px-3 pt-2">
                        {[
                            { key: 'low' as const, label: `Low stock (${low.length})` },
                            { key: 'out' as const, label: `Out of stock (${out.length})` },
                        ].map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setTab(t.key)}
                                className={
                                    tab === t.key
                                        ? 'border-primary text-foreground -mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap'
                                        : 'text-muted-foreground hover:text-foreground -mb-px border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap'
                                }
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    <div className="overflow-x-auto p-3">
                        {rows.length === 0 ? (
                            <p className="text-muted-foreground p-2 text-sm">
                                {tab === 'low' ? 'Nothing is at or below its threshold.' : 'Nothing is out of stock.'}
                            </p>
                        ) : (
                            <table className="w-full min-w-[52rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                        <th className="py-1.5">Product</th>
                                        <th className="py-1.5">Category</th>
                                        <th className="py-1.5">By warehouse</th>
                                        <th className="py-1.5 text-right">Total</th>
                                        <th className="py-1.5 text-right">Threshold</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-1.5">
                                                {row.name}
                                                <span className="text-muted-foreground ml-2 text-xs">{row.sku}</span>
                                            </td>
                                            <td className="text-muted-foreground py-1.5">{row.category ?? '-'}</td>
                                            <td className="py-1.5">
                                                {row.by_warehouse.length === 0 ? (
                                                    <span className="text-muted-foreground text-xs">No stock anywhere</span>
                                                ) : (
                                                    <span className="flex flex-wrap gap-1.5">
                                                        {row.by_warehouse.map((cell) => (
                                                            <Badge key={cell.name} variant="secondary" className="font-normal">
                                                                {cell.name}: {qty(cell.quantity)}
                                                            </Badge>
                                                        ))}
                                                    </span>
                                                )}
                                            </td>
                                            <td className={`py-1.5 text-right tabular-nums ${tab === 'out' ? 'text-destructive font-medium' : ''}`}>
                                                {qty(row.total)}
                                            </td>
                                            <td className="text-muted-foreground py-1.5 text-right tabular-nums">
                                                {row.threshold !== null ? qty(row.threshold) : 'not set'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>

                {without_threshold > 0 && (
                    <p className="text-muted-foreground text-xs">
                        {without_threshold} in-stock product{without_threshold === 1 ? ' has' : 's have'} no low-stock threshold set, so
                        {without_threshold === 1 ? ' it' : ' they'} can never appear here. Thresholds are set on the product form.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
