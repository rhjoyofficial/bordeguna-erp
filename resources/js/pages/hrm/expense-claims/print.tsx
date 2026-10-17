import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { DownloadPdfButton } from '@/components/download-pdf-button';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface VoucherLine {
    spent_on: string | null;
    category: string | null;
    description: string | null;
    amount: string;
}

interface DeclinedLine extends VoucherLine {
    reason: string | null;
}

interface Claim {
    id: number;
    claim_number: string;
    lines: VoucherLine[];
    declined: DeclinedLine[];
    employee: string;
    employee_code: string | null;
    spent_on: string | null;
    settled_on: string | null;
    purpose: string;
    category: string | null;
    charged_to: string | null;
    amount: string;
    method: string;
    paid_from: string | null;
    salary_month: string | null;
    settled_by: string | null;
    approved_journal: string | null;
    settlement_journal: string | null;
    voucher_number: string | null;
}

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * One copy of the reimbursement voucher.
 *
 * ⚠ Deliberately the same shape as the payment voucher in Purchasing. Two
 * documents that mean "we paid this person" should not look like they came from
 * two different companies, and a filing clerk should not have to learn a second
 * layout.
 */
function ReimbursementCopy({ claim, issuer, copyLabel }: { claim: Claim; issuer: Issuer; copyLabel: string }) {
    return (
        <div className="official-document border-sidebar-border/70 dark:border-sidebar-border relative mx-auto w-full max-w-2xl rounded-xl border bg-white p-6 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
            <DocumentLetterhead issuer={issuer} title="Reimbursement voucher" subtitle={`Claim ${claim.claim_number}`} />

            {/* ⭐ Joy, 7 Sep: the copy name is a WATERMARK, not a corner label. The
                two copies are otherwise identical, so the watermark is the only
                thing telling them apart - which means it has to be readable at a
                glance from across a desk, and unmissable on a photocopy.

                ⚠ `aria-hidden` and pointer-events-none: it is decoration over the
                document, and it must never sit between a reader and the text or be
                announced to a screen reader as content. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 flex items-center justify-center overflow-hidden">
                <span className="-rotate-30 text-5xl font-bold tracking-[0.2em] whitespace-nowrap text-black/[0.07] uppercase select-none">
                    {copyLabel}
                </span>
            </div>

            {/* ⭐ Joy, 7 Sep: "table structure is always good." A ruled grid is what
                an official voucher looks like, and it is also denser than stacked
                paragraphs - the same facts in less of the page. */}
            <table className="mt-3 w-full border-collapse text-[11px]">
                <tbody>
                    <tr>
                        <td className="w-28 border border-black/25 bg-black/[0.03] px-2 py-1 text-black/60">Reimbursed to</td>
                        <td className="border border-black/25 px-2 py-1 font-medium">
                            {claim.employee}
                            {claim.employee_code && <span className="ml-2 text-black/50">{claim.employee_code}</span>}
                        </td>
                        <td className="w-24 border border-black/25 bg-black/[0.03] px-2 py-1 text-black/60">Spent on</td>
                        <td className="w-28 border border-black/25 px-2 py-1">{formatDate(claim.spent_on)}</td>
                    </tr>
                    <tr>
                        <td className="border border-black/25 bg-black/[0.03] px-2 py-1 text-black/60">How</td>
                        <td className="border border-black/25 px-2 py-1">
                            {claim.method}
                            {/* ⚠ One of these two, never both: money either left an account
                                today or it is going out with a named month's salary. */}
                            {claim.paid_from && <span className="text-black/60"> - {claim.paid_from}</span>}
                            {claim.salary_month && <span className="text-black/60"> - {claim.salary_month}</span>}
                        </td>
                        <td className="border border-black/25 bg-black/[0.03] px-2 py-1 text-black/60">Paid on</td>
                        <td className="border border-black/25 px-2 py-1">{formatDate(claim.settled_on)}</td>
                    </tr>
                </tbody>
            </table>

            <table className="mt-2 w-full border-collapse text-[11px]">
                <thead>
                    <tr className="bg-black/[0.03]">
                        <th className="border border-black/25 px-2 py-1 text-left font-medium">What it was for</th>
                        <th className="w-28 border border-black/25 px-2 py-1 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {/* ⚠⚠ ONE ROW PER SPEND. A trip paid as a single figure gives
                        the person signing nothing to check their receipts against;
                        this is the breakdown they handed in, back on paper. A
                        one-spend claim still reads as one row, exactly as before. */}
                    {claim.lines.length === 0 ? (
                        <tr>
                            <td className="border border-black/25 px-2 py-1 align-top">
                                {claim.purpose}
                                {/* ⚠ The CATEGORY, and only the account when there is
                                    none - a claim raised before categories existed.
                                    A chart code is the accountant's word for this
                                    line, and one copy of this voucher is handed to the
                                    person being paid. */}
                                {(claim.category ?? claim.charged_to) && (
                                    <span className="block text-[10px] text-black/50">{claim.category ?? claim.charged_to}</span>
                                )}
                            </td>
                            <td className="border border-black/25 px-2 py-1 text-right align-top">{money(claim.amount)}</td>
                        </tr>
                    ) : (
                        claim.lines.map((line, index) => (
                            <tr key={index}>
                                <td className="border border-black/25 px-2 py-1 align-top">
                                    {line.description ?? claim.purpose}
                                    <span className="block text-[10px] text-black/50">
                                        {[line.category, line.spent_on ? formatDate(line.spent_on) : null].filter(Boolean).join(' · ')}
                                    </span>
                                </td>
                                <td className="border border-black/25 px-2 py-1 text-right align-top">{money(line.amount)}</td>
                            </tr>
                        ))
                    )}
                    <tr>
                        <td className="border border-black/25 px-2 py-1 text-right font-semibold">Total</td>
                        <td className="border border-black/25 px-2 py-1 text-right font-semibold">BDT {money(claim.amount)}</td>
                    </tr>
                </tbody>
            </table>

            {/* ⚠⚠ WHAT WAS REFUSED IS PRINTED TOO, and it is deliberately below the
                total rather than inside it. The employee asked for this money and
                is not getting it; a voucher that simply omitted the line would
                leave them to work out the difference for themselves. */}
            {claim.declined.length > 0 && (
                <table className="mt-2 w-full border-collapse text-[11px]">
                    <thead>
                        <tr className="bg-black/[0.03]">
                            <th className="border border-black/25 px-2 py-1 text-left font-medium">Not approved</th>
                            <th className="w-28 border border-black/25 px-2 py-1 text-right font-medium">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {claim.declined.map((line, index) => (
                            <tr key={index}>
                                <td className="border border-black/25 px-2 py-1 align-top">
                                    {line.description ?? '-'}
                                    {line.reason && <span className="block text-[10px] text-black/50">{line.reason}</span>}
                                </td>
                                <td className="border border-black/25 px-2 py-1 text-right align-top">{money(line.amount)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {/* ⭐ Signature labels centred in their own cells, and the space above
                each one is the space somebody signs in. */}
            <table className="mt-6 w-full border-collapse text-[11px]">
                <tbody>
                    <tr>
                        {['Received by', 'Paid by', 'Approved by'].map((role) => (
                            <td key={role} className="w-1/3 px-2 pt-10 align-bottom">
                                <div className="border-t border-black/45 pt-1 text-center text-black/70">{role}</div>
                            </td>
                        ))}
                    </tr>
                </tbody>
            </table>

            {/* ⭐ The journal numbers are printed on the document itself. Somebody
                auditing this a year from now should be able to walk from the paper
                to the ledger without asking anyone where it went. */}
            <p className="mt-3 text-[10px] text-black/50">
                {claim.approved_journal && <>Expense posted {claim.approved_journal}. </>}
                {claim.settlement_journal && <>Payment posted {claim.settlement_journal}. </>}
                {claim.voucher_number && <>Voucher {claim.voucher_number}. </>}
                {claim.settled_by && <>Settled by {claim.settled_by}.</>}
            </p>
        </div>
    );
}

/**
 * The reimbursement voucher, printed twice on one sheet.
 *
 * ⚠⚠ WHY THIS EXISTS. Paying a claim that was expensed at approval clears the
 * payable directly and produces NO expense voucher, so until this page there was
 * no printable document at all: cash left the till with nothing to file and
 * nothing for the recipient to sign. The books were right and the paperwork did
 * not exist.
 *
 * Two copies from one render, for the same reason the payment voucher does it:
 * the copy that gets signed and the copy that gets handed over cannot disagree.
 */
export default function ExpenseClaimPrint({ claim, issuer }: { claim: Claim; issuer: Issuer }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Expense claims', href: '/hrm/expense-claims' },
        { title: claim.claim_number, href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Reimbursement ${claim.claim_number}`} />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/hrm/expense-claims">Back</Link>
                    </Button>
                    <DownloadPdfButton type="expense-claim" id={claim.id} />
                    <Button size="sm" onClick={() => window.print()}>
                        Print
                    </Button>
                </div>

                <ReimbursementCopy claim={claim} issuer={issuer} copyLabel="Office copy" />

                <div className="print:break-before-page" />

                <ReimbursementCopy claim={claim} issuer={issuer} copyLabel="Employee copy" />
            </div>
        </AppLayout>
    );
}
