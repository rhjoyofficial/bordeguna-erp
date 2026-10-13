import { RenewalChip } from '@/components/assets/renewal-chip';
import { Pagination } from '@/components/data-table/pagination';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Account {
    id: number;
    kind: string;
    kind_label: string;
    name: string;
    provider: string | null;
    identifier: string | null;
    url: string | null;
    responsible: string | null;
    status: string;
    status_label: string;
    renews_on: string | null;
    renewal_tone: 'overdue' | 'soon' | null;
    access_count: number;
    /** The home business, when another business of the group shares this account with us. */
    shared_from: string | null;
}

interface Option {
    value: string;
    label: string;
}

interface Filters {
    q?: string;
    kind?: string;
    status?: string;
    responsible?: string;
    renewing?: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accounts hub', href: '/assets/accounts' }];

/**
 * The accounts hub (asset register slice 4): every account the business owns,
 * who is responsible for it, and when it renews.
 */
export default function CompanyAccountsIndex({
    accounts,
    filters,
    kinds,
    statuses,
    responsibles,
    can_manage,
    counts,
}: {
    accounts: Paginated<Account>;
    filters: Filters;
    kinds: Option[];
    statuses: Option[];
    responsibles: { id: number; label: string }[];
    can_manage: boolean;
    counts: { open: number; renewing: number; overdue: number };
}) {
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next: Filters) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== undefined && value !== ''));
        router.get('/assets/accounts', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounts hub" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Accounts hub</h1>
                        <p className="text-muted-foreground max-w-3xl text-sm">
                            {counts.open} on record
                            {counts.renewing > 0 && (
                                <>
                                    , <span className="text-amber-600 dark:text-amber-500">{counts.renewing} renewing within 30 days</span>
                                </>
                            )}
                            {counts.overdue > 0 && (
                                <>
                                    , <span className="text-destructive">{counts.overdue} overdue</span>
                                </>
                            )}
                            .
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href="/assets/accounts/export">Export</a>
                        </Button>
                        {can_manage && (
                            <Button variant="outline" asChild>
                                <Link href="/assets/accounts/import">Import</Link>
                            </Button>
                        )}
                        {can_manage && (
                            <Button asChild>
                                <Link href="/assets/accounts/create">New account</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-2">
                    <form onSubmit={search} className="flex w-full gap-2 sm:w-auto">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Name, provider, address, URL"
                            className="w-full sm:w-64"
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </form>
                    <SearchableSelect
                        fit
                        value={filters.kind ?? ''}
                        onChange={(value) => apply({ kind: value })}
                        options={[{ value: '', label: 'Every kind' }, ...kinds]}
                        placeholder="Every kind"
                    />
                    <SearchableSelect
                        fit
                        value={filters.status ?? ''}
                        onChange={(value) => apply({ status: value })}
                        options={[{ value: '', label: 'Active and suspended' }, ...statuses]}
                        placeholder="Every status"
                    />
                    <SearchableSelect
                        fit
                        value={filters.responsible ?? ''}
                        onChange={(value) => apply({ responsible: value })}
                        options={[{ value: '', label: 'Anyone responsible' }, ...responsibles.map((o) => ({ value: String(o.id), label: o.label }))]}
                        placeholder="Anyone responsible"
                    />
                    <Button
                        variant={filters.renewing ? 'default' : 'outline'}
                        size="sm"
                        onClick={() => apply({ renewing: filters.renewing ? '' : '1' })}
                    >
                        Renewing soon
                    </Button>
                </div>

                {accounts.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                        Nothing matches.{can_manage && ' Record the first account with "New account", or import a file.'}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-col gap-3 md:hidden">
                            {accounts.data.map((account) => (
                                <Link key={account.id} href={`/assets/accounts/${account.id}`} className="rounded-lg border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="font-medium">{account.name}</p>
                                            <p className="text-muted-foreground text-xs break-all">
                                                {account.kind_label}
                                                {account.provider && ` - ${account.provider}`}
                                                {account.identifier && ` - ${account.identifier}`}
                                            </p>
                                        </div>
                                        <Badge variant={account.status === 'active' ? 'secondary' : 'outline'}>{account.status_label}</Badge>
                                    </div>
                                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        <span className="text-muted-foreground">{account.responsible ?? 'Nobody responsible'}</span>
                                        {account.shared_from && <Badge variant="outline">Shared by {account.shared_from}</Badge>}
                                        <RenewalChip renews_on={account.renews_on} tone={account.renewal_tone} />
                                    </div>
                                </Link>
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Account</th>
                                        <th className="p-3">Kind</th>
                                        <th className="p-3">Provider</th>
                                        <th className="p-3">Responsible</th>
                                        <th className="p-3">Access</th>
                                        <th className="p-3">Renews</th>
                                        <th className="p-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.data.map((account) => (
                                        <tr key={account.id} className="border-t">
                                            <td className="p-3">
                                                <Link
                                                    href={`/assets/accounts/${account.id}`}
                                                    className="font-medium underline-offset-2 hover:underline"
                                                >
                                                    {account.name}
                                                </Link>
                                                {account.identifier && (
                                                    <span className="text-muted-foreground block text-xs break-all">{account.identifier}</span>
                                                )}
                                                {account.shared_from && (
                                                    <Badge variant="outline" className="mt-1 text-xs">
                                                        Shared by {account.shared_from}
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3">{account.kind_label}</td>
                                            <td className="p-3">{account.provider ?? '-'}</td>
                                            <td className="p-3">{account.responsible ?? <span className="text-muted-foreground">-</span>}</td>
                                            <td className="p-3 tabular-nums">{account.access_count}</td>
                                            <td className="p-3">
                                                <RenewalChip renews_on={account.renews_on} tone={account.renewal_tone} />
                                            </td>
                                            <td className="p-3">
                                                <Badge variant={account.status === 'active' ? 'secondary' : 'outline'}>{account.status_label}</Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <Pagination meta={accounts} />
            </div>
        </AppLayout>
    );
}
