import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface ProductRow {
    product: string | null;
    sku: string | null;
    runs: number;
    output_quantity: string;
    consumed_value: string;
    waste_value: string;
    waste_percent: number | null;
    flagged_runs: number;
}

interface YieldCheck {
    checked: boolean;
    deviation_percent?: number;
    tolerance_percent?: number;
    content_unit?: string | null;
}

interface RunRow {
    id: number;
    run_number: string;
    occurred_at: string | null;
    warehouse: string | null;
    product: string | null;
    output_quantity: string;
    consumed_value: string | null;
    waste_value: string | null;
    expense_waste: boolean;
    yield_check: YieldCheck | null;
    flagged: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Production yield', href: '/inventory/production-yield' },
];

const money = (value: string | null) =>
    value === null ? '-' : Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function ProductionYield({ month, products, runs }: { month: string; products: ProductRow[]; runs: RunRow[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Production Yield" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Production yield</h1>
                        <p className="text-muted-foreground text-sm">
                            Where a drifting yield gets noticed. Waste percentages here are value-based: the only ratio that adds up honestly across
                            kilograms, litres and pieces. Per-unit figures live on each run.
                        </p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="month">Month</Label>
                        <Input
                            id="month"
                            type="month"
                            value={month}
                            className="w-44"
                            onChange={(e) => router.get('/inventory/production-yield', { month: e.target.value }, { preserveState: true })}
                        />
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Output product</th>
                                <th className="p-3 text-right">Runs</th>
                                <th className="p-3 text-right">Output produced</th>
                                <th className="p-3 text-right">Consumed value</th>
                                <th className="p-3 text-right">Waste value</th>
                                <th className="p-3 text-right">Waste % (by value)</th>
                                <th className="p-3 text-right">Flagged runs</th>
                            </tr>
                        </thead>
                        <tbody>
                            {products.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        No confirmed production runs in this month.
                                    </td>
                                </tr>
                            )}
                            {products.map((row, index) => (
                                <tr key={index} className="border-t">
                                    <td className="p-3 font-medium">
                                        {row.product} <span className="text-muted-foreground text-xs">{row.sku}</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{row.runs}</td>
                                    <td className="p-3 text-right tabular-nums">{row.output_quantity}</td>
                                    <td className="p-3 text-right tabular-nums">{row.consumed_value}</td>
                                    <td className="p-3 text-right tabular-nums">{row.waste_value}</td>
                                    <td className="p-3 text-right tabular-nums">{row.waste_percent === null ? '-' : `${row.waste_percent}%`}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {row.flagged_runs > 0 ? <Badge variant="destructive">{row.flagged_runs}</Badge> : '0'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {runs.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Runs in {month}</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Run</th>
                                        <th className="p-3">Date</th>
                                        <th className="p-3">Output</th>
                                        <th className="p-3 text-right">Quantity</th>
                                        <th className="p-3 text-right">Consumed value</th>
                                        <th className="p-3 text-right">Waste value</th>
                                        <th className="p-3 text-right">Yield deviation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => (
                                        <tr key={run.id} className="border-t">
                                            <td className="p-3">
                                                <Link
                                                    href={`/inventory/production-runs/${run.id}`}
                                                    className="text-primary font-medium hover:underline"
                                                >
                                                    {run.run_number}
                                                </Link>
                                            </td>
                                            <td className="p-3">{run.occurred_at}</td>
                                            <td className="p-3">{run.product}</td>
                                            <td className="p-3 text-right tabular-nums">{run.output_quantity}</td>
                                            <td className="p-3 text-right tabular-nums">{money(run.consumed_value)}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                {money(run.waste_value)}
                                                {run.expense_waste && (
                                                    <Badge variant="outline" className="ml-2">
                                                        expensed
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {run.yield_check?.checked ? (
                                                    <span className={run.flagged ? 'text-destructive font-medium' : ''}>
                                                        {run.yield_check.deviation_percent}%
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">not checked</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
