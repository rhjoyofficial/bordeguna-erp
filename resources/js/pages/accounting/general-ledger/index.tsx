import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface AccountOption {
    id: number;
    label: string;
    type_label: string;
    postable: boolean;
}

interface PartyOption {
    type: string;
    id: number;
    label: string;
}

interface Entry {
    id: number;
    journal_id: number;
    journal_number: string;
    date: string;
    memo: string | null;
    party: string | null;
    debit: string | null;
    credit: string | null;
    balance: string;
    balance_side: 'debit' | 'credit';
}

interface Summary {
    opening: string;
    opening_side: 'debit' | 'credit';
    debits: string;
    credits: string;
    closing: string;
    closing_side: 'debit' | 'credit';
    checked: boolean;
    consistent: boolean;
    trial_balance_closing: string | null;
    party_filtered: boolean;
}

interface Pagination {
    page: number;
    pages: number;
    total: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'General ledger', href: '/accounting/general-ledger' },
];

export default function GeneralLedgerIndex({
    from,
    to,
    accounts,
    account,
    parties,
    party,
    entries,
    summary,
    pagination,
    ledger_empty,
}: {
    from: string;
    to: string;
    accounts: AccountOption[];
    account: { id: number; code: string; name: string; label: string; type_label: string } | null;
    parties: PartyOption[];
    party: string | null;
    entries: Entry[];
    summary: Summary | null;
    pagination: Pagination | null;
    ledger_empty: boolean;
}) {
    const [filters, setFilters] = useState({
        account: account ? String(account.id) : '',
        from,
        to,
        party: party ?? '',
    });

    const show = (overrides: Record<string, string | number> = {}) => {
        router.get(route('accounting.general-ledger'), { ...filters, ...overrides }, { preserveState: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        show();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="General ledger" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">General ledger</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        Every posting against one account, in the order it happened, with the balance after each one. This is the detail behind a line
                        on the trial balance or either statement.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="account">Account</Label>
                        <select
                            id="account"
                            value={filters.account}
                            onChange={(e) => setFilters({ ...filters, account: e.target.value, party: '' })}
                            className="border-input bg-background h-9 w-80 rounded-md border px-3 text-sm"
                        >
                            <option value="">Choose an account</option>
                            {accounts.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={filters.from}
                            onChange={(e) => setFilters({ ...filters, from: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={filters.to}
                            onChange={(e) => setFilters({ ...filters, to: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    {parties.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="party">Party</Label>
                            <select
                                id="party"
                                value={filters.party}
                                onChange={(e) => setFilters({ ...filters, party: e.target.value })}
                                className="border-input bg-background h-9 w-56 rounded-md border px-3 text-sm"
                            >
                                <option value="">Everyone</option>
                                {parties.map((option) => (
                                    <option key={`${option.type}:${option.id}`} value={`${option.type}:${option.id}`}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <Button variant="outline">Show</Button>
                </form>

                {/* C-GLA-5. An account has to be chosen, and the page says so rather than showing an empty table. */}
                {!account && (
                    <div className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border rounded-md border px-4 py-8 text-center text-sm">
                        {ledger_empty
                            ? 'Nothing has been posted to the ledger yet.'
                            : 'Choose an account above. A general ledger is one account at a time; for everything in date order, use Journals.'}
                    </div>
                )}

                {account && summary && (
                    <>
                        {/* ⚠⚠ C-GLA-4. The report checks itself against the trial balance and says so. */}
                        {summary.checked && !summary.consistent && (
                            <div className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border px-4 py-3 text-sm font-medium">
                                This listing does not agree with the trial balance. It shows a closing balance of {summary.closing}{' '}
                                {summary.closing_side === 'credit' ? 'Cr' : 'Dr'}, while the trial balance reports {summary.trial_balance_closing} for
                                the same account and period. Postings are missing from this page. Run accounting:verify-ledger.
                            </div>
                        )}

                        {summary.party_filtered && (
                            <div className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border rounded-md border px-4 py-3 text-sm">
                                Filtered to one party. The opening balance below is that party's share of the account, not the account's own opening
                                balance, and there is no trial-balance figure to check it against.
                            </div>
                        )}

                        <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap gap-x-10 gap-y-2 rounded-md border px-4 py-3 text-sm">
                            <div>
                                <div className="text-muted-foreground">Opening balance</div>
                                <div className="font-medium tabular-nums">
                                    {summary.opening} {summary.opening_side === 'credit' ? 'Cr' : 'Dr'}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Debits in period</div>
                                <div className="font-medium tabular-nums">{summary.debits}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Credits in period</div>
                                <div className="font-medium tabular-nums">{summary.credits}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">Closing balance</div>
                                <div className="font-medium tabular-nums">
                                    {summary.closing} {summary.closing_side === 'credit' ? 'Cr' : 'Dr'}
                                </div>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[60rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                        <th className="py-2">Date</th>
                                        <th className="py-2">Journal</th>
                                        <th className="py-2">Description</th>
                                        <th className="py-2">Party</th>
                                        <th className="py-2 text-right">Debit</th>
                                        <th className="py-2 text-right">Credit</th>
                                        <th className="py-2 text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-2 whitespace-nowrap">{entry.date}</td>
                                            <td className="py-2">
                                                <Link
                                                    href={route('accounting.journals.show', entry.journal_id)}
                                                    className="text-primary font-mono underline-offset-4 hover:underline"
                                                >
                                                    {entry.journal_number}
                                                </Link>
                                            </td>
                                            <td className="text-muted-foreground py-2">{entry.memo}</td>
                                            <td className="py-2">{entry.party}</td>
                                            <td className="py-2 text-right tabular-nums">{entry.debit}</td>
                                            <td className="py-2 text-right tabular-nums">{entry.credit}</td>
                                            <td className="py-2 text-right font-medium tabular-nums">
                                                {entry.balance} {entry.balance_side === 'credit' ? 'Cr' : 'Dr'}
                                            </td>
                                        </tr>
                                    ))}
                                    {entries.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-muted-foreground py-6 text-center">
                                                Nothing was posted to {account.label} in this period.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>

                        {pagination && pagination.pages > 1 && (
                            <div className="flex items-center gap-3 text-sm">
                                <Button variant="outline" disabled={pagination.page <= 1} onClick={() => show({ page: pagination.page - 1 })}>
                                    Previous
                                </Button>
                                <span className="text-muted-foreground">
                                    Page {pagination.page} of {pagination.pages}, {pagination.total} postings
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={pagination.page >= pagination.pages}
                                    onClick={() => show({ page: pagination.page + 1 })}
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
