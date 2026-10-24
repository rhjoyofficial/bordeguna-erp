import { Chip, CompactTable, Pill, StatusDot } from '@/components/assets/assets-ui';
import { ThingDialog, type ThingFormOptions } from '@/components/assets/thing-dialog';
import { Pagination } from '@/components/data-table/pagination';
import { StockReasonDialog } from '@/components/inventory/stock-reason-dialog';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Item {
    id: number;
    tag: string;
    name: string;
    category: string | null;
    quantity: number;
    is_group: boolean;
    brand_model: string | null;
    location: string;
    status: string;
    status_label: string;
    condition: string;
    condition_label: string;
    part_of: string | null;
    with: string;
    // With an outside party past the day it was expected back (13 Oct 2026).
    overdue: boolean;
}

interface Option {
    value: string;
    label: string;
}

interface Filters {
    q?: string;
    category?: string;
    place?: string;
    status?: string;
    condition?: string;
    idle?: string;
    overdue?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Things', href: '/assets/items' },
];

// ⚠ Condition drawn apart from status: "in use, and damaged" is a real state.
function Condition({ item }: { item: Item }) {
    const bad = item.condition === 'damaged' || item.condition === 'poor';

    return <span className={cn(bad ? 'text-destructive font-medium' : 'text-muted-foreground')}>{item.condition_label}</span>;
}

/**
 * The physical register (asset management slice A1): what the business keeps,
 * where each thing is, and what shape it is in.
 *
 * ⭐ Redesigned 15 Oct 2026 as the Register tab of Things: status chips with
 * their counts above the filters, a compact table, the same actions and the
 * same filter semantics as before. Cards on a phone, a table on a desk: the
 * person checking a shelf is standing at it.
 */
