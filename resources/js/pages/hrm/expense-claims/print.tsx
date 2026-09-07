import { DocumentLetterhead, type Issuer } from '@/components/document-letterhead';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Claim {
    claim_number: string;
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
        <div className="official-document border-sidebar-border/70 dark:border-sidebar-border mx-auto w-full max-w-2xl rounded-xl border bg-white p-8 text-black dark:bg-white print:max-w-none print:rounded-none print:border-0 print:p-0">
            <DocumentLetterhead issuer={issuer} title="Reimbursement voucher" subtitle={`Claim ${claim.claim_number}`} />

            <p className="mt-2 text-right text-xs tracking-widest text-black/50 uppercase">{copyLabel}</p>

            <div className="mt-4 flex flex-wrap justify-between gap-6 text-sm">
                <div>
                    <p className="text-black/60">Reimbursed to</p>
                    <p className="font-medium">{claim.employee}</p>
                    {claim.employee_code && <p className="text-xs text-black/50">{claim.employee_code}</p>}
                </div>
                <div className="text-right">
                    <p>
                        <span className="text-black/60">Spent on: </span>
                        {claim.spent_on ?? '-'}
                    </p>
                    <p>
                        <span className="text-black/60">Paid on: </span>
                        {claim.settled_on ?? '-'}
                    </p>
                    <p>
                        <span className="text-black/60">How: </span>
                        {claim.method}
                    </p>
                    {/* ⚠ One of these two, never both: money either left an account
                        today or it is going out with a named month's salary. */}
                    {claim.paid_from && (
                        <p>
                            <span className="text-black/60">Paid from: </span>
                            {claim.paid_from}
                        </p>
                    )}
                    {claim.salary_month && (
                        <p>
                            <span className="text-black/60">With salary of: </span>
                            {claim.salary_month}
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
                    <tr className="border-b border-black/10">
                        <td className="py-2">
                            <span className="font-medium">{claim.purpose}</span>
                            {claim.charged_to && <span className="block text-xs text-black/50">{claim.charged_to}</span>}
                        </td>
                        <td className="py-2 text-right">{money(claim.amount)}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td className="py-3 text-right font-semibold">Total</td>
                        <td className="py-3 text-right text-lg font-semibold">BDT {money(claim.amount)}</td>
                    </tr>
                </tfoot>
            </table>

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

            {/* ⭐ The journal numbers are printed on the document itself. Somebody
                auditing this a year from now should be able to walk from the paper
                to the ledger without asking anyone where it went. */}
            <p className="mt-4 text-xs text-black/50">
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
                    <Button size="sm" onClick={() => window.print()}>
                        Print / save as PDF
                    </Button>
                </div>

                <ReimbursementCopy claim={claim} issuer={issuer} copyLabel="Office copy" />

                <div className="print:break-before-page" />

                <ReimbursementCopy claim={claim} issuer={issuer} copyLabel="Employee copy" />
            </div>
        </AppLayout>
    );
}
