import { ReversalNotice, ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

/**
 * ⚠⚠ `amount_minor` is SIGNED whole paisa: positive is a debit, negative a
 * credit. There are no `debit` and `credit` columns. The first version of this
 * page read `line.debit` / `line.credit`, which are simply undefined, so the
 * journal rendered with both money columns BLANK - the accounts were listed and
 * the amounts were not. Every HTTP test still passed, because they asserted the
 * journal had two lines and never looked at what was in them.
 */
interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

/** Paisa to a readable amount. Same helper the sales invoice page uses. */
const taka = (minor: number) => formatMoney(minor / 100);

/**
 * ⚠⚠ These dates arrive as PLAIN `Y-m-d` strings because the controller calls
 * toDateString() on them. That is the fix, and it has to be server-side.
 *
 * A `date` cast serialises to JSON in UTC. Dhaka is UTC+6, so midnight local
 * becomes 18:00 the PREVIOUS day, and slicing the first ten characters of that
 * renders a payment made on the 8th as the 7th. Slicing in the browser is the
 * bug, not the cure. The database held 2026-08-08 correctly the whole time and
 * only the screen was wrong.
 */
const asDate = (value: string | null): string => value ?? '-';

interface Allocation {
    id: number;
    amount: string;
    bill: {
        id: number;
        bill_number: string;
        bill_date: string | null;
        total: string;
        outstanding: string;
    } | null;
}

interface Payment {
    id: number;
    paid_on: string;
    /** Formatted taka, not the raw decimal(15,4) the column holds. */
    amount: string;
    tds_amount: string;
    vds_amount: string;
    net_paid: string;
    reference: string | null;
    note: string | null;
    supplier: { id: number; name: string; code: string | null } | null;
    money_account: { id: number; name: string; type: string } | null;
    created_by: { id: number; name: string } | null;
    /** PUR-003 (14 Oct 2026): null while the payment stands. */
    reversal: Reversal | null;
    reversed_allocations: { bill_number: string | null; amount: string }[] | null;
}

export default function PaymentShow({
    payment,
    allocations,
    journal,
    can_reverse,
    advance = null,
}: {
    payment: Payment;
    allocations: Allocation[];
    can_reverse: boolean;
    /** Supplier advances (ruling P2): what this payment paid beyond its bills. */
    advance?: { id: number; number: string; amount: string; tds_amount: string } | null;
    journal: { id: number; reference: string | null; memo: string | null; lines: JournalLine[] } | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Payments', href: '/purchasing/payments' },
        { title: payment.reference || `Payment #${payment.id}`, href: `/purchasing/payments/${payment.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment #${payment.id}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <Detail label="Supplier" value={payment.supplier?.name ?? '-'} />
                    <Detail label="Paid from" value={payment.money_account?.name ?? '-'} />
                    <Detail label="Payment date" value={asDate(payment.paid_on)} />
                    <Detail label="Amount" value={formatMoney(payment.amount)} emphasis />
                    {Number(payment.tds_amount) > 0 && <Detail label="TDS held back" value={formatMoney(payment.tds_amount)} />}
                    {Number(payment.vds_amount) > 0 && <Detail label="VDS held back" value={formatMoney(payment.vds_amount)} />}
                    {payment.net_paid !== payment.amount && <Detail label="Left the account" value={formatMoney(payment.net_paid)} />}
                    <Detail label="Reference" value={payment.reference || '-'} />
                    <Detail label="Recorded by" value={payment.created_by?.name ?? '-'} />
                    {payment.note && <Detail label="Note" value={payment.note} />}
                </div>

                {advance && (
                    <p className="text-sm">
                        Also paid {formatMoney(advance.amount)} more than the bills owed, kept as advance{' '}
                        <Link href={`/purchasing/advances/${advance.id}`} className="font-medium hover:underline">
                            {advance.number}
                        </Link>
                        .
                    </p>
                )}

                {payment.reversal && <ReversalNotice reversal={payment.reversal} released={payment.reversed_allocations} />}

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Bills settled by this payment</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Bill</th>
                                    <th className="p-3">Date</th>
                                    <th className="p-3 text-right">Bill total</th>
                                    <th className="p-3 text-right">Paid by this</th>
                                    <th className="p-3 text-right">Still outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                {allocations.map((allocation) => (
                                    <tr key={allocation.id} className="border-t">
                                        <td className="p-3 font-medium">{allocation.bill?.bill_number ?? '-'}</td>
                                        <td className="p-3">{asDate(allocation.bill?.bill_date ?? null)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(allocation.bill?.total)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(allocation.amount)}</td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(allocation.bill?.outstanding)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* The journal is shown on the document on purpose. "Show me what this
                    did to the books" is the question that makes an accountant trust
                    the system. */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">What this did to the books</h2>
                    {journal ? (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Account</th>
                                        <th className="p-3">Memo</th>
                                        <th className="p-3 text-right">Debit</th>
                                        <th className="p-3 text-right">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {journal.lines.map((line) => (
                                        <tr key={line.id} className="border-t">
                                            <td className="p-3">{line.account ? `${line.account.code} ${line.account.name}` : '-'}</td>
                                            <td className="text-muted-foreground p-3">{line.memo}</td>
                                            {/* Signed paisa: positive debits, negative credits. */}
                                            <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                            <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Badge variant="outline">No journal posted</Badge>
                    )}
                </section>

                {/* PUR-003 (owner decision 14 Oct 2026): correction is a reversal, never an edit. */}
                {can_reverse && (
                    <ReverseDocumentForm
                        kind="payment"
                        action={`/purchasing/payments/${payment.id}/reverse`}
                        originalDate={payment.paid_on}
                        idPrefix="reverse-payment"
                    />
                )}

                <Link href="/purchasing/payments" className="text-sm hover:underline">
                    Back to payments
                </Link>
            </div>
        </AppLayout>
    );
}

function Detail({ label, value, emphasis }: { label: string; value: string; emphasis?: boolean }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span className={emphasis ? 'text-lg font-semibold tabular-nums' : 'text-sm'}>{value}</span>
        </div>
    );
}
