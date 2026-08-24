import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    product: string | null;
    sku: string | null;
    batch_number: string | null;
    warehouse: string | null;
    quantity: number;
    expiry_date: string;
    days: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Expiry', href: '/inventory/expiry' },
];

const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * Per BATCH, not per product: two receipts of the same product expire on two
 * different days, and this screen names the exact batch that is going.
 */
export default function ExpiryIndex({ expired, expiring, soon_days }: { expired: Row[]; expiring: Row[]; soon_days: number }) {
    const [tab, setTab] = useState<'expired' | 'expiring'>(expired.length > 0 ? 'expired' : 'expiring');

    const rows = tab === 'expired' ? expired : expiring;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock expiry" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 className="text-lg font-medium">Stock expiry</h2>
                    <p className="text-muted-foreground text-xs">
                        Expired stock is never sold (the FIFO picker refuses it by date), and the nightly job proposes a write-off for each expired
                        batch on{' '}
                        <Link href="/inventory/stock" className="text-primary hover:underline">
                            the stock screen
                        </Link>
                        .
                    </p>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
                    <div className="border-border flex gap-1 border-b px-3 pt-2">
                        {[
                            { key: 'expired' as const, label: `Expired (${expired.length})` },
                            { key: 'expiring' as const, label: `Expiring within ${soon_days} days (${expiring.length})` },
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
                                {tab === 'expired'
                                    ? 'No expired stock is being held. Batches with no expiry date never appear here.'
                                    : `Nothing expires in the next ${soon_days} days. Batches with no expiry date never appear here.`}
                            </p>
                        ) : (
                            <table className="w-full min-w-[52rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                        <th className="py-1.5">Product</th>
                                        <th className="py-1.5">Batch</th>
                                        <th className="py-1.5">Warehouse</th>
                                        <th className="py-1.5 text-right">Quantity held</th>
                                        <th className="py-1.5">Expiry date</th>
                                        <th className="py-1.5 text-right">{tab === 'expired' ? 'Days overdue' : 'Days left'}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-1.5">
                                                {row.product}
                                                <span className="text-muted-foreground ml-2 text-xs">{row.sku}</span>
                                            </td>
                                            <td className="text-muted-foreground py-1.5 font-mono text-xs">{row.batch_number ?? '-'}</td>
                                            <td className="text-muted-foreground py-1.5">{row.warehouse}</td>
                                            <td className="py-1.5 text-right tabular-nums">{qty(row.quantity)}</td>
                                            <td className="py-1.5 whitespace-nowrap">{row.expiry_date}</td>
                                            <td
                                                className={`py-1.5 text-right font-medium tabular-nums ${
                                                    tab === 'expired' ? 'text-destructive' : row.days <= 7 ? 'text-destructive' : ''
                                                }`}
                                            >
                                                {row.days}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
