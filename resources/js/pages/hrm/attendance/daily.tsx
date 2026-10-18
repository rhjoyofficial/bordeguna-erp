import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { addDays, datesInText, dhakaToday, showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

interface CorrectionTypeOption {
    value: string;
    label: string;
    needs_punch: boolean;
    needs_time: boolean;
}

interface Fix {
    summary: string;
    by: string | null;
    at: string | null;
}

interface Waiver {
    id: number;
    reason: string;
    by: string | null;
    at: string | null;
}

interface Day {
    fixes: Fix[];
    waiver: Waiver | null;
    costs_money: boolean;
    can_forgive: boolean;
    id: number;
    date: string;
    weekday: string;
    employee_id: number;
    employee: string | null;
    employee_code: string | null;
    status: string;
    status_value: string;
    consequence: string;
    has_consequence: boolean;
    first_in: string | null;
    last_out: string | null;
    worked_hours: number;
    break_minutes: number;
    meal: {
        resolved_from: string;
        minutes: number;
        spans?: { out: string; in: string; minutes: number }[];
        overrun_minutes?: number;
        cause?: string;
    } | null;
    payable_hours: number;
    late_minutes: number;
    early_leave_minutes: number;
    grace_window: string | null;
    occurrence_number: number | null;
    is_provisional: boolean;
    holds_payroll: boolean;
    flags: string[];
    notes: string[];
    policy_version: number | null;
    finalized_by: string | null;
}

interface DailyProps {
    on: string;
    filters: { from: string; to: string; employee_id: number | null; review: boolean; holds: boolean; meals: boolean };
    employees: { id: number; name: string }[];
    days: Day[];
    summary: { total: number; provisional: number; holds: number; with_consequence: number };
    policy: { name: string; version: number; effective_from: string } | null;
    department_policies: { name: string; version: number; department: string | null }[];
    correction_types: CorrectionTypeOption[];
    directions: { value: string; label: string }[];
    can_waive: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Punch log', href: '/hrm/attendance' },
    { title: 'Daily attendance', href: '/hrm/attendance/daily' },
];

function statusVariant(status: string): 'default' | 'secondary' | 'outline' {
    if (status === 'present' || status === 'field_duty') return 'default';
    if (status === 'absent' || status === 'missing_punch') return 'outline';
    return 'secondary';
}

/**
 * Joy, 24 Aug 2026: a late day must catch the eye. The amber chip idiom the
 * HR dashboard already uses, readable in both themes; every other status
 * keeps its variant untouched.
 */
function statusBadgeClass(status: string): string {
    return status === 'late' ? 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' : '';
}

/** One headline number for the range on screen. */
function Tile({ label, value, tone, hint }: { label: string; value: number; tone?: 'warn' | 'bad'; hint?: string }) {
    return (
        <div className="border-border rounded-lg border p-3">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p
                className={
                    tone === 'bad'
                        ? 'text-2xl font-semibold text-red-600 dark:text-red-400'
                        : tone === 'warn'
                          ? 'text-2xl font-semibold text-amber-600 dark:text-amber-400'
                          : 'text-2xl font-semibold'
                }
            >
                {value}
            </p>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

/**
 * Fix a day without leaving the grid (Joy, 23 Aug 2026: "can be fixed direct
 * from here"). Everyone on this screen holds hrm.attendance.manage, so the
 * correction filed here APPLIES IMMEDIATELY and the day is reprocessed - the
 * same privileged path the Corrections screen uses, through the same endpoint,
 * so there is exactly one way a correction happens.
 */
function FixDayDialog({ day, types, directions }: { day: Day; types: CorrectionTypeOption[]; directions: { value: string; label: string }[] }) {
    const [open, setOpen] = useState(false);
    const [type, setType] = useState(types[0]?.value ?? 'add_punch');
    const [punches, setPunches] = useState<{ id: number; label: string }[]>([]);
    const [punchId, setPunchId] = useState('');
    const [correctedAt, setCorrectedAt] = useState(`${day.date}T18:00`);
    const [direction, setDirection] = useState('');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const selected = types.find((t) => t.value === type);

    // The day's real punches, fetched when the dialog opens so amend and
    // disregard can point at one - the same endpoint the Corrections screen
    // uses.
    const openDialog = (next: boolean) => {
        setOpen(next);
        if (next) {
            fetch(route('hrm.attendance.corrections.punches') + `?employee_id=${day.employee_id}&date=${day.date}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((rows) => setPunches(rows))
                .catch(() => setPunches([]));
        }
    };

    const submit = () => {
        setSaving(true);
        router.post(
            route('hrm.attendance.corrections.store'),
            {
                employee_id: day.employee_id,
                attendance_date: day.date,
                type,
                punch_id: selected?.needs_punch ? punchId : null,
                corrected_at: selected?.needs_time ? correctedAt.replace('T', ' ') + ':00' : null,
                direction: direction === '' ? null : direction,
                reason,
            },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={openDialog}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Fix
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Fix {showDate(day.date)} for {day.employee}
                    </DialogTitle>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="fix-type" required>
                            What is wrong
                        </Label>
                        <SearchSelect id="fix-type" value={type} onChange={(e) => setType(e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    {selected?.needs_punch && (
                        <div className="grid gap-2">
                            <Label htmlFor="fix-punch" required>
                                Which punch
                            </Label>
                            <SearchSelect id="fix-punch" value={punchId} onChange={(e) => setPunchId(e.target.value)}>
                                <option value="">- Choose -</option>
                                {punches.map((punch) => (
                                    <option key={punch.id} value={punch.id}>
                                        {punch.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                    )}
                    {selected?.needs_time && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="fix-time" required>
                                    Correct time
                                </Label>
                                <Input
                                    id="fix-time"
                                    aria-required
                                    type="datetime-local"
                                    value={correctedAt}
                                    onChange={(e) => setCorrectedAt(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fix-direction">Direction</Label>
                                <SearchSelect id="fix-direction" value={direction} onChange={(e) => setDirection(e.target.value)}>
                                    <option value="">- Unknown -</option>
                                    {directions.map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="fix-reason" required>
                            Reason
                        </Label>
                        <Input id="fix-reason" aria-required value={reason} onChange={(e) => setReason(e.target.value)} placeholder="What happened" />
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={saving || reason.trim() === '' || (selected?.needs_punch ? punchId === '' : false)}>
                        Apply fix
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Forgive one day's salary deduction (Joy, 24 Aug 2026). Shown only to
 * holders of hrm.attendance.waive, and only on a day that costs money. The
 * day's verdict does not change - absent stays absent on every screen - the
 * deduction alone is removed from payroll, with the forgiver's name and
 * reason on permanent record.
 */
function ForgiveDialog({ day }: { day: Day }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = () => {
        setSaving(true);
        router.post(
            route('hrm.attendance.waivers.store'),
            { employee_id: day.employee_id, attendance_date: day.date, reason },
            { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => setSaving(false) },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Forgive
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        Forgive {showDate(day.date)} for {day.employee}
                    </DialogTitle>
                    <DialogDescription>
                        Forgives this day: any salary deduction is removed and the day stops counting toward the monthly lateness allowance, so a
                        later day is not pushed into a deduction by it. The attendance record itself does not change - the day still reads{' '}
                        {day.status.toLowerCase()} everywhere - and your name and reason stay on record. It can be undone.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="waive-reason" required>
                        Reason
                    </Label>
                    <Input
                        id="waive-reason"
                        aria-required
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Why this day is forgiven"
                    />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={saving || reason.trim() === ''}>
                        Forgive this day
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Issue a show cause notice straight from the late day it is about
 * (Joy, 24 Aug 2026). The subject and body arrive prefilled from the day's
 * own facts - window, occurrence, date - and stay editable, because the
 * template is a starting point and the letter is HR's.
 */
function ShowCauseDialog({ day, on }: { day: Day; on: string }) {
    const month = new Date(day.date + 'T00:00:00').toLocaleString('en-GB', { month: 'long', year: 'numeric' });
    const defaultSubject = 'Show cause notice for repeated late arrival';
    const defaultBody =
        `Dear ${day.employee ?? 'employee'},\n\n` +
        `Our attendance records show that on ${showDate(day.date)} you arrived ${day.late_minutes} minutes after your shift start` +
        (day.grace_window ? ` (${day.grace_window})` : '') +
        (day.occurrence_number ? `, your ${ordinal(day.occurrence_number)} such occurrence in ${month}` : '') +
        '.\n\nYou are requested to explain in writing, within 3 working days of receiving this notice, why disciplinary or salary consequences under the attendance policy should not be applied.\n\nIf no satisfactory explanation is received within this time, the consequences stated in the policy will apply.';

    const [open, setOpen] = useState(false);
    const [subject, setSubject] = useState(defaultSubject);
    const [body, setBody] = useState(defaultBody);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const submit = () =>
        router.post(
            route('hrm.notices.store'),
            {
                employee_id: day.employee_id,
                subject,
                body,
                context: {
                    window_label: day.grace_window,
                    occurrence: day.occurrence_number,
                    month,
                    dates: [day.date],
                },
            },
            {
                preserveScroll: true,
                onError: (e: Record<string, string>) => setErrors(e),
                onSuccess: () => setOpen(false),
            },
        );

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    // Re-seed on every open so the template always describes
                    // the row it was opened from, not a previous one.
                    setSubject(defaultSubject);
                    setBody(defaultBody);
                    setErrors({});
                }
                setOpen(next);
            }}
        >
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm">
                    Show cause
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Show cause notice - {day.employee} ({showDate(on)})
                    </DialogTitle>
                    <DialogDescription>
                        Edit the letter as needed. Issuing puts it on permanent record and notifies the employee in the ERP; both of you can print a
                        copy.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor={`sc_subject_${day.id}`} required>
                            Subject
                        </Label>
                        <Input id={`sc_subject_${day.id}`} aria-required value={subject} onChange={(e) => setSubject(e.target.value)} />
                        {errors.subject && <p className="text-destructive text-xs">{errors.subject}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`sc_body_${day.id}`} required>
                            Notice text
                        </Label>
                        <textarea
                            id={`sc_body_${day.id}`}
                            aria-required
                            className="border-input bg-background min-h-48 w-full rounded-md border px-3 py-2 text-sm"
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                        />
                        {errors.body && <p className="text-destructive text-xs">{errors.body}</p>}
                    </div>
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button onClick={submit} disabled={subject.trim() === '' || body.trim() === ''}>
                        Issue notice
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ordinal(n: number): string {
    const suffix = n % 100 >= 11 && n % 100 <= 13 ? 'th' : (['th', 'st', 'nd', 'rd'][n % 10] ?? 'th');

    return `${n}${suffix}`;
}

/**
 * A filter that is switched on says so in words, and can be switched off from
 * where it is doing the hiding. Three unlabelled checkboxes in a toolbar left
 * somebody staring at an empty grid wondering what happened to their day.
 */
function FilterToggle({ label, active, onChange }: { label: string; active: boolean; onChange: (next: boolean) => void }) {
    return (
        <button
            type="button"
            onClick={() => onChange(!active)}
            aria-pressed={active}
            className={
                active
                    ? 'border-primary bg-primary text-primary-foreground rounded-full border px-3 py-1 text-xs font-medium'
                    : 'border-border text-muted-foreground hover:text-foreground rounded-full border px-3 py-1 text-xs'
            }
        >
            {label}
        </button>
    );
}

export default function DailyAttendance({
    on,
    filters,
    employees = [],
    days,
    summary,
    policy,
    department_policies = [],
    correction_types = [],
    directions = [],
    can_waive = false,
}: DailyProps) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    // `on` is left out: it is the single-day shortcut, and sending it with a
    // range would pin the grid to one day whatever the date boxes say.
    const reload = (params: Record<string, string | number | boolean | null>) =>
        router.get(route('hrm.attendance.daily.index'), { ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    const isRange = filters.from !== filters.to;
    const anyFilter = filters.review || filters.holds || filters.meals || filters.employee_id !== null;

    const run = (routeName: string, data: Record<string, string | number | boolean | number[]>) => {
        setBusy(true);
        router.post(route(routeName), data, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    // Presets count back from the To date already on screen, not from today, so
    // "last 7 days" while reading last March means the last seven days of that
    // window rather than a jump home.
    const jump = (span: number) => {
        // Calendar arithmetic on the Y-m-d itself: no browser time zone involved.
        reload({ from: addDays(filters.to, -span + 1), to: filters.to });
    };

    const showToday = () => {
        const iso = dhakaToday();
        reload({ from: iso, to: iso });
    };

    const columns = isRange ? 10 : 9;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Daily attendance" />
            <div className="flex flex-col gap-6 px-4 py-6">
                <ReportHeading title="Daily attendance" />

                {/* Filters first, in labelled fields rather than a crowded toolbar.
                    Running the engine is a different kind of action and lives in
                    its own strip below, so nobody presses it looking for Show. */}
                <div className="border-border rounded-xl border">
                    <FormRow className="p-4">
                        <Field label="From" htmlFor="from">
                            <Input id="from" type="date" value={filters.from} onChange={(e) => reload({ from: e.target.value })} />
                        </Field>
                        <Field label="To" htmlFor="to">
                            <Input id="to" type="date" value={filters.to} onChange={(e) => reload({ to: e.target.value })} />
                        </Field>
                        <Field label="Employee" htmlFor="employee">
                            <SearchSelect
                                id="employee"
                                value={filters.employee_id ?? ''}
                                onChange={(e) => reload({ employee_id: e.target.value || null })}
                            >
                                <option value="">All employees</option>
                                {employees.map((e) => (
                                    <option key={e.id} value={e.id}>
                                        {e.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <div className="border-border flex flex-wrap items-center gap-2 border-t px-4 py-3">
                        <span className="text-muted-foreground text-xs">Show only</span>
                        <FilterToggle label="Needs review" active={filters.review} onChange={(v) => reload({ review: v })} />
                        <FilterToggle label="Holding payroll" active={filters.holds} onChange={(v) => reload({ holds: v })} />
                        <FilterToggle label="Meal exceptions" active={filters.meals} onChange={(v) => reload({ meals: v })} />
                        <span className="bg-border mx-1 hidden h-4 w-px sm:inline-block" />
                        <Button variant="outline" size="sm" onClick={showToday}>
                            Today
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => jump(7)}>
                            Last 7 days
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => jump(30)}>
                            Last 30 days
                        </Button>
                        {anyFilter && (
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-foreground ml-auto text-xs underline underline-offset-2"
                                onClick={() => reload({ review: false, holds: false, meals: false, employee_id: null })}
                            >
                                Clear filters
                            </button>
                        )}
                    </div>

                    {/* Process and finalise act on ONE day, so they are offered only
                        when one day is being looked at, and the day is named on the
                        button. Offering them over a range would suggest they do
                        something they do not. */}
                    <div className="border-border bg-muted/40 flex flex-wrap items-center gap-3 border-t px-4 py-3">
                        {isRange ? (
                            <p className="text-muted-foreground text-xs">
                                Processing and finalising work on a single day. Set From and To to the same date to run the engine.
                            </p>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-xs">Engine actions for {showDate(on)}</p>
                                <Button size="sm" variant="outline" disabled={busy} onClick={() => run('hrm.attendance.daily.process', { on })}>
                                    {busy ? 'Working…' : 'Process day'}
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={busy || summary.provisional === 0}
                                    onClick={() => run('hrm.attendance.daily.finalize', { on })}
                                >
                                    Finalise all
                                </Button>
                                {summary.provisional === 0 && summary.total > 0 && (
                                    <span className="text-muted-foreground text-xs">Nothing is awaiting review on this day.</span>
                                )}
                            </>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Tile
                        label="Days processed"
                        value={summary.total}
                        hint={isRange ? `${showDate(filters.from)} to ${showDate(filters.to)}` : showDate(on)}
                    />
                    <Tile label="Awaiting review" value={summary.provisional} tone={summary.provisional > 0 ? 'warn' : undefined} />
                    <Tile label="Holding payroll" value={summary.holds} tone={summary.holds > 0 ? 'bad' : undefined} />
                    <Tile label="With a consequence" value={summary.with_consequence} tone={summary.with_consequence > 0 ? 'warn' : undefined} />
                </div>

                {policy === null ? (
                    <div className="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border p-3 text-sm">
                        No attendance policy is in force on this date, so lateness and half-day rules cannot be applied.
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        Judged by “{policy.name}” v{policy.version}, in force from {showDate(policy.effective_from)}.
                        {department_policies.length > 0 && (
                            <>
                                {' '}
                                Except{' '}
                                {department_policies.map((p) => `${p.department ?? 'a department'}, judged by “${p.name}” v${p.version}`).join('; ')}.
                            </>
                        )}
                    </p>
                )}

                <div className="border-border overflow-x-auto rounded-xl border">
                    {days.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-sm">
                            {anyFilter
                                ? 'Nothing matches these filters. Clear them to see the whole range.'
                                : `Nothing processed between ${showDate(filters.from)} and ${showDate(filters.to)}. Pick a single date and use “Process day” to run the engine over the captured punches.`}
                        </p>
                    ) : (
                        <table className="w-full min-w-[72rem] text-sm">
                            <thead>
                                <tr className="border-border bg-muted/40 text-muted-foreground border-b text-left text-xs tracking-wide uppercase">
                                    {isRange && <th className="px-4 py-2 font-medium">Date</th>}
                                    <th className="px-4 py-2 font-medium">Employee</th>
                                    <th className="px-4 py-2 font-medium">Status</th>
                                    <th className="px-4 py-2 font-medium">In / Out</th>
                                    <th className="px-4 py-2 text-right font-medium">Worked</th>
                                    <th className="px-4 py-2 font-medium">Meal</th>
                                    <th className="px-4 py-2 text-right font-medium">Payable</th>
                                    <th className="px-4 py-2 text-right font-medium">Late</th>
                                    <th className="px-4 py-2 font-medium">Consequence</th>
                                    <th className="px-4 py-2 text-right font-medium">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {days.map((day) => (
                                    <Fragment key={day.id}>
                                        <tr className="border-border hover:bg-muted/30 border-b align-top">
                                            {isRange && (
                                                <td className="px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                    {showDate(day.date)} <span className="text-muted-foreground">{day.weekday}</span>
                                                </td>
                                            )}
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <span className="font-medium">{day.employee}</span>
                                                <span className="text-muted-foreground ml-2 text-xs">{day.employee_code}</span>
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                <Badge variant={statusVariant(day.status_value)} className={statusBadgeClass(day.status_value)}>
                                                    {day.status}
                                                </Badge>
                                                {day.waiver && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 border-emerald-500/50 text-emerald-700 dark:text-emerald-400"
                                                        title={`${day.waiver.reason}${day.waiver.by ? ` (by ${day.waiver.by})` : ''}${day.waiver.at ? ` at ${day.waiver.at}` : ''}`}
                                                    >
                                                        Forgiven
                                                    </Badge>
                                                )}
                                                {day.fixes.length > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2"
                                                        title={day.fixes.map((f) => `${f.summary}${f.by ? ` (by ${f.by})` : ''}`).join('\n')}
                                                    >
                                                        Fixed
                                                    </Badge>
                                                )}
                                                {day.is_provisional && <span className="text-muted-foreground ml-2 text-xs">provisional</span>}
                                                {day.holds_payroll && (
                                                    <Badge variant="outline" className="ml-2 border-red-500/50 text-red-600 dark:text-red-400">
                                                        Payroll hold
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                {day.first_in ?? '-'} / {day.last_out ?? '-'}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                {day.worked_hours}h
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2.5 font-mono text-xs whitespace-nowrap">
                                                {day.meal === null
                                                    ? '-'
                                                    : day.meal.resolved_from === 'punches' && day.meal.spans?.length
                                                      ? `${day.meal.spans[0].out.slice(0, 5)}-${day.meal.spans[0].in.slice(0, 5)} (${day.meal.minutes}m)`
                                                      : `${day.meal.minutes}m fallback`}
                                                {day.meal?.overrun_minutes !== undefined && (
                                                    <Badge variant="outline" className="ml-1">
                                                        +{day.meal.overrun_minutes}m
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-right whitespace-nowrap tabular-nums">{day.payable_hours}h</td>
                                            <td className="text-muted-foreground px-4 py-2.5 text-right whitespace-nowrap tabular-nums">
                                                {day.late_minutes > 0 ? `${day.late_minutes}m` : '-'}
                                                {day.occurrence_number !== null && <span className="ml-1 text-xs">#{day.occurrence_number}</span>}
                                            </td>
                                            <td className="px-4 py-2.5 whitespace-nowrap">
                                                {day.has_consequence ? (
                                                    <Badge variant="outline">{day.consequence}</Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2.5 text-right whitespace-nowrap">
                                                <Button variant="ghost" size="sm" onClick={() => setExpanded(expanded === day.id ? null : day.id)}>
                                                    {expanded === day.id ? 'Hide' : 'Explain'}
                                                </Button>
                                                {day.is_provisional && !day.holds_payroll && !isRange && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() => run('hrm.attendance.daily.finalize', { on, ids: [day.id] })}
                                                    >
                                                        Finalise
                                                    </Button>
                                                )}
                                                {/* Any day can be fixed from its own row, not only held
                                                    ones - the fix applies instantly and the day is
                                                    reprocessed before this grid reloads. */}
                                                <FixDayDialog day={day} types={correction_types} directions={directions} />
                                                {/* A late day can become a formal question from its own
                                                    row - everyone here holds hrm.attendance.manage, the
                                                    same gate the notices screen enforces. */}
                                                {day.late_minutes > 0 && <ShowCauseDialog day={day} on={day.date} />}
                                                {/* Money actions, only for hrm.attendance.waive holders:
                                                    forgive a costing day, or undo a forgiveness. */}
                                                {can_waive && day.can_forgive && !day.waiver && <ForgiveDialog day={day} />}
                                                {can_waive && day.waiver && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() => {
                                                            setBusy(true);
                                                            router.post(
                                                                route('hrm.attendance.waivers.revoke', day.waiver!.id),
                                                                {},
                                                                { preserveScroll: true, onFinish: () => setBusy(false) },
                                                            );
                                                        }}
                                                    >
                                                        Undo forgive
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                        {expanded === day.id && (
                                            <tr className="border-border bg-muted/30 border-b">
                                                <td colSpan={columns} className="px-4 py-3">
                                                    <div className="text-xs">
                                                        <p className="mb-1 font-medium">How this day was decided</p>
                                                        <ul className="text-muted-foreground ml-4 list-disc space-y-1">
                                                            {day.notes.map((note, i) => (
                                                                <li key={i}>{note}</li>
                                                            ))}
                                                        </ul>
                                                        {day.waiver && (
                                                            <p className="mt-2 text-emerald-700 dark:text-emerald-400">
                                                                Deduction forgiven{day.waiver.by ? ` by ${day.waiver.by}` : ''}
                                                                {day.waiver.at ? ` at ${day.waiver.at}` : ''}: {day.waiver.reason} The day&apos;s
                                                                record above is unchanged; only the salary deduction is removed.
                                                            </p>
                                                        )}
                                                        {day.fixes.length > 0 && (
                                                            <div className="mt-2">
                                                                <p className="mb-1 font-medium">Fixes applied to this day</p>
                                                                <ul className="text-muted-foreground ml-4 list-disc space-y-1">
                                                                    {day.fixes.map((fix, i) => (
                                                                        <li key={i}>
                                                                            {datesInText(fix.summary)}
                                                                            {fix.by ? ` - by ${fix.by}` : ''}
                                                                            {fix.at ? ` at ${fix.at}` : ''}
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        )}
                                                        {day.flags.length > 0 && (
                                                            <div className="mt-2 flex flex-wrap gap-2">
                                                                {day.flags.map((flag) => (
                                                                    <Badge key={flag} variant="secondary">
                                                                        {flag.replace(/_/g, ' ')}
                                                                    </Badge>
                                                                ))}
                                                            </div>
                                                        )}
                                                        {day.policy_version !== null && (
                                                            <p className="text-muted-foreground mt-2">Policy version {day.policy_version}.</p>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
