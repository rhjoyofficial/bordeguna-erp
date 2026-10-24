import InputError from '@/components/input-error';
import {
    type ActionSetProps,
    type Availability,
    BlockedAction,
    CorrectionReasonDialog,
    type ReasonOption,
} from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Corrections wave 3, CRM (24 Oct 2026; FINAL-PLAN 3.2 and 6.3): the buttons
 * of a call, visit or note (Edit, Delete within 24 hours, Correct after), a
 * lead (Reverse conversion or Delete, and Reopen), a deal (Reopen), a phone
 * book contact (Delete) and an applied file (Reverse import).
 *
 * ⚠ The server decides everything again (the policies under the row lock):
 * a blocked button is drawn greyed with its sentence, and every reason is
 * checked on the server too. No <form> and no key handling: Enter never
 * submits (Joy, 19 Oct 2026). Dates come first in every popup.
 */

export type CrmReasons = Partial<Record<'correction' | 'deletion' | 'reversal', ReasonOption[]>>;

export type CorrectableActivity = {
    id: number;
    type: string;
    label: string;
    at: string | null;
    summary: string;
    body: string | null;
    outcome: string | null;
    duration_seconds: number | null;
    direction: string | null;
    has_direction: boolean;
    has_duration: boolean;
    seen: string | null;
    actions: ActionSetProps;
};

/** "2026-10-24 09:00:00" -> "2026-10-24T09:00" for the date-and-time box. */
const forInput = (at: string | null) => (at ? at.slice(0, 16).replace(' ', 'T') : '');

function find(set: ActionSetProps | null | undefined, action: string): Availability | undefined {
    return set?.actions.find((a) => a.action === action);
}

// ── Calls, visits and notes ─────────────────────────────────────────────────

type ActivityFields = { occurred_at: string; direction: string; summary: string; body: string; outcome: string; minutes: string };

function fieldsOf(a: CorrectableActivity): ActivityFields {
    return {
        occurred_at: forInput(a.at),
        direction: a.direction ?? '',
        summary: a.summary,
        body: a.body ?? '',
        outcome: a.outcome ?? '',
        minutes: a.duration_seconds !== null ? String(Math.round(a.duration_seconds / 60)) : '',
    };
}

function payloadOf(fields: ActivityFields, a: CorrectableActivity) {
    return {
        occurred_at: fields.occurred_at,
        direction: a.has_direction ? fields.direction || null : null,
        summary: fields.summary,
        body: fields.body || null,
        outcome: fields.outcome || null,
        duration_seconds: a.has_duration && fields.minutes !== '' ? Math.round(Number(fields.minutes) * 60) : null,
        seen: a.seen,
    };
}

