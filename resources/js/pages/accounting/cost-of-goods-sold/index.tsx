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
    quantity: string;
    cost: string;
    revenue: string;
    gross_profit: string;
    margin_percent: string | null;
}

export default function CostOfGoodsSoldIndex({
    from,
    to,
    rows,
    by_brand,
    totals,
    control,
}: {
    from: string;
    to: string;
    rows: Row[];
    by_brand: { brand: string; cost: string; revenue: string; gross_profit: string }[];
    totals: { cost: string; revenue: string; gross_profit: string };
    control: {
        account: string | null;
        balance: string | null;
        has_account: boolean;
        ties_out: boolean;
        difference: string;
    };
}) {
    const [start, setStart] = useState(from);
    const [end, setEnd] = useState(to);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounting', href: '/accounting/journals' },
        { title: 'Cost of goods sold', href: route('accounting.cost-of-goods-sold') },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.cost-of-goods-sold'), { from: start, to: end }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cost of goods sold" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <form onSubmit={submit} className="flex items-end gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="from">From</Label>
                            <Input id="from" type="date" value={start} onChange={(e) => setStart(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="to">To</Label>
                            <Input id="to" type="date" value={end} onChange={(e) => setEnd(e.target.value)} />
                        </div>
                        <Button type="submit">Show</Button>
                    </form>
                </div>

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
                                Agrees with <strong>{control.account}</strong>, which moved <strong>{control.balance}</strong> in this period.
                            </span>
                        ) : (
                            <span>
                                <strong>Does not agree with the ledger.</strong> {control.account} moved <strong>{control.balance}</strong> in this
                                period, these documents total <strong>{totals.cost}</strong>, a difference of <strong>{control.difference}</strong>.
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950">
                        This business has no <strong>Cost of Goods Sold</strong> account mapped, so there is nothing to check these figures against.
                    </div>
                )}

                {by_brand.length > 0 && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="p-2 text-left" colSpan={4}>
                                        By brand
                                    </th>
                                </tr>
                                <tr>
                                    <th className="p-2 text-left font-medium">Brand</th>
                                    <th className="p-2 text-right font-medium">Revenue</th>
                                    <th className="p-2 text-right font-medium">Cost</th>
                                    <th className="p-2 text-right font-medium">Gross profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {by_brand.map((b) => (
                                    <tr key={b.brand} className="border-t">
                                        <td className="p-2">{b.brand}</td>
                                        <td className="p-2 text-right tabular-nums">{b.revenue}</td>
                                        <td className="p-2 text-right tabular-nums">{b.cost}</td>
                                        <td className="p-2 text-right tabular-nums">{b.gross_profit}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="p-2 text-left">SKU</th>
                                <th className="p-2 text-left">Product</th>
                                <th className="p-2 text-left">Brand</th>
                                <th className="p-2 text-right">Quantity</th>
                                <th className="p-2 text-right">Revenue</th>
                                <th className="p-2 text-right">Cost</th>
                                <th className="p-2 text-right">Gross profit</th>
                                <th className="p-2 text-right">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground p-4 text-center">
                                        Nothing was sold between {from} and {to}.
                                    </td>
                                </tr>
                            )}
                            {rows.map((row) => (
                                <tr key={row.product_sku} className="border-t">
                                    <td className="p-2 font-mono text-xs">{row.product_sku}</td>
                                    <td className="p-2">{row.product_name}</td>
                                    <td className="p-2">{row.brand ?? '-'}</td>
                                    <td className="p-2 text-right tabular-nums">{row.quantity}</td>
                                    <td className="p-2 text-right tabular-nums">{row.revenue}</td>
                                    <td className="p-2 text-right tabular-nums">{row.cost}</td>
                                    <td className="p-2 text-right tabular-nums">{row.gross_profit}</td>
                                    {/*
                                      ⚠ An em dash, not "0.0%". Until prices are
                                      loaded nothing has revenue, and a zero
                                      margin everywhere is a number people learn
                                      to ignore.
                                    */}
                                    <td className="p-2 text-right tabular-nums">{row.margin_percent === null ? '-' : `${row.margin_percent}%`}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="bg-muted/50 font-semibold">
                            <tr>
                                <td colSpan={4} className="p-2 text-right">
                                    Total
                                </td>
                                <td className="p-2 text-right tabular-nums">{totals.revenue}</td>
                                <td className="p-2 text-right tabular-nums">{totals.cost}</td>
                                <td className="p-2 text-right tabular-nums">{totals.gross_profit}</td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
