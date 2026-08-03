import { Button } from '@/components/ui/button';
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
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Trial balance</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Every account with a balance or any movement in the period. In a correct ledger the two columns are equal,
                        because every debit was posted with a matching credit.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} className="w-44" />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} className="w-44" />
                    </div>
                    <Button variant="outline">Show</Button>
                </form>

                {/* The one thing this page exists to say, said before the detail. */}
                {!empty && (
                    <div
                        className={`rounded-md border px-4 py-3 text-sm ${
                            totals.balances
                                ? 'border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border'
                                : 'border-destructive/40 bg-destructive/5 font-medium text-destructive'
                        }`}
                    >
                        {totals.balances
                            ? `Balanced. Debits and credits both total ${totals.debit}.`
                            : `These books do not balance. Debits and credits differ by ${totals.difference}. Run accounting:verify-ledger to find which journal is at fault.`}
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead>
                            <tr className="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border">
                                <th className="py-2">Code</th>
                                <th className="py-2">Account</th>
                                <th className="py-2 text-right">Opening</th>
                                <th className="py-2 text-right">Debit</th>
                                <th className="py-2 text-right">Credit</th>
                                <th className="py-2 text-right">Closing</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.code} className="border-b border-sidebar-border/70 dark:border-sidebar-border">
                                    <td className="py-2 font-mono text-muted-foreground">{row.code}</td>
                                    <td className="py-2">{row.name}</td>
                                    <td className="py-2 text-right tabular-nums text-muted-foreground">
                                        {row.opening && `${row.opening} ${row.opening_side === 'credit' ? 'Cr' : 'Dr'}`}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{row.debit}</td>
                                    <td className="py-2 text-right tabular-nums">{row.credit}</td>
                                    <td className="py-2 text-right tabular-nums font-medium">
                                        {row.closing} {row.closing_side === 'credit' ? 'Cr' : 'Dr'}
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="py-6 text-center text-muted-foreground">
                                        {empty
                                            ? 'Nothing has been posted to the ledger yet.'
                                            : 'No account had a balance or any movement in this period.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
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
                            </tfoot>
                        )}
                    </table>
                </div>

                <p className="max-w-2xl text-sm text-muted-foreground">
                    A trial balance that balances proves the entries were recorded in pairs. It does not prove they were recorded
                    against the right accounts, and it cannot tell you about something nobody entered at all.
                </p>
            </div>
        </AppLayout>
    );
}
