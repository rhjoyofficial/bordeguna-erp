import { EMPTY_PROFILE, LeadProfileFields, profileFromLead, profilePayload } from '@/components/crm/lead-profile-fields';
import {
    type DuplicateMatch,
    type Lead,
    type LeadTypeOption,
    type Lookups,
    type Option,
    type ValueLabel,
    contactLabelFor,
    nameLabelFor,
} from '@/components/crm/lead-types';
import { BdPlaceFields } from '@/components/form/bd-place-fields';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

const EMPTY = {
    lead_type: 'customer',
    name: '',
    contact_name: '',
    phone: '',
    secondary_phone: '',
    email: '',
    address: '',
    district: '',
    thana: '',
    area: '',
    market: '',
    landmark: '',
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
    ...EMPTY_PROFILE,
};

// ⚠ Remembered per browser, never per business record: an officer who adds ten
// retailers in a bazaar should not re-pick "Retailer" ten times, and a call
// centre agent's browser keeps "Customer". Storage can be unavailable (private
// window, blocked site data), so every touch is wrapped and the default holds.
const TYPE_KEY = 'crm.leads.lastType';

function rememberedType(types: LeadTypeOption[]): string {
    try {
        const saved = window.localStorage.getItem(TYPE_KEY);

        return saved && types.some((t) => t.value === saved) ? saved : EMPTY.lead_type;
    } catch {
        return EMPTY.lead_type;
    }
}

function rememberType(value: string): void {
    try {
        window.localStorage.setItem(TYPE_KEY, value);
    } catch {
        // Nothing to do: the next capture simply starts from the default.
    }
}

const textareaClass = 'border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm';

