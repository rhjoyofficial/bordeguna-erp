import { Chip, CompactTable, Meter, Pill, Stat } from '@/components/assets/assets-ui';
import { RenewalChip } from '@/components/assets/renewal-chip';
import { Pagination } from '@/components/data-table/pagination';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Accounts & logins', href: '/assets/accounts' },
];

/** The kinds grouped into five chips, so the row stays one line (the picker still offers each kind). */
const KIND_GROUPS: { key: string; label: string; kinds: string[] }[] = [
    { key: 'email', label: 'Email', kinds: ['email'] },
    { key: 'web', label: 'Domain and hosting', kinds: ['domain', 'hosting', 'website'] },
    { key: 'software', label: 'Software', kinds: ['saas', 'software', 'cloud'] },
    { key: 'portals', label: 'Portals', kinds: ['bank_portal', 'government_portal', 'courier_portal'] },
    { key: 'other', label: 'Other', kinds: ['social_page', 'other'] },
];

const GROUP_BAR = ['bg-primary', 'bg-primary/70', 'bg-sky-500', 'bg-primary/40', 'bg-muted-foreground/40'];

/**
 * The accounts hub (asset register slice 4): every account the business owns,
 * who is responsible for it, and when it renews.
 *
 * ⭐ Redesigned 15 Oct 2026 as the first tab of Accounts & logins: the
 * numbers at the top (each one a filter), the kinds as chips, a compact
 * table with the "Shared by" badge. Same filters, same meaning.
 */
