import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Item {
    id: number;
    status: 'pending' | 'working' | 'booked' | 'refused' | 'unknown' | 'fix_address';
    message: string | null;
    order_id: number;
    order_number: string | null;
    shop_reference: string | null;
    recipient: string | null;
    place: string;
    challan: string | null;
    account: string | null;
    consignment_id: number | null;
    consignment_number: string | null;
    fee_quoted: string | null;
    cod_amount: number | null;
}

interface Run {
    id: number;
    status: string;
    total: number;
    done: number;
    booked: number;
    refused: number;
    unknown: number;
    fix_address: number;
    error: string | null;
    account: string | null;
    warehouse: string | null;
    by: string | null;
    at: string | null;
    finished: boolean;
    items: Item[];
}

const LABEL: Record<Item['status'], string> = {
    pending: 'waiting',
    working: 'working',
    booked: 'booked',
    refused: 'refused',
    unknown: 'no clear answer',
    fix_address: 'fix address',
};

const TONE: Record<Item['status'], 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'outline',
    working: 'secondary',
    booked: 'default',
    refused: 'destructive',
    unknown: 'destructive',
    fix_address: 'secondary',
};

/**
 * One bulk booking, filling in as the queued job answers each order. The page
 * re-reads itself on the run's own broadcast; while the run is going it also
 * asks the progress endpoint every two seconds, so it moves without a socket.
 */
export default function BulkRun({ run: initial, can_manage_locations }: { run: Run; can_manage_locations: boolean }) {
    const [run, setRun] = useState<Run>(initial);

    useEffect(() => setRun(initial), [initial]);

    useEffect(() => {
        if (run.finished) return;

        const timer = window.setInterval(async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(route('sales.parcels-to-book.runs.progress', run.id), { headers: { Accept: 'application/json' } });
                if (response.ok) setRun(await response.json());
            } catch {
                // The next tick tries again.
            }
        }, 2000);

        return () => window.clearInterval(timer);
    }, [run.id, run.finished]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/orders' },
        { title: 'Parcels to book', href: '/sales/parcels-to-book' },
        { title: `Run #${run.id}`, href: `/sales/parcels-to-book/runs/${run.id}` },
    ];

    const percent = run.total === 0 ? 100 : Math.round((run.done / run.total) * 100);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bulk booking #${run.id}`} />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Bulk booking #{run.id}</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {run.at}, {run.by ?? 'someone'}. Courier: {run.account ?? "each order's default"}. Leaves from:{' '}
                        {run.warehouse ?? "each order's warehouse"}.
                    </p>
                </div>

                <div className="flex flex-col gap-2" aria-live="polite">
                    <div className="bg-muted h-2 w-full max-w-xl overflow-hidden rounded-full">
                        <div className="bg-primary h-full transition-all duration-500" style={{ width: `${percent}%` }} />
                    </div>
                    <p className="text-sm tabular-nums">
                        {run.done} of {run.total} answered: {run.booked} booked, {run.refused} refused, {run.unknown} with no clear answer,{' '}
                        {run.fix_address} to fix. {run.finished ? (run.status === 'failed' ? 'The run stopped.' : 'Finished.') : 'Working...'}
                    </p>
                    {run.error && <p className="text-destructive text-sm">{run.error}</p>}
                </div>

                <div className="flex flex-wrap gap-2">
                    {run.booked > 0 && (
                        <>
                            <Button variant="outline" asChild>
                                <a href={route('sales.parcels-to-book.labels', { run: run.id, per_page: 4 })} target="_blank" rel="noreferrer">
                                    Print labels, 4 per page
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('sales.parcels-to-book.labels', { run: run.id, per_page: 2 })} target="_blank" rel="noreferrer">
                                    Print labels, 2 per page
                                </a>
                            </Button>
                        </>
                    )}
                    <Button variant="ghost" asChild>
                        <Link href={route('sales.parcels-to-book.index')}>Back to parcels to book</Link>
                    </Button>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2 pr-3">Order</th>
                                <th className="py-2 pr-3">Recipient</th>
                                <th className="py-2 pr-3">Answer</th>
                                <th className="py-2 pr-3">Challan</th>
                                <th className="py-2 pr-3">Consignment</th>
                                <th className="py-2 pr-3 text-right">COD</th>
                                <th className="py-2 pr-3 text-right">Fee quoted</th>
                                <th className="py-2">Why</th>
                            </tr>
                        </thead>
                        <tbody>
                            {run.items.map((item) => (
                                <tr key={item.id} className="border-sidebar-border/40 border-b align-top">
                                    <td className="py-2 pr-3">
                                        <Link href={route('sales.orders.show', item.order_id)} className="underline underline-offset-2">
                                            {item.order_number}
                                        </Link>
                                        {item.shop_reference && <div className="text-muted-foreground font-mono text-xs">{item.shop_reference}</div>}
                                    </td>
                                    <td className="py-2 pr-3">
                                        {item.recipient}
                                        <div className="text-muted-foreground text-xs">{item.place}</div>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Badge variant={TONE[item.status]}>{LABEL[item.status]}</Badge>
                                        {item.account && <div className="text-muted-foreground text-xs">{item.account}</div>}
                                    </td>
                                    <td className="py-2 pr-3">{item.challan ?? '-'}</td>
                                    <td className="py-2 pr-3 font-mono">
                                        {item.consignment_number ?? '-'}
                                        {item.consignment_id !== null && (
                                            <div>
                                                <a
                                                    className="text-xs underline underline-offset-2"
                                                    href={route('sales.parcels-to-book.labels', { ids: [item.consignment_id], per_page: 4 })}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    label
                                                </a>
                                            </div>
                                        )}
                                    </td>
                                    <td className="py-2 pr-3 text-right tabular-nums">
                                        {item.cod_amount !== null ? formatMoney(item.cod_amount) : '-'}
                                    </td>
                                    <td className="py-2 pr-3 text-right tabular-nums">
                                        {item.fee_quoted !== null ? formatMoney(item.fee_quoted) : '-'}
                                    </td>
                                    <td className="py-2">
                                        {item.message}
                                        {item.status === 'fix_address' && can_manage_locations && (
                                            <>
                                                {' '}
                                                <Link href={route('couriers.settings.locations.index')} className="underline underline-offset-2">
                                                    Couriers &gt; Locations
                                                </Link>
                                            </>
                                        )}
                                        {item.status === 'unknown' && (
                                            <>
                                                {' '}
                                                <Link href={route('sales.orders.show', item.order_id)} className="underline underline-offset-2">
                                                    Settle on the order
                                                </Link>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
