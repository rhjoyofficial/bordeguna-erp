import { Pagination } from '@/components/data-table/pagination';
import { NoteDialog } from '@/components/notes/note-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

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
    // ⚠ Add and edit happen in a popup (Joy, 14 Oct 2026: "click add button pop
    // up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Note | null | undefined>(undefined);

    const applyFilters = (next: Partial<{ search: string; show: string }>) => {
        router.get('/notes', { search: next.search ?? search, show: next.show ?? filters.show }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notes" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-medium">Notes</h2>
                    {can.manage && (
                        <Button type="button" onClick={() => setDialog(null)}>
                            Add a note
                        </Button>
                    )}
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
                            className="max-w-md"
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
                                    {/* ⚠ The popup is drawn only for someone who may write notes. */}
                                    {can.manage && (
                                        <Button variant="ghost" size="sm" onClick={() => setDialog(note)}>
                                            Edit
                                        </Button>
                                    )}
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

            {can.manage && <NoteDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} note={dialog ?? null} />}
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
