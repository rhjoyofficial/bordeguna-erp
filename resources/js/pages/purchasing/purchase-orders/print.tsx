import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Order {
    id: number;
    po_number: string;
    expected_date: string | null;
    status: string;
    note: string | null;
    created_at: string;
    supplier: { id: number; name: string; code: string; contact_name: string | null; address: string | null; phone: string | null } | null;
    warehouse: { id: number; code: string; name: string } | null;
    lines: {
        id: number;
        quantity_ordered: string;
        unit_cost: string;
        product: {
            id: number;
            name: string;
            sku: string;
            unit_of_measure: { abbreviation: string } | null;
            purchase_unit: { abbreviation: string } | null;
        } | null;
    }[];
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The purchase order as the supplier receives it.
 *
 * ⚠⚠ THE UNIT IS PRINTED BESIDE EVERY QUANTITY, and it is the PURCHASE unit.
 * Mustard oil is ordered in kilograms while the warehouse holds litres. An
 * order reading "100" with no unit is an invitation to send 100 of the wrong
 * thing, and the money follows the mistake all the way to the bill.
 */
export default function PurchaseOrderPrint({
    order,
    subtotal,
    tax,
    total,
    issuer,
}: {
    order: Order;
    subtotal: string;
    tax: string;
    total: string;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
        { title: order.po_number, href: `/purchasing/purchase-orders/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Purchase order ${order.po_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/purchasing/purchase-orders/${order.id}`}>Back to the order</Link>
                    </Button>
                    <DownloadPdfButton type="purchase-order" id={order.id} />
                    <Button size="sm" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Purchase order" subtitle={order.po_number} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">To</p>
                            <p className="font-medium">{order.supplier?.name}</p>
                            {order.supplier?.contact_name && <p className="text-black/70">Attn: {order.supplier.contact_name}</p>}
                            {order.supplier?.address && <p className="max-w-xs whitespace-pre-line text-black/70">{order.supplier.address}</p>}
                            {order.supplier?.phone && <p className="text-black/70">{order.supplier.phone}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Raised: </span>
                                {order.created_at?.slice(0, 10)}
                            </p>
                            {order.expected_date && (
                                <p>
                                    <span className="text-black/60">Wanted by: </span>
                                    {order.expected_date.slice(0, 10)}
                                </p>
                            )}
                            {order.warehouse && (
                                <p>
                                    <span className="text-black/60">Deliver to: </span>
                                    {order.warehouse.name}
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
                                    <th className="py-2 pr-2 text-right font-medium">Rate</th>
                                    <th className="py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {order.lines.map((line, index) => {
                                    const unit = line.product?.purchase_unit?.abbreviation ?? line.product?.unit_of_measure?.abbreviation ?? '';

                                    return (
                                        <tr key={line.id} className="border-b border-black/10 align-top">
                                            <td className="py-2 pr-2 text-black/60">{index + 1}</td>
                                            <td className="py-2 pr-2">
                                                <p>{line.product?.name ?? 'Item'}</p>
                                                {line.product?.sku && <p className="text-xs text-black/50">{line.product.sku}</p>}
                                            </td>
                                            <td className="py-2 pr-2 text-right">
                                                {quantity(line.quantity_ordered)}
                                                {unit && <span className="text-black/60"> {unit}</span>}
                                            </td>
                                            <td className="py-2 pr-2 text-right">
                                                {money(line.unit_cost)}
                                                {unit && <span className="text-black/60"> /{unit}</span>}
                                            </td>
                                            <td className="py-2 text-right">{money(Number(line.quantity_ordered) * Number(line.unit_cost))}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex justify-end">
                        <dl className="w-64 text-sm">
                            <div className="flex justify-between py-1">
                                <dt className="text-black/60">Subtotal</dt>
                                <dd>{money(subtotal)}</dd>
                            </div>
                            {Number(tax) > 0 && (
                                <div className="flex justify-between py-1">
                                    <dt className="text-black/60">Tax</dt>
                                    <dd>{money(tax)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between border-t border-black/20 py-2 font-semibold">
                                <dt>Order value</dt>
                                <dd>BDT {money(total)}</dd>
                            </div>
                        </dl>
                    </div>

                    {order.note && <p className="mt-6 text-sm whitespace-pre-line text-black/70">{order.note}</p>}

                    <p className="mt-6 text-xs text-black/50">Please quote {order.po_number} on the delivery note and the bill.</p>

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Supplier acceptance</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
