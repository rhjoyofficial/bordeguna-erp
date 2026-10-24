import { Panel } from '@/components/assets/assets-ui';
import { ActivityLogForm, type ActivityTypeOption } from '@/components/crm/activity-log-form';
import { ActivityCorrections, type CrmReasons } from '@/components/crm/crm-corrections';
import { clockTime, dayMonth, number } from '@/components/crm/lead-format';
import { LeadHead, type LeadHeadFacts } from '@/components/crm/lead-head';
import { type Inspection, LeadInspections } from '@/components/crm/lead-inspections';
import { type LeadDialog, type LeadFormOptions, LeadPopup } from '@/components/crm/lead-popup';
import { LeadSamples, type Sample } from '@/components/crm/lead-samples';
import { type Lead, type Option, type ValueLabel } from '@/components/crm/lead-types';
import InputError from '@/components/input-error';
import { type ActionSetProps } from '@/components/purchasing/correction-actions';
import { RecordHistory } from '@/components/record-history';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeft, MapPin } from 'lucide-react';
import { FormEventHandler, type ReactNode, useEffect, useState } from 'react';

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
    location: Located | null;
    photos: { id: number; name: string; url: string }[];
    /** ⭐ Corrections wave 3: the marks and the author's buttons. */
    direction: string | null;
    has_direction: boolean;
    has_duration: boolean;
    edited: boolean;
    corrected: boolean;
    correction_reason: string | null;
    is_correction: boolean;
    seen: string | null;
    actions: ActionSetProps;
}

