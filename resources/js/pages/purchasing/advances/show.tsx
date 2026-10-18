import { ReversalNotice, ReverseDocumentForm, type Reversal } from '@/components/purchasing/reverse-document';
import { RefundForm, RefundsTable, type RefundRow } from '@/components/purchasing/supplier-advance-refunds';
import { AppliedAdvancesTable, type AppliedAdvance } from '@/components/purchasing/supplier-advances';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Advance {
    id: number;
    number: string;
    kind: 'payment' | 'overpayment' | 'opening';
    kind_label: string;
    supplier: { id: number; name: string } | null;
    purchase_order: { id: number; po_number: string } | null;
    money_account: string | null;
    paid_on: string | null;
    amount: string;
    open: string;
    is_reversed: boolean;
    note: string | null;
    reference: string | null;
    tds_amount: string;
    net_paid: string;
    applied: string;
    refunded: string;
    supplier_payment_id: number | null;
    created_by: string | null;
    reversal: Reversal | null;
}

interface JournalView {
    id: number;
    journal_number: string;
    memo: string | null;
    lines: { id: number; account: string | null; memo: string | null; amount_minor: number }[];
}

/**
 * ⭐ One supplier advance (ruling P2, 18 Oct 2026): what was paid, what bills
 * have used, what came back, what is still open, and what it did to the books.
 */
export default function AdvanceShow({
    advance,
    applications,
    refunds,
    journal,
    moneyAccounts,
    can_record,
    can_reverse,
    today,
}: {
    advance: Advance;
    applications: AppliedAdvance[];
    refunds: RefundRow[];
    journal: JournalView | null;
    moneyAccounts: { id: number; name: string; balance?: string | null }[];
    can_record: boolean;
    can_reverse: boolean;
    today: string;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Advances', href: '/purchasing/advances' },
        { title: advance.number, href: `/purchasing/advances/${advance.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Advance ${advance.number}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Advance {advance.number}</h1>
                        <p className="text-muted-foreground text-sm">
                            {advance.kind_label}
                            {advance.supplier && (
                                <>
                                    {' to '}
                                    <Link href={`/purchasing/suppliers/${advance.supplier.id}/advances`} className="hover:underline">
                                        {advance.supplier.name}
                                    </Link>
                                </>
                            )}
                            {advance.purchase_order && (
                                <>
                                    {' · against '}
                                    <Link href={`/purchasing/purchase-orders/${advance.purchase_order.id}`} className="hover:underline">
                                        {advance.purchase_order.po_number}
                                    </Link>
                                </>
                            )}
                            {advance.paid_on ? ` · ${showDate(advance.paid_on)}` : ''}
                            {advance.supplier_payment_id && (
                                <>
                                    {' · part of '}
                                    <Link href={`/purchasing/payments/${advance.supplier_payment_id}`} className="hover:underline">
                                        payment #{advance.supplier_payment_id}
                                    </Link>
                                </>
                            )}
                        </p>
                    </div>
                    {advance.is_reversed && <Badge variant="destructive">Reversed</Badge>}
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    {[
                        ['Advance', advance.amount],
                        ['Applied to bills', advance.applied],
                        ['Refunded', advance.refunded],
                        ['Still open', advance.open],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-lg border p-4">
                            <div className="text-muted-foreground text-sm">{label}</div>
                            <div className="text-lg font-semibold tabular-nums">{formatMoney(value)}</div>
                        </div>
                    ))}
                </div>

                <div className="grid gap-4 rounded-lg border p-4 text-sm md:grid-cols-4">
                    <Detail label="Paid from" value={advance.money_account ?? '-'} />
                    {Number(advance.tds_amount) > 0 && <Detail label="TDS held back" value={formatMoney(advance.tds_amount)} />}
                    {advance.net_paid !== advance.amount && <Detail label="Left the account" value={formatMoney(advance.net_paid)} />}
                    <Detail label="Reference" value={advance.reference || '-'} />
                    <Detail label="Recorded by" value={advance.created_by ?? '-'} />
                    {advance.note && <Detail label="Note" value={advance.note} />}
                </div>

                {advance.reversal && <ReversalNotice reversal={advance.reversal} />}

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Applied to bills</h2>
                    <AppliedAdvancesTable applications={applications.map((a) => ({ ...a, can_reverse: can_record }))} showBill />
                    {can_record && !advance.is_reversed && Number(advance.open) > 0 && advance.supplier && (
                        <p className="text-sm">
                            To use it, open the bill, or{' '}
                            <Link href={`/purchasing/suppliers/${advance.supplier.id}/advances`} className="font-medium hover:underline">
                                this supplier&apos;s advances
                            </Link>
                            .
                        </p>
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Refunds</h2>
                    <RefundsTable refunds={refunds} canReverse={can_record} />
                    {can_record && !advance.is_reversed && Number(advance.open) > 0 && advance.supplier && (
                        <RefundForm
                            supplierId={advance.supplier.id}
                            options={[{ key: `advance:${advance.id}`, label: advance.number, open: advance.open }]}
                            moneyAccounts={moneyAccounts}
                            today={today}
                        />
                    )}
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">What this did to the books</h2>
                    {journal ? (
                        <div className="overflow-x-auto rounded-lg border">
                            <div className="bg-muted/50 border-b p-3 text-sm font-medium">{journal.journal_number}</div>
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="p-3 font-medium">Account</th>
                                        <th className="p-3 font-medium">Memo</th>
                                        <th className="p-3 text-right font-medium">Debit</th>
                                        <th className="p-3 text-right font-medium">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {journal.lines.map((line) => (
                                        <tr key={line.id} className="border-t">
                                            <td className="p-3">{line.account ?? '-'}</td>
                                            <td className="text-muted-foreground p-3">{line.memo}</td>
                                            {/* Signed paisa: positive debits, negative credits. */}
                                            <td className="p-3 text-right tabular-nums">
                                                {line.amount_minor > 0 ? formatMoney(line.amount_minor / 100) : ''}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {line.amount_minor < 0 ? formatMoney(-line.amount_minor / 100) : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <Badge variant="outline">No journal posted</Badge>
                    )}
                </section>

                {can_reverse && (
                    <ReverseDocumentForm
                        kind="advance"
                        action={`/purchasing/advances/${advance.id}/reverse`}
                        originalDate={advance.paid_on ?? ''}
                        idPrefix="reverse-advance"
                    />
                )}

                <Link href="/purchasing/advances" className="text-sm hover:underline">
                    Back to advances
                </Link>
            </div>
        </AppLayout>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-muted-foreground text-xs">{label}</span>
            <span>{value}</span>
        </div>
    );
}
