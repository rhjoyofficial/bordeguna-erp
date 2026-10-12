import { Amount, LedgerHead } from '@/components/ledger-text';
import { DateRangeBar, ReportHeading } from '@/components/report/report-shell';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface MoneyAccountLabel {
    label: string;
    type_label: string;
    is_active: boolean;
}

interface Row {
    account_id: number;
    code: string;
    name: string;
    money_accounts: MoneyAccountLabel[];
    opening: string | null;
    opening_side: 'debit' | 'credit';
    in: string | null;
    out: string | null;
    closing: string;
    closing_side: 'debit' | 'credit';
}

interface Totals {
    opening: string;
    opening_side: 'debit' | 'credit';
    in: string;
    out: string;
    closing: string;
    closing_side: 'debit' | 'credit';
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Cash & bank book', href: '/accounting/cash-and-bank' },
];

/* A credit balance on a cash or bank row is the overdrawn case, and it is the
 * one state worth a marker: an unmarked figure would read as money in hand. */
const side = (amount: string, sideOf: 'debit' | 'credit') => (sideOf === 'credit' ? `${amount} Cr` : amount);

export default function CashAndBankIndex({
    from,
    to,
    fiscal_year_start_month,
    rows,
    totals,
    unmapped,
    noMoneyAccounts,
    ledgerEmpty,
}: {
    from: string;
    to: string;
    fiscal_year_start_month: number | null;
    rows: Row[];
    totals: Totals;
    unmapped: string[];
    noMoneyAccounts: boolean;
    ledgerEmpty: boolean;
}) {
    const [range, setRange] = useState({ from, to });

    const show = (next: { from: string; to: string }) => {
        setRange(next);
        router.get(route('accounting.cash-and-bank'), next, { preserveState: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        show(range);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash & bank book" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Cash & bank book" />

                <DateRangeBar
                    from={range.from}
                    to={range.to}
                    onChange={setRange}
                    onSubmit={submit}
                    onApply={show}
                    fiscalYearStartMonth={fiscal_year_start_month}
                />

                {/* C-CBB-4: whose movements this report cannot see. */}
                {unmapped.length > 0 && (
                    <div className="rounded-md border border-amber-500/40 bg-amber-500/5 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                        Not included: {unmapped.join(', ')}. {unmapped.length === 1 ? 'This account is' : 'These accounts are'} not linked to a ledger
                        account, so {unmapped.length === 1 ? 'its' : 'their'} movements appear nowhere on this report. Link{' '}
                        {unmapped.length === 1 ? 'it' : 'them'} on the{' '}
                        <Link href="/accounting/money-accounts" className="underline underline-offset-2">
                            Bank &amp; cash
                        </Link>{' '}
                        screen.
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2">Code</th>
                                <th className="py-2">Account</th>
                                <th className="py-2 text-right">Opening</th>
                                <th className="py-2 text-right">Money in</th>
                                <th className="py-2 text-right">Money out</th>
                                <th className="py-2 text-right">Closing</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.account_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b align-top">
                                    <td className="text-muted-foreground py-2 font-mono text-xs">{row.code}</td>
                                    <td className="py-2">
                                        {/* C-CBB-5: the drill-down is the existing General Ledger, window preserved. */}
                                        <LedgerHead
                                            name={row.name}
                                            href={`/accounting/general-ledger?account=${row.account_id}&from=${from}&to=${to}`}
                                        />
                                        {/* C-CBB-1: which tills and banks the figure spans - labels, not filters. */}
                                        <div className="text-muted-foreground text-xs">
                                            {row.money_accounts.map((a) => `${a.label}${a.is_active ? '' : ' (deactivated)'}`).join(' · ')}
                                        </div>
                                    </td>
                                    <td className="text-muted-foreground py-2 text-right tabular-nums">
                                        {row.opening && side(row.opening, row.opening_side)}
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.in} tone="debit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={row.out} tone="credit" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={side(row.closing, row.closing_side)} tone="balance" negative={row.closing_side === 'credit'} />
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                        {noMoneyAccounts ? (
                                            <>
                                                No bank accounts, cash boxes or wallets have been set up yet. Add them on the{' '}
                                                <Link href="/accounting/money-accounts" className="underline underline-offset-2">
                                                    Bank &amp; cash
                                                </Link>{' '}
                                                screen.
                                            </>
                                        ) : ledgerEmpty ? (
                                            'Nothing has been posted to the ledger yet.'
                                        ) : (
                                            'No linked account had a balance or any movement in this period.'
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="font-bold">
                                    <td className="py-2" colSpan={2}>
                                        Total
                                    </td>
                                    <td className="text-muted-foreground py-2 text-right tabular-nums">
                                        {side(totals.opening, totals.opening_side)}
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={totals.in} tone="debit" className="font-bold" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={totals.out} tone="credit" className="font-bold" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount
                                            value={side(totals.closing, totals.closing_side)}
                                            tone="total"
                                            negative={totals.closing_side === 'credit' ? true : undefined}
                                        />
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
