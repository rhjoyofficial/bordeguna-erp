import { Amount, LedgerHead } from '@/components/ledger-text';
import { DateRangeBar, EmptyRow, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
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
    fiscal_year_start_month,
    rows,
    totals,
    empty,
}: {
    from: string;
    to: string;
    fiscal_year_start_month: number | null;
    rows: Row[];
    totals: Totals;
    empty: boolean;
}) {
    const [range, setRange] = useState({ from, to });

    const show = (next: { from: string; to: string }) => {
        setRange(next);
        router.get(route('accounting.trial-balance'), next, { preserveState: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        show(range);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trial balance" />

            <ReportPage>
                <ReportHeading title="Trial balance" />

                <DateRangeBar
                    from={range.from}
                    to={range.to}
                    onChange={setRange}
                    onSubmit={submit}
                    onApply={show}
                    fiscalYearStartMonth={fiscal_year_start_month}
                />

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
                                <tr className="font-bold">
                                    <td className="py-2" colSpan={5}>
                                        Total debits
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={totals.debit} tone="debit" className="font-bold" />
                                    </td>
                                </tr>
                                <tr className="font-bold">
                                    <td className="py-2" colSpan={5}>
                                        Total credits
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={totals.credit} tone="credit" className="font-bold" />
                                    </td>
                                </tr>
                            </>
                        ) : undefined
                    }
                >
                    {rows.map((row) => (
                        <tr key={row.code} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                            <td className="text-muted-foreground py-2 font-mono text-xs">{row.code}</td>
                            <td className="py-2">
                                <LedgerHead name={row.name} />
                            </td>
                            <td className="text-muted-foreground py-2 text-right tabular-nums">
                                {row.opening && `${row.opening} ${row.opening_side === 'credit' ? 'Cr' : 'Dr'}`}
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.debit} tone="debit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.credit} tone="credit" />
                            </td>
                            <td className="py-2 text-right">
                                <Amount value={row.closing} tone="balance" suffix={row.closing_side === 'credit' ? 'Cr' : 'Dr'} />
                            </td>
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <EmptyRow colSpan={6}>
                            {empty ? 'Nothing has been posted to the ledger yet.' : 'No account had a balance or any movement in this period.'}
                        </EmptyRow>
                    )}
                </ReportTable>
            </ReportPage>
        </AppLayout>
    );
}
