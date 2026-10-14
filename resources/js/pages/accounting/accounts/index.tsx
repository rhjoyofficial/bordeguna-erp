import { AccountDialog, type ChartAccount, type ChartAccountType } from '@/components/accounting/account-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyRow, ReportHeading, ReportPage, ReportTable, StatRow, StatTile } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/accounts' },
    { title: 'Chart of accounts', href: '/accounting/accounts' },
];

/**
 * Depth is derived from the parent chain rather than stored. A `depth` column
 * would be a second fact about the same relationship, and the two disagree the
 * first time somebody re-parents an account.
 */
function depthOf(account: ChartAccount, byId: Map<number, ChartAccount>): number {
    let depth = 0;
    let parent = account.parent_id === null ? undefined : byId.get(account.parent_id);

    while (parent && depth < 50) {
        depth += 1;
        parent = parent.parent_id === null ? undefined : byId.get(parent.parent_id);
    }

    return depth;
}

// `undefined` = the popup is closed; `account: null` = a new head.
type DialogState = { account: ChartAccount | null; preset: { type: string; parent_id: number } | null } | undefined;

export default function ChartOfAccounts({
    accounts,
    types,
    money_group_ids,
}: {
    accounts: ChartAccount[];
    types: ChartAccountType[];
    money_group_ids: number[];
}) {
    const { canManageChartOfAccounts, canCreateLedgerHeads, canUpdateLedgerHeads, canDeleteLedgerHeads } = usePage<SharedData>().props;

    // ⚠ New and Edit open ONE popup (Joy, 14 Oct 2026). Nothing above the
    // chart but the counts and the search.
    const [dialog, setDialog] = useState<DialogState>(undefined);
    const [search, setSearch] = useState('');

    const byId = useMemo(() => new Map(accounts.map((account) => [account.id, account])), [accounts]);

    // A search keeps each match AND the groups above it, so a found head is
    // still read in its place ("6320 Security services" under "6300 Admin").
    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (term === '') {
            return accounts;
        }

        const keep = new Set<number>();

        for (const account of accounts) {
            if (account.code.toLowerCase().includes(term) || account.name.toLowerCase().includes(term)) {
                let current: ChartAccount | undefined = account;

                while (current && !keep.has(current.id)) {
                    keep.add(current.id);
                    current = current.parent_id === null ? undefined : byId.get(current.parent_id);
                }
            }
        }

        return accounts.filter((account) => keep.has(account.id));
    }, [accounts, byId, search]);

    // "Add under" is offered only on a group the person may actually file a
    // head under: the same rule the popup's Sits under list applies.
    // ⚠ Never on Cash in Hand, Bank Accounts or Mobile Financial Services, for
    // anyone: the server refuses a head typed in there (Bank & cash makes them).
    const mayAddUnder = (account: ChartAccount) =>
        canCreateLedgerHeads && !account.is_postable && account.is_active && !money_group_ids.includes(account.id);

    const destroy = (account: ChartAccount) => {
        router.delete(route('accounting.accounts.destroy', account.id), { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of accounts" />
            <ReportPage>
                <ReportHeading
                    title="Chart of accounts"
                    actions={
                        canManageChartOfAccounts && (
                            <Button type="button" disabled={!canCreateLedgerHeads} onClick={() => setDialog({ account: null, preset: null })}>
                                New ledger head
                            </Button>
                        )
                    }
                />

                {/* Counts of the rows below, not ledger figures. */}
                <StatRow>
                    <StatTile label="Accounts" value={accounts.length} />
                    <StatTile label="Can be posted to" value={accounts.filter((a) => a.is_postable).length} />
                    <StatTile label="Groups" value={accounts.filter((a) => !a.is_postable).length} />
                    <StatTile label="Inactive" value={accounts.filter((a) => !a.is_active).length} />
                </StatRow>

                <div className="flex flex-wrap items-center gap-3 print:hidden">
                    <Input
                        aria-label="Find an account by code or name"
                        className="w-72"
                        placeholder="Find by code or name"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    {search.trim() !== '' && (
                        <>
                            <span className="text-muted-foreground text-sm">{visible.length} shown, with the groups they sit in</span>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setSearch('')}>
                                Clear
                            </Button>
                        </>
                    )}
                </div>

                <ReportTable
                    minWidth="44rem"
                    head={
                        <>
                            <th className="py-2">Code</th>
                            <th className="py-2">Account</th>
                            <th className="py-2">Type</th>
                            <th className="py-2">Normal balance</th>
                            <th className="py-2 text-right">Actions</th>
                        </>
                    }
                >
                    {visible.map((account) => {
                        const depth = depthOf(account, byId);

                        return (
                            <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="text-muted-foreground py-2 font-mono">{account.code}</td>
                                <td className="py-2" style={{ paddingLeft: `${depth * 1.5}rem` }}>
                                    <span className={account.is_postable ? '' : 'font-semibold'}>{account.name}</span>
                                    {!account.is_postable && <span className="text-muted-foreground ml-2 text-xs">group</span>}
                                    {!account.is_active && (
                                        <Badge variant="destructive" className="ml-2">
                                            Inactive
                                        </Badge>
                                    )}
                                    {account.note && <div className="text-muted-foreground text-xs">{account.note}</div>}
                                </td>
                                <td className="text-muted-foreground py-2">{account.type_label}</td>
                                <td className="text-muted-foreground py-2">{account.normal_balance === 'debit' ? 'Debit' : 'Credit'}</td>
                                <td className="py-2 text-right whitespace-nowrap">
                                    {canManageChartOfAccounts && (
                                        <>
                                            {mayAddUnder(account) && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setDialog({ account: null, preset: { type: account.type, parent_id: account.id } })
                                                    }
                                                >
                                                    Add under
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                disabled={!canUpdateLedgerHeads}
                                                onClick={() => setDialog({ account, preset: null })}
                                            >
                                                Edit
                                            </Button>
                                            <ConfirmDialog
                                                destructive
                                                title={`Delete ${account.code} - ${account.name}?`}
                                                description="Only an account with no postings and nothing under it can be deleted. If it has history, switch it off with Edit instead."
                                                confirmLabel="Delete"
                                                onConfirm={() => destroy(account)}
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={account.is_system || !canDeleteLedgerHeads}
                                                        title={
                                                            account.is_system
                                                                ? 'The system posts to this account. Deactivate it instead of deleting it.'
                                                                : undefined
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                }
                                            />
                                        </>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                    {visible.length === 0 && <EmptyRow colSpan={5}>{search.trim() !== '' ? 'No account matches.' : 'No accounts yet.'}</EmptyRow>}
                </ReportTable>
            </ReportPage>

            {canManageChartOfAccounts && (
                <AccountDialog
                    open={dialog !== undefined}
                    onOpenChange={(open) => !open && setDialog(undefined)}
                    account={dialog?.account ?? null}
                    preset={dialog?.preset ?? null}
                    accounts={accounts}
                    types={types}
                    moneyGroupIds={money_group_ids}
                />
            )}
        </AppLayout>
    );
}
