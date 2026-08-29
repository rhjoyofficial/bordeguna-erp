import { Pagination } from '@/components/data-table/pagination';
import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Change {
    field: string;
    from: string | null;
    to: string | null;
}

interface Entry {
    id: number;
    at: string;
    event: string | null;
    subject: string;
    subject_id: number | null;
    causer: string;
    changes: Change[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit trail', href: '/settings/audit' }];

const eventTone: Record<string, 'secondary' | 'outline' | 'destructive'> = {
    created: 'secondary',
    updated: 'outline',
    deleted: 'destructive',
};

/** How many changed fields a row shows before it needs opening. */
const CHANGES_SHOWN = 3;

function isoDaysAgo(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return date.toISOString().slice(0, 10);
}

function isoToday(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function Audit({
    entries,
    filters: initialFilters,
    types,
    events,
    people,
}: {
    entries: Paginated<Entry>;
    filters: { search: string; type: string; event: string; causer: string; record: string; from: string; to: string };
    types: { value: string; label: string }[];
    events: string[];
    people: { value: string; label: string }[];
}) {
    const { filters, setFilter, setMany, reset, hasActiveFilters } = useFilters('/settings/audit', initialFilters);
    const [opened, setOpened] = useState<number[]>([]);

    const toggle = (id: number) => setOpened((o) => (o.includes(id) ? o.filter((x) => x !== id) : [...o, id]));

    // ⚠ The presets set BOTH ends in one update. Two calls to setFilter would
    // queue two debounced visits and send the first with half a range.
    const preset = (days: number) => setMany({ from: days === 0 ? isoToday() : isoDaysAgo(days), to: isoToday() });

    const activeCount = Object.values(filters).filter((v) => v !== '' && v !== null).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit trail" />

            <SettingsLayout wide>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Audit trail"
                        description="Every change the system recorded: who made it, when, and what it was before. Read-only, and nothing here can be edited or removed."
                    />

                    <div className="space-y-3 rounded-lg border p-4">
                        <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                            <div className="grid gap-1.5 md:col-span-2">
                                <Label htmlFor="audit-search">Search</Label>
                                <Input
                                    id="audit-search"
                                    placeholder="A name, a value, anything"
                                    value={filters.search}
                                    onChange={(e) => setFilter('search', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-causer">Who</Label>
                                <SearchSelect id="audit-causer" value={filters.causer} onChange={(e) => setFilter('causer', e.target.value)}>
                                    <option value="">Anyone</option>
                                    {people.map((person) => (
                                        <option key={person.value} value={person.value}>
                                            {person.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-type">Record</Label>
                                <SearchSelect id="audit-type" value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                                    <option value="">Everything</option>
                                    {types.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-event">Action</Label>
                                <SearchSelect id="audit-event" value={filters.event} onChange={(e) => setFilter('event', e.target.value)}>
                                    <option value="">Any</option>
                                    {events.map((event) => (
                                        <option key={event} value={event}>
                                            {event}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-record">Record number</Label>
                                <Input
                                    id="audit-record"
                                    inputMode="numeric"
                                    placeholder="e.g. 12"
                                    value={filters.record}
                                    onChange={(e) => setFilter('record', e.target.value.replace(/\D/g, ''))}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-from">From</Label>
                                <Input id="audit-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-to">To</Label>
                                <Input id="audit-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-xs">Quick range</span>
                            <Button type="button" variant="outline" size="sm" onClick={() => preset(0)}>
                                Today
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => preset(7)}>
                                Last 7 days
                            </Button>
                            <Button type="button" variant="outline" size="sm" onClick={() => preset(30)}>
                                Last 30 days
                            </Button>

                            <span className="text-muted-foreground ml-auto text-sm">
                                {entries.total} entr{entries.total === 1 ? 'y' : 'ies'}
                                {hasActiveFilters && ` matching ${activeCount} filter${activeCount === 1 ? '' : 's'}`}
                            </span>

                            {hasActiveFilters && (
                                <Button variant="ghost" size="sm" onClick={reset}>
                                    Clear filters
                                </Button>
                            )}
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 whitespace-nowrap">When</th>
                                    <th className="p-3">Who</th>
                                    <th className="p-3">Record</th>
                                    <th className="p-3">Action</th>
                                    <th className="p-3">What changed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => {
                                    const isOpen = opened.includes(entry.id);
                                    const shown = isOpen ? entry.changes : entry.changes.slice(0, CHANGES_SHOWN);
                                    const hidden = entry.changes.length - shown.length;

                                    return (
                                        <tr key={entry.id} className="border-t align-top">
                                            <td className="p-3 whitespace-nowrap">{entry.at}</td>
                                            <td className="p-3 whitespace-nowrap">{entry.causer}</td>
                                            <td className="p-3 whitespace-nowrap">
                                                {entry.subject}
                                                {entry.subject_id !== null && <span className="text-muted-foreground"> #{entry.subject_id}</span>}
                                            </td>
                                            <td className="p-3">
                                                <Badge variant={eventTone[entry.event ?? ''] ?? 'outline'}>{entry.event ?? 'changed'}</Badge>
                                            </td>
                                            <td className="p-3">
                                                {entry.changes.length === 0 ? (
                                                    <span className="text-muted-foreground">Nothing recorded</span>
                                                ) : (
                                                    <>
                                                        <ul className="space-y-0.5">
                                                            {shown.map((change) => (
                                                                <li key={change.field} className="break-words">
                                                                    <span className="text-muted-foreground">{change.field}: </span>
                                                                    {/* The old value first, because the question
                                                                        is almost always "what was it before". */}
                                                                    <span className="decoration-muted-foreground/60 line-through">
                                                                        {change.from ?? 'empty'}
                                                                    </span>{' '}
                                                                    <span aria-hidden>to</span>{' '}
                                                                    <span className="font-medium">{change.to ?? 'empty'}</span>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                        {/* ⚠ A record with twenty changed fields used to
                                                            make one row taller than the screen, which is
                                                            what made this table unreadable. */}
                                                        {(hidden > 0 || isOpen) && (
                                                            <Button variant="ghost" size="sm" onClick={() => toggle(entry.id)}>
                                                                {isOpen ? 'Show less' : `Show ${hidden} more`}
                                                            </Button>
                                                        )}
                                                    </>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {entries.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                            {hasActiveFilters
                                                ? 'Nothing recorded for these filters. Clearing them will show the whole trail.'
                                                : 'Nothing has been recorded yet.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination meta={entries} />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