/** Where a visit's phone said it was (CRM slice 2d). */
interface Located {
    latitude: string;
    longitude: string;
    accuracy_meters: number | null;
    map_url: string;
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

interface Deal {
    id: number;
    name: string;
    value: number;
    stage: string | null;
}

type HistoryFilter = 'all' | 'call' | 'visit' | 'msg' | 'note';

const HISTORY_FILTERS: { key: HistoryFilter; label: string; types: string[] }[] = [
    { key: 'all', label: 'All', types: [] },
    { key: 'call', label: 'Calls', types: ['call'] },
    { key: 'visit', label: 'Visits', types: ['visit', 'meeting'] },
    { key: 'msg', label: 'Messages', types: ['whatsapp', 'sms', 'email'] },
    { key: 'note', label: 'Notes', types: ['note'] },
];

/**
 * A lead's page, or a customer's history.
 *
 * ⭐ Round 3 (16 Oct 2026): for a LEAD this is the lead page of the design: the
 * head card (who, where, how to reach them, status, the facts checked before a
 * call), "Log what happened", the history, the next step, the details that
 * used to live only in the edit popup, what they asked for and the deal. Edit
 * and Make a customer open the same popups as the lead list.
 *
 * A customer's history keeps the same body with a plain heading; it is opened
 * from Sales › Customers and lights that tab.
 */
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
    inspections,
    samples,
    sampleResults,
    canInspect,
    canJudgeSample,
    now,
    lead,
    leadHead,
    deals = [],
    selectableStatuses = [],
    lostReasons = [],
    leadCan,
    leadForm,
    correctionReasons,
    isOwner,
    leadActions,
    leadReopen,
}: {
    subject: {
        kind: string;
        id: number;
        name: string | null;
        phone: string | null;
        converted_customer_id: number | null;
        converted_supplier_id: number | null;
        lead_type: string | null;
        pinned: (Located & { visited_at: string | null; by: string | null }) | null;
    };
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
    inspections: Inspection[];
    samples: Sample[];
    sampleResults: { value: string; label: string }[];
    canInspect: boolean;
    canJudgeSample: boolean;
    now: string;
    lead?: Lead;
    leadHead?: LeadHeadFacts;
    deals?: Deal[];
    selectableStatuses?: ValueLabel[];
    lostReasons?: Option[];
    leadCan?: { convert: boolean; convert_supplier: boolean; verify: boolean };
    /** Optional prop: fetched only when Edit or Make a customer opens. */
    leadForm?: LeadFormOptions;
    /** ⭐ Corrections wave 3 (24 Oct 2026). */
    correctionReasons: CrmReasons;
    isOwner: boolean;
    leadActions?: ActionSetProps;
    leadReopen?: { blocker: string | null } | null;
}) {
    // The app runs on Dhaka time, so the server's date is the shop's date.
    const { t } = useTranslation();
    const today = now.slice(0, 10);
    const title = subject.name ?? `${subject.kind} #${subject.id}`;

    const breadcrumbs: BreadcrumbItem[] =
        subject.kind === 'lead'
            ? [
                  { title: 'CRM', href: '/crm/overview' },
                  { title: 'Leads', href: '/crm/leads' },
                  { title, href: `/crm/lead/${subject.id}/timeline` },
              ]
            : [
                  { title: 'Customers', href: '/sales/customers' },
                  { title, href: `/crm/customer/${subject.id}/timeline` },
              ];

    const [dialog, setDialog] = useState<LeadDialog>(null);
    const [preset, setPreset] = useState<{ type: string; nonce: number } | null>(null);
    const [historyFilter, setHistoryFilter] = useState<HistoryFilter>('all');

    const openDialog = (next: LeadDialog) => {
        setDialog(next);
        if (next && !leadForm) router.reload({ only: ['leadForm'] });
    };

    const can = {
        manage: canRecord,
        convert: leadCan?.convert ?? false,
        convertSupplier: leadCan?.convert_supplier ?? false,
        verify: leadCan?.verify ?? false,
    };

    const shownTypes = HISTORY_FILTERS.find((f) => f.key === historyFilter)?.types ?? [];
    const history = activities.filter((a) => shownTypes.length === 0 || shownTypes.includes(a.type));
    const openTasks = tasks.filter((t) => t.status === 'open');
    const closedTasks = tasks.filter((t) => t.status !== 'open');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex flex-col gap-4 p-4">
                {subject.kind === 'lead' && (
                    <Link href="/crm/leads" className="text-muted-foreground inline-flex w-fit items-center gap-1 text-sm hover:underline">
                        <ChevronLeft className="size-4" />
                        All leads
                    </Link>
                )}

                {lead && leadHead ? (
                    <LeadHead
                        lead={lead}
                        facts={leadHead}
                        can={can}
                        selectableStatuses={selectableStatuses}
                        lostReasons={lostReasons}
                        actions={leadActions}
                        reopen={leadReopen}
                        reasons={correctionReasons}
                        onEdit={() => openDialog({ kind: 'edit', lead })}
                        onConvert={() => openDialog({ kind: 'convert', lead, customerId: null })}
                        onWhatsApp={() => {
                            setPreset({ type: 'whatsapp', nonce: Date.now() });
                            document.getElementById('log-activity')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }}
                    />
                ) : (
                    <CustomerHeading subject={subject} />
                )}

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        {canRecord && <ActivityLogForm subject={subject} activityTypes={activityTypes} directions={directions} preset={preset} />}

                        <Panel
                            title="History"
                            aside={
                                <div role="group" aria-label="Show" className="flex flex-wrap gap-1">
                                    {HISTORY_FILTERS.map((f) => (
                                        <button
                                            key={f.key}
                                            type="button"
                                            aria-pressed={historyFilter === f.key}
                                            onClick={() => setHistoryFilter(f.key)}
                                            className={cn(
                                                'h-7 rounded-full border px-2.5 text-xs',
                                                historyFilter === f.key ? 'border-foreground bg-foreground text-background' : 'hover:bg-muted',
                                            )}
                                        >
                                            {f.label}
                                        </button>
                                    ))}
                                </div>
                            }
                            flush
                        >
                            {history.length === 0 ? (
                                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing recorded yet.</p>
                            ) : (
                                <ol>
                                    {history.map((a) => (
                                        <HistoryItem key={a.id} activity={a} directions={directions} reasons={correctionReasons} />
                                    ))}
                                </ol>
                            )}
                            {isOwner && (
                                <Link
                                    href="/settings/recycle-bin?module=crm.activity"
                                    className="text-muted-foreground block px-4 pb-3 text-xs underline"
                                >
                                    {t('corr.crm.recently_deleted')}
                                </Link>
                            )}
                        </Panel>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <NextStep tasks={openTasks} done={closedTasks} today={today} canRecord={canRecord} />
                        {canRecord && <FollowUpForm subject={subject} assignableUsers={assignableUsers} canAssign={canAssign} />}

                        {lead && (
                            <LeadDetails
                                lead={lead}
                                canEdit={can.manage && lead.status !== 'converted'}
                                onEdit={() => openDialog({ kind: 'edit', lead })}
                            />
                        )}

                        <AskedFor
                            subject={subject}
                            interests={interests}
                            interestStatuses={interestStatuses}
                            products={products}
                            categories={categories}
                            brands={brands}
                            units={units}
                            canRecord={canRecord}
                        />

                        {/* ⭐ CRM slice 2c: evidence for a distributor or a supplier. Each
                            renders nothing for a lead of another type with no history. */}
                        {subject.kind === 'lead' && (
                            <>
                                <LeadInspections
                                    leadId={subject.id}
                                    leadType={subject.lead_type}
                                    inspections={inspections}
                                    canInspect={canInspect}
                                    today={today}
                                />
                                <LeadSamples
                                    leadId={subject.id}
                                    leadType={subject.lead_type}
                                    samples={samples}
                                    sampleResults={sampleResults}
                                    canRecord={canRecord}
                                    canJudge={canJudgeSample}
                                    today={today}
                                />
                            </>
                        )}

                        {lead && <DealCard deals={deals} />}
                    </div>
                </div>
            </div>

            {lead && <LeadPopup dialog={dialog} options={leadForm ?? null} onChange={setDialog} />}
        </AppLayout>
    );
}

