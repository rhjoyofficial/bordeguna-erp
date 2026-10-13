import { ThingDialog, type ThingFormOptions } from '@/components/assets/thing-dialog';
import { Pagination } from '@/components/data-table/pagination';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Things we keep', href: '/assets/items' }];

// ⚠ Condition drawn apart from status: "in use, and damaged" is a real state.
function ConditionBadge({ item }: { item: Item }) {
    if (item.condition === 'damaged' || item.condition === 'poor') {
        return <Badge variant="destructive">{item.condition_label}</Badge>;
    }

    return <Badge variant="outline">{item.condition_label}</Badge>;
}

/**
 * The physical register (asset management slice A1): what the business keeps,
 * where each thing is, and what shape it is in.
 *
 * ⭐ Cards on a phone, a table on a desk: the person checking a shelf is
 * standing at it.
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
    form,
}: {
    items: Paginated<Item>;
    filters: Filters;
    categories: { id: number; name: string }[];
    places: Option[];
    statuses: Option[];
    conditions: Option[];
    can_manage: boolean;
    counts: { kept: number; damaged: number; under_repair: number; overdue_outside: number };
    // What the "Add a thing" popup needs; sent only to someone who may add.
    form: ThingFormOptions | null;
}) {
    const [q, setQ] = useState(filters.q ?? '');
    // ⭐ "Add a thing" is a popup over the list (13 Oct 2026), not a page of its own.
    const [adding, setAdding] = useState(false);

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
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Things we keep</h1>
                        <p className="text-muted-foreground max-w-3xl text-sm">
                            {counts.kept} kept
                            {counts.damaged > 0 && `, ${counts.damaged} damaged`}
                            {counts.under_repair > 0 && `, ${counts.under_repair} under repair`}.
                            {counts.overdue_outside > 0 && (
                                <>
                                    {' '}
                                    <Badge variant="destructive">{counts.overdue_outside} overdue from outside</Badge>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/assets/items/labels">Print tags</Link>
                        </Button>
                        {/* A plain link, not an Inertia visit: the export is a file download. */}
                        <Button variant="outline" asChild>
                            <a href="/assets/items/export">Export</a>
                        </Button>
                        {can_manage && (
                            <Button variant="outline" asChild>
                                <Link href="/assets/items/import">Import</Link>
                            </Button>
                        )}
                        {can_manage && form && <Button onClick={() => setAdding(true)}>Add a thing</Button>}
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-2">
                    <form onSubmit={search} className="flex w-full gap-2 sm:w-auto">
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Tag, name, brand, serial, room"
                            className="w-full sm:w-64"
                        />
                        <Button type="submit" variant="outline">
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
                        value={filters.status ?? ''}
                        onChange={(value) => apply({ status: value })}
                        options={[{ value: '', label: 'Every status except disposed' }, ...statuses]}
                        placeholder="Every status"
                    />
                    <Button variant={filters.idle ? 'default' : 'outline'} size="sm" onClick={() => apply({ idle: filters.idle ? '' : '1' })}>
                        Idle only
                    </Button>
                    <Button
                        variant={filters.overdue ? 'destructive' : 'outline'}
                        size="sm"
                        onClick={() => apply({ overdue: filters.overdue ? '' : '1' })}
                    >
                        Overdue from outside
                    </Button>
                    <SearchableSelect
                        fit
                        value={filters.condition ?? ''}
                        onChange={(value) => apply({ condition: value })}
                        options={[{ value: '', label: 'Any condition' }, ...conditions]}
                        placeholder="Any condition"
                    />
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
                                        <ConditionBadge item={item} />
                                        {item.overdue && <Badge variant="destructive">Overdue from outside</Badge>}
                                        {item.part_of && <span className="text-muted-foreground text-xs">Part of {item.part_of}</span>}
                                    </div>
                                </Link>
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Tag</th>
                                        <th className="p-3">Thing</th>
                                        <th className="p-3">Category</th>
                                        <th className="p-3">Where</th>
                                        <th className="p-3">Who has it</th>
                                        <th className="p-3">Status</th>
                                        <th className="p-3">Condition</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.data.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-3 font-mono text-xs whitespace-nowrap">
                                                <Link href={`/assets/items/${item.id}`} className="underline-offset-2 hover:underline">
                                                    {item.tag}
                                                </Link>
                                            </td>
                                            <td className="p-3">
                                                <Link href={`/assets/items/${item.id}`} className="font-medium underline-offset-2 hover:underline">
                                                    {item.name}
                                                </Link>
                                                {item.is_group && <span className="text-muted-foreground"> x {item.quantity}</span>}
                                                {item.brand_model && <span className="text-muted-foreground block text-xs">{item.brand_model}</span>}
                                                {item.part_of && <span className="text-muted-foreground block text-xs">Part of {item.part_of}</span>}
                                            </td>
                                            <td className="text-muted-foreground p-3">{item.category}</td>
                                            <td className="p-3">{item.location}</td>
                                            <td className="p-3">
                                                {item.with}
                                                {item.overdue && (
                                                    <Badge variant="destructive" className="ml-2">
                                                        Overdue from outside
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="p-3">{item.status_label}</td>
                                            <td className="p-3">
                                                <ConditionBadge item={item} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <Pagination meta={items} />

                {can_manage && form && <ThingDialog open={adding} onOpenChange={setAdding} item={null} options={form} />}
            </div>
        </AppLayout>
    );
}
