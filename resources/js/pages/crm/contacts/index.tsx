import { Pagination } from '@/components/data-table/pagination';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Group {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    contacts_count: number;
}

interface Contact {
    id: number;
    name: string;
    company: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    source: string | null;
    note: string | null;
    is_active: boolean;
    linked: string | null;
    groups: { id: number; name: string }[];
}

interface ImportPlan {
    rowCount: number;
    creates: { line: number; name: string; phone: string }[];
    updates: { line: number; name: string; phone: string; changes: string[] }[];
    unchanged: number;
    absentFromFile: number;
    warnings: string[];
    errors: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Phone book', href: '/crm/contacts' },
];

const EMPTY_CONTACT = {
    name: '',
    company: '',
    phone: '',
    email: '',
    address: '',
    source: '',
    note: '',
    is_active: true as boolean,
};

const EMPTY_GROUP = { name: '', description: '', is_active: true as boolean };

export default function PhoneBookIndex({
    contacts,
    groups,
    filters,
    totals,
    can,
}: {
    contacts: Paginated<Contact>;
    groups: Group[];
    filters: { search: string; group: string | null; status: string };
    totals: { all: number; active: number; reachable_by_sms: number; reachable_by_email: number };
    can: { manage: boolean };
}) {
    // ⚠ Read as a top-level prop, exactly like the customer importer's panel.
    // `flash` shares only success and error, so a plan looked for there would
    // silently never arrive and the review step would look like it did nothing.
    const plan = (usePage().props as unknown as { contactImportPlan?: ImportPlan }).contactImportPlan;

    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<Contact | null>(null);
    const [editingGroup, setEditingGroup] = useState<Group | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkGroup, setBulkGroup] = useState('');

    const contactForm = useForm(EMPTY_CONTACT);
    const groupForm = useForm(EMPTY_GROUP);
    const importForm = useForm<{ file: File | null }>({ file: null });

    const applyFilters = (next: Partial<{ search: string; group: string; status: string }>) => {
        router.get(
            '/crm/contacts',
            {
                search: next.search ?? search,
                group: next.group ?? filters.group ?? '',
                status: next.status ?? filters.status,
            },
            { preserveState: true, replace: true },
        );
    };

    const submitContact: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            contactForm.put(route('crm.contacts.update', editing.id), { onSuccess: () => cancelContact() });

            return;
        }

        contactForm.post(route('crm.contacts.store'), { onSuccess: () => contactForm.reset() });
    };

    const cancelContact = () => {
        setEditing(null);
        contactForm.clearErrors();
        contactForm.reset();
    };

    const editContact = (contact: Contact) => {
        setEditing(contact);
        contactForm.clearErrors();
        contactForm.setData({
            name: contact.name,
            company: contact.company ?? '',
            phone: contact.phone ?? '',
            email: contact.email ?? '',
            address: contact.address ?? '',
            source: contact.source ?? '',
            note: contact.note ?? '',
            is_active: contact.is_active,
        });
    };

    const submitGroup: FormEventHandler = (e) => {
        e.preventDefault();

        if (editingGroup) {
            groupForm.put(route('crm.contact-groups.update', editingGroup.id), {
                onSuccess: () => {
                    setEditingGroup(null);
                    groupForm.reset();
                },
            });

            return;
        }

        groupForm.post(route('crm.contact-groups.store'), { onSuccess: () => groupForm.reset() });
    };

    const toggleOne = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const toggleAllOnPage = () => {
        const ids = contacts.data.map((c) => c.id);
        setSelected((s) => (ids.every((id) => s.includes(id)) ? s.filter((id) => !ids.includes(id)) : [...new Set([...s, ...ids])]));
    };

    const bulk = (action: 'add' | 'remove') => {
        if (!bulkGroup || selected.length === 0) {
            return;
        }

        router.post(
            action === 'add' ? route('crm.contact-groups.add') : route('crm.contact-groups.remove'),
            { group_id: bulkGroup, contact_ids: selected },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

    const submitImport: FormEventHandler = (e) => {
        e.preventDefault();
        importForm.post(route('crm.contacts.import.review'), { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Phone book" />
            <div className="flex flex-col gap-8 p-4">
                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Tile label="In the book" value={totals.all} />
                    <Tile label="Active" value={totals.active} />
                    <Tile label="Has a phone" value={totals.reachable_by_sms} />
                    <Tile label="Has an email" value={totals.reachable_by_email} />
                </section>

                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Phone book</h2>
                    </div>

                    <FormRow className="rounded-lg border p-4">
                        <Field label="Search" htmlFor="search" size="lg">
                            <Input
                                id="search"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search })}
                                placeholder="Name, company, email or number"
                            />
                        </Field>
                        <Field label="Group" htmlFor="group_filter">
                            <SearchSelect id="group_filter" value={filters.group ?? ''} onChange={(e) => applyFilters({ group: e.target.value })}>
                                <option value="">Every group</option>
                                {groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Status" htmlFor="status_filter">
                            <SearchSelect id="status_filter" value={filters.status} onChange={(e) => applyFilters({ status: e.target.value })}>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="all">All</option>
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    {can.manage && (
                        <form onSubmit={submitContact} className="flex flex-col gap-4 rounded-lg border p-4">
                            {/* Each field as wide as what it holds (30 Aug 2026 audit):
                                a phone is a phone box, an address takes the room. */}
                            <FormRow>
                                <Field label="Name" htmlFor="name" size="md" required error={contactForm.errors.name}>
                                    <Input id="name" value={contactForm.data.name} onChange={(e) => contactForm.setData('name', e.target.value)} />
                                </Field>
                                <Field label="Company" htmlFor="company" size="md" error={contactForm.errors.company}>
                                    <Input
                                        id="company"
                                        value={contactForm.data.company}
                                        onChange={(e) => contactForm.setData('company', e.target.value)}
                                    />
                                </Field>
                                <Field label="Phone" htmlFor="phone" size="md" error={contactForm.errors.phone}>
                                    <Input
                                        id="phone"
                                        value={contactForm.data.phone}
                                        onChange={(e) => contactForm.setData('phone', e.target.value)}
                                        placeholder="01712345678"
                                    />
                                </Field>
                                <Field label="Email" htmlFor="email" size="lg" error={contactForm.errors.email}>
                                    <Input id="email" value={contactForm.data.email} onChange={(e) => contactForm.setData('email', e.target.value)} />
                                </Field>
                            </FormRow>
                            <FormRow>
                                <Field label="Address" htmlFor="address" size="grow">
                                    <Input
                                        id="address"
                                        value={contactForm.data.address}
                                        onChange={(e) => contactForm.setData('address', e.target.value)}
                                    />
                                </Field>
                                <Field label="Where from" htmlFor="source" size="md">
                                    <Input
                                        id="source"
                                        value={contactForm.data.source}
                                        onChange={(e) => contactForm.setData('source', e.target.value)}
                                        placeholder="Trade fair 2026"
                                    />
                                </Field>
                                <Field label="Note" htmlFor="note" size="grow">
                                    <Input id="note" value={contactForm.data.note} onChange={(e) => contactForm.setData('note', e.target.value)} />
                                </Field>
                            </FormRow>
                            <div className="flex items-center gap-2">
                                <Button type="submit" disabled={contactForm.processing}>
                                    {editing ? 'Save contact' : 'Add contact'}
                                </Button>
                                {editing && (
                                    <Button type="button" variant="ghost" onClick={cancelContact}>
                                        Cancel
                                    </Button>
                                )}
                            </div>
                        </form>
                    )}

                    {can.manage && selected.length > 0 && (
                        <div className="flex flex-wrap items-end gap-2 rounded-lg border p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="bulk_group">{selected.length} selected</Label>
                                <SearchSelect fit id="bulk_group" value={bulkGroup} onChange={(e) => setBulkGroup(e.target.value)}>
                                    <option value="">Choose a group</option>
                                    {groups.map((g) => (
                                        <option key={g.id} value={g.id}>
                                            {g.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <Button type="button" onClick={() => bulk('add')} disabled={!bulkGroup}>
                                Add to group
                            </Button>
                            <Button type="button" variant="outline" onClick={() => bulk('remove')} disabled={!bulkGroup}>
                                Remove from group
                            </Button>
                            <Button type="button" variant="ghost" onClick={() => setSelected([])}>
                                Clear selection
                            </Button>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    {can.manage && (
                                        <th className="p-3">
                                            <input
                                                type="checkbox"
                                                aria-label="Select every contact on this page"
                                                checked={contacts.data.length > 0 && contacts.data.every((c) => selected.includes(c.id))}
                                                onChange={toggleAllOnPage}
                                            />
                                        </th>
                                    )}
                                    <th className="p-3">Name</th>
                                    <th className="p-3">Phone</th>
                                    <th className="p-3">Email</th>
                                    <th className="p-3">Groups</th>
                                    <th className="p-3">Where from</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {contacts.data.map((contact) => (
                                    <tr key={contact.id} className="border-t">
                                        {can.manage && (
                                            <td className="p-3">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select ${contact.name}`}
                                                    checked={selected.includes(contact.id)}
                                                    onChange={() => toggleOne(contact.id)}
                                                />
                                            </td>
                                        )}
                                        <td className="p-3">
                                            <div className="font-medium">{contact.name}</div>
                                            {contact.company && <div className="text-muted-foreground text-xs">{contact.company}</div>}
                                            {!contact.is_active && (
                                                <Badge variant="outline" className="mt-1">
                                                    Inactive
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3">{contact.phone ?? '-'}</td>
                                        <td className="p-3">{contact.email ?? '-'}</td>
                                        <td className="p-3">
                                            <div className="flex flex-wrap gap-1">
                                                {contact.groups.map((g) => (
                                                    <Badge key={g.id} variant="secondary">
                                                        {g.name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            {contact.linked ? <Badge variant="outline">{contact.linked}</Badge> : (contact.source ?? '-')}
                                        </td>
                                        <td className="p-3 text-right">
                                            {can.manage && !contact.linked && (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => editContact(contact)}>
                                                    Edit
                                                </Button>
                                            )}
                                            {can.manage &&
                                                (contact.is_active ? (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(route('crm.contacts.deactivate', contact.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Deactivate
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(route('crm.contacts.reactivate', contact.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Reactivate
                                                    </Button>
                                                ))}
                                        </td>
                                    </tr>
                                ))}
                                {contacts.data.length === 0 && (
                                    <tr>
                                        <td className="text-muted-foreground p-6 text-center" colSpan={can.manage ? 7 : 6}>
                                            Nobody here yet. Add someone above, import a file, or pull the leads and customers you already have.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination meta={contacts} />
                </section>

                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Groups</h2>
                    </div>

                    {can.manage && (
                        <form onSubmit={submitGroup}>
                            <FormRow className="rounded-lg border p-4">
                                <Field label="Name" htmlFor="group_name" size="md" required error={groupForm.errors.name}>
                                    <Input id="group_name" value={groupForm.data.name} onChange={(e) => groupForm.setData('name', e.target.value)} />
                                </Field>
                                <Field label="Description" htmlFor="group_description" size="grow">
                                    <Input
                                        id="group_description"
                                        value={groupForm.data.description}
                                        onChange={(e) => groupForm.setData('description', e.target.value)}
                                    />
                                </Field>
                                <div className="flex items-center gap-2 self-end">
                                    <Button type="submit" disabled={groupForm.processing}>
                                        {editingGroup ? 'Save group' : 'Add group'}
                                    </Button>
                                    {editingGroup && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => {
                                                setEditingGroup(null);
                                                groupForm.reset();
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                </div>
                            </FormRow>
                        </form>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Group</th>
                                    <th className="p-3">People</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.map((group) => (
                                    <tr key={group.id} className="border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{group.name}</div>
                                            {group.description && <div className="text-muted-foreground text-xs">{group.description}</div>}
                                        </td>
                                        <td className="p-3">{group.contacts_count}</td>
                                        <td className="p-3">{group.is_active ? 'Active' : 'Inactive'}</td>
                                        <td className="p-3 text-right">
                                            {can.manage && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditingGroup(group);
                                                        groupForm.setData({
                                                            name: group.name,
                                                            description: group.description ?? '',
                                                            is_active: group.is_active,
                                                        });
                                                    }}
                                                >
                                                    Edit
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {groups.length === 0 && (
                                    <tr>
                                        <td className="text-muted-foreground p-6 text-center" colSpan={4}>
                                            No groups yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {can.manage && (
                    <section className="flex flex-col gap-4">
                        <div>
                            <h2 className="text-lg font-medium">Import, export and CRM</h2>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" asChild>
                                <a href={route('crm.contacts.template')}>Download blank template</a>
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <a href={route('crm.contacts.export')}>Export the phone book</a>
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post(route('crm.contacts.pull'), { type: 'customer' }, { preserveScroll: true })}
                            >
                                Pull in customers
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => router.post(route('crm.contacts.pull'), { type: 'lead' }, { preserveScroll: true })}
                            >
                                Pull in leads
                            </Button>
                        </div>

                        <form onSubmit={submitImport} className="flex flex-wrap items-end gap-2 rounded-lg border p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="file" required>Contact file (CSV)</Label>
                                <Input
                                    id="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)}
                                />
                                <InputError message={importForm.errors.file} />
                            </div>
                            <Button type="submit" disabled={importForm.processing || !importForm.data.file}>
                                Review this file
                            </Button>
                        </form>

                        {plan && (
                            <div className="flex flex-col gap-3 rounded-lg border p-4">
                                <h3 className="font-medium">What this file would do</h3>
                                <p className="text-sm">
                                    {plan.rowCount} row(s) read. {plan.creates.length} to add, {plan.updates.length} to change, {plan.unchanged}{' '}
                                    already correct. {plan.absentFromFile} contact(s) in the book are not mentioned by this file and will be left
                                    exactly as they are.
                                </p>

                                {plan.errors.length > 0 && (
                                    <div className="text-destructive flex flex-col gap-1 text-sm">
                                        <strong>This file cannot be imported until these are fixed:</strong>
                                        {plan.errors.map((e, i) => (
                                            <span key={i}>{e}</span>
                                        ))}
                                    </div>
                                )}

                                {plan.warnings.length > 0 && (
                                    <div className="flex flex-col gap-1 text-sm">
                                        <strong>Worth reading before you apply it:</strong>
                                        {plan.warnings.map((w, i) => (
                                            <span key={i}>{w}</span>
                                        ))}
                                    </div>
                                )}

                                {plan.updates.length > 0 && (
                                    <div className="flex flex-col gap-1 text-sm">
                                        <strong>Changes:</strong>
                                        {plan.updates.map((u) => (
                                            <span key={u.line}>
                                                Line {u.line} {u.name}: {u.changes.join('; ')}
                                            </span>
                                        ))}
                                    </div>
                                )}

                                <div>
                                    <Button
                                        type="button"
                                        disabled={plan.errors.length > 0}
                                        onClick={() => router.post(route('crm.contacts.import.apply'), {}, { preserveScroll: true })}
                                    >
                                        Apply this file
                                    </Button>
                                </div>
                            </div>
                        )}
                    </section>
                )}
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
