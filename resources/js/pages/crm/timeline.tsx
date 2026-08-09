import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Activity {
    id: number;
    kind: 'activity';
    type: string;
    label: string;
    at: string | null;
    summary: string;
    body: string | null;
    outcome: string | null;
    duration_seconds: number | null;
    by: string | null;
}

interface Task {
    id: number;
    kind: 'task';
    title: string;
    note: string | null;
    at: string | null;
    status: string;
    status_label: string;
    is_overdue: boolean;
    assigned_to: string | null;
    assigned_to_user_id: number | null;
    completed_at: string | null;
    completed_by: string | null;
}

interface ActivityTypeOption {
    value: string;
    label: string;
    has_direction: boolean;
    has_duration: boolean;
}

export default function CrmTimeline({
    subject,
    activities,
    tasks,
    activityTypes,
    directions,
    assignableUsers,
    canRecord,
    canAssign,
}: {
    subject: { kind: string; id: number; name: string | null; phone: string | null; converted_customer_id: number | null };
    activities: Activity[];
    tasks: Task[];
    activityTypes: ActivityTypeOption[];
    directions: { value: string; label: string }[];
    assignableUsers: { id: number; name: string }[];
    canRecord: boolean;
    canAssign: boolean;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'CRM', href: '/crm/leads' },
        { title: subject.name ?? `${subject.kind} #${subject.id}`, href: `/crm/${subject.kind}/${subject.id}/timeline` },
    ];

    const log = useForm({
        type: 'call',
        direction: 'outbound',
        occurred_at: '',
        summary: '',
        body: '',
        outcome: '',
        duration_seconds: '',
    });

    const task = useForm({ title: '', note: '', due_at: '', assigned_to_user_id: '' });

    const chosen = activityTypes.find((t) => t.value === log.data.type);

    const submitLog: FormEventHandler = (e) => {
        e.preventDefault();
        log.post(`/crm/${subject.kind}/${subject.id}/activities`, {
            preserveScroll: true,
            onSuccess: () => log.reset(),
        });
    };

    const submitTask: FormEventHandler = (e) => {
        e.preventDefault();
        task.post(`/crm/${subject.kind}/${subject.id}/tasks`, {
            preserveScroll: true,
            onSuccess: () => task.reset(),
        });
    };

    const setTaskStatus = (t: Task, status: string) =>
        router.put(`/crm/tasks/${t.id}`, { status }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={subject.name ?? 'Timeline'} />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{subject.name ?? `${subject.kind} #${subject.id}`}</h1>
                    <p className="text-sm text-muted-foreground">
                        {subject.phone ?? 'No phone'} · {subject.kind}
                        {subject.converted_customer_id && ' · converted to a customer'}
                    </p>
                </div>

                {canRecord && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form onSubmit={submitLog} className="flex flex-col gap-3 rounded-md border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <p className="text-sm font-medium">Record what happened</p>
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="type">Kind</Label>
                                    <select id="type" className={selectClass} value={log.data.type} onChange={(e) => log.setData('type', e.target.value)}>
                                        {activityTypes.map((t) => (
                                            <option key={t.value} value={t.value}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                {/* ⚠ A note has no direction and only a call has a
                                    duration. Hidden rather than stored and ignored. */}
                                {chosen?.has_direction && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="direction">Direction</Label>
                                        <select id="direction" className={selectClass} value={log.data.direction} onChange={(e) => log.setData('direction', e.target.value)}>
                                            {directions.map((d) => (
                                                <option key={d.value} value={d.value}>
                                                    {d.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                {chosen?.has_duration && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="duration_seconds">Seconds</Label>
                                        <Input id="duration_seconds" className="w-24" inputMode="numeric" value={log.data.duration_seconds} onChange={(e) => log.setData('duration_seconds', e.target.value)} />
                                    </div>
                                )}
                                <div className="grid gap-2">
                                    {/* ⚠⚠ When it HAPPENED, not when it was typed. */}
                                    <Label htmlFor="occurred_at">When (blank = now)</Label>
                                    <Input id="occurred_at" type="datetime-local" className="w-52" value={log.data.occurred_at} onChange={(e) => log.setData('occurred_at', e.target.value)} />
                                    <InputError message={log.errors.occurred_at} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="summary">What happened</Label>
                                <Input id="summary" value={log.data.summary} onChange={(e) => log.setData('summary', e.target.value)} placeholder="Wants 5kg, will confirm Thursday" />
                                <InputError message={log.errors.summary} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="body">Detail (optional)</Label>
                                <textarea id="body" className="min-h-20 rounded-md border border-input bg-background p-2 text-sm" value={log.data.body} onChange={(e) => log.setData('body', e.target.value)} />
                            </div>
                            <div>
                                <Button disabled={log.processing}>Record</Button>
                                <span className="ml-3 text-xs text-muted-foreground">This cannot be edited afterwards.</span>
                            </div>
                        </form>

                        <form onSubmit={submitTask} className="flex flex-col gap-3 rounded-md border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <p className="text-sm font-medium">Add a follow-up</p>
                            <div className="grid gap-2">
                                <Label htmlFor="title">What needs doing</Label>
                                <Input id="title" value={task.data.title} onChange={(e) => task.setData('title', e.target.value)} placeholder="Call back about the 5kg order" />
                                <InputError message={task.errors.title} />
                            </div>
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="due_at">Due</Label>
                                    <Input id="due_at" type="datetime-local" className="w-52" value={task.data.due_at} onChange={(e) => task.setData('due_at', e.target.value)} />
                                    <InputError message={task.errors.due_at} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="assigned_to_user_id">Assign to</Label>
                                    <select
                                        id="assigned_to_user_id"
                                        className={selectClass}
                                        value={task.data.assigned_to_user_id}
                                        onChange={(e) => task.setData('assigned_to_user_id', e.target.value)}
                                        disabled={!canAssign}
                                    >
                                        <option value="">Me</option>
                                        {assignableUsers.map((u) => (
                                            <option key={u.id} value={u.id}>
                                                {u.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={task.errors.assigned_to_user_id} />
                                </div>
                            </div>
                            <div>
                                <Button disabled={task.processing}>Add follow-up</Button>
                            </div>
                        </form>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-2">
                    <div>
                        <h2 className="mb-2 text-sm font-medium">Follow-ups</h2>
                        <div className="flex flex-col gap-2">
                            {tasks.map((t) => (
                                <div key={t.id} className="rounded-md border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-medium">{t.title}</p>
                                            <p className="text-muted-foreground">
                                                Due {t.at ?? '-'} · {t.assigned_to ?? 'nobody'}
                                                {t.completed_at && ` · done ${t.completed_at} by ${t.completed_by}`}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            {t.is_overdue && <Badge variant="destructive">Overdue</Badge>}
                                            <Badge variant="secondary">{t.status_label}</Badge>
                                        </div>
                                    </div>
                                    {canRecord && t.status === 'open' && (
                                        <div className="mt-2 flex gap-2">
                                            <Button size="sm" variant="ghost" onClick={() => setTaskStatus(t, 'done')}>
                                                Mark done
                                            </Button>
                                            {/* ⚠ Cancelled, never deleted: deciding not
                                                to do it is information. */}
                                            <Button size="sm" variant="ghost" onClick={() => setTaskStatus(t, 'cancelled')}>
                                                Cancel
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            ))}
                            {tasks.length === 0 && <p className="text-sm text-muted-foreground">No follow-ups.</p>}
                        </div>
                    </div>

                    <div>
                        <h2 className="mb-2 text-sm font-medium">History</h2>
                        <div className="flex flex-col gap-2">
                            {activities.map((a) => (
                                <div key={a.id} className="rounded-md border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="font-medium">{a.summary}</p>
                                        <span className="shrink-0 text-xs text-muted-foreground">{a.at}</span>
                                    </div>
                                    <p className="text-muted-foreground">
                                        {a.label}
                                        {a.duration_seconds !== null && ` · ${a.duration_seconds}s`}
                                        {a.outcome && ` · ${a.outcome}`}
                                        {a.by && ` · ${a.by}`}
                                    </p>
                                    {a.body && <p className="mt-1 whitespace-pre-wrap">{a.body}</p>}
                                </div>
                            ))}
                            {activities.length === 0 && <p className="text-sm text-muted-foreground">Nothing recorded yet.</p>}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