function CustomerHeading({ subject }: { subject: { kind: string; id: number; name: string | null; phone: string | null; pinned: unknown } }) {
    return (
        <section className="bg-card rounded-xl border p-4">
            <h1 className="text-2xl font-semibold tracking-tight">{subject.name ?? `${subject.kind} #${subject.id}`}</h1>
            <p className="text-muted-foreground text-sm">
                {subject.phone ? (
                    <a href={`tel:${subject.phone}`} className="underline underline-offset-4">
                        {subject.phone}
                    </a>
                ) : (
                    'No phone'
                )}
                , customer history
            </p>
        </section>
    );
}

function HistoryItem({
    activity: a,
    directions,
    reasons,
}: {
    activity: Activity;
    directions: { value: string; label: string }[];
    reasons: CrmReasons;
}) {
    const { t } = useTranslation();

    return (
        <li className={cn('flex gap-3 border-t px-4 py-3 text-sm', a.corrected && 'opacity-60')}>
            <div className="text-muted-foreground w-14 shrink-0 text-xs tabular-nums">
                <div className="text-foreground font-medium">{dayMonth(a.at)}</div>
                <div>{clockTime(a.at)}</div>
            </div>
            <div className="min-w-0 flex-1">
                <p>
                    <span className="text-muted-foreground mr-1.5 text-xs font-medium">{a.label}</span>
                    {/* ⭐ Wave 3: a corrected entry stays, struck through, with its reason. */}
                    <span className={cn('font-medium', a.corrected && 'line-through')}>{a.summary}</span>
                    {a.corrected && <span className="ml-1.5 rounded border px-1 text-[11px]">{t('corr.crm.activity.corrected_badge')}</span>}
                    {a.is_correction && !a.corrected && (
                        <span className="ml-1.5 rounded border px-1 text-[11px]">{t('corr.crm.activity.correction_badge')}</span>
                    )}
                    {a.edited && <span className="text-muted-foreground ml-1.5 text-[11px]">{t('corr.crm.activity.edited_badge')}</span>}
                </p>
                {a.corrected && a.correction_reason && (
                    <p className="text-muted-foreground text-xs">{t('corr.crm.activity.corrected_why', { reason: a.correction_reason })}</p>
                )}
                {a.body && <p className={cn('mt-1 whitespace-pre-wrap', a.corrected && 'line-through')}>{a.body}</p>}
                <p className="text-muted-foreground mt-1 text-xs">
                    {[
                        a.by,
                        a.outcome,
                        a.duration_seconds !== null ? `${Math.max(1, Math.round(a.duration_seconds / 60))} min` : null,
                        a.photos.length > 0 ? `${a.photos.length} ${a.photos.length === 1 ? 'photo' : 'photos'}` : null,
                    ]
                        .filter(Boolean)
                        .join('. ')}
                </p>
                {a.location && (
                    <a className="mt-1 flex w-fit items-center gap-1 text-xs underline" href={a.location.map_url} target="_blank" rel="noreferrer">
                        <MapPin className="size-3" />
                        Location
                        {a.location.accuracy_meters !== null && `, within ${a.location.accuracy_meters} m`}
                    </a>
                )}
                {a.photos.length > 0 && (
                    <div className="mt-2 flex flex-wrap gap-2">
                        {a.photos.map((p) => (
                            <a key={p.id} href={p.url} target="_blank" rel="noreferrer" title={p.name}>
                                <img src={p.url} alt={p.name} loading="lazy" className="h-20 w-20 rounded border object-cover" />
                            </a>
                        ))}
                    </div>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <ActivityCorrections activity={a} directions={directions} reasons={reasons} />
                    {(a.edited || a.corrected || a.is_correction) && <RecordHistory type="crm.activity" id={a.id} label={a.label} />}
                </div>
            </div>
        </li>
    );
}

function NextStep({ tasks, done, today, canRecord }: { tasks: Task[]; done: Task[]; today: string; canRecord: boolean }) {
    const setStatus = (t: Task, status: string) => router.put(`/crm/tasks/${t.id}`, { status }, { preserveScroll: true });

    return (
        <Panel
            title="Next step"
            aside={
                canRecord ? (
                    <a href="#follow-up" className="text-primary text-xs hover:underline">
                        + Follow-up
                    </a>
                ) : undefined
            }
            flush
        >
            {tasks.length === 0 ? (
                <p className="px-4 pb-4 text-sm text-amber-700 dark:text-amber-400">Nothing planned. Add the next follow-up.</p>
            ) : (
                <ul>
                    {tasks.map((t) => (
                        <li key={t.id} className="border-t px-4 py-3 text-sm">
                            <p className={cn('text-xs font-medium', t.is_overdue ? 'text-destructive' : 'text-muted-foreground')}>
                                {t.is_overdue
                                    ? `Overdue, was due ${dayMonth(t.at)}`
                                    : t.at?.slice(0, 10) === today
                                      ? `Today ${clockTime(t.at)}`
                                      : dayMonth(t.at)}
                            </p>
                            <p className="font-medium">{t.title}</p>
                            {t.note && <p className="text-muted-foreground text-xs">{t.note}</p>}
                            <p className="text-muted-foreground text-xs">{t.assigned_to ?? 'Nobody'}</p>
                            {canRecord && (
                                <div className="mt-2 flex gap-1.5">
                                    <Button size="sm" variant="outline" onClick={() => setStatus(t, 'done')}>
                                        Done
                                    </Button>
                                    {/* ⚠ Cancelled, never deleted: deciding not to do it is information. */}
                                    <Button size="sm" variant="ghost" onClick={() => setStatus(t, 'cancelled')}>
                                        Cancel
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {done.length > 0 && (
                <details className="border-t px-4 py-2 text-sm">
                    <summary className="text-muted-foreground cursor-pointer text-xs">{done.length} done or cancelled</summary>
                    <ul className="mt-2 grid gap-1.5">
                        {done.map((t) => (
                            <li key={t.id} className="text-muted-foreground text-xs">
                                {t.title}, {t.status_label.toLowerCase()}
                                {t.completed_at && ` ${dayMonth(t.completed_at)}`}
                                {t.completed_by && ` by ${t.completed_by}`}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </Panel>
    );
}

function FollowUpForm({
    subject,
    assignableUsers,
    canAssign,
}: {
    subject: { kind: string; id: number };
    assignableUsers: { id: number; name: string }[];
    canAssign: boolean;
}) {
    const task = useForm({ title: '', note: '', due_at: '', assigned_to_user_id: '' });

    // "Add a follow-up" on the lead list lands here (#follow-up).
    useEffect(() => {
        if (window.location.hash === '#follow-up') document.getElementById('title')?.focus({ preventScroll: true });
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        task.post(`/crm/${subject.kind}/${subject.id}/tasks`, { preserveScroll: true, onSuccess: () => task.reset() });
    };

    return (
        <form id="follow-up" onSubmit={submit} className="bg-card flex scroll-mt-20 flex-col gap-3 rounded-xl border p-4">
            <h2 className="text-[15px] font-semibold">Plan the next follow-up</h2>
            <div className="grid gap-2">
                <Label htmlFor="title" required>
                    What needs doing
                </Label>
                <Input
                    id="title"
                    aria-required
                    value={task.data.title}
                    onChange={(e) => task.setData('title', e.target.value)}
                    placeholder="Call back about the 5 kg order"
                />
                <InputError message={task.errors.title} />
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-2">
                    <Label htmlFor="due_at" required>
                        Due
                    </Label>
                    <Input
                        id="due_at"
                        aria-required
                        type="datetime-local"
                        className="w-fit"
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
                <Button size="sm" disabled={task.processing}>
                    Add follow-up
                </Button>
            </div>
        </form>
    );
}

/** The details that used to live only in the edit popup. */
function LeadDetails({ lead, canEdit, onEdit }: { lead: Lead; canEdit: boolean; onEdit: () => void }) {
    const p = lead.profile ?? {};
    const rows: [string, ReactNode][] = [];
    const add = (label: string, value: ReactNode) => {
        if (value !== null && value !== undefined && value !== '') rows.push([label, value]);
    };

    if (lead.lead_type === 'retailer') {
        add('Shop type', p.shop_type);
        add('Buys from now', p.current_supplier);
        add('Wants credit', p.wants_credit === 'yes' ? 'Yes' : p.wants_credit === 'no' ? 'No' : null);
        add('Sells now', p.competitor_brands && p.competitor_brands.length > 0 ? p.competitor_brands.join(', ') : null);
    }
    if (lead.lead_type === 'distributor') {
        add('Business', p.business_nature);
        add('Also distributes', p.other_companies);
        add('Warehouses', p.warehouse_count != null ? `${p.warehouse_count}${p.warehouse_ownership ? `, ${p.warehouse_ownership}` : ''}` : null);
        add('Vehicles', p.vehicle_count);
        add('Sales staff', p.sales_staff_count);
        add('Covers', p.coverage && p.coverage.length > 0 ? p.coverage.map((c) => `${c.thana}, ${c.district}`).join('; ') : null);
    }
    if (lead.lead_type === 'supplier') {
        add('Supplier type', p.supplier_type);
        add('Supplies', p.supply_categories);
        add('From', p.origin_district);
    }
    add('Second phone', lead.secondary_phone);
    add('Email', lead.email);
    add('Qualification', lead.qualification_note);
    add('Note', lead.note);

    return (
        <Panel
            title="Details"
            aside={
                canEdit ? (
                    <button type="button" onClick={onEdit} className="text-primary text-xs hover:underline">
                        Edit
                    </button>
                ) : undefined
            }
        >
            {rows.length === 0 ? (
                <p className="text-muted-foreground mt-2 text-sm">Nothing more recorded.</p>
            ) : (
                <dl className="mt-2 grid gap-2 text-sm">
                    {rows.map(([label, value]) => (
                        <div key={label} className="grid grid-cols-[8rem_minmax(0,1fr)] gap-2">
                            <dt className="text-muted-foreground text-xs leading-5">{label}</dt>
                            <dd className="break-words whitespace-pre-wrap">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </Panel>
    );
}

function AskedFor({
    subject,
    interests,
    interestStatuses,
    products,
    categories,
    brands,
    units,
    canRecord,
}: {
    subject: { kind: string; id: number };
    interests: Interest[];
    interestStatuses: { value: string; label: string }[];
    products: { id: number; label: string }[];
    categories: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    units: { id: number; name: string; abbreviation: string }[];
    canRecord: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [interestKind, setInterestKind] = useState('product');
    const interest = useForm({ product_id: '', category_id: '', brand_id: '', requested_quantity: '', requested_unit_id: '', note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        interest.post(`/crm/${subject.kind}/${subject.id}/interests`, {
            preserveScroll: true,
            onSuccess: () => {
                interest.reset();
                setAdding(false);
            },
        });
    };

    return (
        <Panel
            title="What they asked for"
            aside={
                canRecord && !adding ? (
                    <button type="button" onClick={() => setAdding(true)} className="text-primary text-xs hover:underline">
                        + Add
                    </button>
                ) : undefined
            }
            flush
        >
            {adding && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t px-4 py-3">
                    <div className="grid gap-2">
                        <Label htmlFor="interest_kind">About</Label>
                        <SearchSelect fit id="interest_kind" value={interestKind} onChange={(e) => setInterestKind(e.target.value)}>
                            <option value="product">A product</option>
                            <option value="category">A category</option>
                            <option value="brand">A brand</option>
                        </SearchSelect>
                    </div>
                    <div className="grid min-w-0 gap-2">
                        <Label htmlFor="interest_target" required>
                            Which
                        </Label>
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
                                /* ⚠ Exactly one of the three is ever sent: three different
                                   statements in one row would make every count ambiguous. */
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
                            className="w-20 text-right"
                            inputMode="decimal"
                            value={interest.data.requested_quantity}
                            onChange={(e) => interest.setData('requested_quantity', e.target.value)}
                        />
                        <InputError message={interest.errors.requested_quantity} />
                    </div>
                    <div className="grid gap-2">
                        {/* ⚠⚠ The unit THEY said, which may differ from the product's
                            stock unit. Never converted at capture. */}
                        <Label htmlFor="requested_unit_id">Unit</Label>
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
                            className="w-40"
                            placeholder="For Eid"
                            value={interest.data.note}
                            onChange={(e) => interest.setData('note', e.target.value)}
                        />
                    </div>
                    <div className="flex gap-1.5">
                        <Button size="sm" disabled={interest.processing}>
                            Save
                        </Button>
                        <Button size="sm" type="button" variant="ghost" onClick={() => setAdding(false)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
            {interests.length === 0 ? (
                !adding && <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing asked for yet.</p>
            ) : (
                <ul>
                    {interests.map((i) => (
                        <li key={i.id} className="border-t px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <p className="font-medium">
                                    {i.what}
                                    {/* ⚠ Surfaced, not hidden: a request for a product we no
                                        longer sell is a demand signal worth seeing. */}
                                    {i.product_is_active === false && (
                                        <span className="ml-2 text-xs font-medium text-amber-700 dark:text-amber-400">not sold now</span>
                                    )}
                                </p>
                                {i.quantity && (
                                    <span className="shrink-0 tabular-nums">
                                        {i.quantity} {i.unit ?? ''}
                                    </span>
                                )}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {i.status === 'open' ? 'Still wants it' : i.status_label}, asked {dayMonth(i.requested_at)}
                                {i.by && `, ${i.by}`}
                                {i.note && `. ${i.note}`}
                                {i.closed_note && `. ${i.closed_note}`}
                            </p>
                            {canRecord && i.status === 'open' && (
                                <SearchSelect
                                    fit
                                    aria-label={`Close the request for ${i.what}`}
                                    className="mt-1.5 text-xs"
                                    value=""
                                    onChange={(e) =>
                                        e.target.value &&
                                        router.put(`/crm/interests/${i.id}/close`, { status: e.target.value }, { preserveScroll: true })
                                    }
                                >
                                    <option value="">Close as...</option>
                                    {interestStatuses.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function DealCard({ deals }: { deals: Deal[] }) {
    return (
        <Panel
            title="Deal"
            aside={
                <Link href="/crm/pipeline" className="text-primary text-xs hover:underline">
                    Pipeline
                </Link>
            }
        >
            {deals.length === 0 ? (
                <p className="text-muted-foreground mt-2 text-sm">No deal yet. Start one on the Pipeline when they agree to a first order.</p>
            ) : (
                <ul className="mt-2 grid gap-2 text-sm">
                    {deals.map((deal) => (
                        <li key={deal.id} className="flex justify-between gap-3">
                            <span className="min-w-0">
                                <span className="block truncate font-medium">{deal.name}</span>
                                <span className="text-muted-foreground text-xs">{deal.stage ?? 'No stage'}</span>
                            </span>
                            <span className="shrink-0 tabular-nums">Tk {number(deal.value)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