export default function CompanyAccountsIndex({
    accounts,
    filters,
    kinds,
    statuses,
    responsibles,
    can_manage,
    counts,
    by_kind,
}: {
    accounts: Paginated<Account>;
    filters: Filters;
    kinds: Option[];
    statuses: Option[];
    responsibles: { id: number; label: string }[];
    can_manage: boolean;
    counts: { open: number; renewing: number; overdue: number; shared: number };
    by_kind: { value: string; count: number }[];
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

    const groups = KIND_GROUPS.map((group) => ({
        ...group,
        count: by_kind.filter((row) => group.kinds.includes(row.value)).reduce((sum, row) => sum + row.count, 0),
    }));
    const activeGroup = groups.find((group) => filters.kind && group.kinds.includes(filters.kind));
    // ⚠ A chip of several kinds opens the first of them in the filter, and the picker beside it narrows further.
    const pickGroup = (group: (typeof groups)[number]) => apply({ kind: activeGroup?.key === group.key ? '' : group.kinds[0] });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounts hub" />
            <div className="flex flex-col gap-3 p-4">
                <div className="flex flex-wrap items-end justify-end gap-3">
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href="/assets/accounts/export">Export</a>
                        </Button>
                        {can_manage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/accounts/import">Import</Link>
                            </Button>
                        )}
                        {can_manage && (
                            <Button size="sm" asChild>
                                <Link href="/assets/accounts/create">New account</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <section className="bg-card flex flex-wrap items-center gap-x-10 gap-y-3 rounded-xl border px-5 py-4">
                    <Stat label="On record" value={counts.open} />
                    <button type="button" onClick={() => apply({ renewing: '1', kind: '' })} className="text-left underline-offset-4 hover:underline">
                        <Stat label="Renewing within 30 days" value={counts.renewing} tone={counts.renewing > 0 ? 'warning' : 'ok'} />
                    </button>
                    <button type="button" onClick={() => apply({ renewing: '1', kind: '' })} className="text-left underline-offset-4 hover:underline">
                        <Stat label="Renewal overdue" value={counts.overdue} tone={counts.overdue > 0 ? 'danger' : 'ok'} />
                    </button>
                    <Stat
                        label="Shared by the group"
                        value={counts.shared}
                        hint="Accounts another business of the group shares with this one. Readable here, changed only in the home business."
                    />
                    {counts.open > 0 && (
                        <div className="ml-auto max-w-md min-w-64 flex-1 basis-72">
                            <p className="text-muted-foreground text-xs">By kind</p>
                            <Meter
                                className="mt-2"
                                label={groups.map((group) => `${group.label} ${group.count}`).join(', ')}
                                segments={groups.map((group, index) => ({ share: (group.count / counts.open) * 100, className: GROUP_BAR[index] }))}
                            />
                            <p className="text-muted-foreground mt-1.5 text-xs">
                                {groups
                                    .filter((group) => group.count > 0)
                                    .map((group, index) => (
                                        <span key={group.key}>
                                            {index > 0 && ' · '}
                                            {group.label} <span className="tabular-nums">{group.count}</span>
                                        </span>
                                    ))}
                            </p>
                        </div>
                    )}
                </section>

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={search} className="flex w-full gap-2 sm:w-auto">
                        <div className="relative w-full sm:w-60">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Name, provider, address, URL"
                                className="h-8 pl-8 text-[12.5px]"
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Search
                        </Button>
                    </form>
                    <Chip active={!filters.kind} onClick={() => apply({ kind: '' })}>
                        Every kind
                    </Chip>
                    {groups.map((group) => (
                        <Chip key={group.key} active={activeGroup?.key === group.key} onClick={() => pickGroup(group)}>
                            {group.label}
                        </Chip>
                    ))}
                    <SearchableSelect
                        fit
                        value={filters.kind ?? ''}
                        onChange={(value) => apply({ kind: value })}
                        options={[{ value: '', label: 'Every kind' }, ...kinds]}
                        placeholder="Every kind"
                    />
                    <SearchableSelect
                        fit
                        value={filters.responsible ?? ''}
                        onChange={(value) => apply({ responsible: value })}
                        options={[{ value: '', label: 'Anyone responsible' }, ...responsibles.map((o) => ({ value: String(o.id), label: o.label }))]}
                        placeholder="Anyone responsible"
                    />
                    <SearchableSelect
                        fit
                        value={filters.status ?? ''}
                        onChange={(value) => apply({ status: value })}
                        options={[{ value: '', label: 'Active and suspended' }, ...statuses]}
                        placeholder="Every status"
                    />
                    <Chip
                        active={!!filters.renewing}
                        tone={counts.renewing > 0 ? 'warning' : undefined}
                        onClick={() => apply({ renewing: filters.renewing ? '' : '1' })}
                    >
                        Renewing soon
                    </Chip>
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
                                        {account.shared_from && <Pill tone="info">Shared by {account.shared_from}</Pill>}
                                        <RenewalChip renews_on={account.renews_on} tone={account.renewal_tone} />
                                    </div>
                                </Link>
                            ))}
                        </div>

                        <div className="bg-card hidden overflow-hidden rounded-xl border md:block">
                            <CompactTable>
                                <thead className="bg-muted/40 text-muted-foreground">
                                    <tr>
                                        <th>Account</th>
                                        <th>Kind</th>
                                        <th>Provider</th>
                                        <th>Responsible</th>
                                        <th className="text-right">Can sign in</th>
                                        <th>Renews</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.data.map((account) => (
                                        <tr key={account.id}>
                                            <td>
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
                                                    <Pill tone="info" className="mt-1">
                                                        Shared by {account.shared_from}
                                                    </Pill>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground">{account.kind_label}</td>
                                            <td>{account.provider ?? '-'}</td>
                                            <td>{account.responsible ?? <span className="text-muted-foreground">-</span>}</td>
                                            <td className="text-right tabular-nums">{account.access_count}</td>
                                            <td className="whitespace-nowrap">
                                                <RenewalChip renews_on={account.renews_on} tone={account.renewal_tone} />
                                            </td>
                                            <td>
                                                <Pill tone={account.status === 'active' ? 'muted' : 'warning'}>{account.status_label}</Pill>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-t px-3.5 py-2">
                                <p className="text-muted-foreground text-xs">
                                    Passwords never show in this list or in the export. They open on the account, after you type your own password
                                    again.
                                </p>
                                <Pagination meta={accounts} />
                            </div>
                        </div>
                        <div className="md:hidden">
                            <Pagination meta={accounts} />
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
