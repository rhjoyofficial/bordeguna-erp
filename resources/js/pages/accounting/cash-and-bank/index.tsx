import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    rows,
    totals,
    unmapped,
    noMoneyAccounts,
    ledgerEmpty,
}: {
    from: string;
    to: string;
    rows: Row[];
    totals: Totals;
    unmapped: string[];
    noMoneyAccounts: boolean;
    ledgerEmpty: boolean;
}) {
    const [range, setRange] = useState({ from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.cash-and-bank'), range, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash & bank book" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Cash &amp; bank book</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        Where the money sits and how it moved in the period. One row per ledger account that a bank account, cash box or wallet
                        reports under; the figures are the trial balance&apos;s own.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
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
                    <Button variant="outline">Show</Button>
                </form>

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
                                    <td className="text-muted-foreground py-2 font-mono">{row.code}</td>
                                    <td className="py-2">
                                        {/* C-CBB-5: the drill-down is the existing General Ledger, window preserved. */}
                                        <Link
                                            href={`/accounting/general-ledger?account=${row.account_id}&from=${from}&to=${to}`}
                                            className="underline-offset-2 hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        {/* C-CBB-1: which tills and banks the figure spans - labels, not filters. */}
                                        <div className="text-muted-foreground text-xs">
                                            {row.money_accounts.map((a) => `${a.label}${a.is_active ? '' : ' (deactivated)'}`).join(' · ')}
                                        </div>
                                    </td>
                                    <td className="text-muted-foreground py-2 text-right tabular-nums">
                                        {row.opening && side(row.opening, row.opening_side)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{row.in}</td>
                                    <td className="py-2 text-right tabular-nums">{row.out}</td>
                                    <td className="py-2 text-right font-medium tabular-nums">{side(row.closing, row.closing_side)}</td>
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
                                <tr className="font-medium">
                                    <td className="py-2" colSpan={2}>
                                        Total
                                    </td>
                                    <td className="text-muted-foreground py-2 text-right tabular-nums">
                                        {side(totals.opening, totals.opening_side)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{totals.in}</td>
                                    <td className="py-2 text-right tabular-nums">{totals.out}</td>
                                    <td className="py-2 text-right tabular-nums">{side(totals.closing, totals.closing_side)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                <p className="text-muted-foreground max-w-2xl text-sm">
                    A row is a ledger account, so several bank accounts that report under one ledger account appear as one figure: the books do not
                    record a split between them. Click an account to see every posting behind its balance.
                </p>
            </div>
        </AppLayout>
    );
}
