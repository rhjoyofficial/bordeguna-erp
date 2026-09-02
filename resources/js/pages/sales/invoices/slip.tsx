import { type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The thermal slip (POS spec section 19): 80mm of what the counter customer
 * walks away with. Every figure came from the server; the barcode carries the
 * invoice number the returns screen accepts, so a scan closes the loop.
 * Reprint is simply visiting this page again.
 */
interface SlipInvoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    subtotal: string;
    invoice_discount: string;
    total: string;
    created_at: string;
    customer: { id: number; name: string; phone: string | null } | null;
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

interface Payment {
    account: string | null;
    amount: string;
    received_on: string | null;
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

export default function InvoiceSlip({
    invoice,
    outstanding,
    payments,
    soldBy,
    issuer,
    barcodeSvg,
}: {
    invoice: SlipInvoice;
    outstanding: string;
    payments: Payment[];
    soldBy: string | null;
    issuer: Issuer;
    barcodeSvg: string;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Slip ${invoice.invoice_number}`} />
            {/* The paper is 80mm; the printable column is ~72mm. Zero page
                margin - thermal printers feed continuous paper. */}
            <style>{'@media print { @page { size: 80mm auto; margin: 0 } body { margin: 0 } }'}</style>

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2 print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/invoices/${invoice.id}`}>Back to the invoice</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print slip
                    </Button>
                </div>

                <div className="official-document mx-auto w-[72mm] bg-white px-1 py-2 font-mono text-[11px] leading-snug text-black print:mx-0">
                    <div className="text-center">
                        <p className="text-[13px] font-bold">{issuer.name}</p>
                        {issuer.address && <p className="whitespace-pre-line">{issuer.address}</p>}
                        {issuer.phone && <p>{issuer.phone}</p>}
                    </div>

                    <div className="my-1 border-t border-dashed border-black" />

                    <p>
                        {invoice.invoice_number}
                        <span className="float-right">{invoice.invoice_date}</span>
                    </p>
                    {invoice.customer && !invoice.customer.name.toLowerCase().includes('walk-in') && <p>Customer: {invoice.customer.name}</p>}
                    {soldBy && <p>Sold by: {soldBy}</p>}

                    <div className="my-1 border-t border-dashed border-black" />

                    {invoice.lines.map((line) => (
                        <div key={line.id}>
                            <p>{line.product?.name ?? line.description ?? 'Item'}</p>
                            <p className="pl-2">
                                {quantity(line.quantity)} x {money(line.unit_price)}
                                {Number(line.line_discount) > 0 && ` less ${money(line.line_discount)}`}
                                <span className="float-right">{money(line.line_net)}</span>
                            </p>
                        </div>
                    ))}

                    <div className="my-1 border-t border-dashed border-black" />

                    <p>
                        Subtotal<span className="float-right">{money(invoice.subtotal)}</span>
                    </p>
                    {Number(invoice.invoice_discount) > 0 && (
                        <p>
                            Discount<span className="float-right">-{money(invoice.invoice_discount)}</span>
                        </p>
                    )}
                    <p className="text-[13px] font-bold">
                        TOTAL<span className="float-right">BDT {money(invoice.total)}</span>
                    </p>

                    {payments.map((payment, index) => (
                        <p key={index}>
                            Paid ({payment.account ?? 'cash'})<span className="float-right">{money(payment.amount)}</span>
                        </p>
                    ))}
                    {Number(outstanding) > 0 && (
                        <p className="font-bold">
                            DUE<span className="float-right">BDT {money(outstanding)}</span>
                        </p>
                    )}

                    <div className="my-1 border-t border-dashed border-black" />

                    {/* The invoice number as Code 128: scan it into the returns
                        screen and the sale opens itself. */}
                    <div className="flex justify-center py-1" dangerouslySetInnerHTML={{ __html: barcodeSvg }} />
                    <p className="text-center">{invoice.invoice_number}</p>

                    <p className="mt-1 text-center">Thank you for shopping with us.</p>
                    <p className="text-center">Keep this slip for returns or exchange.</p>
                </div>
            </div>
        </AppLayout>
    );
}
