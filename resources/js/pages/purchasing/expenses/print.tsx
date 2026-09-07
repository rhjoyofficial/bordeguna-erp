import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Voucher {
    voucher_number: string;
    spent_on: string | null;
    paid_from: string | null;
    payee: string;
    reference: string | null;
    note: string | null;
    total: string;
    recorded_by: string | null;
    lines: { account: string | null; description: string | null; amount: string }[];
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * One copy of the payment voucher.
 *
 * ⚠ The two copies are byte-identical apart from the word in the corner. They
 * are generated from the same data in the same render, so the copy that gets
 * signed and the copy that gets handed over cannot disagree.
 */
function VoucherCopy({ voucher, issuer, copyLabel }: { voucher: Voucher; issuer: Issuer; copyLabel: string }) {
    return (
        <div className="official-document relative border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
            <DocumentLetterhead issuer={issuer} title="Payment voucher" subtitle={`No. ${voucher.voucher_number}`} />

            {/* ⭐ Joy, 7 Sep: the copy name is a WATERMARK, not a corner label. The
                two copies are otherwise identical, so the watermark is the only
                thing telling them apart - which means it has to be readable at a
                glance from across a desk, and unmissable on a photocopy.

                ⚠ `aria-hidden` and pointer-events-none: it is decoration over the
                document, and it must never sit between a reader and the text or be
                announced to a screen reader as content. */}
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden"
            >
                <span className="-rotate-30 text-5xl font-bold tracking-[0.2em] whitespace-nowrap text-black/[0.07] uppercase select-none">
                    {copyLabel}
                </span>
            </div>

            <div className="mt-4 flex flex-wrap justify-between gap-6 text-sm">
                <div>
                    <p className="text-black/60">Paid to</p>
                    <p className="font-medium">{voucher.payee}</p>
                </div>
                <div className="text-right">
                    <p>
                        <span className="text-black/60">Date: </span>
                        {voucher.spent_on ?? '-'}
                    </p>
                    {voucher.paid_from && (
                        <p>
                            <span className="text-black/60">Paid from: </span>
                            {voucher.paid_from}
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
                                <span className="font-medium">{line.description || line.account || 'Expense'}</span>
                                {/* ⚠ The account is printed small beside the description, not
                                    instead of it. Whoever files this needs to know which head it
                                    was charged to; whoever receives it needs plain words. */}
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

            <div className="mt-12 flex justify-between gap-8 text-sm">
                <div className="flex-1">
                    <div className="border-t border-black/40 pt-1">Received by</div>
                </div>
                <div className="flex-1">
                    <div className="border-t border-black/40 pt-1">Paid by</div>
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
 * The payment voucher, printed twice on one sheet.
 *
 * ⚠⚠ TWO COPIES, ONE PRESS. In this office the person paid signs the copy that
 * stays in the file. Printing the two separately means the signed copy and the
 * handed copy can quietly differ; printing both from one render means they
 * cannot. Browser print is the download, exactly as the invoice and challan.
 */
export default function ExpensePrint({ voucher, issuer }: { voucher: Voucher; issuer: Issuer }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Expenses', href: '/purchasing/expenses' },
        { title: `Voucher ${voucher.voucher_number}`, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment voucher ${voucher.voucher_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/purchasing/expenses">Back</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                <VoucherCopy voucher={voucher} issuer={issuer} copyLabel="Office copy" />

                {/* The page break belongs to print only; on screen the two copies
                    simply stack so what you see is what comes out. */}
                <div className="print:break-before-page" />

                <VoucherCopy voucher={voucher} issuer={issuer} copyLabel="Recipient copy" />
            </div>
        </AppLayout>
    );
}
