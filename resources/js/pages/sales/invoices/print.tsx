import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Invoice {
    id: number;
    invoice_number: string;
    /** Already formatted by the server as "05 Sep 2026". Never a raw timestamp. */
    invoice_date: string | null;
    due_date: string | null;
    is_overdue: boolean;
    subtotal: string;
    invoice_discount: string;
    tax_total: string;
    total: string;
    /** Frozen on the invoice. `taxed` is false for every invoice raised while VAT is off. */
    vat: { taxed: boolean; bin: string | null; prices_include_tax: boolean; value_excluding_vat: string };
    note: string | null;
    customer: { name: string; code: string | null; address: string | null; phone: string | null; email: string | null } | null;
    lines: {
        id: number;
        description: string | null;
        quantity: string;
        unit_price: string;
        line_discount: string;
        line_net: string;
        tax_rate: string;
        tax_amount: string;
        product: { name: string; sku: string } | null;
    }[];
}

// ⚠ The server sends decimal strings. Formatted for reading, never re-computed
// here: a total the paper worked out for itself is a total that can disagree
// with the ledger.
const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

/**
 * The status stamp, and the only thing on this document that is an opinion.
 *
 * ⚠ Derived from what has been received, not stored, because a stored status is
 * a second version of the truth that drifts the moment a receipt is recorded.
 */
function statusOf(invoice: Invoice, received: string, outstanding: string): { label: string; tone: string } {
    if (Number(outstanding) <= 0) {
        return { label: 'PAID', tone: 'border-black text-black' };
    }

    if (Number(received) > 0) {
        return { label: 'PART PAID', tone: 'border-black/50 text-black/70' };
    }

    if (invoice.is_overdue) {
        return { label: 'OVERDUE', tone: 'border-black text-black' };
    }

    return { label: 'UNPAID', tone: 'border-black/50 text-black/70' };
}

/**
 * The customer's invoice.
 *
 * ⭐ Built to what a commercial invoice is expected to carry anywhere: who is
 * billing, who is billed, an invoice number and date, payment terms, itemised
 * lines with rate and amount, a totals block that reconciles, the amount in
 * words, how to pay, and a place to sign. Anything missing from that list is
 * something a customer's accounts department has to telephone about.
 *
 * ⚠⚠ Dates arrive already formatted. This page previously printed
 * `invoice.invoice_date` straight from the model, which Inertia serialised as
 * "2026-09-04T18:00:00.000000Z" - unreadable, and a day earlier than the
 * invoice actually was, because the cast renders in UTC and the date is a Dhaka
 * date. The document was quietly wrong, not merely ugly.
 */