export default function AssetItemsIndex({
    items,
    filters,
    categories,
    places,
    statuses,
    conditions,
    can_manage,
    counts,
    status_counts,
    form,
    deleteReasons,
}: {
    items: Paginated<Item>;
    filters: Filters;
    categories: { id: number; name: string }[];
    places: Option[];
    statuses: Option[];
    conditions: Option[];
    can_manage: boolean;
    counts: { kept: number; pieces: number; damaged: number; under_repair: number; overdue_outside: number };
    status_counts: { value: string; label: string; count: number }[];
    // What the "Add a thing" popup needs; sent only to someone who may add.
    form: ThingFormOptions | null;
    // ⭐ Corrections wave 3 (24 Oct 2026): Delete selected (a double import).
    deleteReasons: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState<number[]>([]);
    const toggle = (id: number) => setSelected((now) => (now.includes(id) ? now.filter((x) => x !== id) : [...now, id]));
    const [q, setQ] = useState(filters.q ?? '');
    // ⭐ "Add a thing" is a popup over the list (13 Oct 2026), not a page of its own.
    // The overview's button arrives with ?add=1 and opens it straight away.
    const [adding, setAdding] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('add'));

    const apply = (next: Filters) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== undefined && value !== ''));
        router.get('/assets/items', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        apply({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Things we keep" />
            <div className="flex flex-col gap-3 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        <span className="text-foreground font-semibold tabular-nums">{counts.kept}</span> kept ·{' '}
                        <span className="tabular-nums">{counts.pieces}</span> pieces
                        {counts.damaged > 0 && (
                            <>
                                {' '}
                                · <span className="text-destructive">{counts.damaged} damaged</span>
                            </>
                        )}
                        {counts.overdue_outside > 0 && (
                            <>
                                {' '}
                                · <span className="text-destructive">{counts.overdue_outside} overdue from outside</span>
                            </>
                        )}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/assets/items/labels">Print tags</Link>
                        </Button>
                        {/* A plain link, not an Inertia visit: the export is a file download. */}
                        <Button variant="outline" size="sm" asChild>
                            <a href="/assets/items/export">Export</a>
                        </Button>
                        {can_manage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/items/import">Import</Link>
                            </Button>
                        )}
                        {can_manage && selected.length > 0 && (
                            <StockReasonDialog
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        {t('corr.assets.delete_selected', { count: selected.length })}
                                    </Button>
                                }
                                title={t('corr.assets.delete_selected_title', { count: selected.length })}
                                description={`${t('corr.assets.delete_thing_note')} ${t('corr.assets.delete_selected_note')}`}
                                confirmLabel={t('corr.assets.delete')}
                                reasons={deleteReasons}
                                url="/assets/items/delete"
                                data={{ ids: selected }}
                                destructive
                            />
                        )}
                        {can_manage && form && (
                            <Button size="sm" onClick={() => setAdding(true)}>
                                Add a thing
                            </Button>
                        )}
                    </div>
                </div>

                {/* ⚠ The chips ARE the status filter, with the count behind each; "All" is every status except disposed, as before. */}
                <div className="flex flex-wrap gap-2">
                    <Chip active={!filters.status} count={counts.kept} onClick={() => apply({ status: '' })}>
                        All
                    </Chip>
                    {status_counts.map((status) => (
                        <Chip
                            key={status.value}
                            active={filters.status === status.value}
                            count={status.count}
                            onClick={() => apply({ status: filters.status === status.value ? '' : status.value })}
                        >
                            {status.label}
                        </Chip>
                    ))}
                    {filters.status === 'disposed' && (
                        <Chip active onClick={() => apply({ status: '' })}>
                            Disposed
                        </Chip>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form onSubmit={search} className="flex w-full gap-2 sm:w-auto">
                        <div className="relative w-full sm:w-64">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2" />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Tag, name, brand, serial, room"
                                className="h-8 pl-8 text-[12.5px]"
                            />
                        </div>
                        <Button type="submit" variant="outline" size="sm">
                            Search
                        </Button>
                    </form>
                    <SearchableSelect
                        fit
                        value={filters.category ?? ''}
                        onChange={(value) => apply({ category: value })}
                        options={[{ value: '', label: 'Every category' }, ...categories.map((c) => ({ value: String(c.id), label: c.name }))]}
                        placeholder="Every category"
                    />
                    <SearchableSelect
                        fit
                        value={filters.place ?? ''}
                        onChange={(value) => apply({ place: value })}
                        options={[{ value: '', label: 'Everywhere' }, ...places]}
                        placeholder="Everywhere"
                    />
                    <SearchableSelect
                        fit
                        value={filters.condition ?? ''}
                        onChange={(value) => apply({ condition: value })}
                        options={[{ value: '', label: 'Any condition' }, ...conditions]}
                        placeholder="Any condition"
                    />
                    {/* Disposed things leave the list unless asked for: the one status the chips do not offer. */}
                    <SearchableSelect
                        fit
                        value={filters.status ?? ''}
                        onChange={(value) => apply({ status: value })}
                        options={[{ value: '', label: 'Every status except disposed' }, ...statuses]}
                        placeholder="Every status"
                    />
                    <Chip active={!!filters.idle} onClick={() => apply({ idle: filters.idle ? '' : '1' })}>
                        Idle only
                    </Chip>
                    <Chip active={!!filters.overdue} tone="danger" onClick={() => apply({ overdue: filters.overdue ? '' : '1' })}>
                        Overdue from outside
                    </Chip>
                    <span className="text-muted-foreground ml-auto text-xs">
                        Showing <span className="tabular-nums">{items.from ?? 0}</span>
                        {items.to && items.to !== items.from ? <span className="tabular-nums"> to {items.to}</span> : null} of{' '}
                        <span className="tabular-nums">{items.total}</span>
                    </span>
                </div>

                {items.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                        Nothing matches.{can_manage && ' Add the first thing with "Add a thing".'}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-col gap-3 md:hidden">
                            {items.data.map((item) => (
                                <Link key={item.id} href={`/assets/items/${item.id}`} className="rounded-lg border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="font-medium">{item.name}</p>
                                            <p className="text-muted-foreground font-mono text-xs">{item.tag}</p>
                                        </div>
                                        <Badge variant="secondary">{item.status_label}</Badge>
                                    </div>
                                    <p className="text-muted-foreground mt-1">
                                        {item.location}
                                        {item.is_group && ` - ${item.quantity} pieces`}
                                        {!item.is_group && ` - ${item.with}`}
                                    </p>
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        <Condition item={item} />
                                        {item.overdue && <Pill tone="danger">Overdue from outside</Pill>}
                                        {item.part_of && <span className="text-muted-foreground text-xs">Part of {item.part_of}</span>}
                                    </div>
                                </Link>
                            ))}
                        </div>

                        <div className="bg-card hidden overflow-hidden rounded-xl border md:block">
                            <CompactTable>
                                <thead className="bg-muted/40 text-muted-foreground">
                                    <tr>
                                        {can_manage && <th className="w-8">{t('corr.assets.select')}</th>}
                                        <th>Tag</th>
                                        <th>Thing</th>
                                        <th>Category</th>
                                        <th>Where</th>
                                        <th>Who has it</th>
                                        <th>Status</th>
                                        <th>Condition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.data.map((item) => (
                                        <tr key={item.id}>
                                            {can_manage && (
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`${t('corr.assets.select')} ${item.tag}`}
                                                        checked={selected.includes(item.id)}
                                                        onChange={() => toggle(item.id)}
                                                    />
                                                </td>
                                            )}
                                            <td className="font-mono text-xs whitespace-nowrap">
                                                <Link href={`/assets/items/${item.id}`} className="underline-offset-2 hover:underline">
                                                    {item.tag}
                                                </Link>
                                            </td>
                                            <td>
                                                <Link href={`/assets/items/${item.id}`} className="font-medium underline-offset-2 hover:underline">
                                                    {item.name}
                                                </Link>
                                                {item.is_group && <span className="text-muted-foreground"> x {item.quantity}</span>}
                                                {item.brand_model && <span className="text-muted-foreground block text-xs">{item.brand_model}</span>}
                                                {item.part_of && <span className="text-muted-foreground block text-xs">Part of {item.part_of}</span>}
                                            </td>
                                            <td className="text-muted-foreground">{item.category}</td>
                                            <td>{item.location}</td>
                                            <td>
                                                <span className={cn(item.with.startsWith('Nobody') && 'text-muted-foreground')}>{item.with}</span>
                                                {item.overdue && (
                                                    <Pill tone="danger" className="ml-2">
                                                        Overdue
                                                    </Pill>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap">
                                                <StatusDot status={item.status} />
                                                {item.status_label}
                                            </td>
                                            <td>
                                                <Condition item={item} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                            <div className="border-t px-3.5 py-2">
                                <Pagination meta={items} />
                            </div>
                        </div>
                        <div className="md:hidden">
                            <Pagination meta={items} />
                        </div>
                    </>
                )}

                {can_manage && form && <ThingDialog open={adding} onOpenChange={setAdding} item={null} options={form} />}
            </div>
        </AppLayout>
    );
}
