import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Voucher {
    id: number;
    voucher_number: string;
    received_on: string | null;
    received_into: string | null;
    payer: string;
    reference: string | null;
    note: string | null;
    total: string;
    recorded_by: string | null;
    lines: { account: string | null; description: string | null; amount: string }[];
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * One copy of the receipt voucher.
 *
 * ⚠ The two copies are byte-identical apart from the word in the corner,
 * generated from the same data in the same render, so the copy that is signed
 * and the copy that is handed over cannot disagree.
 */
function VoucherCopy({ voucher, issuer, copyLabel }: { voucher: Voucher; issuer: Issuer; copyLabel: string }) {
    return (
        <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
            <DocumentLetterhead issuer={issuer} title="Receipt voucher" subtitle={`No. ${voucher.voucher_number}`} />

            <p className="mt-2 text-right text-xs tracking-widest text-black/50 uppercase">{copyLabel}</p>

            <div className="mt-4 flex flex-wrap justify-between gap-6 text-sm">
                <div>
                    <p className="text-black/60">Received from</p>
                    <p className="font-medium">{voucher.payer}</p>
                </div>
                <div className="text-right">
                    <p>
                        <span className="text-black/60">Date: </span>
                        {voucher.received_on ?? '-'}
                    </p>
                    {voucher.received_into && (
                        <p>
                            <span className="text-black/60">Received into: </span>
                            {voucher.received_into}
                        </p>
                    )}
                    {voucher.reference && (
                        <p>
                            <span className="text-black/60">Reference: </span>
                            {voucher.reference}
                        </p>
                    )}
                </div>
            </div>

            <table className="mt-6 w-full text-sm">
                <thead>
                    <tr className="border-b border-black/20 text-left">
                        <th className="py-2">What it was for</th>
                        <th className="py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {voucher.lines.map((line, index) => (
                        <tr key={index} className="border-b border-black/10">
                            <td className="py-2">
                                <span className="font-medium">{line.description || line.account || 'Income'}</span>
                                {/* ⚠ The account prints small beside the description, not
                                    instead of it. Whoever files this needs the head it was
                                    credited to; whoever pays needs plain words. */}
                                {line.description && line.account && <span className="block text-xs text-black/50">{line.account}</span>}
                            </td>
                            <td className="py-2 text-right">{money(line.amount)}</td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr>
                        <td className="py-3 text-right font-semibold">Total</td>
                        <td className="py-3 text-right text-lg font-semibold">BDT {money(voucher.total)}</td>
                    </tr>
                </tfoot>
            </table>

            {voucher.note && (
                <p className="mt-4 text-sm">
                    <span className="text-black/60">Note: </span>
                    {voucher.note}
                </p>
            )}

            {/* ⚠ The signature roles are the mirror of the payment voucher's.
                On money going out the payee signs for having received it; on
                money coming in WE sign for having received it, and the payer
                keeps a copy saying so. */}
            <div className="mt-12 flex justify-between gap-8 text-sm">
                <div className="flex-1">
                    <div className="border-t border-black/40 pt-1">Paid by</div>
                </div>
                <div className="flex-1">
                    <div className="border-t border-black/40 pt-1">Received by</div>
                </div>
                <div className="flex-1">
                    <div className="border-t border-black/40 pt-1">Approved by</div>
                </div>
            </div>

            {voucher.recorded_by && <p className="mt-4 text-xs text-black/50">Recorded by {voucher.recorded_by}</p>}
        </div>
    );
}

/**
 * The receipt voucher, printed twice on one sheet.
 *
 * ⚠⚠ TWO COPIES, ONE PRESS, the same rule the payment voucher follows: the
 * person who handed the money over gets a copy, the office keeps the one that
 * is signed, and printing both from one render means they cannot differ.
 */
export default function IncomeVoucherPrint({ voucher, issuer }: { voucher: Voucher; issuer: Issuer }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Other income', href: '/accounting/other-income' },
        { title: `Voucher ${voucher.voucher_number}`, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt voucher ${voucher.voucher_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/accounting/other-income">Back</Link>
                    </Button>
                    <DownloadPdfButton type="income-voucher" id={voucher.id} />
                    <Button size="sm" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <VoucherCopy voucher={voucher} issuer={issuer} copyLabel="Office copy" />

                {/* The page break belongs to print only; on screen the two copies
                    stack so what you see is what comes out. */}
                <div className="print:break-before-page" />

                <VoucherCopy voucher={voucher} issuer={issuer} copyLabel="Payer copy" />
            </div>
        </AppLayout>
    );
}
