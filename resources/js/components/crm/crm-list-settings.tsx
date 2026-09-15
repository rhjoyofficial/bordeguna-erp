import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export interface CrmListEntry {
    id: number;
    name: string;
    is_active: boolean;
}

export interface CrmList {
    key: string;
    label: string;
    entries: CrmListEntry[];
}

/**
 * The lists a lead picks from - shop types, supplier types, competitor brands -
 * and the reasons a lead is lost (CRM lead slice 2c).
 *
 * ⚠ Retire, never delete. A shop recorded under "Food / restaurant" must still
 * say so after the entry leaves the picker, so the only way out of a list is the
 * Retire button, and Bring back reverses it.
 */
export function CrmListSettings({ lists }: { lists: CrmList[] }) {
    return (
        <section className="flex flex-col gap-4">
            <div>
                <h2 className="text-lg font-medium">Lists a lead picks from</h2>
                <p className="text-muted-foreground text-sm">
                    What officers choose from on a lead. Rename freely; retire an entry to take it out of the picker without changing any lead that
                    already carries it.
                </p>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                {lists.map((list) => (
                    <ListCard key={list.key} list={list} />
                ))}
            </div>
        </section>
    );
}

function ListCard({ list }: { list: CrmList }) {
    const form = useForm({ name: '' });

    const add: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('crm.settings.lists.store', list.key), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <h3 className="font-medium">{list.label}</h3>
            {list.entries.length === 0 && <p className="text-muted-foreground text-sm">Nothing here yet.</p>}
            <ul className="flex flex-col gap-1">
                {list.entries.map((entry) => (
                    <EntryRow key={entry.id} listKey={list.key} entry={entry} />
                ))}
            </ul>
            <form onSubmit={add} className="flex flex-wrap items-start gap-2">
                <div className="grid min-w-0 flex-1 gap-1">
                    <Input
                        aria-label={`New entry for ${list.label}`}
                        placeholder="Add an entry"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <Button type="submit" variant="outline" disabled={form.processing || !form.data.name.trim()}>
                    Add
                </Button>
            </form>
        </div>
    );
}

function EntryRow({ listKey, entry }: { listKey: string; entry: CrmListEntry }) {
    const [renaming, setRenaming] = useState(false);
    const form = useForm({ name: entry.name, is_active: entry.is_active });

    const save = (next: { name: string; is_active: boolean }) =>
        router.put(route('crm.settings.lists.update', [listKey, entry.id]), next, {
            preserveScroll: true,
            onSuccess: () => setRenaming(false),
            onError: (errors) => form.setError(errors as Record<'name' | 'is_active', string>),
        });

    if (renaming) {
        return (
            <li className="flex flex-wrap items-start gap-2">
                <div className="grid min-w-0 flex-1 gap-1">
                    <Input aria-label="Name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    <InputError message={form.errors.name} />
                </div>
                <Button type="button" size="sm" onClick={() => save({ name: form.data.name, is_active: entry.is_active })}>
                    Save
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => setRenaming(false)}>
                    Cancel
                </Button>
            </li>
        );
    }

    return (
        <li className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span className={entry.is_active ? '' : 'text-muted-foreground line-through'}>{entry.name}</span>
            <span className="flex items-center gap-1">
                {!entry.is_active && <Badge variant="outline">Retired</Badge>}
                <Button type="button" size="sm" variant="ghost" onClick={() => setRenaming(true)}>
                    Rename
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={() => save({ name: entry.name, is_active: !entry.is_active })}>
                    {entry.is_active ? 'Retire' : 'Bring back'}
                </Button>
            </span>
        </li>
    );
}
