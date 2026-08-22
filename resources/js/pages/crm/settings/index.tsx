import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Source {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    note: string | null;
    campaigns_count: number;
}

interface Campaign {
    id: number;
    code: string;
    name: string;
    lead_source_id: number;
    source: string | null;
    brand_id: number | null;
    brand: string | null;
    external_campaign_id: string | null;
    starts_on: string | null;
    ends_on: string | null;
    is_active: boolean;
    note: string | null;
}

interface Brand {
    id: number;
    name: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Sources & campaigns', href: '/crm/settings' },
];

const EMPTY_SOURCE = { code: '', name: '', is_active: true as boolean, note: '' };
const EMPTY_CAMPAIGN = {
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

export default function CrmSettingsIndex({
    sources,
    campaigns,
    brands,
}: {
    sources: Source[];
    campaigns: Campaign[];
    brands: Brand[];
}) {
    const [editingSource, setEditingSource] = useState<Source | null>(null);
    const [editingCampaign, setEditingCampaign] = useState<Campaign | null>(null);

    const sourceForm = useForm(EMPTY_SOURCE);
    const campaignForm = useForm(EMPTY_CAMPAIGN);

    const submitSource: FormEventHandler = (e) => {
        e.preventDefault();

        if (editingSource) {
            sourceForm.put(route('crm.settings.sources.update', editingSource.id), { onSuccess: () => cancelSource() });

            return;
        }

        sourceForm.post(route('crm.settings.sources.store'), { onSuccess: () => sourceForm.reset() });
    };

    const cancelSource = () => {
        setEditingSource(null);
        sourceForm.clearErrors();
        sourceForm.reset();
    };

    const editSource = (source: Source) => {
        setEditingSource(source);
        sourceForm.clearErrors();
        sourceForm.setData({ code: source.code, name: source.name, is_active: source.is_active, note: source.note ?? '' });
    };

    const submitCampaign: FormEventHandler = (e) => {
        e.preventDefault();

        if (editingCampaign) {
            campaignForm.put(route('crm.settings.campaigns.update', editingCampaign.id), { onSuccess: () => cancelCampaign() });

            return;
        }

        campaignForm.post(route('crm.settings.campaigns.store'), { onSuccess: () => campaignForm.reset() });
    };

    const cancelCampaign = () => {
        setEditingCampaign(null);
        campaignForm.clearErrors();
        campaignForm.reset();
    };

    const editCampaign = (campaign: Campaign) => {
        setEditingCampaign(campaign);
        campaignForm.clearErrors();
        campaignForm.setData({
            lead_source_id: String(campaign.lead_source_id),
            brand_id: campaign.brand_id ? String(campaign.brand_id) : '',
            code: campaign.code,
            name: campaign.name,
            external_campaign_id: campaign.external_campaign_id ?? '',
            starts_on: campaign.starts_on ?? '',
            ends_on: campaign.ends_on ?? '',
            is_active: campaign.is_active,
            note: campaign.note ?? '',
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CRM sources & campaigns" />
            <div className="flex flex-col gap-8 p-4">
                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Lead sources</h2>
                        <p className="text-sm text-muted-foreground">
                            Where leads come from. The code is permanent - reporting groups by this row, not by the name, so a
                            source can be renamed without splitting its history.
                        </p>
                    </div>

                    <form onSubmit={submitSource} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="source_code">Code</Label>
                            <Input
                                id="source_code"
                                value={sourceForm.data.code}
                                onChange={(e) => sourceForm.setData('code', e.target.value)}
                                placeholder="META"
                            />
                            <InputError message={sourceForm.errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="source_name">Name</Label>
                            <Input
                                id="source_name"
                                value={sourceForm.data.name}
                                onChange={(e) => sourceForm.setData('name', e.target.value)}
                                placeholder="Meta Ads"
                            />
                            <InputError message={sourceForm.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="source_active">Active</Label>
                            <select
                                id="source_active"
                                className={selectClass}
                                value={sourceForm.data.is_active ? '1' : '0'}
                                onChange={(e) => sourceForm.setData('is_active', e.target.value === '1')}
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="source_note">Note</Label>
                            <Input id="source_note" value={sourceForm.data.note} onChange={(e) => sourceForm.setData('note', e.target.value)} />
                        </div>
                        <div className="flex items-end gap-2 md:col-span-4">
                            <Button type="submit" disabled={sourceForm.processing}>
                                {editingSource ? 'Save source' : 'Add source'}
                            </Button>
                            {editingSource && (
                                <Button type="button" variant="ghost" onClick={cancelSource}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Source</th>
                                    <th className="p-3">Campaigns</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {sources.map((source) => (
                                    <tr key={source.id} className="border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{source.name}</div>
                                            <div className="text-xs text-muted-foreground">{source.code}</div>
                                        </td>
                                        <td className="p-3">{source.campaigns_count}</td>
                                        <td className="p-3">
                                            <Badge variant={source.is_active ? 'secondary' : 'outline'}>
                                                {source.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => editSource(source)}>
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-lg font-medium">Campaigns</h2>
                        <p className="text-sm text-muted-foreground">
                            A named acquisition effort. The external id is the platform's own - Meta's campaign id, for example -
                            and is stored as supplied.
                        </p>
                    </div>

                    <form onSubmit={submitCampaign} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_source">Source</Label>
                            <select
                                id="campaign_source"
                                className={selectClass}
                                value={campaignForm.data.lead_source_id}
                                onChange={(e) => campaignForm.setData('lead_source_id', e.target.value)}
                            >
                                <option value="">Select source</option>
                                {sources.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={campaignForm.errors.lead_source_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_brand">Brand (optional)</Label>
                            <select
                                id="campaign_brand"
                                className={selectClass}
                                value={campaignForm.data.brand_id}
                                onChange={(e) => campaignForm.setData('brand_id', e.target.value)}
                            >
                                <option value="">No brand</option>
                                {brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={campaignForm.errors.brand_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_code">Code</Label>
                            <Input
                                id="campaign_code"
                                value={campaignForm.data.code}
                                onChange={(e) => campaignForm.setData('code', e.target.value)}
                                placeholder="EID-HONEY-2026"
                            />
                            <InputError message={campaignForm.errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_name">Name</Label>
                            <Input
                                id="campaign_name"
                                value={campaignForm.data.name}
                                onChange={(e) => campaignForm.setData('name', e.target.value)}
                                placeholder="Eid Honey Campaign 2026"
                            />
                            <InputError message={campaignForm.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_external">External campaign id</Label>
                            <Input
                                id="campaign_external"
                                value={campaignForm.data.external_campaign_id}
                                onChange={(e) => campaignForm.setData('external_campaign_id', e.target.value)}
                            />
                            <InputError message={campaignForm.errors.external_campaign_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_starts">Starts on</Label>
                            <Input
                                id="campaign_starts"
                                type="date"
                                value={campaignForm.data.starts_on}
                                onChange={(e) => campaignForm.setData('starts_on', e.target.value)}
                            />
                            <InputError message={campaignForm.errors.starts_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_ends">Ends on</Label>
                            <Input
                                id="campaign_ends"
                                type="date"
                                value={campaignForm.data.ends_on}
                                onChange={(e) => campaignForm.setData('ends_on', e.target.value)}
                            />
                            <InputError message={campaignForm.errors.ends_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="campaign_active">Active</Label>
                            <select
                                id="campaign_active"
                                className={selectClass}
                                value={campaignForm.data.is_active ? '1' : '0'}
                                onChange={(e) => campaignForm.setData('is_active', e.target.value === '1')}
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                        <div className="grid gap-2 md:col-span-4">
                            <Label htmlFor="campaign_note">Note</Label>
                            <Input id="campaign_note" value={campaignForm.data.note} onChange={(e) => campaignForm.setData('note', e.target.value)} />
                        </div>
                        <div className="flex items-end gap-2 md:col-span-4">
                            <Button type="submit" disabled={campaignForm.processing}>
                                {editingCampaign ? 'Save campaign' : 'Add campaign'}
                            </Button>
                            {editingCampaign && (
                                <Button type="button" variant="ghost" onClick={cancelCampaign}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Campaign</th>
                                    <th className="p-3">Source</th>
                                    <th className="p-3">Brand</th>
                                    <th className="p-3">Runs</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.map((campaign) => (
                                    <tr key={campaign.id} className="border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{campaign.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {campaign.code}
                                                {campaign.external_campaign_id ? ` · ${campaign.external_campaign_id}` : ''}
                                            </div>
                                        </td>
                                        <td className="p-3">{campaign.source ?? '-'}</td>
                                        <td className="p-3">{campaign.brand ?? '-'}</td>
                                        <td className="p-3">
                                            {campaign.starts_on ?? '-'} → {campaign.ends_on ?? '-'}
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={campaign.is_active ? 'secondary' : 'outline'}>
                                                {campaign.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => editCampaign(campaign)}>
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
