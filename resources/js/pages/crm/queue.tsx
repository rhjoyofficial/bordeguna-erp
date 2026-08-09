import { Pagination } from '@/components/data-table/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface QueueTask {
    id: number;
    title: string;
    due_at: string | null;
    is_overdue: boolean;
    status: string;
    assigned_to: string | null;
    subject_kind: 'lead' | 'customer';
    subject_id: number;
    subject_name: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Follow-ups', href: '/crm/queue' },
];

export default function CrmQueue({
    tasks,
    seesEverybody,
    filters,
}: {
    tasks: Paginated<QueueTask>;
    seesEverybody: boolean;
    filters: { status: string };
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Follow-ups" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Follow-ups</h1>
                        <p className="text-sm text-muted-foreground">
                            {/* ⚠ Whose queue this is depends on a permission, so the
                                page says which it is rather than leaving somebody to
                                assume they can see everything. */}
                            {seesEverybody ? 'Everyone’s open follow-ups, soonest first.' : 'Your open follow-ups, soonest first.'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant={filters.status === 'all' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => router.get('/crm/queue', { status: 'all' }, { preserveScroll: true })}
                        >
                            All
                        </Button>
                        <Button
                            variant={filters.status !== 'all' ? 'default' : 'ghost'}
                            size="sm"
                            onClick={() => router.get('/crm/queue', {}, { preserveScroll: true })}
                        >
                            Open only
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Due</th>
                                <th className="p-3">Follow-up</th>
                                <th className="p-3">About</th>
                                <th className="p-3">Assigned to</th>
                                <th className="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tasks.data.map((task) => (
                                <tr key={task.id} className="border-t">
                                    <td className="p-3 whitespace-nowrap">
                                        {task.due_at ?? '-'}
                                        {task.is_overdue && (
                                            <Badge variant="destructive" className="ml-2">
                                                Overdue
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{task.title}</td>
                                    <td className="p-3">
                                        <Link
                                            href={`/crm/${task.subject_kind}/${task.subject_id}/timeline`}
                                            className="font-medium hover:underline"
                                        >
                                            {task.subject_name ?? `${task.subject_kind} #${task.subject_id}`}
                                        </Link>
                                        <span className="ml-2 text-xs text-muted-foreground">{task.subject_kind}</span>
                                    </td>
                                    <td className="p-3 text-muted-foreground">{task.assigned_to ?? 'Nobody'}</td>
                                    <td className="p-3 text-muted-foreground">{task.status}</td>
                                </tr>
                            ))}
                            {tasks.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        Nothing to follow up.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={tasks} />
            </div>
        </AppLayout>
    );
}
