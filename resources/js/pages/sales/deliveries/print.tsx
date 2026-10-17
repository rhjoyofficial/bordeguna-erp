import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { LineBatches, type LineBatch } from '@/components/sales/line-batches';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Delivery {
    id: number;
    delivery_number: string;
    delivered_at: string;
    note: string | null;
    customer: { id: number; name: string; code: string | null; address: string | null; phone: string | null } | null;
    warehouse: { id: number; code: string; name: string } | null;
    lines: {
        id: number;
        quantity: string;
        product: { id: number; name: string; sku: string } | null;
        order_line: {
            id: number;
            description: string | null;
            order: { id: number; order_number: string; special_instructions: string | null } | null;
        } | null;
        /** Owner, 17 Oct 2026: the batches this line took. */
        batches?: LineBatch[];
    }[];
}

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The challan that travels with the goods.
 *
 * ⚠⚠ NO PRICES ANYWHERE, and that is the whole design. A challan states what
 * left the warehouse so the person receiving it can count the boxes and sign;
 * money is the invoice's job. A delivery line does not even carry a price.
 */
export default function DeliveryPrint({ delivery, issuer }: { delivery: Delivery; issuer: Issuer }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Challans', href: '/sales/deliveries' },
        { title: delivery.delivery_number, href: `/sales/deliveries/${delivery.id}/print` },
    ];

    const orders = Array.from(new Set(delivery.lines.map((line) => line.order_line?.order?.order_number).filter(Boolean) as string[]));
    // Owner, 17 Oct 2026: what the order asks of the packer and the rider.
    const instructions = Array.from(new Set(delivery.lines.map((line) => line.order_line?.order?.special_instructions).filter(Boolean) as string[]));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Challan ${delivery.delivery_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/sales/deliveries">Back to deliveries</Link>
                    </Button>
                    <DownloadPdfButton type="delivery" id={delivery.id} />
                    <Button size="sm" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Delivery challan" subtitle={delivery.delivery_number} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">Delivered to</p>
                            <p className="font-medium">{delivery.customer?.name ?? 'Walk-in customer'}</p>
                            {delivery.customer?.code && <p className="text-black/60">{delivery.customer.code}</p>}
                            {delivery.customer?.address && <p className="max-w-xs whitespace-pre-line text-black/70">{delivery.customer.address}</p>}
                            {delivery.customer?.phone && <p className="text-black/70">{delivery.customer.phone}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Date: </span>
                                {delivery.delivered_at}
                            </p>
                            {delivery.warehouse && (
                                <p>
                                    <span className="text-black/60">Dispatched from: </span>
                                    {delivery.warehouse.name}
                                </p>
                            )}
                            {orders.length > 0 && (
                                <p>
                                    <span className="text-black/60">Against order: </span>
                                    {orders.join(', ')}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-6 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y border-black/20 text-left">
                                    <th className="py-2 pr-2 font-medium">#</th>
                                    <th className="py-2 pr-2 font-medium">Item</th>
                                    <th className="py-2 pr-2 text-right font-medium">Quantity</th>
                                    <th className="py-2 text-right font-medium">Received</th>
                                </tr>
                            </thead>
                            <tbody>
                                {delivery.lines.map((line, index) => (
                                    <tr key={line.id} className="border-b border-black/10 align-top">
                                        <td className="py-2 pr-2 text-black/60">{index + 1}</td>
                                        <td className="py-2 pr-2">
                                            <p>{line.product?.name ?? 'Item'}</p>
                                            {line.product?.sku && <p className="text-xs text-black/50">{line.product.sku}</p>}
                                            {line.order_line?.description && <p className="text-xs text-black/70">{line.order_line.description}</p>}
                                            <LineBatches batches={line.batches} />
                                        </td>
                                        <td className="py-2 pr-2 text-right">{quantity(line.quantity)}</td>
                                        {/* Left blank on purpose: the person taking delivery
                                            writes what they actually counted. */}
                                        <td className="py-2 text-right text-black/30">__________</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {instructions.length > 0 && (
                        <p className="mt-6 text-sm whitespace-pre-line">
                            <span className="font-medium">Special instructions: </span>
                            {instructions.join(' / ')}
                        </p>
                    )}

                    {delivery.note && <p className="mt-6 text-sm whitespace-pre-line text-black/70">{delivery.note}</p>}

                    <p className="mt-6 text-xs text-black/50">This challan states what left the warehouse. It is not a bill.</p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Received by, with date</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
