import { Amount, DateCell, DocNo, PartyText } from '@/components/ledger-text';
import { showDate } from '@/lib/dates';
import { Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';

/**
 * ⭐ Supplier advances (ruling P2, 18 Oct 2026): their own ageing, below the
 * payables, never mixed into what we owe. Aged from the day the money left;
 * the total is checked against 1160 Advances to Suppliers the same way the
 * payables are checked against 2110.
 */
export interface AdvanceAgeingData {
    rows: {
        party_id: number;
        party_name: string;
        buckets: Record<string, string>;
        total: string;
        documents: { number: string; date: string; days: number; bucket: string; total: string; open: string }[];
    }[];
    totals: { buckets: Record<string, string>; total: string };
    control: { account: string | null; balance: string | null; ties_out: boolean; difference: string };
}

export function AdvanceAgeing({ data, buckets }: { data: AdvanceAgeingData; buckets: { key: string; label: string }[] }) {
    const [open, setOpen] = useState<number | null>(null);

    return (
        <section className="bg-card text-card-foreground rounded-xl border">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                <div>
                    <h2 className="font-semibold">Advances to suppliers</h2>
                    <p className="text-muted-foreground text-xs">
                        Money suppliers hold of ours, by how long they have held it. Not part of what we owe above.
                    </p>
                </div>
                {data.control.balance !== null && (
                    <p className={`text-xs ${data.control.ties_out ? 'text-muted-foreground' : 'text-destructive'}`}>
                        {data.control.ties_out
                            ? `${data.control.account} stands at ${data.control.balance}, and so does this table.`
                            : `${data.control.account} stands at ${data.control.balance}; this table disagrees by ${data.control.difference}.`}
                    </p>
                )}
            </div>
            {data.rows.length === 0 ? (
                <p className="text-muted-foreground px-4 py-3 text-sm">No supplier holds an advance on this date.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="px-3.5 py-2">Supplier</th>
                                {buckets.map((bucket) => (
                                    <th key={bucket.key} className="px-3.5 py-2 text-right">
                                        {bucket.label}
                                    </th>
                                ))}
                                <th className="px-3.5 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.rows.map((row) => (
                                <Fragment key={row.party_id}>
                                    <tr
                                        className="hover:bg-muted/40 cursor-pointer border-b"
                                        onClick={() => setOpen(open === row.party_id ? null : row.party_id)}
                                    >
                                        <td className="px-3.5 py-2">
                                            <PartyText>{row.party_name}</PartyText>
                                        </td>
                                        {buckets.map((bucket) => (
                                            <td key={bucket.key} className="px-3.5 py-2 text-right">
                                                <Amount value={row.buckets[bucket.key]} tone="balance" />
                                            </td>
                                        ))}
                                        <td className="px-3.5 py-2 text-right">
                                            <Amount value={row.total} tone="total" />
                                        </td>
                                    </tr>
                                    {open === row.party_id && (
                                        <tr className="bg-muted/30 border-b">
                                            <td colSpan={buckets.length + 2} className="px-3.5 py-2">
                                                <ul className="grid gap-1 text-xs">
                                                    {row.documents.map((document) => (
                                                        <li
                                                            key={`${document.number}-${document.date}`}
                                                            className="flex flex-wrap justify-between gap-2"
                                                        >
                                                            <span>
                                                                <DocNo>{document.number}</DocNo> <DateCell>{showDate(document.date)}</DateCell>,{' '}
                                                                {document.days} days
                                                            </span>
                                                            <span className="tabular-nums">{document.open}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                                <Link
                                                    href={`/purchasing/suppliers/${row.party_id}/advances`}
                                                    className="mt-1 inline-block text-xs hover:underline"
                                                >
                                                    Open this supplier&apos;s advances
                                                </Link>
                                            </td>
                                        </tr>
                                    )}
                                </Fragment>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t-2 font-bold">
                                <td className="px-3.5 py-2">Total</td>
                                {buckets.map((bucket) => (
                                    <td key={bucket.key} className="px-3.5 py-2 text-right">
                                        <Amount value={data.totals.buckets[bucket.key]} tone="total" />
                                    </td>
                                ))}
                                <td className="px-3.5 py-2 text-right">
                                    <Amount value={data.totals.total} tone="total" />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </section>
    );
}
