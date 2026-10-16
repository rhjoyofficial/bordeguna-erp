import { BulkContactsDialog } from '@/components/crm/bulk-contacts-dialog';
import { ContactDialog } from '@/components/crm/contact-dialog';
import { ContactGroupDialog } from '@/components/crm/contact-group-dialog';
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
    { title: 'CRM', href: '/crm/overview' },
    { title: 'Phone book', href: '/crm/contacts' },
];

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
    // ⚠ Add and edit happen in popups (Joy, 14 Oct 2026: "click add button pop
    // up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    const [contactDialog, setContactDialog] = useState<Contact | null | undefined>(undefined);
    const [groupDialog, setGroupDialog] = useState<Group | null | undefined>(undefined);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkGroup, setBulkGroup] = useState('');

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
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-medium">Phone book</h2>
                        {can.manage && (
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" onClick={() => setContactDialog(null)}>
                                    Add contact
                                </Button>
                                <Button type="button" variant="outline" onClick={() => setBulkOpen(true)}>
                                    Bulk add
                                </Button>
                            </div>
                        )}
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

                    {can.manage && selected.length > 0 && (
                        <div className="flex flex-wrap items-end gap-2 rounded-lg border p-4">
                            <div className="grid gap-2">
                                <Label htmlFor="bulk_group" required>
                                    {selected.length} selected
                                </Label>
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
                                                <Button type="button" variant="ghost" size="sm" onClick={() => setContactDialog(contact)}>
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
                                            Nobody here yet. Use Add contact or Bulk add, import a file, or pull the leads and customers you already
                                            have.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <Pagination meta={contacts} />
                </section>

                <section className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-medium">Groups</h2>
                        {can.manage && (
                            <Button type="button" variant="outline" onClick={() => setGroupDialog(null)}>
                                Add group
                            </Button>
                        )}
                    </div>

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
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    applyFilters({ group: String(group.id) });
                                                    window.scrollTo({ top: 0, behavior: 'smooth' });
                                                }}
                                            >
                                                Show people
                                            </Button>
                                            {can.manage && (
                                                <Button type="button" variant="ghost" size="sm" onClick={() => setGroupDialog(group)}>
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
                            {/* Excel first (Joy, 14 Oct 2026); the CSV stays for anyone who needs it. */}
                            <Button type="button" variant="outline" asChild>
                                <a href={route('crm.contacts.template')}>Excel template</a>
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <a href={route('crm.contacts.export')}>Export to Excel</a>
                            </Button>
                            <Button type="button" variant="ghost" asChild>
                                <a href={route('crm.contacts.template', { format: 'csv' })}>CSV template</a>
                            </Button>
                            <Button type="button" variant="ghost" asChild>
                                <a href={route('crm.contacts.export', { format: 'csv' })}>Export CSV</a>
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
                                <Label htmlFor="file" required>
                                    Contact file (Excel or CSV)
                                </Label>
                                <Input
                                    id="file"
                                    type="file"
                                    required
                                    accept=".xlsx,.csv,text/csv"
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

            {can.manage && (
                <>
                    <ContactDialog
                        open={contactDialog !== undefined}
                        onOpenChange={(open) => !open && setContactDialog(undefined)}
                        contact={contactDialog ?? null}
                    />
                    <ContactGroupDialog
                        open={groupDialog !== undefined}
                        onOpenChange={(open) => !open && setGroupDialog(undefined)}
                        group={groupDialog ?? null}
                    />
                    <BulkContactsDialog open={bulkOpen} onOpenChange={setBulkOpen} groups={groups} />
                </>
            )}
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