export function LeadForm({
    editing,
    onDone,
    onConvertOnto,
    leadTypes,
    districts,
    selectableStatuses,
    brands,
    sources,
    campaigns,
    lostReasons,
    agents,
    canAssign,
    canConvert,
    canVerify,
    lookups,
    potentials,
    importances,
    warehouseOwnerships,
}: {
    editing: Lead | null;
    onDone: () => void;
    onConvertOnto: (lead: Lead, customerId: number) => void;
    leadTypes: LeadTypeOption[];
    districts: string[];
    selectableStatuses: ValueLabel[];
    brands: Option[];
    sources: Option[];
    campaigns: Option[];
    lostReasons: Option[];
    agents: Option[];
    canAssign: boolean;
    canConvert: boolean;
    canVerify: boolean;
    lookups: Lookups;
    potentials: ValueLabel[];
    importances: ValueLabel[];
    warehouseOwnerships: ValueLabel[];
}) {
    const [matches, setMatches] = useState<DuplicateMatch | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors, transform } = useForm({
        ...EMPTY,
        lead_type: rememberedType(leadTypes),
    });

    // Only the current type's profile fields leave the browser. See profilePayload.
    transform((d) => profilePayload(d.lead_type, d));

    // Loading a lead into the form, or emptying it when editing ends.
    useEffect(() => {
        clearErrors();
        setMatches(null);

        if (!editing) {
            setData({ ...EMPTY, lead_type: rememberedType(leadTypes) });

            return;
        }

        setData({
            ...EMPTY,
            lead_type: editing.lead_type,
            name: editing.name ?? '',
            contact_name: editing.contact_name ?? '',
            phone: editing.phone ?? '',
            secondary_phone: editing.secondary_phone ?? '',
            email: editing.email ?? '',
            address: editing.address ?? '',
            district: editing.district ?? '',
            thana: editing.thana ?? '',
            area: editing.area ?? '',
            market: editing.market ?? '',
            landmark: editing.landmark ?? '',
            status: editing.status,
            brand_id: editing.brand_id ? String(editing.brand_id) : '',
            assigned_to_user_id: editing.assigned_to_user_id ? String(editing.assigned_to_user_id) : '',
            qualification_note: editing.qualification_note ?? '',
            lost_reason_id: editing.lost_reason_id ? String(editing.lost_reason_id) : '',
            note: editing.note ?? '',
            ...profileFromLead(editing),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    const isShop = leadTypes.find((t) => t.value === data.lead_type)?.is_shop ?? false;

    /**
     * ⚠⚠ WARN, NEVER BLOCK a PERSON's number: households share one, so a hard
     * block would only make staff invent fake numbers. A SHOP's number is
     * refused on save by the server (Joy, 12 Sep), and the banner says which.
     * Checked on BOTH phones, matched against both phones of existing leads.
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

        const response = await fetch(`/crm/leads/duplicates?${params.toString()}`, { headers: { Accept: 'application/json' } });

        if (response.ok) {
            setMatches((await response.json()) as DuplicateMatch);
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('crm.leads.update', editing.id), { preserveScroll: true, onSuccess: () => onDone() });

            return;
        }

        rememberType(data.lead_type);

        post(route('crm.leads.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setData('lead_type', data.lead_type);
                setMatches(null);
            },
        });
    };

    /**
     * ⚠⚠ DIRECTION. `linkDuplicate(duplicate: $lead, survivor: $survivor)` and
     * the route is `leads/{lead}/duplicate-of`, so THE LEAD BEING EDITED becomes
     * the duplicate and the one clicked survives. The button says exactly that.
     * ⚠ Only while editing, and only for whoever may merge (the ASM).
     */
    const duplicateForm = useForm({ survivor_id: '' });

    const markDuplicateOf = (survivorId: number) => {
        if (!editing) {
            return;
        }

        duplicateForm.transform(() => ({ survivor_id: survivorId }));
        duplicateForm.post(route('crm.leads.duplicate-of', editing.id), { preserveScroll: true, onSuccess: () => onDone() });
    };

    const campaignsForSource = campaigns.filter((c) => !data.lead_source_id || String(c.lead_source_id) === data.lead_source_id);

    const hasMatches = matches !== null && (matches.leads.length > 0 || (matches.held_elsewhere ?? []).length > 0 || matches.customers.length > 0);

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="flex flex-wrap items-center justify-between gap-2 sm:col-span-2 lg:col-span-4">
                <h2 className="font-medium">{editing ? `Edit ${editing.name ?? `lead #${editing.id}`}` : 'Add a lead'}</h2>
                {editing && <span className="text-muted-foreground text-xs">Lead #{editing.id}</span>}
            </div>

            {/* ── Who and where ───────────────────────────────────────────── */}
            <div className="grid gap-2">
                <Label htmlFor="lead_type">Type</Label>
                <SearchSelect id="lead_type" value={data.lead_type} onChange={(e) => setData('lead_type', e.target.value)}>
                    {leadTypes.map((t) => (
                        <option key={t.value} value={t.value}>
                            {t.label}
                        </option>
                    ))}
                </SearchSelect>
                <InputError message={errors.lead_type} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="name">{nameLabelFor(data.lead_type)}</Label>
                <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="contact_name">{contactLabelFor(data.lead_type)} (optional)</Label>
                <Input id="contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                <InputError message={errors.contact_name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="status">Status</Label>
                {/* ⚠ `converted` is absent by design - it asserts a customer
                    exists and is set only by the conversion action. */}
                <SearchSelect id="status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                    {selectableStatuses.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </SearchSelect>
                <InputError message={errors.status} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="phone">Phone</Label>
                <Input
                    id="phone"
                    type="tel"
                    inputMode="tel"
                    value={data.phone}
                    onChange={(e) => setData('phone', e.target.value)}
                    onBlur={(e) => checkDuplicates(e.target.value)}
                />
                <InputError message={errors.phone} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="secondary_phone">Second phone (optional)</Label>
                <Input
                    id="secondary_phone"
                    type="tel"
                    inputMode="tel"
                    value={data.secondary_phone}
                    onChange={(e) => setData('secondary_phone', e.target.value)}
                    onBlur={(e) => e.target.value.trim() && checkDuplicates(e.target.value)}
                />
                <InputError message={errors.secondary_phone} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="email">Email (optional)</Label>
                <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                <InputError message={errors.email} />
            </div>

            {hasMatches && (
                <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm sm:col-span-2 lg:col-span-4">
                    <p className="font-medium">This phone number is already on record.</p>
                    <p className="text-muted-foreground">
                        {isShop
                            ? 'Two shops cannot share a number, so saving is refused if one of these is a shop lead. A person with this number is fine.'
                            : 'A household may share a number, so this is a warning and not a block. Use an existing record where it is the same person.'}
                    </p>
                    <ul className="mt-2 flex flex-col gap-1.5">
                        {matches?.customers.map((c) => (
                            <li key={`c-${c.id}`} className="flex flex-wrap items-center gap-2">
                                <span>
                                    <span className="font-medium">Customer:</span> {c.name} {c.code ? `(${c.code})` : ''} - {c.phone}
                                </span>
                                {/* Already a customer, so the honest act is a conversion
                                    onto that record rather than a duplicate link. */}
                                {editing && canConvert && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => onConvertOnto(editing, c.id)}>
                                        This lead is them
                                    </Button>
                                )}
                            </li>
                        ))}
                        {matches?.leads.map((l) => (
                            <li key={`l-${l.id}`} className="flex flex-wrap items-center gap-2">
                                <span>
                                    <span className="font-medium">Lead:</span> {l.name ?? '(no name)'} - {l.phone} - {l.status}
                                </span>
                                {editing && canVerify && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={duplicateForm.processing}
                                        onClick={() => markDuplicateOf(l.id)}
                                    >
                                        This lead is a duplicate of that one
                                    </Button>
                                )}
                            </li>
                        ))}
                        {matches?.held_elsewhere?.map((h, i) => (
                            <li key={`h-${i}`}>
                                <span className="font-medium">Lead:</span> held by {h.held_by ?? 'nobody yet'}. Ask them, or your sales lead, before
                                adding it again.
                            </li>
                        ))}
                    </ul>
                    {!editing && canVerify && (
                        <p className="text-muted-foreground mt-2 text-xs">
                            Save this lead first if you want to link it to one of these. Nothing is ever deleted or merged away.
                        </p>
                    )}
                    <InputError message={(duplicateForm.errors as Record<string, string>).survivor_id} />
                </div>
            )}

            <BdPlaceFields
                value={{ district: data.district, thana: data.thana, area: data.area }}
                onChange={(place) => setData({ ...data, ...place })}
                districts={districts}
                required={isShop}
                errors={{ district: errors.district, thana: errors.thana, area: errors.area }}
            />
            <div className="grid gap-2">
                <Label htmlFor="market">Market or bazaar (optional)</Label>
                <Input id="market" value={data.market} onChange={(e) => setData('market', e.target.value)} />
                <InputError message={errors.market} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="landmark">Landmark (optional)</Label>
                <Input id="landmark" value={data.landmark} onChange={(e) => setData('landmark', e.target.value)} />
                <InputError message={errors.landmark} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="address">House and road (optional)</Label>
                <Input id="address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                <InputError message={errors.address} />
            </div>

            <LeadProfileFields
                leadType={data.lead_type}
                data={data}
                set={(field, value) => setData(field, value as never)}
                errors={errors as Record<string, string | undefined>}
                lookups={lookups}
                potentials={potentials}
                importances={importances}
                warehouseOwnerships={warehouseOwnerships}
                districts={districts}
            />

            {/* ── Working the lead ────────────────────────────────────────── */}
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
            {canAssign && (
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
                    {lostReasons.map((r) => (
                        <option key={r.id} value={r.id}>
                            {r.name}
                        </option>
                    ))}
                </SearchSelect>
                <InputError message={errors.lost_reason_id} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="qualification_note">Qualification note</Label>
                <textarea
                    id="qualification_note"
                    className={textareaClass}
                    value={data.qualification_note}
                    onChange={(e) => setData('qualification_note', e.target.value)}
                />
                <InputError message={errors.qualification_note} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="note">Note</Label>
                <textarea id="note" className={textareaClass} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                <InputError message={errors.note} />
            </div>

            {/* ── Attribution ─────────────────────────────────────────────────
                ⚠ Folded away, not removed. A field officer standing in a shop has
                no campaign to record, and eleven advertising boxes between him
                and the Save button are how a form gets abandoned. A call centre
                opens it. ⚠ Appending only: a source recorded on an EDIT adds a
                new touch to the history; it never overwrites the first. */}
            <details className="border-t pt-4 sm:col-span-2 lg:col-span-4">
                <summary className="cursor-pointer text-sm font-medium">
                    Where this lead came from (optional){' '}
                    {editing && <span className="text-muted-foreground font-normal">- adds a new touch, never overwrites</span>}
                </summary>
                <div className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="lead_source_id">Source</Label>
                        <SearchSelect
                            id="lead_source_id"
                            value={data.lead_source_id}
                            onChange={(e) => setData({ ...data, lead_source_id: e.target.value, campaign_id: '' })}
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
                    {(
                        [
                            ['ad_set', 'Ad set'],
                            ['ad_name', 'Ad'],
                            ['external_lead_id', 'External lead id'],
                            ['utm_source', 'utm_source'],
                            ['utm_medium', 'utm_medium'],
                            ['utm_campaign', 'utm_campaign'],
                            ['utm_content', 'utm_content'],
                            ['utm_term', 'utm_term'],
                        ] as const
                    ).map(([field, label]) => (
                        <div key={field} className="grid gap-2">
                            <Label htmlFor={field}>{label}</Label>
                            <Input id={field} value={data[field]} onChange={(e) => setData(field, e.target.value)} />
                        </div>
                    ))}
                    <div className="grid gap-2">
                        <Label htmlFor="occurred_at">Occurred at</Label>
                        <Input id="occurred_at" type="date" value={data.occurred_at} onChange={(e) => setData('occurred_at', e.target.value)} />
                    </div>
                </div>
            </details>

            <div className="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="submit" disabled={processing}>
                    {editing ? 'Save changes' : 'Save lead'}
                </Button>
                {editing && (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}
