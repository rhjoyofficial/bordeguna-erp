import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { INSTRUMENT_LABEL, moneyLabelFor, type VoucherDocument } from './show';

/**
 * The printed voucher: what gets signed and filed.
 *
 * ⚠⚠ IT STATES ITS OWN STATUS. A reversed voucher prints REVERSED across itself
 * with the entry that reversed it, so a copy pulled from a file can never be
 * mistaken for a live payment.
 *
 * ⚠ Browser print is the PDF (Chromium shapes Bengali names correctly; dompdf
 * does not), the same as the invoice and the payment voucher before it.
 */
export default function VoucherPrint({ voucher, issuer }: { voucher: VoucherDocument; issuer: Issuer }) {
    const type = voucher.type;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: type.plural, href: route('accounting.vouchers.index', type.value) },
        { title: voucher.title, href: route('accounting.vouchers.show', voucher.id) },
        { title: 'Print', href: '#' },
    ];

    const signatures =
        type.value === 'payment'
            ? ['Prepared by', 'Checked by', 'Approved by', 'Received by']
            : type.value === 'receipt'
              ? ['Prepared by', 'Checked by', 'Approved by', 'Paid by']
              : ['Prepared by', 'Checked by', 'Approved by'];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${type.label} ${voucher.number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('accounting.vouchers.show', voucher.id)}>Back</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print or save as PDF
                    </Button>
                </div>

                <div className="official-document border-sidebar-border/70 dark:border-sidebar-border relative mx-auto w-full max-w-3xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
                    <DocumentLetterhead issuer={issuer} title={type.label} subtitle={`No. ${voucher.number}`} />

                    {voucher.status === 'reversed' && (
                        <div aria-hidden="true" className="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
                            <span className="-rotate-30 text-7xl font-bold tracking-[0.2em] whitespace-nowrap text-red-700/15 uppercase select-none">
                                Reversed
                            </span>
                        </div>
                    )}

                    <div className="mt-4 flex flex-wrap justify-between gap-6 text-sm">
                        <div className="space-y-1">
                            {type.counterparty_label && (
                                <p>
                                    <span className="text-black/60">{type.counterparty_label}: </span>
                                    <span className="font-medium">{voucher.counterparty ?? '-'}</span>
                                </p>
                            )}
                            {type.uses_money_account && (
                                <p>
                                    <span className="text-black/60">{moneyLabelFor(type.value)}: </span>
                                    {voucher.money_account ?? '-'}
                                </p>
                            )}
                            {type.value === 'contra' && (
                                <p>
                                    <span className="text-black/60">Moved to: </span>
                                    {voucher.to_money_account ?? '-'}
                                </p>
                            )}
                            {voucher.instrument.type && (
                                <p>
                                    <span className="text-black/60">Paid by: </span>
                                    {INSTRUMENT_LABEL[voucher.instrument.type] ?? voucher.instrument.type}
                                    {voucher.instrument.number ? ` no. ${voucher.instrument.number}` : ''}
                                    {voucher.instrument.date ? `, dated ${voucher.instrument.date}` : ''}
                                    {voucher.instrument.bank ? `, ${voucher.instrument.bank}` : ''}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1 text-right">
                            <p>
                                <span className="text-black/60">Date: </span>
                                {voucher.date}
                            </p>
                            <p>
                                <span className="text-black/60">Status: </span>
                                <span className="font-medium uppercase">{voucher.status}</span>
                            </p>
                            {voucher.journal && (
                                <p>
                                    <span className="text-black/60">Ledger entry: </span>
                                    {voucher.journal.number}
                                </p>
                            )}
                        </div>
                    </div>

                    <table className="mt-6 w-full border-collapse text-sm">
                        <thead>
                            <tr className="border-y border-black/40 text-left">
                                <th className="py-2 pr-3 font-semibold">Particulars</th>
                                <th className="w-32 py-2 pr-3 text-right font-semibold">Debit</th>
                                <th className="w-32 py-2 text-right font-semibold">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {type.has_lines ? (
                                voucher.lines.map((line, index) => (
                                    <tr key={index} className="border-b border-black/10 align-top">
                                        <td className="py-2 pr-3">
                                            {line.account}
                                            {line.party ? ` · ${line.party}` : ''}
                                            {line.narration && <div className="text-xs text-black/60">{line.narration}</div>}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{line.debit}</td>
                                        <td className="py-2 text-right tabular-nums">{line.credit}</td>
                                    </tr>
                                ))
                            ) : (
                                <>
                                    <tr className="border-b border-black/10">
                                        <td className="py-2 pr-3">{voucher.to_money_account}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{voucher.total}</td>
                                        <td />
                                    </tr>
                                    <tr className="border-b border-black/10">
                                        <td className="py-2 pr-3 pl-6">{voucher.money_account}</td>
                                        <td />
                                        <td className="py-2 text-right tabular-nums">{voucher.total}</td>
                                    </tr>
                                </>
                            )}
                            {type.has_lines && type.uses_money_account && (
                                <tr className="border-b border-black/10">
                                    <td className={`py-2 pr-3 ${type.value === 'payment' ? 'pl-6' : ''}`}>{voucher.money_account}</td>
                                    <td className="py-2 pr-3 text-right tabular-nums">{type.value === 'receipt' ? voucher.total : ''}</td>
                                    <td className="py-2 text-right tabular-nums">{type.value === 'payment' ? voucher.total : ''}</td>
                                </tr>
                            )}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-black/40 font-semibold">
                                <td className="py-2 pr-3">Total</td>
                                <td className="py-2 pr-3 text-right tabular-nums">{voucher.total}</td>
                                <td className="py-2 text-right tabular-nums">{voucher.total}</td>
                            </tr>
                        </tfoot>
                    </table>

                    <p className="mt-4 text-sm">
                        <span className="text-black/60">In words: </span>
                        <span className="font-medium">{voucher.total_in_words}</span>
                    </p>

                    {voucher.narration && (
                        <p className="mt-2 text-sm">
                            <span className="text-black/60">Narration: </span>
                            {voucher.narration}
                        </p>
                    )}

                    {voucher.reversal && (
                        <p className="mt-3 border border-red-700/40 p-2 text-sm text-red-800">
                            REVERSED on {voucher.reversal.date} by ledger entry {voucher.reversal.number}
                            {voucher.reversal.by ? ` (${voucher.reversal.by})` : ''}. Reason: {voucher.reversal.reason}
                        </p>
                    )}

                    <div className="mt-16 flex justify-between gap-6 text-sm">
                        {signatures.map((label) => (
                            <div key={label} className="flex-1">
                                <div className="border-t border-black/40 pt-1">{label}</div>
                                {label === 'Prepared by' && voucher.prepared_by && <div className="text-xs text-black/60">{voucher.prepared_by}</div>}
                                {label === 'Approved by' && voucher.posted_by && (
                                    <div className="text-xs text-black/60">Posted: {voucher.posted_by}</div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
