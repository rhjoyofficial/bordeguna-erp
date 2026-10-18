import { PlanList, RecordList, type MaintenancePlanRow, type MaintenanceRecordRow } from '@/components/assets/maintenance-lists';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Ending {
    item_id: number;
    tag: string;
    name: string;
    what: string;
    ends_on: string;
    by: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Things', href: '/assets/items' },
    { title: 'Maintenance', href: '/assets/maintenance' },
];

/**
 * Maintenance (slice A4): what is due, what is open, what is about to run out
 * of cover, and what was done lately.
 */
export default function AssetMaintenance({
    due,
    open,
    recent,
    ending,
    ending_within_days,
    can_manage,
}: {
    due: MaintenancePlanRow[];
    open: MaintenanceRecordRow[];
    recent: MaintenanceRecordRow[];
    ending: Ending[];
    ending_within_days: number;
    can_manage: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Maintenance" />
            <div className="flex flex-col gap-5 p-4">
                {/* The tab row above names the screen; only the action sits here (15 Oct 2026).
                    A routine-care plan starts from the thing's own page, since it belongs to one thing. */}
                {can_manage && (
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button size="sm" asChild>
                            <Link href="/assets/maintenance/create">Record work or a fault</Link>
                        </Button>
                    </div>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">Due ({due.length})</h2>
                    <PlanList plans={due} canManage={can_manage} withItem />
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">Open ({open.length})</h2>
                    <RecordList records={open} withItem />
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">Cover ending within {ending_within_days} days</h2>
                    {ending.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None.</p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {ending.map((end) => (
                                <li key={`${end.item_id}-${end.what}`}>
                                    <span className="text-muted-foreground">{showDate(end.ends_on)}</span>{' '}
                                    <Link href={`/assets/items/${end.item_id}`} className="font-mono text-xs underline underline-offset-2">
                                        {end.tag}
                                    </Link>{' '}
                                    {end.name}: {end.what}
                                    {end.by && ` with ${end.by}`}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">Lately</h2>
                    <RecordList records={recent} withItem />
                </section>
            </div>
        </AppLayout>
    );
}
