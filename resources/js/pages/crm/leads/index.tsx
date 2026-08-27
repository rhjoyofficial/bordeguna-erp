import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Lead {
    id: number;
    name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    status: string;
    status_label: string;
    brand_id: number | null;
    brand: string | null;
    assigned_to_user_id: number | null;
    assigned_to: string | null;
    qualification_note: string | null;
    lost_reason_id: number | null;
    note: string | null;
    converted_customer: string | null;
    created_at: string | null;
}

interface Option {
    id: number;
    name: string;
    code?: string;
    lead_source_id?: number;
}

interface DuplicateMatch {
    leads: { id: number; name: string | null; phone: string | null; status: string }[];
    customers: { id: number; name: string; code: string | null; phone: string | null; type: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Leads', href: '/crm/leads' },
];

const EMPTY = {
    name: '',
    phone: '',
    email: '',
    address: '',
    status: 'new',
    brand_id: '',
    assigned_to_user_id: '',
    qualification_note: '',
    lost_reason_id: '',
    note: '',
    // Attribution: optional on both create and edit.
    lead_source_id: '',
    campaign_id: '',
    ad_set: '',
    ad_name: '',
    utm_source: '',
    utm_medium: '',
    utm_campaign: '',
    utm_content: '',
    utm_term: '',
    external_lead_id: '',
    occurred_at: '',
};

export default function LeadsIndex({
    leads,
    filters: initialFilters,
    statuses,
    selectable_statuses,
    brands,
    sources,
    campaigns,
    lost_reasons,
    agents,
    can_assign,
}: {
    leads: Paginated<Lead>;
    filters: { search: string; status: string; brand_id: string; assigned_to_user_id: string };
    statuses: { value: string; label: string }[];
    selectable_statuses: { value: string; label: string }[];
    brands: Option[];
    sources: Option[];
    campaigns: Option[];
    lost_reasons: Option[];
    agents: Option[];
    can_assign: boolean;
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/crm/leads', initialFilters);

    const [editing, setEditing] = useState<Lead | null>(null);
    const [matches, setMatches] = useState<DuplicateMatch | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    /**
     * ⚠⚠ WARN, NEVER BLOCK. A match is shown so the user can go and use the
     * existing record instead of creating a second one. Households share
     * numbers, so a hard block would only make staff invent fake ones.
     * True record-linking is conversion, which is Phase 3.
     */
    const checkDuplicates = async (phone: string) => {
        if (!phone.trim()) {
            setMatches(null);

            return;
        }

        const params = new URLSearchParams({ phone });

        if (editing) {
            params.set('ignore_lead_id', String(editing.id));
        }

        const response = await fetch(`/crm/leads/duplicates?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            setMatches((await response.json()) as DuplicateMatch);
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('crm.leads.update', editing.id), { onSuccess: () => cancel() });

            return;
        }

        post(route('crm.leads.store'), {
            onSuccess: () => {
                reset();
                setMatches(null);
            },
        });
    };

    const edit = (lead: Lead) => {
        setEditing(lead);
        clearErrors();
        setMatches(null);
        setData({
            ...EMPTY,
            name: lead.name ?? '',
            phone: lead.phone ?? '',
            email: lead.email ?? '',
            address: lead.address ?? '',
            status: lead.status,
            brand_id: lead.brand_id ? String(lead.brand_id) : '',
            assigned_to_user_id: lead.assigned_to_user_id ? String(lead.assigned_to_user_id) : '',
            qualification_note: lead.qualification_note ?? '',
            lost_reason_id: lead.lost_reason_id ? String(lead.lost_reason_id) : '',
            note: lead.note ?? '',
        });
    };

    const cancel = () => {
        setEditing(null);
        clearErrors();
        setMatches(null);
        reset();
    };

    // Only campaigns belonging to the chosen source, so the two axes cannot disagree.
    const campaignsForSource = campaigns.filter((c) => !data.lead_source_id || String(c.lead_source_id) === data.lead_source_id);

    const hasMatches = matches !== null && (matches.leads.length > 0 || matches.customers.length > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leads" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone</Label>
                        <Input
                            id="phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            onBlur={(e) => checkDuplicates(e.target.value)}
                        />
                        <InputError message={errors.phone} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        {/* ⚠ `converted` is absent by design - it asserts a customer
                            exists and is set only by the Phase 3 conversion action. */}
                        <SearchSelect id="status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                            {selectable_statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.status} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="brand_id">Brand (optional)</Label>
                        <SearchSelect id="brand_id" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                            <option value="">No brand</option>
                            {brands.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.brand_id} />
                    </div>
                    {can_assign && (
                        <div className="grid gap-2">
                            <Label htmlFor="assigned_to_user_id">Assigned to</Label>
                            <SearchSelect
                                id="assigned_to_user_id"
                                value={data.assigned_to_user_id}
                                onChange={(e) => setData('assigned_to_user_id', e.target.value)}
                            >
                                <option value="">Unassigned</option>
                                {agents.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.assigned_to_user_id} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="lost_reason_id">Lost reason</Label>
                        <SearchSelect id="lost_reason_id" value={data.lost_reason_id} onChange={(e) => setData('lost_reason_id', e.target.value)}>
                            <option value="">None</option>
                            {lost_reasons.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.lost_reason_id} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="address">Address</Label>
                        <textarea
                            id="address"
                            className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        <InputError message={errors.address} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="qualification_note">Qualification note</Label>
                        <textarea
                            id="qualification_note"
                            className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                            value={data.qualification_note}
                            onChange={(e) => setData('qualification_note', e.target.value)}
                        />
                        <InputError message={errors.qualification_note} />
                    </div>
                    <div className="grid gap-2 md:col-span-4">
                        <Label htmlFor="note">Note</Label>
                        <textarea
                            id="note"
                            className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    {/* ── Attribution ────────────────────────────────────────
                        ⚠ Appending only. Recording a source on an EDIT adds a new
                        touch to the acquisition history; it never overwrites the
                        original one. */}
                    <div className="border-t pt-4 md:col-span-4">
                        <p className="mb-2 text-sm font-medium">
                            Attribution {editing && <span className="text-muted-foreground">(adds a new touch, never overwrites)</span>}
                        </p>
                        <div className="grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2">
                                <Label htmlFor="lead_source_id">Source</Label>
                                <SearchSelect
                                    id="lead_source_id"
                                    value={data.lead_source_id}
                                    onChange={(e) => {
                                        setData('lead_source_id', e.target.value);
                                        setData('campaign_id', '');
                                    }}
                                >
                                    <option value="">None</option>
                                    {sources.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.lead_source_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="campaign_id">Campaign</Label>
                                <SearchSelect id="campaign_id" value={data.campaign_id} onChange={(e) => setData('campaign_id', e.target.value)}>
                                    <option value="">None</option>
                                    {campaignsForSource.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.campaign_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ad_set">Ad set</Label>
                                <Input id="ad_set" value={data.ad_set} onChange={(e) => setData('ad_set', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ad_name">Ad</Label>
                                <Input id="ad_name" value={data.ad_name} onChange={(e) => setData('ad_name', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="external_lead_id">External lead id</Label>
                                <Input
                                    id="external_lead_id"
                                    value={data.external_lead_id}
                                    onChange={(e) => setData('external_lead_id', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="utm_source">utm_source</Label>
                                <Input id="utm_source" value={data.utm_source} onChange={(e) => setData('utm_source', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="utm_medium">utm_medium</Label>
                                <Input id="utm_medium" value={data.utm_medium} onChange={(e) => setData('utm_medium', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="utm_campaign">utm_campaign</Label>
                                <Input id="utm_campaign" value={data.utm_campaign} onChange={(e) => setData('utm_campaign', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="utm_content">utm_content</Label>
                                <Input id="utm_content" value={data.utm_content} onChange={(e) => setData('utm_content', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="utm_term">utm_term</Label>
                                <Input id="utm_term" value={data.utm_term} onChange={(e) => setData('utm_term', e.target.value)} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="occurred_at">Occurred at</Label>
                                <Input
                                    id="occurred_at"
                                    type="date"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                />
                            </div>
                        </div>
                    </div>

                    {hasMatches && (
                        <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm md:col-span-4">
                            <p className="font-medium">This phone number already exists.</p>
                            <p className="text-muted-foreground">
                                A household may share a number, so this is a warning and not a block. Use an existing record where it is the same
                                person.
                            </p>
                            <ul className="mt-2 list-disc pl-5">
                                {matches?.customers.map((c) => (
                                    <li key={`c-${c.id}`}>
                                        <span className="font-medium">Customer:</span> {c.name} {c.code ? `(${c.code})` : ''} - {c.phone}
                                    </li>
                                ))}
                                {matches?.leads.map((l) => (
                                    <li key={`l-${l.id}`}>
                                        <span className="font-medium">Lead:</span> {l.name ?? '(no name)'} - {l.phone} - {l.status}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="flex items-end gap-2 md:col-span-4">
                        <Button type="submit" disabled={processing}>
                            {editing ? 'Save changes' : 'Capture lead'}
                        </Button>
                        {editing && (
                            <>
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                                <span className="text-muted-foreground self-center text-sm">Editing lead #{editing.id}</span>
                            </>
                        )}
                    </div>
                </form>

                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search name, phone or email"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All statuses</option>
                        {statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.brand_id} onChange={(e) => setFilter('brand_id', e.target.value)}>
                        <option value="">All brands</option>
                        <option value="none">No brand</option>
                        {brands.map((b) => (
                            <option key={b.id} value={b.id}>
                                {b.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.assigned_to_user_id} onChange={(e) => setFilter('assigned_to_user_id', e.target.value)}>
                        <option value="">All agents</option>
                        <option value="none">Unassigned</option>
                        {agents.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </SearchSelect>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Lead</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Brand</th>
                                <th className="p-3">Assigned</th>
                                <th className="p-3">Captured</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {leads.data.map((lead) => (
                                <tr key={lead.id} className="border-t">
                                    <td className="p-3">
                                        {/* ⚠⚠ The only route to a lead's own history from
                                            this list. Everything CRM records - calls, notes,
                                            product interests, follow-ups - was reachable ONLY
                                            from the follow-up queue, so a lead with no open
                                            task had a history nobody could open. */}
                                        <Link href={`/crm/lead/${lead.id}/timeline`} className="font-medium underline-offset-4 hover:underline">
                                            {lead.name ?? '(no name)'}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {lead.phone ?? '-'} {lead.email ? `· ${lead.email}` : ''}
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <Badge variant="secondary">{lead.status_label}</Badge>
                                        {lead.converted_customer && (
                                            <div className="text-muted-foreground mt-1 text-xs">→ {lead.converted_customer}</div>
                                        )}
                                    </td>
                                    <td className="p-3">{lead.brand ?? '-'}</td>
                                    <td className="p-3">{lead.assigned_to ?? '-'}</td>
                                    <td className="p-3">{lead.created_at ?? '-'}</td>
                                    <td className="p-3 text-right">
                                        {/* A converted lead is history and is not editable. */}
                                        {lead.status !== 'converted' && (
                                            <Button variant="ghost" size="sm" onClick={() => edit(lead)}>
                                                Edit
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={leads} />
            </div>
        </AppLayout>
    );
}
