import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Note {
    id: number;
    title: string;
    body: string | null;
    is_private: boolean;
    is_pinned: boolean;
    remind_on: string | null;
    archived: boolean;
    author: string | null;
    updated: string | null;
    can_edit: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notes', href: '/notes' }];

const EMPTY = {
    title: '',
    body: '',
    is_private: false as boolean,
    is_pinned: false as boolean,
    remind_on: '',
};

export default function NotesIndex({
    notes,
    filters,
    counts,
    can,
}: {
    notes: Paginated<Note>;
    filters: { search: string; show: string };
    counts: { active: number; pinned: number; archived: number };
    can: { manage: boolean };
}) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<Note | null>(null);

    const form = useForm(EMPTY);

    const applyFilters = (next: Partial<{ search: string; show: string }>) => {
        router.get('/notes', { search: next.search ?? search, show: next.show ?? filters.show }, { preserveState: true, replace: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            form.put(route('notes.update', editing.id), { preserveScroll: true, onSuccess: () => cancel() });

            return;
        }

        form.post(route('notes.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const cancel = () => {
        setEditing(null);
        form.clearErrors();
        form.reset();
    };

    const edit = (note: Note) => {
        setEditing(note);
        form.clearErrors();
        form.setData({
            title: note.title,
            body: note.body ?? '',
            is_private: note.is_private,
            is_pinned: note.is_pinned,
            remind_on: note.remind_on ?? '',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notes" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">Notes</h2>
                    <p className="text-muted-foreground text-sm">
                        Shared by default, private when you say so. Give a note a date and it comes back to you on the calendar that day, which is the
                        difference between a board and a drawer. Nothing here deletes: archived notes stay searchable.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Tile label="On the board" value={counts.active} />
                    <Tile label="Pinned" value={counts.pinned} />
                    <Tile label="Archived" value={counts.archived} />
                </div>

                <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-2 md:col-span-3">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                            placeholder="Anything in the title or the text"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="show">Show</Label>
                        <SearchSelect id="show" value={filters.show} onChange={(e) => applyFilters({ show: e.target.value })}>
                            <option value="active">On the board</option>
                            <option value="archived">Archived</option>
                            <option value="all">Everything</option>
                        </SearchSelect>
                    </div>
                </div>

                {can.manage && (
                    <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
                        <div className="grid gap-2">
                            <Label htmlFor="title">Title</Label>
                            <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            <InputError message={form.errors.title} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="body">Note</Label>
                            <textarea
                                id="body"
                                className="border-input bg-background min-h-28 rounded-md border p-2 text-sm"
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                            />
                            <InputError message={form.errors.body} />
                        </div>
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="remind_on">Bring this back on</Label>
                                <Input
                                    id="remind_on"
                                    type="date"
                                    value={form.data.remind_on}
                                    onChange={(e) => form.setData('remind_on', e.target.value)}
                                />
                                <InputError message={form.errors.remind_on} />
                            </div>
                            <label className="flex items-end gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.is_private}
                                    onChange={(e) => form.setData('is_private', e.target.checked)}
                                />
                                Only me
                            </label>
                            <label className="flex items-end gap-2 text-sm">
                                <input type="checkbox" checked={form.data.is_pinned} onChange={(e) => form.setData('is_pinned', e.target.checked)} />
                                Pin to the top
                            </label>
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Save note' : 'Add note'}
                            </Button>
                            {editing && (
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                )}

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {notes.data.map((note) => (
                        <div key={note.id} className={`flex flex-col gap-2 rounded-lg border p-4 ${note.archived ? 'opacity-60' : ''}`}>
                            <div className="flex items-start justify-between gap-2">
                                <h3 className="font-medium">{note.title}</h3>
                                <div className="flex shrink-0 gap-1">
                                    {note.is_pinned && <Badge variant="secondary">Pinned</Badge>}
                                    {note.is_private && <Badge variant="outline">Only me</Badge>}
                                </div>
                            </div>

                            {note.body && <p className="text-muted-foreground text-sm whitespace-pre-wrap">{note.body}</p>}

                            <div className="text-muted-foreground mt-auto flex flex-wrap items-center gap-2 text-xs">
                                <span>{note.author}</span>
                                <span>{note.updated}</span>
                                {note.remind_on && <Badge variant="outline">Back on {note.remind_on}</Badge>}
                            </div>

                            {note.can_edit && (
                                <div className="flex flex-wrap gap-1">
                                    <Button variant="ghost" size="sm" onClick={() => edit(note)}>
                                        Edit
                                    </Button>
                                    {!note.archived && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('notes.pin', note.id), {}, { preserveScroll: true })}
                                        >
                                            {note.is_pinned ? 'Unpin' : 'Pin'}
                                        </Button>
                                    )}
                                    {note.archived ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('notes.restore', note.id), {}, { preserveScroll: true })}
                                        >
                                            Put back
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('notes.archive', note.id), {}, { preserveScroll: true })}
                                        >
                                            Archive
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}

                    {notes.data.length === 0 && (
                        <p className="text-muted-foreground col-span-full rounded-lg border p-6 text-center text-sm">Nothing written down yet.</p>
                    )}
                </div>

                <Pagination meta={notes} />
            </div>
        </AppLayout>
    );
}

function Tile({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="text-2xl font-semibold">{value}</div>
        </div>
    );
}
