import SelfServiceTabs from '@/components/self-service-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface MyItem {
    id: number;
    tag: string;
    name: string;
    category: string | null;
    business: string | null;
    since: string | null;
    condition: string;
    acknowledged_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My profile', href: '/hrm/my-profile' },
    { title: 'My assets', href: '/hrm/my-assets' },
];

/**
 * What the signed-in employee holds (slice A2), and the one thing they can
 * say about it: "yes, I have it". Returning a thing is recorded by the keeper.
 */
export default function MyAssets({ items, pieces }: { items: MyItem[]; pieces: { tag: string | null; name: string | null; quantity: number }[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My assets" />
            <div className="px-4 pt-4">
                <SelfServiceTabs active="/hrm/my-assets" />
            </div>
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h2 className="text-lg font-medium">Things I hold</h2>
                    <p className="text-muted-foreground text-sm">
                        What the company has handed to you. When something arrives, press &quot;Yes, I have it&quot;. To give something back, hand it
                        to whoever keeps the register; they record it.
                    </p>
                </div>

                {items.length === 0 && pieces.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">Nothing is recorded as with you.</p>
                ) : (
                    <ul className="grid gap-2">
                        {items.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium">{item.name}</p>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="font-mono">{item.tag}</span>
                                        {item.category && ` - ${item.category}`}
                                        {item.since && ` - since ${item.since}`}
                                        {item.business && ` - ${item.business}`}
                                    </p>
                                </div>
                                {item.acknowledged_at ? (
                                    <Badge variant="secondary">Acknowledged {item.acknowledged_at}</Badge>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() => router.post(`/hrm/my-assets/${item.id}/acknowledge`, {}, { preserveScroll: true })}
                                    >
                                        Yes, I have it
                                    </Button>
                                )}
                            </li>
                        ))}
                        {pieces.map((piece, index) => (
                            <li key={`p${index}`} className="rounded-lg border p-3 text-sm">
                                <p className="font-medium">
                                    {piece.name} x {piece.quantity}
                                </p>
                                <p className="text-muted-foreground font-mono text-xs">{piece.tag}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