/** The entry's own fields, date first. */
function ActivityFieldsForm({
    activity,
    fields,
    onChange,
    directions,
    errors,
}: {
    activity: CorrectableActivity;
    fields: ActivityFields;
    onChange: (next: ActivityFields) => void;
    directions: { value: string; label: string }[];
    errors: Record<string, string>;
}) {
    const { t } = useTranslation();
    const set = (key: keyof ActivityFields, value: string) => onChange({ ...fields, [key]: value });

    return (
        <div className="grid gap-3">
            <div className="grid gap-1">
                <Label htmlFor={`act-when-${activity.id}`}>{t('corr.crm.activity.when')}</Label>
                <Input
                    id={`act-when-${activity.id}`}
                    type="datetime-local"
                    value={fields.occurred_at}
                    onChange={(e) => set('occurred_at', e.target.value)}
                />
                <InputError message={errors.occurred_at} />
            </div>
            {activity.has_direction && (
                <div className="grid gap-1">
                    <Label htmlFor={`act-dir-${activity.id}`}>{t('corr.crm.activity.direction')}</Label>
                    <select
                        id={`act-dir-${activity.id}`}
                        value={fields.direction}
                        onChange={(e) => set('direction', e.target.value)}
                        className="border-input bg-background h-9 w-fit rounded-md border px-2 text-sm"
                    >
                        <option value="">-</option>
                        {directions.map((d) => (
                            <option key={d.value} value={d.value}>
                                {d.label}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            <div className="grid gap-1">
                <Label htmlFor={`act-sum-${activity.id}`} required>
                    {t('corr.crm.activity.summary')}
                </Label>
                <Input
                    id={`act-sum-${activity.id}`}
                    value={fields.summary}
                    maxLength={255}
                    size={Math.min(60, Math.max(20, fields.summary.length + 2))}
                    className="w-auto max-w-full"
                    onChange={(e) => set('summary', e.target.value)}
                />
                <InputError message={errors.summary} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor={`act-body-${activity.id}`}>{t('corr.crm.activity.body')}</Label>
                {/* The one full-width control: notes are prose. */}
                <textarea
                    id={`act-body-${activity.id}`}
                    value={fields.body}
                    onChange={(e) => set('body', e.target.value)}
                    className="border-input bg-background/80 min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                />
            </div>
            <div className="flex flex-wrap gap-3">
                <div className="grid gap-1">
                    <Label htmlFor={`act-out-${activity.id}`}>{t('corr.crm.activity.outcome')}</Label>
                    <Input
                        id={`act-out-${activity.id}`}
                        value={fields.outcome}
                        maxLength={30}
                        size={18}
                        className="w-auto"
                        onChange={(e) => set('outcome', e.target.value)}
                    />
                </div>
                {activity.has_duration && (
                    <div className="grid gap-1">
                        <Label htmlFor={`act-min-${activity.id}`}>{t('corr.crm.activity.minutes')}</Label>
                        <Input
                            id={`act-min-${activity.id}`}
                            type="number"
                            min={0}
                            value={fields.minutes}
                            className="w-20"
                            onChange={(e) => set('minutes', e.target.value)}
                        />
                    </div>
                )}
            </div>
            {(errors.activity || errors.stale) && <p className="text-destructive text-sm">{errors.activity ?? errors.stale}</p>}
        </div>
    );
}

/** Edit your own fresh entry: no reason (it is still your draft), before and after kept in History. */
function EditActivityDialog({ activity, directions }: { activity: CorrectableActivity; directions: { value: string; label: string }[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [fields, setFields] = useState<ActivityFields>(() => fieldsOf(activity));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const save = () => {
        setBusy(true);
        router.put(route('crm.activities.update', activity.id), payloadOf(fields, activity), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onError: (e) => setErrors(e as Record<string, string>),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setFields(fieldsOf(activity));
                    setErrors({});
                }
            }}
        >
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    {t('corr.crm.edit')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('corr.crm.activity.edit_title', { label: activity.label })}</DialogTitle>
                    <DialogDescription>{t('corr.crm.activity.edit_note')}</DialogDescription>
                </DialogHeader>
                <ActivityFieldsForm activity={activity} fields={fields} onChange={setFields} directions={directions} errors={errors} />
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.go_back')}
                    </Button>
                    <Button type="button" disabled={busy || fields.summary.trim() === ''} onClick={save}>
                        {t('corr.crm.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Correct an older entry: the form opens pre-filled, a reason is required. */
function CorrectActivityDialog({
    activity,
    directions,
    reasons,
}: {
    activity: CorrectableActivity;
    directions: { value: string; label: string }[];
    reasons: ReasonOption[];
}) {
    const { t } = useTranslation();
    const [fields, setFields] = useState<ActivityFields>(() => fieldsOf(activity));

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline" onClick={() => setFields(fieldsOf(activity))}>
                    {t('corr.crm.correct')}
                </Button>
            }
            title={t('corr.crm.activity.correct_title', { label: activity.label })}
            description={t('corr.crm.activity.correct_note')}
            reasons={reasons}
            submitUrl={route('crm.activities.correct', activity.id)}
            confirmLabel={t('corr.crm.correct')}
            data={() => payloadOf(fields, activity)}
        >
            <ActivityFieldsForm activity={activity} fields={fields} onChange={setFields} directions={directions} errors={{}} />
        </CorrectionReasonDialog>
    );
}

/** The buttons under one entry of a lead's or a customer's history. */
export function ActivityCorrections({
    activity,
    directions,
    reasons,
}: {
    activity: CorrectableActivity;
    directions: { value: string; label: string }[];
    reasons: CrmReasons;
}) {
    const { t } = useTranslation();
    const edit = find(activity.actions, 'edit');
    const remove = find(activity.actions, 'delete');
    const correct = find(activity.actions, 'correct');

    if (!edit && !remove && !correct) {
        return null;
    }

    return (
        <div className="mt-2 flex flex-wrap items-start gap-2">
            {edit &&
                (edit.allowed ? (
                    <EditActivityDialog activity={activity} directions={directions} />
                ) : (
                    <BlockedAction availability={edit} label={t('corr.crm.edit')} />
                ))}
            {remove &&
                (remove.allowed ? (
                    <CorrectionReasonDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                {t('corr.crm.delete')}
                            </Button>
                        }
                        title={t('corr.crm.activity.delete_title', { label: activity.label })}
                        description={t('corr.crm.bin_note')}
                        reasons={reasons.deletion ?? []}
                        submitUrl={route('crm.activities.delete', activity.id)}
                        confirmLabel={t('corr.crm.delete')}
                        destructive
                    />
                ) : (
                    <BlockedAction availability={remove} label={t('corr.crm.delete')} />
                ))}
            {correct &&
                (correct.allowed ? (
                    <CorrectActivityDialog activity={activity} directions={directions} reasons={reasons.correction ?? []} />
                ) : (
                    <BlockedAction availability={correct} label={t('corr.crm.correct')} />
                ))}
        </div>
    );
}

// ── Leads ───────────────────────────────────────────────────────────────────

/**
 * Reverse conversion or Delete (the policy offers one), and Reopen on a lost
 * lead. Drawn in the lead's head beside Edit details.
 */
export function LeadCorrections({
    leadId,
    name,
    actions,
    reopen,
    reasons,
}: {
    leadId: number;
    name: string;
    actions: ActionSetProps | null | undefined;
    reopen: { blocker: string | null } | null | undefined;
    reasons: CrmReasons;
}) {
    const { t } = useTranslation();
    const reverse = find(actions, 'reverse');
    const remove = find(actions, 'delete');

    return (
        <>
            {reopen &&
                (reopen.blocker === null ? (
                    <CorrectionReasonDialog
                        trigger={<Button size="sm">{t('corr.crm.reopen')}</Button>}
                        title={t('corr.crm.lead.reopen_title', { name })}
                        description={t('corr.crm.lead.reopen_note')}
                        reasons={reasons.correction ?? []}
                        submitUrl={route('crm.leads.reopen', leadId)}
                        confirmLabel={t('corr.crm.reopen')}
                    />
                ) : (
                    <BlockedAction
                        availability={{
                            action: 'reopen',
                            label: 'Reopen',
                            allowed: false,
                            requestable: false,
                            blockers: [{ code: 'blocked', message: reopen.blocker, url: null, requestable: false }],
                            primary: true,
                            requires_reason: true,
                            reason_context: 'correction',
                            date: null,
                        }}
                        label={t('corr.crm.reopen')}
                    />
                ))}
            {reverse &&
                (reverse.allowed ? (
                    <CorrectionReasonDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                {t('corr.crm.lead.reverse')}
                            </Button>
                        }
                        title={t('corr.crm.lead.reverse_title', { name })}
                        description={t('corr.crm.lead.reverse_note')}
                        reasons={reasons.reversal ?? []}
                        submitUrl={route('crm.leads.reverse-conversion', leadId)}
                        confirmLabel={t('corr.crm.lead.reverse')}
                        destructive
                    />
                ) : (
                    <BlockedAction availability={reverse} label={t('corr.crm.lead.reverse')} />
                ))}
            {remove &&
                (remove.allowed ? (
                    <CorrectionReasonDialog
                        trigger={
                            <Button size="sm" variant="outline">
                                {t('corr.crm.delete')}
                            </Button>
                        }
                        title={t('corr.crm.lead.delete_title', { name })}
                        description={t('corr.crm.lead.delete_note')}
                        reasons={reasons.deletion ?? []}
                        submitUrl={route('crm.leads.delete', leadId)}
                        confirmLabel={t('corr.crm.delete')}
                        destructive
                    />
                ) : (
                    <BlockedAction availability={remove} label={t('corr.crm.delete')} />
                ))}
        </>
    );
}

// ── Deals ───────────────────────────────────────────────────────────────────

export function DealReopen({ dealId, name, reasons }: { dealId: number; name: string; reasons: ReasonOption[] }) {
    const { t } = useTranslation();

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline">
                    {t('corr.crm.reopen')}
                </Button>
            }
            title={t('corr.crm.deal.reopen_title', { name })}
            description={t('corr.crm.deal.reopen_note')}
            reasons={reasons}
            submitUrl={route('crm.opportunities.reopen', dealId)}
            confirmLabel={t('corr.crm.reopen')}
        />
    );
}

// ── Phone book ──────────────────────────────────────────────────────────────

export function ContactDelete({ contactId, name, reasons }: { contactId: number; name: string; reasons: ReasonOption[] }) {
    const { t } = useTranslation();

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline">
                    {t('corr.crm.delete')}
                </Button>
            }
            title={t('corr.crm.contact.delete_title', { name })}
            description={t('corr.crm.bin_note')}
            reasons={reasons}
            submitUrl={route('crm.contacts.delete', contactId)}
            confirmLabel={t('corr.crm.delete')}
            destructive
        />
    );
}

export type ImportBatch = {
    id: number;
    file_name: string | null;
    at: string | null;
    by: string | null;
    created: number;
    updated: number;
    reversed_at: string | null;
    reversed_by: string | null;
    reverse_reason: string | null;
};

type ReversalPlan = {
    remove: { id: number; name: string }[];
    restore: { id: number; name: string; changes: string[] }[];
    kept: { id: number; name: string; why: string }[];
};

/** Reverse import, with the preview read first (a GET, writes nothing). */
function ReverseImportDialog({ batch, reasons }: { batch: ImportBatch; reasons: ReasonOption[] }) {
    const { t } = useTranslation();
    const [plan, setPlan] = useState<ReversalPlan | null>(null);

    const load = async () => {
        setPlan(null);
        const response = await fetch(route('crm.contacts.imports.preview', batch.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (response.ok) {
            setPlan((await response.json()) as ReversalPlan);
        }
    };

    return (
        <CorrectionReasonDialog
            trigger={
                <Button size="sm" variant="outline" onClick={() => void load()}>
                    {t('corr.crm.imports.reverse')}
                </Button>
            }
            title={t('corr.crm.imports.reverse_title')}
            description={t('corr.crm.imports.reverse_note')}
            reasons={reasons}
            submitUrl={route('crm.contacts.imports.reverse', batch.id)}
            confirmLabel={t('corr.crm.imports.reverse')}
            destructive
        >
            {plan === null ? (
                <p className="text-muted-foreground">{t('corr.crm.imports.loading')}</p>
            ) : (
                <div className="grid gap-2 rounded-md border p-3">
                    <PlanList title={t('corr.crm.imports.remove')} lines={plan.remove.map((r) => r.name)} />
                    <PlanList title={t('corr.crm.imports.restore')} lines={plan.restore.map((r) => `${r.name}: ${r.changes.join(', ')}`)} />
                    <PlanList title={t('corr.crm.imports.kept')} lines={plan.kept.map((r) => `${r.name}: ${r.why}`)} />
                </div>
            )}
        </CorrectionReasonDialog>
    );
}

function PlanList({ title, lines }: { title: string; lines: string[] }) {
    return (
        <div>
            <span className="font-medium">
                {title} ({lines.length})
            </span>
            {lines.length > 0 && (
                <ul className="text-muted-foreground mt-1 list-disc pl-5 text-xs">
                    {lines.map((line, i) => (
                        <li key={`${line}-${i}`}>{line}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** The phone book's recent files, each with Reverse import. */
export function RecentImports({ imports, reasons, canManage }: { imports: ImportBatch[]; reasons: ReasonOption[]; canManage: boolean }) {
    const { t } = useTranslation();

    if (imports.length === 0) {
        return null;
    }

    return (
        <section className="bg-card rounded-xl border p-4">
            <h2 className="mb-2 text-sm font-semibold">{t('corr.crm.imports.title')}</h2>
            <ul className="divide-y text-sm">
                {imports.map((batch) => (
                    <li key={batch.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <span className="font-medium">{batch.file_name ?? `#${batch.id}`}</span>
                            <span className="text-muted-foreground block text-xs">
                                {[
                                    batch.at ? batch.at.slice(0, 10).split('-').reverse().join('/') : null,
                                    batch.by,
                                    t('corr.crm.imports.line', { created: batch.created, updated: batch.updated }),
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                            </span>
                            {batch.reversed_at && (
                                <span className="text-muted-foreground block text-xs">
                                    {t('corr.crm.imports.reversed')}
                                    {batch.reversed_by ? `, ${batch.reversed_by}` : ''}
                                    {batch.reverse_reason ? `: ${batch.reverse_reason}` : ''}
                                </span>
                            )}
                        </span>
                        {canManage && !batch.reversed_at && <ReverseImportDialog batch={batch} reasons={reasons} />}
                    </li>
                ))}
            </ul>
        </section>
    );
}
