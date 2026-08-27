import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Row {
    product_sku: string;
    product_name: string;
    brand: string | null;
    warehouse: string;
    batch_number: string | null;
    expiry_date: string | null;
    quantity: string;
    unit_cost: string | null;
    value: string;
    anomaly: string | null;
}

interface Group {
    quantity: string;
    value: string;
}

export default function InventoryValuationIndex({
    as_at,
    rows,
    by_brand,
    by_warehouse,
    total,
    control,
    anomaly_count,
}: {
    as_at: string;
    rows: Row[];
    by_brand: (Group & { brand: string })[];
    by_warehouse: (Group & { warehouse: string })[];
    total: string;
    control: {
        account: string | null;
        balance: string | null;
        has_account: boolean;
        ties_out: boolean;
        difference: string;
    };
    anomaly_count: number;
}) {
    const [date, setDate] = useState(as_at);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: 'Inventory valuation', href: route('accounting.inventory-valuation') },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.inventory-valuation'), { as_at: date }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory valuation" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Inventory valuation</h1>
                        <p className="text-muted-foreground text-sm">
                            What the stock was worth on {as_at}, derived from stock movements rather than from the batch cache.
                        </p>
                    </div>

                    <form onSubmit={submit} className="flex items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="as_at">As at</Label>
                            <Input id="as_at" type="date" value={date} onChange={(e) => setDate(e.target.value)} className="w-40" />
                        </div>
                        <Button type="submit">Show</Button>
                    </form>
                </div>

                {/*
                  ⚠⚠ The tie-out, on the screen, every time. A valuation that
                  disagrees with the control account is the single most important
                  thing on this page, so it sits above the numbers rather than
                  under them.
                */}
                {control.has_account ? (
                    <div
                        className={`rounded-lg border p-4 text-sm ${
                            control.ties_out
                                ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950'
                                : 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950'
                        }`}
                    >
                        {control.ties_out ? (
                            <span>
                                Agrees with <strong>{control.account}</strong> at <strong>{control.balance}</strong>.
                            </span>
                        ) : (
                            <span>
                                <strong>Does not agree with the ledger.</strong> {control.account} holds <strong>{control.balance}</strong>, this
                                valuation totals <strong>{total}</strong>, a difference of <strong>{control.difference}</strong>. Run{' '}
                                <code>accounting:reconcile</code> and <code>inventory:verify-ledger</code>.
                            </span>
                        )}
                    </div>
                ) : (
                    /*
                      ⚠ Expected for LaLaDia, Bionic and Azmion: they hold stock
                      documents and no postings at all. Saying WHY is the whole
                      point - a bare red number would look like a defect.
                    */
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950">
                        This business has no <strong>Stock on Hand</strong> account mapped, so accounting is switched off for it and there is nothing
                        to check this total against. The stock figures below are still correct; they are simply not in any set of books.
                    </div>
                )}

                {anomaly_count > 0 && (
                    <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm dark:border-red-900 dark:bg-red-950">
                        <strong>
                            {anomaly_count} layer{anomaly_count === 1 ? '' : 's'} need attention.
                        </strong>{' '}
                        Negative stock, value stranded on an empty layer, or goods the books treat as free. They are marked in the table and are never
                        hidden to make the total look tidy.
                    </div>
                )}

                <div className="grid gap-6 md:grid-cols-2">
                    <Summary title="By brand" label="Brand" rows={by_brand.map((b) => ({ name: b.brand, ...b }))} />
                    <Summary title="By warehouse" label="Warehouse" rows={by_warehouse.map((w) => ({ name: w.warehouse, ...w }))} />
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="p-2 text-left">SKU</th>
                                <th className="p-2 text-left">Product</th>
                                <th className="p-2 text-left">Brand</th>
                                <th className="p-2 text-left">Warehouse</th>
                                <th className="p-2 text-left">Batch</th>
                                <th className="p-2 text-right">Quantity</th>
                                <th className="p-2 text-right">Unit cost</th>
                                <th className="p-2 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-4 text-center">
                                        No stock on hand as at {as_at}.
                                    </td>
                                </tr>
                            )}
                            {rows.map((row, i) => (
                                <tr key={i} className={`border-t ${row.anomaly ? 'bg-red-50 dark:bg-red-950' : ''}`}>
                                    <td className="p-2 font-mono text-xs">{row.product_sku}</td>
                                    <td className="p-2">
                                        {row.product_name}
                                        {row.anomaly && <div className="mt-1 text-xs font-medium text-red-700 dark:text-red-300">{row.anomaly}</div>}
                                    </td>
                                    <td className="p-2">{row.brand ?? '-'}</td>
                                    <td className="p-2">{row.warehouse}</td>
                                    <td className="p-2">
                                        {row.batch_number ?? '-'}
                                        {row.expiry_date && <span className="text-muted-foreground block text-xs">expires {row.expiry_date}</span>}
                                    </td>
                                    <td className="p-2 text-right tabular-nums">{row.quantity}</td>
                                    <td className="p-2 text-right tabular-nums">{row.unit_cost ?? '-'}</td>
                                    <td className="p-2 text-right tabular-nums">{row.value}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/50 font-semibold">
                            <tr>
                                <td colSpan={7} className="p-2 text-right">
                                    Total
                                </td>
                                <td className="p-2 text-right tabular-nums">{total}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}

function Summary({ title, label, rows }: { title: string; label: string; rows: { name: string; quantity: string; value: string }[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50">
                    <tr>
                        <th className="p-2 text-left" colSpan={3}>
                            {title}
                        </th>
                    </tr>
                    <tr>
                        <th className="p-2 text-left font-medium">{label}</th>
                        <th className="p-2 text-right font-medium">Quantity</th>
                        <th className="p-2 text-right font-medium">Value</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={3} className="text-muted-foreground p-4 text-center">
                                Nothing to show.
                            </td>
                        </tr>
                    )}
                    {rows.map((row) => (
                        <tr key={row.name} className="border-t">
                            <td className="p-2">{row.name}</td>
                            <td className="p-2 text-right tabular-nums">{row.quantity}</td>
                            <td className="p-2 text-right tabular-nums">{row.value}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
