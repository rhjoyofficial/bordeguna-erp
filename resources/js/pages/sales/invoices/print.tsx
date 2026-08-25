import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    subtotal: string;
    invoice_discount: string;
    tax_total: string;
    total: string;
    note: string | null;
    customer: { id: number; name: string; code: string | null; address: string | null; phone: string | null } | null;
    lines: {
        id: number;
        description: string | null;
        quantity: string;
        unit_price: string;
        line_discount: string;
        line_net: string;
        product: { id: number; name: string; sku: string } | null;
    }[];
}

// ⚠ The server sends decimal strings. Formatted for reading, never re-computed
// here: a total the paper worked out for itself is a total that can disagree
// with the ledger.
const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

export default function InvoicePrint({
    invoice,
    outstanding,
    received,
    issuer,
}: {
    invoice: Invoice;
    outstanding: string;
    received: string;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/invoices/${invoice.id}`}>Back to the invoice</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Invoice" subtitle={invoice.invoice_number} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">Billed to</p>
                            <p className="font-medium">{invoice.customer?.name ?? 'Walk-in customer'}</p>
                            {invoice.customer?.code && <p className="text-black/60">{invoice.customer.code}</p>}
                            {invoice.customer?.address && <p className="max-w-xs whitespace-pre-line text-black/70">{invoice.customer.address}</p>}
                            {invoice.customer?.phone && <p className="text-black/70">{invoice.customer.phone}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Invoice date: </span>
                                {invoice.invoice_date}
                            </p>
                            {invoice.due_date && (
                                <p>
                                    <span className="text-black/60">Payment due: </span>
                                    {invoice.due_date}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-6 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y border-black/20 text-left">
                                    <th className="py-2 pr-2 font-medium">Item</th>
                                    <th className="py-2 pr-2 text-right font-medium">Qty</th>
                                    <th className="py-2 pr-2 text-right font-medium">Rate</th>
                                    <th className="py-2 pr-2 text-right font-medium">Discount</th>
                                    <th className="py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines.map((line) => (
                                    <tr key={line.id} className="border-b border-black/10 align-top">
                                        <td className="py-2 pr-2">
                                            <p>{line.product?.name ?? line.description ?? 'Item'}</p>
                                            {line.product?.sku && <p className="text-xs text-black/50">{line.product.sku}</p>}
                                        </td>
                                        <td className="py-2 pr-2 text-right">{quantity(line.quantity)}</td>
                                        <td className="py-2 pr-2 text-right">{money(line.unit_price)}</td>
                                        <td className="py-2 pr-2 text-right">{money(line.line_discount)}</td>
                                        <td className="py-2 text-right">{money(line.line_net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex justify-end">
                        <dl className="w-64 text-sm">
                            <div className="flex justify-between py-1">
                                <dt className="text-black/60">Subtotal</dt>
                                <dd>{money(invoice.subtotal)}</dd>
                            </div>
                            {Number(invoice.invoice_discount) > 0 && (
                                <div className="flex justify-between py-1">
                                    <dt className="text-black/60">Invoice discount</dt>
                                    <dd>{money(invoice.invoice_discount)}</dd>
                                </div>
                            )}
                            {/* ⚠ Shown only when there is tax. The business is not VAT
                                registered, and a "VAT 0.00" line on a customer's paper
                                invites a question with no good answer. */}
                            {Number(invoice.tax_total) > 0 && (
                                <div className="flex justify-between py-1">
                                    <dt className="text-black/60">Tax</dt>
                                    <dd>{money(invoice.tax_total)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between border-t border-black/20 py-2 font-semibold">
                                <dt>Total</dt>
                                <dd>BDT {money(invoice.total)}</dd>
                            </div>
                            {Number(received) > 0 && (
                                <>
                                    <div className="flex justify-between py-1">
                                        <dt className="text-black/60">Received</dt>
                                        <dd>{money(received)}</dd>
                                    </div>
                                    <div className="flex justify-between border-t border-black/20 py-2 font-semibold">
                                        <dt>Balance due</dt>
                                        <dd>BDT {money(outstanding)}</dd>
                                    </div>
                                </>
                            )}
                        </dl>
                    </div>

                    {invoice.note && <p className="mt-6 text-sm whitespace-pre-line text-black/70">{invoice.note}</p>}

                    <div className="mt-16 flex justify-between text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">Received by</div>
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
