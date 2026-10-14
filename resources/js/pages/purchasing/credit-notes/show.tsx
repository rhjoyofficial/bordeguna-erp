import { ReversalNotice, ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Allocation {
    id: number;
    amount: string;
    bill: { id: number; bill_number: string; bill_date: string | null; total: string; outstanding: string } | null;
}

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Credit notes', href: '/purchasing/credit-notes' },
];

const taka = (minor: number) => (Math.abs(minor) / 100).toFixed(2);

export default function CreditNoteShow({
    creditNote,
    allocations,
    journal,
    can_reverse,
}: {
    creditNote: {
        id: number;
        credit_note_number: string;
        credit_date: string | null;
        total: string;
        note: string | null;
        supplier: { id: number; name: string; code: string | null } | null;
        purchase_return: { id: number } | null;
        created_by: { id: number; name: string } | null;
        /** PUR-003 (14 Oct 2026): null while the credit note stands. */
        reversal: Reversal | null;
        reversed_allocations: { bill_number: string | null; amount: string }[] | null;
    };
    allocations: Allocation[];
    can_reverse: boolean;
    journal: { id: number; journal_number: string; lines: JournalLine[] } | null;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Credit note ${creditNote.credit_note_number}`} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <div className="rounded-lg border p-4">
                    <h1 className="text-lg font-semibold">Credit note {creditNote.credit_note_number}</h1>
                    <dl className="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                        <dt className="text-muted-foreground">Supplier</dt>
                        <dd>{creditNote.supplier?.name ?? '-'}</dd>
                        <dt className="text-muted-foreground">Date</dt>
                        <dd>{creditNote.credit_date ?? '-'}</dd>
                        <dt className="text-muted-foreground">Amount</dt>
                        <dd className="tabular-nums">{creditNote.total}</dd>
                        <dt className="text-muted-foreground">Against return</dt>
                        <dd>#{creditNote.purchase_return?.id ?? '-'}</dd>
                    </dl>
                    {creditNote.note && <p className="text-muted-foreground mt-3 text-sm">{creditNote.note}</p>}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Bill credited</th>
                                <th className="p-3">Bill date</th>
                                <th className="p-3 text-right">Credited</th>
                                <th className="p-3 text-right">Still outstanding today</th>
                            </tr>
                        </thead>
                        <tbody>
                            {allocations.map((allocation) => (
                                <tr key={allocation.id} className="border-t">
                                    <td className="p-3 font-medium">{allocation.bill?.bill_number ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">{allocation.bill?.bill_date ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(allocation.amount)}</td>
                                    <td className="p-3 text-right tabular-nums">{allocation.bill?.outstanding ?? '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* "Show me what this did to the books" - same as the invoice
                    and payment pages, because that question is what makes an
                    accountant trust the system. */}
                {journal && (
                    <div className="overflow-x-auto rounded-lg border">
                        <div className="bg-muted/50 border-b p-3 text-sm font-medium">Journal {journal.journal_number}</div>
                        <table className="w-full text-sm">
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">{line.account ? `${line.account.code} ${line.account.name}` : '-'}</td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">
                                            {line.amount_minor >= 0 ? `Dr ${taka(line.amount_minor)}` : `Cr ${taka(line.amount_minor)}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {creditNote.reversal && <ReversalNotice reversal={creditNote.reversal} released={creditNote.reversed_allocations} />}

                {/* PUR-003 (owner decision 14 Oct 2026): correction is a reversal, never an edit. */}
                {can_reverse && (
                    <ReverseDocumentForm
                        kind="credit_note"
                        action={`/purchasing/credit-notes/${creditNote.id}/reverse`}
                        originalDate={creditNote.credit_date ?? ''}
                        idPrefix="reverse-credit-note"
                    />
                )}
            </div>
        </AppLayout>
    );
}