export default function InvoicePrint({
    invoice,
    outstanding,
    received,
    total_in_words,
    issuer,
}: {
    invoice: Invoice;
    outstanding: string;
    received: string;
    total_in_words: string;
    issuer: Issuer;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Invoices', href: '/sales/invoices' },
        { title: invoice.invoice_number, href: `/sales/invoices/${invoice.id}` },
    ];

    const status = statusOf(invoice, received, outstanding);
    const hasPayments = Number(received) > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            {/* A4 with real margins, and a table header that repeats when an
                invoice runs past one page. Without the repeat, page two is a
                column of numbers with nothing saying what they are. */}
            <style>{`
                @media print {
                    @page { size: A4; margin: 14mm 12mm; }
                    thead { display: table-header-group; }
                    tr { break-inside: avoid; }
                    .doc-footer { position: fixed; bottom: 0; left: 0; right: 0; }
                }
            `}</style>

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/sales/invoices/${invoice.id}`}>Back to the invoice</Link>
                    </Button>
                    <div className="flex items-center gap-3">
                        {/* ⚠ One action, honestly labelled. The browser's print dialog is
                            where "Save as PDF" lives, and it is the route that shapes
                            Bengali product names correctly - a server-rendered PDF here
                            would draw the glyphs but not join the conjuncts. */}
                        <span className="text-muted-foreground hidden text-xs sm:inline">Choose “Save as PDF” in the dialog to download</span>
                        <DownloadPdfButton type="invoice" id={invoice.id} />
                        <Button size="sm" onClick={() => window.print()}>
                            Print
                        </Button>
                    </div>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-6 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Invoice" subtitle={invoice.invoice_number} />

                    {/* ── Invoice meta and the parties ───────────────────── */}
                    <div className="mt-4 grid gap-6 sm:grid-cols-2">
                        <div>
                            <p className="text-[11px] tracking-widest text-black/50 uppercase">Billed to</p>
                            <p className="mt-1 font-semibold">{invoice.customer?.name ?? 'Walk-in customer'}</p>
                            {invoice.customer?.code && <p className="text-xs text-black/50">{invoice.customer.code}</p>}
                            {invoice.customer?.address && (
                                <p className="mt-1 max-w-xs text-sm whitespace-pre-line text-black/70">{invoice.customer.address}</p>
                            )}
                            {invoice.customer?.phone && <p className="text-sm text-black/70">{invoice.customer.phone}</p>}
                            {invoice.customer?.email && <p className="text-sm text-black/70">{invoice.customer.email}</p>}
                        </div>

                        <div className="sm:text-right">
                            <table className="ml-auto text-sm">
                                <tbody>
                                    <tr>
                                        <td className="pr-4 text-black/55">Invoice no.</td>
                                        <td className="font-medium">{invoice.invoice_number}</td>
                                    </tr>
                                    <tr>
                                        <td className="pr-4 text-black/55">Invoice date</td>
                                        <td>{invoice.invoice_date ?? '-'}</td>
                                    </tr>
                                    {invoice.due_date && (
                                        <tr>
                                            <td className="pr-4 text-black/55">Payment due</td>
                                            <td>{invoice.due_date}</td>
                                        </tr>
                                    )}
                                    <tr>
                                        <td className="pr-4 text-black/55">Currency</td>
                                        <td>BDT (৳)</td>
                                    </tr>
                                    {/* A tax invoice names the seller's registration. */}
                                    {invoice.vat.taxed && invoice.vat.bin && (
                                        <tr>
                                            <td className="pr-4 text-black/55">Seller BIN</td>
                                            <td className="font-medium">{invoice.vat.bin}</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                            <span className={`mt-3 inline-block border px-3 py-1 text-xs tracking-widest uppercase ${status.tone}`}>
                                {status.label}
                            </span>
                        </div>
                    </div>

                    {/* ── The lines ──────────────────────────────────────── */}
                    <table className="mt-4 w-full border-collapse text-[11px]">
                        <thead>
                            <tr className="bg-black/[0.03] text-left">
                                <th className="w-8 border border-black/25 px-2 py-1 font-medium">#</th>
                                <th className="border border-black/25 px-2 py-1 font-medium">Description</th>
                                <th className="w-16 border border-black/25 px-2 py-1 text-right font-medium">Qty</th>
                                <th className="w-24 border border-black/25 px-2 py-1 text-right font-medium">Rate</th>
                                <th className="w-24 border border-black/25 px-2 py-1 text-right font-medium">Discount</th>
                                <th className="w-28 border border-black/25 px-2 py-1 text-right font-medium">Amount</th>
                                {invoice.vat.taxed && (
                                    <>
                                        <th className="w-14 border border-black/25 px-2 py-1 text-right font-medium">VAT %</th>
                                        <th className="w-24 border border-black/25 px-2 py-1 text-right font-medium">VAT</th>
                                    </>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.lines.map((line, index) => (
                                <tr key={line.id} className="align-top">
                                    <td className="border border-black/25 px-2 py-1 text-black/50">{index + 1}</td>
                                    <td className="border border-black/25 px-2 py-1">
                                        <p>{line.product?.name ?? line.description ?? 'Item'}</p>
                                        {/* The SKU sits under the name, not instead of it: the
                                            customer reads words, the warehouse reads the code. */}
                                        {line.product?.sku && <p className="text-xs text-black/50">{line.product.sku}</p>}
                                        {line.product && line.description && line.description !== line.product.name && (
                                            <p className="text-xs text-black/60">{line.description}</p>
                                        )}
                                    </td>
                                    <td className="border border-black/25 px-2 py-1 text-right">{quantity(line.quantity)}</td>
                                    <td className="border border-black/25 px-2 py-1 text-right">{money(line.unit_price)}</td>
                                    <td className="border border-black/25 px-2 py-1 text-right">{money(line.line_discount)}</td>
                                    <td className="border border-black/25 px-2 py-1 text-right">{money(line.line_net)}</td>
                                    {invoice.vat.taxed && (
                                        <>
                                            <td className="border border-black/25 px-2 py-1 text-right">{quantity(line.tax_rate)}</td>
                                            <td className="border border-black/25 px-2 py-1 text-right">{money(line.tax_amount)}</td>
                                        </>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {/* ── Totals ─────────────────────────────────────────── */}
                    <div className="mt-3 flex justify-end">
                        <dl className="w-72 text-sm">
                            <div className="flex justify-between py-1">
                                <dt className="text-black/55">Subtotal</dt>
                                <dd>{money(invoice.subtotal)}</dd>
                            </div>
                            {Number(invoice.invoice_discount) > 0 && (
                                <div className="flex justify-between py-1">
                                    <dt className="text-black/55">Invoice discount</dt>
                                    <dd>({money(invoice.invoice_discount)})</dd>
                                </div>
                            )}
                            {/* ⚠ Shown only when there is tax. The business is not VAT
                                registered, and a "VAT 0.00" line on a customer's invoice
                                invites a question with no good answer. When a business
                                does register, this line appears on its own. */}
                            {Number(invoice.tax_total) > 0 && (
                                <>
                                    <div className="flex justify-between py-1">
                                        <dt className="text-black/55">Value excluding VAT</dt>
                                        <dd>{money(invoice.vat.value_excluding_vat)}</dd>
                                    </div>
                                    <div className="flex justify-between py-1">
                                        <dt className="text-black/55">
                                            {invoice.vat.prices_include_tax ? 'VAT included in the prices' : 'VAT added'}
                                        </dt>
                                        <dd>{money(invoice.tax_total)}</dd>
                                    </div>
                                </>
                            )}
                            <div className="mt-1 flex justify-between border-t-2 border-black/70 py-2 text-base font-semibold">
                                <dt>Total</dt>
                                <dd>BDT {money(invoice.total)}</dd>
                            </div>
                            {hasPayments && (
                                <>
                                    <div className="flex justify-between py-1">
                                        <dt className="text-black/55">Received</dt>
                                        <dd>({money(received)})</dd>
                                    </div>
                                    <div className="flex justify-between border-t border-black/30 py-2 font-semibold">
                                        <dt>Balance due</dt>
                                        <dd>BDT {money(outstanding)}</dd>
                                    </div>
                                </>
                            )}
                        </dl>
                    </div>

                    {/* ⭐ The amount in words. A figure can have a digit added in front
                        of it; a sentence cannot. */}
                    <div className="mt-3 border-y border-black/15 py-1.5 text-sm">
                        <span className="text-black/55">Amount in words: </span>
                        <span className="font-medium">{total_in_words}</span>
                    </div>

                    {/* ── How to pay, and anything the seller wants to say ─── */}
                    <div className="mt-4 grid gap-6 text-sm sm:grid-cols-2">
                        <div>
                            <p className="text-[11px] tracking-widest text-black/50 uppercase">Payment</p>
                            <p className="mt-1 text-black/70">
                                Please quote invoice <span className="font-medium">{invoice.invoice_number}</span> with your payment.
                            </p>
                            {invoice.due_date && <p className="text-black/70">Payment is due by {invoice.due_date}.</p>}
                        </div>
                        {invoice.note && (
                            <div>
                                <p className="text-[11px] tracking-widest text-black/50 uppercase">Notes</p>
                                <p className="mt-1 whitespace-pre-line text-black/70">{invoice.note}</p>
                            </div>
                        )}
                    </div>

                    {/* ── Signatures ─────────────────────────────────────── */}
                    <table className="mt-10 w-full border-collapse text-[11px]">
                        <tbody>
                            <tr>
                                <td className="w-1/2 px-6 pt-10 align-bottom">
                                    <div className="border-t border-black/45 pt-1 text-center text-black/70">Received by</div>
                                </td>
                                <td className="w-1/2 px-6 pt-10 align-bottom">
                                    <div className="border-t border-black/45 pt-1 text-center text-black/70">For {issuer.name}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <p className="doc-footer mt-8 border-t border-black/10 pt-2 text-center text-[10px] text-black/45">
                        This is a computer-generated invoice from {issuer.name}. {invoice.invoice_number}
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
