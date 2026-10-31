import { Pagination } from '@/components/data-table/pagination';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { formatQuantity } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface RunRow {
    id: number;
    run_number: string;
    status: string;
    warehouse: string | null;
    output_product: string | null;
    output_sku: string | null;
    output_quantity: string;
    consumed_value: string | null;
    waste_value: string | null;
    expense_waste: boolean;
    yield_flagged: boolean;
    occurred_at: string | null;
}

const breadcrumbs = inventoryCrumbs('Production', 'Runs');

const money = (value: string | null) =>
    value === null ? '-' : Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive'> = {
    draft: 'secondary',
    confirmed: 'default',
    reversed: 'destructive',
};

export default function ProductionRunsIndex({ runs, filters }: { runs: Paginated<RunRow>; filters: { status: string } }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Production runs" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchSelect
                            value={filters.status}
                            onChange={(e) =>
                                router.get('/inventory/production-runs', e.target.value ? { status: e.target.value } : {}, {
                                    preserveState: true,
                                })
                            }
                        >
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="reversed">Reversed</option>
                        </SearchSelect>
                        <Button asChild>
                            <Link href="/inventory/production-runs/new">New run</Link>
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="p-3 font-medium">Run</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Warehouse</th>
                                <th className="p-3">Output</th>
                                <th className="p-3 text-right">Quantity</th>
                                <th className="p-3 text-right">Consumed value</th>
                                <th className="p-3 text-right">Waste value</th>
                                <th className="p-3">Yield</th>
                                <th className="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                        No production runs yet. The first one is the moment the gap between the drum and the pack closes.
                                    </td>
                                </tr>
                            )}
                            {runs.data.map((run) => (
                                <tr key={run.id} className="border-t">
                                    <td className="p-3">
                                        <Link href={`/inventory/production-runs/${run.id}`} className="text-primary font-medium hover:underline">
                                            {run.run_number}
                                        </Link>
                                    </td>
                                    <td className="p-3">{formatDate(run.occurred_at)}</td>
                                    <td className="p-3">{run.warehouse}</td>
                                    <td className="p-3">
                                        {run.output_product}
                                        <span className="text-muted-foreground ml-1 text-xs">{run.output_sku}</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(run.output_quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(run.consumed_value)}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {money(run.waste_value)}
                                        {run.expense_waste && (
                                            <Badge variant="outline" className="ml-2">
                                                expensed
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{run.yield_flagged ? <Badge variant="destructive">deviation</Badge> : '-'}</td>
                                    <td className="p-3">
                                        <Badge variant={STATUS_VARIANT[run.status] ?? 'secondary'}>{run.status}</Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={runs} />
            </div>
        </AppLayout>
    );
}
