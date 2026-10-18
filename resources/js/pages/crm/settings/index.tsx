import { CampaignDialog } from '@/components/crm/campaign-dialog';
import { CrmListSettings, type CrmList } from '@/components/crm/crm-list-settings';
import { LeadSourceDialog } from '@/components/crm/lead-source-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

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
    { title: 'CRM', href: '/crm/overview' },
    { title: 'Lists & sources', href: '/crm/settings' },
];

export default function CrmSettingsIndex({
    sources,
    campaigns,
    brands,
    lists,
}: {
    sources: Source[];
    campaigns: Campaign[];
    brands: Brand[];
    lists: CrmList[];
}) {
    // ⚠ Add and edit happen in popups (Joy, 14 Oct 2026: "click add button pop
    // up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    // The whole page is behind `crm.settings.manage`, so whoever sees it may write.
    const [sourceDialog, setSourceDialog] = useState<Source | null | undefined>(undefined);
    const [campaignDialog, setCampaignDialog] = useState<Campaign | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CRM lists & sources" />
            <div className="flex flex-col gap-8 p-4">
                <section className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-medium">Lead sources</h2>
                        <Button type="button" onClick={() => setSourceDialog(null)}>
                            Add a source
                        </Button>
                    </div>

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
                                            <div className="text-muted-foreground text-xs">{source.code}</div>
                                        </td>
                                        <td className="p-3">{source.campaigns_count}</td>
                                        <td className="p-3">
                                            <Badge variant={source.is_active ? 'secondary' : 'outline'}>
                                                {source.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => setSourceDialog(source)}>
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
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-medium">Campaigns</h2>
                        <Button type="button" onClick={() => setCampaignDialog(null)}>
                            Add a campaign
                        </Button>
                    </div>

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
                                            <div className="text-muted-foreground text-xs">
                                                {campaign.code}
                                                {campaign.external_campaign_id ? ` · ${campaign.external_campaign_id}` : ''}
                                            </div>
                                        </td>
                                        <td className="p-3">{campaign.source ?? '-'}</td>
                                        <td className="p-3">{campaign.brand ?? '-'}</td>
                                        <td className="p-3">
                                            {showDate(campaign.starts_on) || '-'} → {showDate(campaign.ends_on) || '-'}
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={campaign.is_active ? 'secondary' : 'outline'}>
                                                {campaign.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => setCampaignDialog(campaign)}>
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <CrmListSettings lists={lists} />
            </div>

            <LeadSourceDialog
                open={sourceDialog !== undefined}
                onOpenChange={(open) => !open && setSourceDialog(undefined)}
                source={sourceDialog ?? null}
            />
            <CampaignDialog
                open={campaignDialog !== undefined}
                onOpenChange={(open) => !open && setCampaignDialog(undefined)}
                campaign={campaignDialog ?? null}
                sources={sources}
                brands={brands}
            />
        </AppLayout>
    );
}
