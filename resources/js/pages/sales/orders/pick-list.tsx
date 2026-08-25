import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Batch {
    batch_number: string | null;
    expiry_date: string | null;
    take: number;
    available: number;
}

interface Line {
    id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    ordered: number;
    delivered: number;
    to_pick: number;
    on_hand: number;
    shortfall: number;
    batches: Batch[];
}

interface Order {
    id: number;
    order_number: string;
    ordered_at: string | null;
    expected_date: string | null;
    status: string;
    note: string | null;
    customer: { id: number; name: string; code: string | null; address: string | null; phone: string | null } | null;
    warehouse: { id: number; name: string; code: string } | null;
}

const quantity = (value: number) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The picking sheet.
 *
 * ⚠⚠ No prices anywhere. This paper goes to the shelf, not to the customer, and
 * a rate on it is an invitation to hand it over with the goods.
 */
export default function PickList({
    order,
    lines,
    nothing_to_pick,
    issuer,
}: {
    order: Order;
    lines: Line[];
    nothing_to_pick: boolean;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales orders', href: '/sales/orders' },
        { title: order.order_number, href: `/sales/orders/${order.id}` },
        { title: 'Pick list', href: `/sales/orders/${order.id}/pick-list` },
    ];

    const short = lines.filter((line) => line.shortfall > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pick list ${order.order_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/orders/${order.id}`}>Back to the order</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                {short.length > 0 && (
                    <div className="mx-auto w-full max-w-3xl rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm print:hidden">
                        {/* Said on screen before anybody walks to the shelf, and
                            again on the paper itself, line by line. */}
                        {short.length} line{short.length === 1 ? '' : 's'} cannot be filled from {order.warehouse?.name ?? 'this warehouse'} today.
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Pick list" subtitle={order.order_number} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">For</p>
                            <p className="font-medium">{order.customer?.name ?? 'Walk-in customer'}</p>
                            {order.customer?.code && <p className="text-black/60">{order.customer.code}</p>}
                            {order.customer?.address && <p className="max-w-xs whitespace-pre-line text-black/70">{order.customer.address}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Pick from: </span>
                                {order.warehouse?.name ?? 'not set'}
                            </p>
                            {order.ordered_at && (
                                <p>
                                    <span className="text-black/60">Ordered: </span>
                                    {order.ordered_at}
                                </p>
                            )}
                            {order.expected_date && (
                                <p>
                                    <span className="text-black/60">Wanted by: </span>
                                    {order.expected_date}
                                </p>
                            )}
                        </div>
                    </div>

                    {nothing_to_pick ? (
                        <p className="mt-6 text-sm">Everything on this order has already been delivered. Nothing to pick.</p>
                    ) : (
                        <div className="mt-6 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-y border-black/20 text-left">
                                        <th className="py-2 pr-2 font-medium">#</th>
                                        <th className="py-2 pr-2 font-medium">Item</th>
                                        <th className="py-2 pr-2 text-right font-medium">Pick</th>
                                        <th className="py-2 pr-2 text-right font-medium">In stock</th>
                                        <th className="py-2 text-right font-medium">Picked</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines
                                        .filter((line) => line.to_pick > 0)
                                        .map((line, index) => (
                                            <tr key={line.id} className="border-b border-black/10 align-top">
                                                <td className="py-2 pr-2 text-black/60">{index + 1}</td>
                                                <td className="py-2 pr-2">
                                                    <p>{line.product}</p>
                                                    {line.sku && <p className="text-xs text-black/50">{line.sku}</p>}
                                                    {/* The batches the system itself will consume, in
                                                        the order it will consume them: oldest expiry
                                                        first. Picking a different box makes the shelf
                                                        and the books disagree. */}
                                                    {line.batches.length > 0 && (
                                                        <ul className="mt-1 text-xs text-black/60">
                                                            {line.batches.map((batch, position) => (
                                                                <li key={`${line.id}-${position}`}>
                                                                    {batch.batch_number ?? 'no batch number'}
                                                                    {batch.expiry_date && ` · expires ${batch.expiry_date}`} · take{' '}
                                                                    {quantity(batch.take)}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                    {line.shortfall > 0 && (
                                                        <p className="mt-1 text-xs font-medium">
                                                            Short by {quantity(line.shortfall)} {line.unit}
                                                        </p>
                                                    )}
                                                    {line.delivered > 0 && (
                                                        <p className="text-xs text-black/50">
                                                            {quantity(line.delivered)} already delivered of {quantity(line.ordered)}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-2 text-right">
                                                    {quantity(line.to_pick)}
                                                    {line.unit && <span className="text-black/60"> {line.unit}</span>}
                                                </td>
                                                <td className="py-2 pr-2 text-right">{quantity(line.on_hand)}</td>
                                                {/* Left blank on purpose: the picker writes what they
                                                    actually took off the shelf. */}
                                                <td className="py-2 text-right text-black/30">__________</td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {order.note && <p className="mt-6 text-sm whitespace-pre-line text-black/70">{order.note}</p>}

                    <p className="mt-6 text-xs text-black/50">
                        Stock shown as it stood when this was printed. Nothing here is reserved, so a counter sale of the same goods can still get
                        there first. Record the delivery to make the picking final.
                    </p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Picked by, with date</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Checked by</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
