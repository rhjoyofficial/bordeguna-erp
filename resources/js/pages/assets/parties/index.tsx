import { AssetPartyDialog } from '@/components/assets/party-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Party {
    id: number;
    name: string;
    organisation: string | null;
    phone: string | null;
    kind: string;
    kind_label: string;
    supplier_id: number | null;
    supplier: string | null;
    note: string | null;
    is_active: boolean;
    holding: number;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Outside parties', href: '/assets/parties' },
];

/**
 * Outside parties that hold things (slice A2): repair shops, freelancers.
 * Switched off, never deleted; hand-overs point at them.
 */
export default function AssetParties({
    parties,
    kinds,
    suppliers,
    can_manage,
}: {
    parties: Party[];
    kinds: Option[];
    suppliers: { id: number; name: string }[];
    can_manage: boolean;
}) {
    // ⭐ Add and Edit open one popup over the list (Joy, 14 Oct 2026: "click add
    // button pop up will add, same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Party | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Outside parties" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold">Outside parties</h1>
                    {can_manage && <Button onClick={() => setDialog(null)}>Add party</Button>}
                </div>

                {parties.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">None yet.</p>
                ) : (
                    <ul className="grid gap-2">
                        {parties.map((party) => (
                            <li key={party.id} className="rounded-lg border p-3 text-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{party.name}</span>
                                        {party.organisation && <span className="text-muted-foreground">{party.organisation}</span>}
                                        {party.phone && <span className="text-muted-foreground">{party.phone}</span>}
                                        <Badge variant="outline">{party.kind_label}</Badge>
                                        {party.holding > 0 && <Badge variant="secondary">holds {party.holding}</Badge>}
                                        {!party.is_active && <Badge variant="outline">Switched off</Badge>}
                                    </div>
                                    {can_manage && (
                                        <Button size="sm" variant="ghost" onClick={() => setDialog(party)}>
                                            Edit
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {can_manage && (
                    <AssetPartyDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        party={dialog ?? null}
                        kinds={kinds}
                        suppliers={suppliers}
                    />
                )}
            </div>
        </AppLayout>
    );
}
