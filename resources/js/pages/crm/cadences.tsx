import { CadenceDialog } from '@/components/crm/cadence-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Cadences (Module 4, Slice 3): standing rhythms that write follow-up tasks.
 * The queue and the overdue digest already carry the tasks; this screen only
 * decides the rhythm. Paused, never deleted.
 */
interface CadenceRow {
    id: number;
    name: string;
    trigger: string;
    delay_days: number;
    task_title: string;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/overview' },
    { title: 'Cadences', href: '/crm/cadences' },
];

const triggerLabel: Record<string, string> = {
    lead_created: 'When a new lead arrives',
    customer_dormant: 'When a customer goes quiet',
};

export default function Cadences({ cadences }: { cadences: CadenceRow[] }) {
    // ⚠ Starting a cadence happens in a popup (Joy, 14 Oct 2026: "click add
    // button pop up will add"). The page is behind crm.settings.manage, so
    // whoever sees it may start one; pausing stays on the row.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cadences" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button type="button" onClick={() => setAdding(true)}>
                        Start a cadence
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Cadence</th>
                                <th className="px-3 py-2 font-medium">Rhythm</th>
                                <th className="px-3 py-2 text-right font-medium">Days</th>
                                <th className="px-3 py-2 font-medium">Task</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {cadences.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-4 text-center">
                                        No cadences yet.
                                    </td>
                                </tr>
                            ) : (
                                cadences.map((cadence) => (
                                    <tr key={cadence.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{cadence.name}</td>
                                        <td className="px-3 py-2">{triggerLabel[cadence.trigger] ?? cadence.trigger}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{cadence.delay_days}</td>
                                        <td className="text-muted-foreground px-3 py-2">{cadence.task_title}</td>
                                        <td className="px-3 py-2">{cadence.is_active ? 'Running' : 'Paused'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.post(`/crm/cadences/${cadence.id}/toggle`, {}, { preserveScroll: true })}
                                            >
                                                {cadence.is_active ? 'Pause' : 'Resume'}
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <CadenceDialog open={adding} onOpenChange={setAdding} />
        </AppLayout>
    );
}
