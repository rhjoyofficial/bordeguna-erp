import { Amount, DateCell, DocNo, LedgerHead, MUTED_ROW, PartyText, ReversalPill, TruncatedText } from '@/components/ledger-text';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
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
    voucher: { id: number; number: string | null; status: string | null } | null;
    reversed_by: { id: number; number: string } | null;
    reversal_of: { id: number; number: string } | null;
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

interface RollupRow {
    id: number;
    label: string;
    opening: string;
    debits: string;
    credits: string;
    closing: string;
    moved: boolean;
}

interface Rollup {
    rows: RollupRow[];
    total: { opening: string; debits: string; credits: string; closing: string };
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
    rollup = null,
    ledger_empty,
    hide_reversed = false,
    hidden_journals = 0,
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
    rollup?: Rollup | null;
    ledger_empty: boolean;
    hide_reversed?: boolean;
    hidden_journals?: number;
}) {
    const [filters, setFilters] = useState({
        account: account ? String(account.id) : '',
        from,
        to,
        party: party ?? '',
        hide_reversed: hide_reversed ? '1' : '',
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
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="account">Account</Label>
                        <SearchSelect
                            fit
                            id="account"
                            value={filters.account}
                            onChange={(e) => setFilters({ ...filters, account: e.target.value, party: '' })}
                        >
                            <option value="">Choose an account</option>
                            {accounts.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input id="from" type="date" value={filters.from} onChange={(e) => setFilters({ ...filters, from: e.target.value })} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                    </div>
                    {parties.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="party">Party</Label>
                            <SearchSelect fit id="party" value={filters.party} onChange={(e) => setFilters({ ...filters, party: e.target.value })}>
                                <option value="">Everyone</option>
                                {parties.map((option) => (
                                    <option key={`${option.type}:${option.id}`} value={`${option.type}:${option.id}`}>
                                        {option.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    )}
                    <Button variant="outline">Show</Button>
                </form>

                {/* C-GLA-5. An account has to be chosen, and the page says so rather than showing an empty table. */}
                {!account && (
                    <div className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border rounded-md border px-4 py-8 text-center text-sm">
                        {ledger_empty ? 'Nothing has been posted to the ledger yet.' : 'Choose an account above.'}
                    </div>
                )}

                {/* A heading: one row per head beneath it, each a link to its own ledger, and the total (26 Sep 2026). */}
                {account && rollup && (
                    <>
                        <div className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border rounded-md border px-4 py-3 text-sm">
                            {account.label} is a heading, so nothing is posted to it directly.
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[42rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                        <th className="py-2">Head</th>
                                        <th className="py-2 text-right">Opening</th>
                                        <th className="py-2 text-right">Debits</th>
                                        <th className="py-2 text-right">Credits</th>
                                        <th className="py-2 text-right">Closing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rollup.rows.map((row) => (
                                        <tr
                                            key={row.id}
                                            className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${row.moved ? '' : 'text-muted-foreground'}`}
                                        >
                                            <td className="py-2">
                                                <LedgerHead name={row.label} onClick={() => show({ account: row.id, party: '' })} />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Amount value={row.opening} tone="balance" />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Amount value={row.debits} tone="debit" />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Amount value={row.credits} tone="credit" />
                                            </td>
                                            <td className="py-2 text-right">
                                                <Amount value={row.closing} tone="balance" />
                                            </td>
                                        </tr>
                                    ))}
                                    {rollup.rows.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                                No head sits under {account.label} yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                                <tfoot>
                                    <tr className="font-bold">
                                        <td className="py-2">Total for {account.label}</td>
                                        <td className="py-2 text-right">
                                            <Amount value={rollup.total.opening} tone="total" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={rollup.total.debits} tone="debit" className="font-bold" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={rollup.total.credits} tone="credit" className="font-bold" />
                                        </td>
                                        <td className="py-2 text-right">
                                            <Amount value={rollup.total.closing} tone="total" />
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </>
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
                                <Amount value={summary.opening} tone="balance" suffix={summary.opening_side === 'credit' ? 'Cr' : 'Dr'} />
                            </div>
                            <div>
                                <div className="text-muted-foreground">Debits in period</div>
                                <Amount value={summary.debits} tone="debit" />
                            </div>
                            <div>
                                <div className="text-muted-foreground">Credits in period</div>
                                <Amount value={summary.credits} tone="credit" />
                            </div>
                            <div>
                                <div className="text-muted-foreground">Closing balance</div>
                                <Amount value={summary.closing} tone="total" suffix={summary.closing_side === 'credit' ? 'Cr' : 'Dr'} />
                            </div>
                        </div>

                        {/* A ledger keeps every posted entry; hiding reversed pairs is a view choice, off by default (12 Oct 2026). */}
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <label className="flex cursor-pointer items-center gap-2">
                                <Checkbox
                                    checked={hide_reversed}
                                    onCheckedChange={(checked) => {
                                        const next = checked === true ? '1' : '';
                                        setFilters({ ...filters, hide_reversed: next });
                                        show({ hide_reversed: next, page: 1 });
                                    }}
                                />
                                Hide reversed entries
                            </label>
                            {hide_reversed && hidden_journals > 0 && (
                                <span className="text-muted-foreground">
                                    {hidden_journals} journals hidden: entries reversed in this period, with their reversals. Balances are unchanged.
                                </span>
                            )}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                        <th className="w-px py-2 whitespace-nowrap">Date</th>
                                        <th className="w-px py-2 whitespace-nowrap">Journal</th>
                                        <th className="w-2/3 py-2">Description</th>
                                        <th className="w-1/3 py-2">Party</th>
                                        <th className="w-px py-2 text-right whitespace-nowrap">Debit</th>
                                        <th className="w-px py-2 text-right whitespace-nowrap">Credit</th>
                                        <th className="w-px py-2 text-right whitespace-nowrap">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {entries.map((entry) => (
                                        <tr
                                            key={entry.id}
                                            className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${entry.reversed_by || entry.reversal_of ? MUTED_ROW : ''}`}
                                        >
                                            <td className="py-2 whitespace-nowrap">
                                                <DateCell>{entry.date}</DateCell>
                                            </td>
                                            <td className="py-2 whitespace-nowrap">
                                                <DocNo href={route('accounting.journals.show', entry.journal_id)}>{entry.journal_number}</DocNo>
                                                {entry.voucher?.number ? (
                                                    <div>
                                                        <DocNo href={route('accounting.vouchers.show', entry.voucher.id)} className="text-xs">
                                                            {entry.voucher.number}
                                                        </DocNo>
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="text-muted-foreground max-w-0 py-2">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <TruncatedText text={entry.memo} className="min-w-0 flex-1" />
                                                    {entry.reversed_by && (
                                                        <ReversalPill
                                                            kind="reversed-by"
                                                            number={entry.reversed_by.number}
                                                            href={route('accounting.journals.show', entry.reversed_by.id)}
                                                        />
                                                    )}
                                                    {entry.reversal_of && (
                                                        <ReversalPill
                                                            kind="reversal-of"
                                                            number={entry.reversal_of.number}
                                                            href={route('accounting.journals.show', entry.reversal_of.id)}
                                                        />
                                                    )}
                                                    {!entry.reversed_by && !entry.reversal_of && entry.voucher?.status === 'reversed' && (
                                                        <ReversalPill kind="voucher-reversed" />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="max-w-0 py-2">
                                                {entry.party ? (
                                                    <PartyText className="block">
                                                        <TruncatedText text={entry.party} />
                                                    </PartyText>
                                                ) : null}
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">
                                                <Amount value={entry.debit} tone="debit" />
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">
                                                <Amount value={entry.credit} tone="credit" />
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">
                                                <Amount value={entry.balance} tone="balance" suffix={entry.balance_side === 'credit' ? 'Cr' : 'Dr'} />
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
