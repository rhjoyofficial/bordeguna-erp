import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogCampaign {
    id: number;
    code: string;
    name: string;
    lead_source_id: number;
    brand_id: number | null;
    external_campaign_id: string | null;
    starts_on: string | null;
    ends_on: string | null;
    is_active: boolean;
    note: string | null;
}

const EMPTY = {
    lead_source_id: '',
    brand_id: '',
    code: '',
    name: '',
    external_campaign_id: '',
    starts_on: '',
    ends_on: '',
    is_active: true as boolean,
    note: '',
};

function fromCampaign(campaign: DialogCampaign): typeof EMPTY {
    return {
        lead_source_id: String(campaign.lead_source_id),
        brand_id: campaign.brand_id ? String(campaign.brand_id) : '',
        code: campaign.code,
        name: campaign.name,
        external_campaign_id: campaign.external_campaign_id ?? '',
        starts_on: campaign.starts_on ?? '',
        ends_on: campaign.ends_on ?? '',
        is_active: campaign.is_active,
        note: campaign.note ?? '',
    };
}

/**
 * Add a campaign or correct one in a popup (Joy, 14 Oct 2026: "click add
 * button pop up will add ... same when edit"). The nine-field form that sat
 * open above the campaigns table is gone; the table is the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ The source and brand pickers are the
 * business's own; the server checks both against it (C-CRM-1).
 */
export function CampaignDialog({
    open,
    onOpenChange,
    campaign,
    sources,
    brands,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    campaign: DialogCampaign | null;
    sources: { id: number; name: string }[];
    brands: { id: number; name: string }[];
}) {
    const editing = campaign !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new campaign, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = campaign ? fromCampaign(campaign) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, campaign]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (campaign) {
            put(route('crm.settings.campaigns.update', campaign.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('crm.settings.campaigns.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${campaign.name}` : 'Add a campaign'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Source" htmlFor="campaign_source" error={errors.lead_source_id} required>
                            <SearchSelect
                                fit
                                id="campaign_source"
                                value={data.lead_source_id}
                                onChange={(e) => setData('lead_source_id', e.target.value)}
                            >
                                <option value="">Select source</option>
                                {sources.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Brand (optional)" htmlFor="campaign_brand" error={errors.brand_id}>
                            <SearchSelect fit id="campaign_brand" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                                <option value="">No brand</option>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Active" htmlFor="campaign_active" error={errors.is_active}>
                            <SearchSelect
                                fit
                                id="campaign_active"
                                value={data.is_active ? '1' : '0'}
                                onChange={(e) => setData('is_active', e.target.value === '1')}
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Code" htmlFor="campaign_code" size="md" error={errors.code} required>
                            <Input
                                id="campaign_code"
                                aria-required
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="EID-HONEY-2026"
                            />
                        </Field>
                        <Field label="Name" htmlFor="campaign_name" size="grow" error={errors.name} required>
                            <Input
                                id="campaign_name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Eid Honey Campaign 2026"
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        {/* ⚠ C-CRM-8: Meta's id, kept as supplied. Ours is the code. */}
                        <Field label="External campaign id" htmlFor="campaign_external" size="md" error={errors.external_campaign_id}>
                            <Input
                                id="campaign_external"
                                value={data.external_campaign_id}
                                onChange={(e) => setData('external_campaign_id', e.target.value)}
                            />
                        </Field>
                        <Field label="Starts on" htmlFor="campaign_starts" error={errors.starts_on}>
                            <Input id="campaign_starts" type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                        </Field>
                        <Field label="Ends on" htmlFor="campaign_ends" error={errors.ends_on}>
                            <Input id="campaign_ends" type="date" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                        </Field>
                    </FormRow>

                    <div className="grid gap-2">
                        <Label htmlFor="campaign_note">Note</Label>
                        <textarea
                            id="campaign_note"
                            rows={2}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save campaign' : 'Add campaign'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
