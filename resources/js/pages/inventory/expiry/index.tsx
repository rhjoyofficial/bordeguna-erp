import { Chip, inventoryCrumbs, Pill } from '@/components/inventory/inventory-ui';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    product: string | null;
    product_id?: number | null;
    sku: string | null;
    batch_number: string | null;
    warehouse: string | null;
    quantity: number;
    expiry_date: string;
    days: number;
    /** This product's own warning window, in days. */
    warning_days: number;
    /** On hold: never picked until released (traceability, 17 Oct 2026). */
    held: boolean;
}

/** ⭐ Traceability S8 (17 Oct 2026): the owner's own question, "what expires in 30 days". '' = each product's own window. */
const WINDOWS: { value: string; label: string }[] = [
    { value: '', label: "Product's own warning" },
    { value: '30', label: '30 days' },
    { value: '60', label: '60 days' },
    { value: '90', label: '90 days' },
    { value: 'all', label: 'All' },
];

const breadcrumbs = inventoryCrumbs('Overview', 'Expiry');

const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * Per BATCH, not per product: two receipts of the same product expire on two
 * different days, and this screen names the exact batch that is going.
 *
 * ⚠ "Expiring soon" is each product's own warning window (set on the product,
 * the default when blank), not one number for the whole shop.
 */
export default function ExpiryIndex({
    expired,
    expiring,
    default_warning_days,
    within = '',
}: {
    expired: Row[];
    expiring: Row[];
    default_warning_days: number;
    within?: string;
}) {
    const [tab, setTab] = useState<'expired' | 'expiring'>(expired.length > 0 ? 'expired' : 'expiring');

    const rows = tab === 'expired' ? expired : expiring;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expiry" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-sm">Expiring within</span>
                    {WINDOWS.map((w) => (
                        <Chip
                            key={w.value}
                            active={within === w.value}
                            onClick={() => router.get('/inventory/expiry', w.value === '' ? {} : { within: w.value }, { preserveScroll: true })}
                        >
                            {w.label}
                        </Chip>
                    ))}
                </div>
                <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border">
                    <div className="border-border flex gap-1 border-b px-3 pt-2">
                        {[
                            { key: 'expired' as const, label: `Expired (${expired.length})` },
                            { key: 'expiring' as const, label: `Expiring soon (${expiring.length})` },
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
                                    : within === ''
                                      ? `Nothing is inside its product's warning window (${default_warning_days} days unless the product sets its own). Batches with no expiry date never appear here.`
                                      : within === 'all'
                                        ? 'No dated stock is being held. Batches with no expiry date never appear here.'
                                        : `Nothing expires within ${within} days. Batches with no expiry date never appear here.`}
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
                                        <th className="py-1.5 text-right">Warns at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-1.5">
                                                {row.product_id ? (
                                                    <Link
                                                        href={`/inventory/products/${row.product_id}`}
                                                        className="underline-offset-2 hover:underline"
                                                    >
                                                        {row.product}
                                                    </Link>
                                                ) : (
                                                    row.product
                                                )}
                                                <span className="text-muted-foreground ml-2 text-xs">{row.sku}</span>
                                            </td>
                                            <td className="py-1.5 font-mono text-xs">
                                                <Link href={`/inventory/batches/${row.id}`} className="underline-offset-2 hover:underline">
                                                    {row.batch_number ?? 'No number'}
                                                </Link>
                                                {row.held && (
                                                    <Pill tone="warning" className="ml-2 font-sans">
                                                        On hold
                                                    </Pill>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-1.5">{row.warehouse}</td>
                                            <td className="py-1.5 text-right tabular-nums">{qty(row.quantity)}</td>
                                            <td className="py-1.5 whitespace-nowrap">{showDate(row.expiry_date)}</td>
                                            <td
                                                className={`py-1.5 text-right font-medium tabular-nums ${
                                                    tab === 'expired' ? 'text-destructive' : row.days <= 7 ? 'text-destructive' : ''
                                                }`}
                                            >
                                                {row.days}
                                            </td>
                                            <td className="text-muted-foreground py-1.5 text-right tabular-nums">{row.warning_days} days</td>
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
