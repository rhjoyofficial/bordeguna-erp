import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

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

interface Interest {
    id: number;
    what: string;
    kind: string;
    sku: string | null;
    product_is_active: boolean | null;
    quantity: string | null;
    unit: string | null;
    note: string | null;
    requested_at: string | null;
    status: string;
    status_label: string;
    closed_note: string | null;
    by: string | null;
}

export default function CrmTimeline({
    subject,
    activities,
    tasks,
    interests,
    interestStatuses,
    activityTypes,
    directions,
    assignableUsers,
    products,
    categories,
    brands,
    units,
    canRecord,
    canAssign,
}: {
    subject: { kind: string; id: number; name: string | null; phone: string | null; converted_customer_id: number | null };
    activities: Activity[];
    tasks: Task[];
    interests: Interest[];
    interestStatuses: { value: string; label: string }[];
    activityTypes: ActivityTypeOption[];
    directions: { value: string; label: string }[];
    assignableUsers: { id: number; name: string }[];
    products: { id: number; label: string }[];
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    units: { id: number; name: string; abbreviation: string }[];
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

    const [interestKind, setInterestKind] = useState('product');
    const interest = useForm({
        product_id: '',
        category_id: '',
        brand_id: '',
        requested_quantity: '',
        requested_unit_id: '',
        note: '',
    });

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

    const submitInterest: FormEventHandler = (e) => {
        e.preventDefault();
        interest.post(`/crm/${subject.kind}/${subject.id}/interests`, {
            preserveScroll: true,
            onSuccess: () => interest.reset(),
        });
    };

    const setTaskStatus = (t: Task, status: string) => router.put(`/crm/tasks/${t.id}`, { status }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={subject.name ?? 'Timeline'} />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{subject.name ?? `${subject.kind} #${subject.id}`}</h1>
                    <p className="text-muted-foreground text-sm">
                        {subject.phone ?? 'No phone'} · {subject.kind}
                        {subject.converted_customer_id && ' · converted to a customer'}
                    </p>
                </div>

                {canRecord && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form
                            onSubmit={submitLog}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-4"
                        >
                            <p className="text-sm font-medium">Record what happened</p>
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="type" required>
                                        Kind
                                    </Label>
                                    <SearchSelect fit id="type" value={log.data.type} onChange={(e) => log.setData('type', e.target.value)}>
                                        {activityTypes.map((t) => (
                                            <option key={t.value} value={t.value}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </div>
                                {/* ⚠ A note has no direction and only a call has a
                                    duration. Hidden rather than stored and ignored. */}
                                {chosen?.has_direction && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="direction">Direction</Label>
                                        <SearchSelect
                                            fit
                                            id="direction"
                                            value={log.data.direction}
                                            onChange={(e) => log.setData('direction', e.target.value)}
                                        >
                                            {directions.map((d) => (
                                                <option key={d.value} value={d.value}>
                                                    {d.label}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </div>
                                )}
                                {chosen?.has_duration && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="duration_seconds">Seconds</Label>
                                        <Input
                                            id="duration_seconds"
                                            className="w-24"
                                            inputMode="numeric"
                                            value={log.data.duration_seconds}
                                            onChange={(e) => log.setData('duration_seconds', e.target.value)}
                                        />
                                    </div>
                                )}
                                <div className="grid gap-2">
                                    {/* ⚠⚠ When it HAPPENED, not when it was typed. */}
                                    <Label htmlFor="occurred_at">When (blank = now)</Label>
                                    <Input
                                        id="occurred_at"
                                        type="datetime-local"
                                        value={log.data.occurred_at}
                                        onChange={(e) => log.setData('occurred_at', e.target.value)}
                                    />
                                    <InputError message={log.errors.occurred_at} />
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="summary" required>
                                    What happened
                                </Label>
                                <Input
                                    id="summary"
                                    value={log.data.summary}
                                    onChange={(e) => log.setData('summary', e.target.value)}
                                    placeholder="Wants 5kg, will confirm Thursday"
                                />
                                <InputError message={log.errors.summary} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="body">Detail (optional)</Label>
                                <textarea
                                    id="body"
                                    className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                                    value={log.data.body}
                                    onChange={(e) => log.setData('body', e.target.value)}
                                />
                            </div>
                            <div>
                                <Button disabled={log.processing}>Record</Button>
                                <span className="text-muted-foreground ml-3 text-xs">This cannot be edited afterwards.</span>
                            </div>
                        </form>

                        <form
                            onSubmit={submitTask}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-3 rounded-md border p-4"
                        >
                            <p className="text-sm font-medium">Add a follow-up</p>
                            <div className="grid gap-2">
                                <Label htmlFor="title" required>
                                    What needs doing
                                </Label>
                                <Input
                                    id="title"
                                    value={task.data.title}
                                    onChange={(e) => task.setData('title', e.target.value)}
                                    placeholder="Call back about the 5kg order"
                                />
                                <InputError message={task.errors.title} />
                            </div>
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="due_at">Due</Label>
                                    <Input
                                        id="due_at"
                                        type="datetime-local"
                                        value={task.data.due_at}
                                        onChange={(e) => task.setData('due_at', e.target.value)}
                                    />
                                    <InputError message={task.errors.due_at} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="assigned_to_user_id">Assign to</Label>
                                    <SearchSelect
                                        fit
                                        id="assigned_to_user_id"
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
                                    </SearchSelect>
                                    <InputError message={task.errors.assigned_to_user_id} />
                                </div>
                            </div>
                            <div>
                                <Button disabled={task.processing}>Add follow-up</Button>
                            </div>
                        </form>
                    </div>
                )}

                {canRecord && (
                    <form
                        onSubmit={submitInterest}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        <p className="w-full text-sm font-medium">What did they ask for?</p>
                        <div className="grid gap-2">
                            <Label htmlFor="interest_kind">About</Label>
                            <SearchSelect fit id="interest_kind" value={interestKind} onChange={(e) => setInterestKind(e.target.value)}>
                                <option value="product">A product</option>
                                <option value="category">A category</option>
                                <option value="brand">A brand</option>
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="interest_target">Which</Label>
                            <SearchSelect
                                fit
                                id="interest_target"
                                value={
                                    interestKind === 'product'
                                        ? interest.data.product_id
                                        : interestKind === 'category'
                                          ? interest.data.category_id
                                          : interest.data.brand_id
                                }
                                onChange={(e) => {
                                    /* ⚠ Exactly one of the three is ever sent: three
                                       different statements in one row would make every
                                       count over the table ambiguous. */
                                    interest.setData({
                                        ...interest.data,
                                        product_id: interestKind === 'product' ? e.target.value : '',
                                        category_id: interestKind === 'category' ? e.target.value : '',
                                        brand_id: interestKind === 'brand' ? e.target.value : '',
                                    });
                                }}
                            >
                                <option value="">Choose</option>
                                {(interestKind === 'product' ? products : interestKind === 'category' ? categories : brands).map(
                                    (o: { id: number; label?: string; name?: string }) => (
                                        <option key={o.id} value={o.id}>
                                            {o.label ?? o.name}
                                        </option>
                                    ),
                                )}
                            </SearchSelect>
                            <InputError message={interest.errors.product_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="requested_quantity">How much</Label>
                            <Input
                                id="requested_quantity"
                                className="w-24 text-right"
                                inputMode="decimal"
                                value={interest.data.requested_quantity}
                                onChange={(e) => interest.setData('requested_quantity', e.target.value)}
                            />
                            <InputError message={interest.errors.requested_quantity} />
                        </div>
                        <div className="grid gap-2">
                            {/* ⚠⚠ The unit THEY said, which may differ from the
                                product's stock unit. Never converted at capture. */}
                            <Label htmlFor="requested_unit_id">In what unit</Label>
                            <SearchSelect
                                fit
                                id="requested_unit_id"
                                value={interest.data.requested_unit_id}
                                onChange={(e) => interest.setData('requested_unit_id', e.target.value)}
                            >
                                <option value="">Not stated</option>
                                {units.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.abbreviation}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="interest_note">Context</Label>
                            <Input
                                id="interest_note"
                                className="w-56"
                                placeholder="For Eid"
                                value={interest.data.note}
                                onChange={(e) => interest.setData('note', e.target.value)}
                            />
                        </div>
                        <Button disabled={interest.processing}>Record what they want</Button>
                    </form>
                )}

                <div>
                    <h2 className="mb-2 text-sm font-medium">What they asked for</h2>
                    <div className="flex flex-col gap-2">
                        {interests.map((i) => (
                            <div key={i.id} className="rounded-md border p-3 text-sm">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="font-medium">
                                            {i.quantity && `${i.quantity} ${i.unit ?? ''} `}
                                            {i.what}
                                            {i.sku && <span className="text-muted-foreground ml-2 text-xs">{i.sku}</span>}
                                            {/* ⚠ Surfaced, not hidden: a request for a
                                                product we no longer sell is a demand
                                                signal worth seeing. */}
                                            {i.product_is_active === false && (
                                                <Badge variant="secondary" className="ml-2">
                                                    not currently sold
                                                </Badge>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {i.kind} · asked {i.requested_at}
                                            {i.by && ` · ${i.by}`}
                                            {i.note && ` · ${i.note}`}
                                            {i.closed_note && ` · ${i.closed_note}`}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Badge variant={i.status === 'open' ? 'default' : 'secondary'}>{i.status_label}</Badge>
                                        {canRecord && i.status === 'open' && (
                                            <SearchSelect
                                                aria-label={`Close interest ${i.id}`}
                                                className="text-xs"
                                                value=""
                                                onChange={(e) =>
                                                    e.target.value &&
                                                    router.put(`/crm/interests/${i.id}/close`, { status: e.target.value }, { preserveScroll: true })
                                                }
                                            >
                                                <option value="">Close as…</option>
                                                {interestStatuses.map((s) => (
                                                    <option key={s.value} value={s.value}>
                                                        {s.label}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                        {interests.length === 0 && <p className="text-muted-foreground text-sm">Nothing asked for yet.</p>}
                    </div>
                </div>

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
                            {tasks.length === 0 && <p className="text-muted-foreground text-sm">No follow-ups.</p>}
                        </div>
                    </div>

                    <div>
                        <h2 className="mb-2 text-sm font-medium">History</h2>
                        <div className="flex flex-col gap-2">
                            {activities.map((a) => (
                                <div key={a.id} className="rounded-md border p-3 text-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="font-medium">{a.summary}</p>
                                        <span className="text-muted-foreground shrink-0 text-xs">{a.at}</span>
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
                            {activities.length === 0 && <p className="text-muted-foreground text-sm">Nothing recorded yet.</p>}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
