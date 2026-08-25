import { Pagination } from '@/components/data-table/pagination';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';

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

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

const eventTone: Record<string, 'secondary' | 'outline' | 'destructive'> = {
    created: 'secondary',
    updated: 'outline',
    deleted: 'destructive',
};

export default function Audit({
    entries,
    filters: initialFilters,
    types,
    events,
}: {
    entries: Paginated<Entry>;
    filters: { search: string; type: string; event: string; from: string; to: string };
    types: { value: string; label: string }[];
    events: string[];
}) {
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/settings/audit', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit trail" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Audit trail"
                        description="Every change the system recorded: who made it, when, and what it was before. Read-only, and nothing here can be edited or removed."
                    />

                    <div className="grid gap-3 md:grid-cols-5">
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
                            <Label htmlFor="audit-type">Record</Label>
                            <select id="audit-type" className={selectClass} value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                                <option value="">Everything</option>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="audit-event">Action</Label>
                            <select
                                id="audit-event"
                                className={selectClass}
                                value={filters.event}
                                onChange={(e) => setFilter('event', e.target.value)}
                            >
                                <option value="">Any</option>
                                {events.map((event) => (
                                    <option key={event} value={event}>
                                        {event}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-from">From</Label>
                                <Input id="audit-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="audit-to">To</Label>
                                <Input id="audit-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} />
                            </div>
                        </div>
                    </div>

                    {hasActiveFilters && (
                        <Button variant="ghost" size="sm" onClick={reset}>
                            Clear filters
                        </Button>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">When</th>
                                    <th className="p-3">Who</th>
                                    <th className="p-3">Record</th>
                                    <th className="p-3">Action</th>
                                    <th className="p-3">What changed</th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr key={entry.id} className="border-t align-top">
                                        <td className="p-3 whitespace-nowrap">{entry.at}</td>
                                        <td className="p-3">{entry.causer}</td>
                                        <td className="p-3">
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
                                                <ul className="space-y-0.5">
                                                    {entry.changes.map((change) => (
                                                        <li key={change.field}>
                                                            <span className="text-muted-foreground">{change.field}: </span>
                                                            {/* The old value first, because the question
                                                                is almost always "what was it before". */}
                                                            <span className="decoration-muted-foreground/60 line-through">
                                                                {change.from ?? 'empty'}
                                                            </span>{' '}
                                                            <span aria-hidden>to</span> <span className="font-medium">{change.to ?? 'empty'}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {entries.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                            Nothing recorded for these filters.
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
