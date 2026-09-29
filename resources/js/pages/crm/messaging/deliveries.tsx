import { Pagination } from '@/components/data-table/pagination';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head } from '@inertiajs/react';

interface Delivery {
    id: number;
    name: string;
    to: string;
    status: string;
    error: string | null;
    parts: number;
    cost: string;
    sent_at: string | null;
}

const tone: Record<string, 'secondary' | 'outline' | 'destructive'> = {
    sent: 'secondary',
    delivered: 'secondary',
    failed: 'destructive',
    queued: 'outline',
};

export default function Deliveries({
    campaign,
    deliveries,
}: {
    campaign: { id: number; name: string; channel: string; status_label: string; sent_at: string | null; estimated_cost: string | null };
    deliveries: Paginated<Delivery>;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: campaign.channel === 'sms' ? 'SMS' : 'Email', href: `/crm/messages/${campaign.channel}` },
        { title: campaign.name, href: `/crm/campaigns/${campaign.id}/deliveries` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Who got ${campaign.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">{campaign.name}</h2>
                    <p className="text-muted-foreground text-sm">
                        {campaign.status_label}
                        {campaign.sent_at && ` on ${campaign.sent_at}`}
                        {campaign.estimated_cost && `, about ${campaign.estimated_cost}`}
                    </p>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Who</th>
                                <th className="p-3">Sent to</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Parts</th>
                                <th className="p-3">Cost</th>
                                <th className="p-3">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            {deliveries.data.map((delivery) => (
                                <tr key={delivery.id} className="border-t align-top">
                                    <td className="p-3">{delivery.name}</td>
                                    <td className="p-3">{delivery.to}</td>
                                    <td className="p-3">
                                        <Badge variant={tone[delivery.status] ?? 'outline'}>{delivery.status}</Badge>
                                        {delivery.error && <div className="text-destructive mt-1 text-xs">{delivery.error}</div>}
                                    </td>
                                    <td className="p-3">{delivery.parts}</td>
                                    <td className="p-3">{delivery.cost}</td>
                                    <td className="p-3 whitespace-nowrap">{delivery.sent_at ?? '-'}</td>
                                </tr>
                            ))}
                            {deliveries.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        Nothing went out.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={deliveries} />
            </div>
        </AppLayout>
    );
}
