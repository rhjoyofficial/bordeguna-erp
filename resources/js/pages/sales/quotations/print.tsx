import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * The quotation on paper: the promise, its expiry, and nothing the record
 * does not hold. Every figure came from the server.
 */
interface QuotePrint {
    id: number;
    quotation_number: string;
    party: string | null;
    valid_until: string;
    status: string;
    terms: string | null;
    note: string | null;
    created_at: string;
    total: number;
    // Rule #6 (14 Oct 2026): some line is at the retail list, the buyer's group having none.
    retail_fallback: boolean;
    lines: {
        id: number;
        product: { id: number; name: string; sku: string | null } | null;
        quantity: string;
        unit_price: string;
        price_source: string | null;
        line_total: number;
    }[];
}

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const quantity = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

export default function QuotationPrint({ quotation, issuer }: { quotation: QuotePrint; issuer: Issuer }) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Sales', href: '/sales/overview' },
        { title: 'Quotations', href: '/sales/quotations' },
        { title: quotation.quotation_number, href: `/sales/quotations/${quotation.id}/print` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Quotation ${quotation.quotation_number}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2 print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/sales/quotations">Back to quotations</Link>
                    </Button>
                    <DownloadPdfButton type="quotation" id={quotation.id} />
                    <Button size="sm" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title="Quotation" subtitle={quotation.quotation_number} />

                    <div className="mt-6 flex flex-wrap justify-between gap-6 text-sm">
                        <div>
                            <p className="text-black/60">Quoted to</p>
                            <p className="font-medium">{quotation.party ?? '-'}</p>
                        </div>
                        <div className="text-right">
                            <p>
                                <span className="text-black/60">Date: </span>
                                {formatDate(quotation.created_at)}
                            </p>
                            <p>
                                <span className="text-black/60">Valid until: </span>
                                {formatDate(quotation.valid_until)}
                            </p>
                        </div>
                    </div>

                    <div className="mt-6 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-y border-black/20 text-left">
                                    <th className="py-2 pr-2 font-medium">Item</th>
                                    <th className="py-2 pr-2 text-right font-medium">Qty</th>
                                    <th className="py-2 pr-2 text-right font-medium">Rate</th>
                                    <th className="py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                {quotation.lines.map((line) => (
                                    <tr key={line.id} className="border-b border-black/10 align-top">
                                        <td className="py-2 pr-2">
                                            <p>{line.product?.name ?? 'Item'}</p>
                                            {line.product?.sku && <p className="text-xs text-black/50">{line.product.sku}</p>}
                                            {line.price_source === 'retail_fallback' && (
                                                <p className="text-xs text-black/60 italic">{t('quotes.retail_fallback_line')}</p>
                                            )}
                                        </td>
                                        <td className="py-2 pr-2 text-right">{quantity(line.quantity)}</td>
                                        <td className="py-2 pr-2 text-right">{money(line.unit_price)}</td>
                                        <td className="py-2 text-right">{money(line.line_total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-4 flex justify-end">
                        <dl className="w-64 text-sm">
                            <div className="flex justify-between border-t border-black/20 py-2 font-semibold">
                                <dt>Total</dt>
                                <dd>BDT {money(quotation.total)}</dd>
                            </div>
                        </dl>
                    </div>

                    <p className="mt-4 text-sm text-black/70">
                        Prices are held until {formatDate(quotation.valid_until)}. After that date, please ask for a fresh quotation.
                    </p>
                    {quotation.retail_fallback && <p className="mt-2 text-sm text-black/70">{t('quotes.retail_fallback_note')}</p>}
                    {quotation.terms && <p className="mt-2 text-sm whitespace-pre-line text-black/70">{quotation.terms}</p>}
                    {quotation.note && <p className="mt-2 text-sm whitespace-pre-line text-black/70">{quotation.note}</p>}

                    <div className="mt-16 flex justify-end text-sm">
                        <div className="w-56 border-t border-black/40 pt-2 text-center text-black/60">For {issuer.name}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
