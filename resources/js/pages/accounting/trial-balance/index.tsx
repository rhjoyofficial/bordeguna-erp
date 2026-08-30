import { EmptyRow, FilterBar, Footnote, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Row {
    code: string;
    name: string;
    type_label: string;
    opening: string | null;
    opening_side: 'debit' | 'credit';
    debit: string | null;
    credit: string | null;
    closing: string;
    closing_side: 'debit' | 'credit';
}

interface Totals {
    debit: string;
    credit: string;
    difference: string;
    balances: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Trial balance', href: '/accounting/trial-balance' },
];

export default function TrialBalanceIndex({
    from,
    to,
    rows,
    totals,
    empty,
}: {
    from: string;
    to: string;
    rows: Row[];
    totals: Totals;
    empty: boolean;
}) {
    const [range, setRange] = useState({ from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.trial-balance'), range, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trial balance" />

            <ReportPage>
                <ReportHeading
                    title="Trial balance"
                    description="Every account with a balance or any movement in the period. In a correct ledger the two columns are equal, because every debit was posted with a matching credit."
                />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={range.from}
                            onChange={(e) => setRange({ ...range, from: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} className="w-44" />
                    </div>
                </FilterBar>

                {/* The one thing this page exists to say, said before the detail. */}
                {!empty && (
                    <Verdict tone={totals.balances ? 'plain' : 'bad'}>
                        {totals.balances
                            ? `Balanced. Debits and credits both total ${totals.debit}.`
                            : `These books do not balance. Debits and credits differ by ${totals.difference}. Run accounting:verify-ledger to find which journal is at fault.`}
                    </Verdict>
                )}

                {!empty && (
                    <StatRow columns={3}>
                        <StatTile label="Total debits" value={totals.debit} />
                        <StatTile label="Total credits" value={totals.credit} />
                        <StatTile
                            label="Difference"
                            value={totals.difference}
                            tone={totals.balances ? 'good' : 'bad'}
                            hint={totals.balances ? 'As it must be.' : 'Every entry should have been posted in a pair.'}
                        />
                    </StatRow>
                )}

                <ReportTable
                    head={
                        <>
                            <th className="py-2">Code</th>
                            <th className="py-2">Account</th>
                            <th className="py-2 text-right">Opening</th>
                            <th className="py-2 text-right">Debit</th>
                            <th className="py-2 text-right">Credit</th>
                            <th className="py-2 text-right">Closing</th>
                        </>
                    }
                    foot={
                        rows.length > 0 ? (
                            <>
                                <tr className="font-medium">
                                    <td className="py-2" colSpan={5}>
                                        Total debits
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{totals.debit}</td>
                                </tr>
                                <tr className="font-medium">
                                    <td className="py-2" colSpan={5}>
                                        Total credits
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{totals.credit}</td>
                                </tr>
                            </>
                        ) : undefined
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.code} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="text-muted-foreground py-2 font-mono">{row.code}</td>
                            <td className="py-2">{row.name}</td>
                            <td className="text-muted-foreground py-2 text-right tabular-nums">
                                {row.opening && `${row.opening} ${row.opening_side === 'credit' ? 'Cr' : 'Dr'}`}
                            </td>
                            <td className="py-2 text-right tabular-nums">{row.debit}</td>
                            <td className="py-2 text-right tabular-nums">{row.credit}</td>
                            <td className="py-2 text-right font-medium tabular-nums">
                                {row.closing} {row.closing_side === 'credit' ? 'Cr' : 'Dr'}
                            </td>
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <EmptyRow colSpan={6}>
                            {empty ? 'Nothing has been posted to the ledger yet.' : 'No account had a balance or any movement in this period.'}
                        </EmptyRow>
                    )}
                </ReportTable>

                <Footnote>
                    A trial balance that balances proves the entries were recorded in pairs. It does not prove they were recorded against the right
                    accounts, and it cannot tell you about something nobody entered at all.
                </Footnote>
            </ReportPage>
        </AppLayout>
    );
}
