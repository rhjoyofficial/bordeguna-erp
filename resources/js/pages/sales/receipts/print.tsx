import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Receipt {
    id: number;
    received_on: string;
    amount: string;
    reference: string | null;
    note: string | null;
    customer: { id: number; name: string; code: string | null; address: string | null; phone: string | null } | null;
    money_account: { id: number; name: string; type: string } | null;
    allocations: {
        id: number;
        amount: string;
        invoice: { id: number; invoice_number: string; invoice_date: string; total: string } | null;
    }[];
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The money receipt.
 *
 * ⚠⚠ Printed from the RECEIPT, not from an invoice. One payment can settle
 * several bills, and a receipt reached through one of them would name that bill
 * while stating an amount covering three.
 */
export default function ReceiptPrint({
    receipt,
    receivedOn,
    heldFor,
    issuer,
}: {
    receipt: Receipt;
    /* ⚠ Formatted by the server. Slicing the first ten characters of
       what Inertia serialises reads a UTC ISO string, which printed the
       previous day for a Dhaka business. */
    receivedOn: string | null;
    // Money paid for an order that no invoice has taken yet.
    heldFor: { id: number; order_number: string; amount: string } | null;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Receipts', href: '/sales/receipts' },
        { title: 'Money receipt', href: `/sales/receipts/${receipt.id}/print` },
    ];

    const firstInvoice = receipt.allocations[0]?.invoice;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Money receipt ${receipt.id}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={firstInvoice ? `/sales/invoices/${firstInvoice.id}` : heldFor ? `/sales/orders/${heldFor.id}` : '/sales/invoices'}
                        >
                            Back
                        </Link>
                    </Button>
                    <div className="flex items-center gap-2">
                        {/* ⚠ A separate document, not a print stylesheet on this
                            one: an A4 page squeezed to 72mm is not a receipt,
                            it is an A4 page nobody can read. */}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/sales/receipts/${receipt.id}/slip`}>Till roll</Link>
                        </Button>
                        <DownloadPdfButton type="receipt" id={receipt.id} />
                        <Button size="sm" onClick={() => window.print()}>
                            Print
                        </Button>
                    </div>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Money receipt" subtitle={`No. ${receipt.id}`} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">Received from</p>
                            <p className="font-medium">{receipt.customer?.name ?? 'Walk-in customer'}</p>
                            {receipt.customer?.code && <p className="text-black/60">{receipt.customer.code}</p>}
                            {receipt.customer?.phone && <p className="text-black/70">{receipt.customer.phone}</p>}
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Date: </span>
                                {receivedOn}
                            </p>
                            {receipt.money_account && (
                                <p>
                                    <span className="text-black/60">Paid into: </span>
                                    {receipt.money_account.name}
                                </p>
                            )}
                            {receipt.reference && (
                                <p>
                                    <span className="text-black/60">Reference: </span>
                                    {receipt.reference}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="mt-6 rounded-md border border-black/20 p-4">
                        <p className="text-sm text-black/60">Amount received</p>
                        <p className="text-2xl font-semibold">BDT {money(receipt.amount)}</p>
                    </div>

                    {receipt.allocations.length > 0 && (
                        <div className="mt-6">
                            <p className="mb-2 text-sm text-black/60">Settling</p>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-y border-black/20 text-left">
                                        <th className="py-2 pr-2 font-medium">Invoice</th>
                                        <th className="py-2 pr-2 font-medium">Dated</th>
                                        <th className="py-2 pr-2 text-right font-medium">Invoice total</th>
                                        <th className="py-2 text-right font-medium">Applied</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {receipt.allocations.map((allocation) => (
                                        <tr key={allocation.id} className="border-b border-black/10">
                                            <td className="py-2 pr-2">{allocation.invoice?.invoice_number ?? '-'}</td>
                                            <td className="py-2 pr-2">{allocation.invoice?.invoice_date?.slice(0, 10) ?? '-'}</td>
                                            <td className="py-2 pr-2 text-right">{allocation.invoice ? money(allocation.invoice.total) : '-'}</td>
                                            <td className="py-2 text-right">{money(allocation.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {heldFor && (
                        <p className="mt-6 text-sm">
                            {/* ⚠ Only what stays true. Found on the walk: "to be
                                settled against its invoice" printed for an order
                                that was cancelled and paid back - a refund is its
                                own document and does not rewrite this one. */}
                            BDT {money(heldFor.amount)} paid in advance for order {heldFor.order_number}, not applied to any invoice.
                        </p>
                    )}

                    {receipt.note && <p className="mt-6 text-sm whitespace-pre-line text-black/70">{receipt.note}</p>}

                    <div className="mt-16 flex justify-end text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
