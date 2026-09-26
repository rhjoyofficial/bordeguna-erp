import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface Transfer {
    id: number;
    transfer_number: string;
    transferred_on: string | null;
    from: string | null;
    to: string | null;
    amount: string;
    fee: string;
    total_withdrawn: string;
    fee_account: string | null;
    reference: string | null;
    note: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/money-transfers' },
    { title: 'Money transfers', href: '/accounting/money-transfers' },
];

/**
 * The TRF- transfers recorded before 26 Sep 2026, read-only.
 *
 * A move between the business's own accounts is now a Contra voucher: drafted,
 * numbered CV- at posting, printed, reversed with a reason, and picked by
 * ledger head (every cash, bank and wallet account owns one). The old form is
 * gone so there is one way to do it; the history stays because a transfer made
 * in August must still be readable in December.
 */
export default function MoneyTransfersIndex({ transfers }: { transfers: Paginated<Transfer> }) {
    const { canPrepareVouchers } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Money transfers" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Money transfers"
                    description="Transfers recorded before Contra vouchers existed. Nothing new is recorded here: a move between your own accounts, bank to the till or bKash to the bank, is a Contra voucher now."
                />

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed p-4 text-sm">
                    <span>
                        Move money between accounts with a <strong>Contra voucher</strong>: it is drafted first, numbered when posted, printable, and
                        reversed with a reason. A bank charge on the move is a separate Payment voucher.
                    </span>
                    {canPrepareVouchers ? (
                        <Button asChild>
                            <Link href="/accounting/vouchers/contra/create">New contra voucher</Link>
                        </Button>
                    ) : (
                        <Button asChild variant="outline">
                            <Link href="/accounting/vouchers/contra">Contra vouchers</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Transfer</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">From</th>
                                <th className="p-3">To</th>
                                <th className="p-3 text-right">Arrived</th>
                                <th className="p-3 text-right">Fee</th>
                                <th className="p-3 text-right">Left the source</th>
                                <th className="p-3">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground p-3" colSpan={8}>
                                        No transfers were recorded on this screen. Newer moves are under Contra vouchers.
                                    </td>
                                </tr>
                            )}
                            {transfers.data.map((t) => (
                                <tr key={t.id} className="border-t">
                                    <td className="p-3 font-medium">{t.transfer_number}</td>
                                    <td className="p-3">{t.transferred_on ?? '-'}</td>
                                    <td className="p-3">{t.from ?? '-'}</td>
                                    <td className="p-3">{t.to ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{t.amount}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {Number(t.fee) > 0 ? t.fee : '-'}
                                        {t.fee_account && <div className="text-muted-foreground text-xs">{t.fee_account}</div>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{t.total_withdrawn}</td>
                                    <td className="p-3">{t.reference ?? '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={transfers} />
            </div>
        </AppLayout>
    );
}
