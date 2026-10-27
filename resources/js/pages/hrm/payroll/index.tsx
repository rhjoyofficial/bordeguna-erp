import { BusyButton } from '@/components/busy-button';
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
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Lock } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

interface Line {
    code: string;
    label: string;
    type: string;
    amount: number;
    quantity: number | null;
    rate: number | null;
    formula: string;
    source: string;
}

interface Payslip {
    id: number;
    employee: string | null;
    employee_code: string | null;
    gross_salary: number;
    total_earnings: number;
    total_deductions: number;
    net: number;
    method: string;
    paid: number;
    is_blocked: boolean;
    block_reasons: string[];
    notes: string[];
    lines: Line[];
}

interface ValidationRow {
    employee_id: number;
    employee: string;
    employee_code: string;
    issues: string[];
    /** ⭐ 27 Oct 2026: the provisional days blocking this person, and the Daily grid filtered to them. */
    review_days?: { date: string; pending: string | null }[];
    review_url?: string | null;
}

interface WarningRow {
    employee_id: number;
    employee: string;
    employee_code: string;
    warnings: string[];
}

interface UnlockRequest {
    id: number;
    reason: string;
    is_emergency_override: boolean;
    override_reason: string | null;
    is_applied: boolean;
    status: string;
    stage: string | null;
    requested_by: string | null;
}

interface Run {
    id: number;
    version: number;
    status: string;
    status_label: string;
    employee_count: number;
    blocked_count: number;
    total_earnings: number;
    total_deductions: number;
    total_net: number;
    calculated_at: string | null;
    calculated_by: string | null;
    approved_at: string | null;
    approved_by: string | null;
    locked_at: string | null;
    locked_by: string | null;
    is_rerunnable: boolean;
    is_reopenable: boolean;
    closed_reason: string | null;
    accrual_missing: boolean;
    queue_state: string | null;
    failure_message: string | null;
    validation_report: {
        issue_count: number;
        checked: number;
        employees: ValidationRow[];
        review_url?: string | null;
        warning_count?: number;
        warnings?: WarningRow[];
        earned_leave?: EarnedLeaveOutcome;
    } | null;
    awaiting_approval: boolean;
    approval_stage: string | null;
    can_approve: boolean;
    unlock_requests: UnlockRequest[];
}

/**
 * ⭐ 27 Oct 2026 (Joy): earned leave is decided when payroll is calculated,
 * so the run carries the outcome for HR to see while reviewing it.
 */
interface EarnedLeaveOutcome {
    granted?: number;
    withheld?: number;
    changed?: number;
    frozen?: boolean;
    days?: number;
    reason?: string | null;
    failed?: string;
    rows?: { employee_code: string; employee: string; days: number; withheld_reason: string | null; changed: boolean }[];
}

interface PeriodRow {
    year: number;
    month: number;
    label: string;
    status: string | null;
    status_label: string | null;
    queue_state: string | null;
    awaiting_approval: boolean;
}

interface YearMonth {
    year: number;
    month: number;
}

interface Props {
    year: number;
    month: number;
    period: { id: number; label: string; start_date: string; end_date: string; divisor_days: number } | null;
    run: Run | null;
    payslips: Payslip[];
    periods: PeriodRow[];
    first_period: YearMonth | null;
    current_cycle: YearMonth;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payroll', href: '/hrm/payroll' }];
const money = (n: number) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const monthLabel = (year: number, month: number) => `${MONTHS[month - 1]} ${year}`;
const ymKey = (ym: YearMonth) => ym.year * 100 + ym.month;
const shiftMonth = (ym: YearMonth, delta: number): YearMonth => {
    const d = new Date(ym.year, ym.month - 1 + delta, 1);
    return { year: d.getFullYear(), month: d.getMonth() + 1 };
};
const shortDate = (iso: string, withYear: boolean) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(withYear ? { year: 'numeric' } : {}) });

const panelClass = 'border-sidebar-border/70 dark:border-sidebar-border rounded-xl border';

/**
 * What one month means in the switcher: a word and a dot. Colour only where
 * it says something: green when money is approved or paid, amber while a
 * person still has to act, grey for the rest.
 */
type MonthTone = 'locked' | 'good' | 'pending' | 'none' | 'off';
interface MonthState {
    label: string;
    tone: MonthTone;
    disabled: boolean;
}

const DOT: Record<MonthTone, string> = {
    locked: 'bg-foreground/70',
    good: 'bg-primary',
    pending: 'bg-amber-500',
    none: 'bg-muted-foreground/40',
    off: 'bg-border',
};

function monthState(ym: YearMonth, periods: PeriodRow[], first: YearMonth | null, current: YearMonth): MonthState {
    const key = ymKey(ym);
    if (key < ymKey(first ?? current)) return { label: 'No payroll', tone: 'off', disabled: true };
    if (key > ymKey(current)) return { label: 'Not started', tone: 'off', disabled: true };

    const p = periods.find((row) => row.year === ym.year && row.month === ym.month);
    if (!p || p.status === null) return { label: 'Not run', tone: 'none', disabled: false };

    switch (p.status) {
        case 'locked':
            return { label: 'Locked', tone: 'locked', disabled: false };
        case 'disbursed':
            return { label: 'Paid', tone: 'good', disabled: false };
        case 'approved':
            return { label: 'Approved', tone: 'good', disabled: false };
        case 'reviewed':
            return { label: p.awaiting_approval ? 'Awaiting approval' : 'Reviewed', tone: 'pending', disabled: false };
        case 'calculated':
            return { label: p.queue_state === 'queued' ? 'Calculating' : 'Calculated', tone: 'pending', disabled: false };
        case 'draft':
            if (p.queue_state === 'queued') return { label: 'Calculating', tone: 'pending', disabled: false };
            if (p.queue_state === 'blocked') return { label: 'Needs fixes', tone: 'pending', disabled: false };
            if (p.queue_state === 'failed') return { label: 'Failed', tone: 'pending', disabled: false };
            return { label: 'Not run', tone: 'none', disabled: false };
        default:
            return { label: p.status_label ?? p.status, tone: 'none', disabled: false };
    }
}

function Dot({ tone, className }: { tone: MonthTone; className?: string }) {
    return <span aria-hidden="true" className={cn('inline-block size-2 shrink-0 rounded-full', DOT[tone], className)} />;
}

/**
 * The month switcher: arrows for the neighbours, and a picker with a year
 * stepper and a 3x4 grid where every month carries its state. Replaces the
 * year box + month list + View button + a row of month chips that grew by one
 * every month (Joy, 15 Oct 2026).
 */
function MonthSwitcher({
    value,
    periods,
    first,
    current,
    onPick,
}: {
    value: YearMonth;
    periods: PeriodRow[];
    first: YearMonth | null;
    current: YearMonth;
    onPick: (ym: YearMonth) => void;
}) {
    const [open, setOpen] = useState(false);
    const [pickerYear, setPickerYear] = useState(value.year);
    const state = (ym: YearMonth) => monthState(ym, periods, first, current);
    const cur = state(value);
    const prev = shiftMonth(value, -1);
    const next = shiftMonth(value, 1);
    const lower = first ?? current;

    return (
        <div className="flex items-center gap-1.5">
            <Button variant="outline" size="icon" aria-label="Previous month" disabled={state(prev).disabled} onClick={() => onPick(prev)}>
                <ChevronLeft />
            </Button>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (next) setPickerYear(value.year);
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        className="h-9 min-w-56 justify-between gap-3 px-3"
                        aria-label={`${monthLabel(value.year, value.month)}, ${cur.label}. Choose a month`}
                    >
                        <span className="text-[15px] font-semibold">{monthLabel(value.year, value.month)}</span>
                        <span className="text-muted-foreground flex items-center gap-1.5 text-xs font-normal">
                            <Dot tone={cur.tone} />
                            {cur.label}
                            <ChevronDown className="size-3.5" />
                        </span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent align="start" className="w-[22.5rem] p-3.5">
                    <div className="mb-3 flex items-center justify-between">
                        <Button variant="outline" size="icon" aria-label="Previous year" onClick={() => setPickerYear((y) => y - 1)}>
                            <ChevronLeft />
                        </Button>
                        <span className="font-semibold">{pickerYear}</span>
                        <Button variant="outline" size="icon" aria-label="Next year" onClick={() => setPickerYear((y) => y + 1)}>
                            <ChevronRight />
                        </Button>
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                        {MONTHS.map((name, i) => {
                            const ym = { year: pickerYear, month: i + 1 };
                            const s = state(ym);
                            const selected = ym.year === value.year && ym.month === value.month;
                            return (
                                <button
                                    key={name}
                                    type="button"
                                    disabled={s.disabled}
                                    aria-pressed={selected}
                                    onClick={() => {
                                        setOpen(false);
                                        onPick(ym);
                                    }}
                                    className={cn(
                                        'bg-background flex min-h-[3.25rem] flex-col items-start gap-0.5 rounded-md border px-2 py-1.5 text-left',
                                        'hover:border-primary disabled:hover:border-border disabled:cursor-not-allowed disabled:opacity-45',
                                        selected ? 'border-primary ring-primary/25 ring-2' : 'border-sidebar-border/70 dark:border-sidebar-border',
                                    )}
                                >
                                    <span className="text-[13px] font-medium">{name.slice(0, 3)}</span>
                                    <span className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                        <Dot tone={s.tone} />
                                        {s.label}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-muted-foreground mt-3 text-[11.5px]">
                        Months before {monthLabel(lower.year, lower.month)} have no payroll in the ERP. Future months open when their cycle starts.
                    </p>
                </PopoverContent>
            </Popover>
            <Button variant="outline" size="icon" aria-label="Next month" disabled={state(next).disabled} onClick={() => onPick(next)}>
                <ChevronRight />
            </Button>
        </div>
    );
}

const STEPS = [
    { status: 'calculated', label: 'Calculated' },
    { status: 'reviewed', label: 'Reviewed' },
    { status: 'approved', label: 'Approved' },
    { status: 'disbursed', label: 'Paid' },
    { status: 'locked', label: 'Locked' },
];

function Stepper({ at, running }: { at: number; running: boolean }) {
    return (
        <ol aria-label="Payroll progress" className="grid grid-cols-5 gap-2">
            {STEPS.map((step, i) => {
                const done = i <= at;
                const next = i === at + 1 && !running;
                return (
                    <li key={step.status} className="flex flex-col gap-2">
                        <div className={cn('h-1 rounded-full', done ? 'bg-primary' : 'bg-border')} />
                        <div className="flex items-center gap-1.5 text-xs">
                            <span
                                className={cn(
                                    'inline-grid size-[18px] shrink-0 place-items-center rounded-full text-[10.5px] font-semibold',
                                    done
                                        ? 'bg-primary text-primary-foreground'
                                        : next
                                          ? 'border-primary text-primary border-[1.5px]'
                                          : 'text-muted-foreground border',
                                )}
                            >
                                {done ? '✓' : i + 1}
                            </span>
                            {/* On a phone five labels do not fit beside their marks: only the
                                step just done and the next one keep their words there. */}
                            <span
                                className={cn(
                                    'truncate',
                                    done || next ? 'font-medium' : 'text-muted-foreground',
                                    i === at || next ? '' : 'hidden sm:inline',
                                )}
                            >
                                {step.label}
                            </span>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * A lifecycle step that needs a stated reason - reopening, cancelling, or
 * requesting an unlock. Each of these changes what somebody gets paid, so none
 * of them is a bare button.
 */
function ReasonDialog({
    label,
    title,
    description,
    routeName,
    runId,
    destructive,
}: {
    label: string;
    title: string;
    description: string;
    routeName: string;
    runId: number;
    destructive?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant={destructive ? 'outline' : 'secondary'}>
                    {label}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="reason" required>
                        Reason
                    </Label>
                    <Input id="reason" aria-required="true" value={reason} onChange={(e) => setReason(e.target.value)} />
                    {errors.reason && <p className="text-destructive text-xs">{errors.reason}</p>}
                    {errors.status && <p className="text-destructive text-xs">{errors.status}</p>}
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                route(routeName, runId),
                                { reason },
                                {
                                    preserveScroll: true,
                                    onError: (e: Record<string, string>) => setErrors(e),
                                    onSuccess: () => {
                                        setOpen(false);
                                        setErrors({});
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

interface Progress {
    state: string;
    processed: number;
    total: number;
}

const POLL_MS = 1500;
// 15 minutes. A run that is still "queued" after that has lost its worker,
// and polling it forever would only hide that.
const MAX_POLLS = 600;

/**
 * Watches a queued calculation (WFL-04 asks for visible progress). The run
 * is opened and marked `queued` by the request that queues the job, so the
 * screen always has an id to poll, including after navigating away and back.
 * When the job writes any other queue_state the page reloads its props.
 */
function useCalculationProgress(run: Run | null): { progress: Progress | null; stale: boolean } {
    const runId = run?.id ?? null;
    const active = run?.queue_state === 'queued';
    const [progress, setProgress] = useState<Progress | null>(null);
    const [stale, setStale] = useState(false);

    useEffect(() => {
        setProgress(null);
        setStale(false);
        if (runId === null || !active) return;

        let cancelled = false;
        let polls = 0;
        let timer: ReturnType<typeof setTimeout> | undefined;

        const tick = async () => {
            if (cancelled) return;
            try {
                const response = await fetch(route('hrm.payroll.progress', runId), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (response.ok) {
                    const data = (await response.json()) as Progress & { queue_state: string | null };
                    if (cancelled) return;
                    setProgress({ state: data.state, processed: data.processed, total: data.total });
                    if (data.queue_state !== 'queued') {
                        router.reload({ only: ['run', 'payslips', 'periods'] });
                        return;
                    }
                }
            } catch {
                // A dropped request is not a failed calculation: try again.
            }
            polls += 1;
            if (polls >= MAX_POLLS) {
                setStale(true);
                return;
            }
            timer = setTimeout(tick, POLL_MS);
        };

        void tick();

        return () => {
            cancelled = true;
            if (timer) clearTimeout(timer);
        };
    }, [runId, active]);

    return { progress, stale };
}

function ProgressPanel({ label, progress, stale, onRetry }: { label: string; progress: Progress | null; stale: boolean; onRetry: () => void }) {
    const total = progress?.total ?? 0;
    const processed = progress?.processed ?? 0;
    const waiting = !progress || progress.state === 'idle';
    const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

    if (stale) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div className="text-sm font-semibold">Still not finished after 15 minutes</div>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        The queue worker may have stopped. Check it, then calculate again; nothing has been approved or paid.
                    </p>
                </div>
                <Button size="sm" variant="outline" onClick={onRetry}>
                    Calculate again
                </Button>
            </div>
        );
    }

    return (
        <div role="status" aria-live="polite">
            <div className="flex flex-wrap justify-between gap-2 text-sm">
                <span className="font-semibold">Calculating {label}…</span>
                <span className="text-muted-foreground tabular-nums">
                    {waiting
                        ? 'Waiting for the queue worker…'
                        : progress.state === 'validating'
                          ? 'Checking the inputs…'
                          : `${processed} of ${total} staff`}
                </span>
            </div>
            <div
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={Math.max(total, 1)}
                aria-valuenow={waiting ? undefined : processed}
                aria-label={`Calculating ${label}`}
                className="bg-muted mt-2.5 h-2 overflow-hidden rounded-full"
            >
                <div
                    className={cn('bg-primary h-full rounded-full transition-[width] duration-300', total === 0 && 'animate-pulse opacity-50')}
                    style={{ width: total === 0 ? '100%' : `${percent}%` }}
                />
            </div>
            <p className="text-muted-foreground mt-2 text-xs">You can leave this page; the calculation keeps running and the result waits here.</p>
        </div>
    );
}

/**
 * The one next action for the run's state, with a line saying why. Every
 * transition the old row of buttons offered is still here, but one at a time
 * and explained (the approved design, 15 Oct 2026).
 */
function NextAction({
    run,
    label,
    monthName,
    running,
    progress,
    stale,
    calculate,
}: {
    run: Run | null;
    label: string;
    monthName: string;
    running: boolean;
    progress: Progress | null;
    stale: boolean;
    calculate: () => void;
}) {
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirmingLock, setConfirmingLock] = useState(false);

    const post = (routeName: string, data: Record<string, string | null> = {}) =>
        router.post(route(routeName, run?.id), data, {
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => {
                setErrors({});
                setNote('');
            },
        });

    const row = 'flex flex-wrap items-center justify-between gap-3.5';
    const actions = 'flex flex-wrap items-center gap-2';
    const title = 'text-sm font-semibold';
    const hint = 'text-muted-foreground mt-0.5 text-xs';

    if (running) {
        return <ProgressPanel label={label} progress={progress} stale={stale} onRetry={calculate} />;
    }

    const voided = run !== null && (run.status === 'rejected' || run.status === 'cancelled');

    if (run === null || run.status === 'draft' || voided) {
        const failed = run?.queue_state === 'failed';
        const blocked = run?.queue_state === 'blocked';
        return (
            <div className={row}>
                <div>
                    <div className={title}>
                        {voided
                            ? `Version ${run.version} was ${run.status_label.toLowerCase()}`
                            : failed
                              ? 'The last calculation failed'
                              : blocked
                                ? 'Fix the issues below, then calculate again'
                                : 'Not calculated yet'}
                    </div>
                    <p className={cn(hint, failed && 'text-destructive')}>
                        {voided
                            ? `${run.closed_reason ? `${run.closed_reason} ` : ''}Calculating again opens a new version; this one stays on record.`
                            : failed
                              ? (run.failure_message ?? 'No reason was recorded.')
                              : blocked
                                ? 'Nothing was calculated. Every problem is listed together so it can be cleared in one pass.'
                                : 'Calculate after the cycle ends. It takes a few seconds and this page updates by itself.'}
                    </p>
                </div>
                <div className={actions}>
                    {run?.status === 'draft' && (
                        <ReasonDialog
                            label="Cancel"
                            title="Cancel this payroll"
                            description="The run is closed with your reason and can no longer be calculated."
                            routeName="hrm.payroll.cancel"
                            runId={run.id}
                            destructive
                        />
                    )}
                    <BusyButton size="sm" busyLabel="Queueing…" onClick={calculate}>
                        {failed || blocked || voided ? 'Calculate again' : `Calculate ${label}`}
                    </BusyButton>
                </div>
            </div>
        );
    }

    if (run.status === 'calculated') {
        return (
            <div className={row}>
                <div>
                    <div className={title}>Next: check the payslips, then send for approval</div>
                    <p className={hint}>
                        {run.blocked_count > 0
                            ? `${run.blocked_count} payslip${run.blocked_count === 1 ? ' is' : 's are'} blocked. Resolve the blocked lines first; nothing is final yet.`
                            : 'Something wrong? Fix the input and recalculate; nothing is final yet.'}
                    </p>
                </div>
                <div className={actions}>
                    <ReasonDialog
                        label="Cancel"
                        title="Cancel this payroll"
                        description="The run is closed with your reason and can no longer be calculated."
                        routeName="hrm.payroll.cancel"
                        runId={run.id}
                        destructive
                    />
                    <BusyButton size="sm" variant="outline" busyLabel="Queueing…" onClick={calculate}>
                        Recalculate
                    </BusyButton>
                    <BusyButton size="sm" disabled={run.blocked_count > 0} onClick={() => post('hrm.payroll.review')}>
                        Send for approval
                    </BusyButton>
                </div>
            </div>
        );
    }

    if (run.status === 'reviewed') {
        const stage = run.approval_stage ? ` (${run.approval_stage})` : '';
        return (
            <div className="flex flex-wrap items-end justify-between gap-3.5">
                <div className="min-w-0 flex-1 basis-80">
                    <div className={title}>Waiting for approval{stage}</div>
                    {run.can_approve ? (
                        <>
                            <label htmlFor="approval_note" className="text-muted-foreground mt-2 block text-xs">
                                Note. Required if your own salary is in this payroll; kept in the approval history.
                            </label>
                            <textarea
                                id="approval_note"
                                rows={2}
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                className="border-input bg-background mt-1.5 w-full rounded-md border px-3 py-2 text-sm"
                                placeholder="Matches the attendance and inputs for the cycle."
                            />
                            {(errors.self_approval_reason || errors.note) && (
                                <p className="text-destructive mt-1 text-xs">{errors.self_approval_reason ?? errors.note}</p>
                            )}
                        </>
                    ) : (
                        <p className={hint}>Somebody else decides this stage. It stays here until they do.</p>
                    )}
                </div>
                <div className={actions}>
                    {run.is_reopenable && (
                        <ReasonDialog
                            label="Reopen"
                            title="Reopen this payroll"
                            description="A new version is created and this one is superseded. Its figures are kept exactly as they are."
                            routeName="hrm.payroll.reopen"
                            runId={run.id}
                        />
                    )}
                    {run.can_approve && (
                        <>
                            <BusyButton size="sm" variant="outline" onClick={() => post('hrm.payroll.reject', { note: note.trim() || null })}>
                                Reject
                            </BusyButton>
                            {/* ⚠⚠ The single most expensive double press in this system:
                                approving authorises every salary in the month. */}
                            <BusyButton size="sm" busyLabel="Approving…" onClick={() => post('hrm.payroll.approve', { note: note.trim() || null })}>
                                Approve
                            </BusyButton>
                        </>
                    )}
                </div>
            </div>
        );
    }

    if (run.status === 'approved') {
        return (
            <div className={row}>
                <div>
                    <div className={title}>Next: pay it</div>
                    <p className={hint}>
                        Mark disbursed releases the salaries for payment and posts the loan recoveries. Then, on the payment page, choose the account
                        and the date the money actually left.
                    </p>
                </div>
                <div className={actions}>
                    {run.is_reopenable && (
                        <ReasonDialog
                            label="Reopen"
                            title="Reopen this payroll"
                            description="A new version is created and this one is superseded. Its figures are kept exactly as they are."
                            routeName="hrm.payroll.reopen"
                            runId={run.id}
                        />
                    )}
                    <a href={route('hrm.payroll.disbursement', run.id)}>
                        <Button size="sm" variant="outline">
                            Go to payment
                        </Button>
                    </a>
                    <BusyButton size="sm" busyLabel="Recording…" onClick={() => post('hrm.payroll.disburse')}>
                        Mark disbursed
                    </BusyButton>
                </div>
            </div>
        );
    }

    if (run.status === 'disbursed') {
        return (
            <>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 flex-1 basis-96">
                        <div className={title}>Released for payment. Last step: lock {monthName}</div>
                        <p className="mt-1.5 text-[13px] leading-relaxed">
                            Locking closes this month for good: nobody can reopen or recalculate it. A later change needs an{' '}
                            <strong className="font-semibold">unlock request</strong> approved by HR Head, then Finance, then Management, and it
                            creates a new version. Lock once the bank statement and payslips agree.
                        </p>
                    </div>
                    <div className={actions}>
                        <a href={route('hrm.payroll.disbursement', run.id)}>
                            <Button size="sm" variant="outline">
                                Payments
                            </Button>
                        </a>
                        {!confirmingLock && (
                            <Button size="sm" onClick={() => setConfirmingLock(true)}>
                                Lock {monthName}
                            </Button>
                        )}
                    </div>
                </div>
                {confirmingLock && (
                    <div className="mt-3.5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-500/50 bg-amber-50 px-3.5 py-3 dark:bg-amber-950/20">
                        <span className="text-[13px] text-amber-800 dark:text-amber-300">
                            Lock {label}? This cannot be undone without three approvals.
                        </span>
                        <span className={actions}>
                            <Button size="sm" variant="outline" onClick={() => setConfirmingLock(false)}>
                                Not now
                            </Button>
                            <BusyButton size="sm" busyLabel="Locking…" onClick={() => post('hrm.payroll.lock')}>
                                Yes, lock it
                            </BusyButton>
                        </span>
                    </div>
                )}
            </>
        );
    }

    // Locked.
    return (
        <div className={row}>
            <div>
                <div className={cn(title, 'flex items-center gap-2')}>
                    <Lock className="size-3.5" aria-hidden="true" />
                    Locked{run.locked_at ? ` on ${showDate(run.locked_at)}` : ''}
                    {run.locked_by ? ` by ${run.locked_by}` : ''}
                </div>
                <p className={hint}>This month is the record. To change it, request an unlock: HR Head, Finance and Management approve in turn.</p>
            </div>
            <div className={actions}>
                <a href={route('hrm.payroll.disbursement', run.id)}>
                    <Button size="sm" variant="outline">
                        Payments
                    </Button>
                </a>
                <ReasonDialog
                    label="Request unlock"
                    title="Request to reopen a locked payroll"
                    description="This needs HR Head, Finance and Management approval in turn. The locked figures are never altered - an approved unlock opens a new version."
                    routeName="hrm.payroll.unlock.store"
                    runId={run.id}
                    destructive
                />
            </div>
        </div>
    );
}

/**
 * ⚠⚠ A run approved before accounting was switched on never posted its salary
 * cost (September 2026, run 10). Paying it then turned Wages Payable into a
 * debit and left the salary expense at zero. Shown only while that is true.
 */
function AccrualMissing({ run }: { run: Run }) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="border-destructive/40 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3">
            <div>
                <h3 className="text-destructive text-sm font-medium">{t('payroll_pay.accrual_missing_title')}</h3>
                <p className="text-muted-foreground mt-0.5 text-xs">{t('payroll_pay.accrual_missing_body')}</p>
                {error && <p className="text-destructive mt-1 text-xs">{error}</p>}
            </div>
            <Button
                size="sm"
                variant="outline"
                disabled={busy}
                onClick={() => {
                    setBusy(true);
                    router.post(
                        route('hrm.payroll.post-accrual', run.id),
                        {},
                        {
                            preserveScroll: true,
                            onError: (e: Record<string, string>) => setError(e.accrual ?? Object.values(e)[0] ?? null),
                            onSuccess: () => setError(null),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                {t('payroll_pay.accrual_post')}
            </Button>
        </div>
    );
}

/**
 * ⭐ 27 Oct 2026 (Joy): earned leave is decided when payroll is calculated and
 * again on each recalculation until the run is approved. One line of totals,
 * then only the people HR would ask about: withheld, or changed since the
 * last calculation.
 */
function EarnedLeavePanel({ outcome }: { outcome: EarnedLeaveOutcome }) {
    const notable = (outcome.rows ?? []).filter((row) => row.withheld_reason !== null || row.changed);

    let summary: string;
    if (outcome.failed) {
        summary = outcome.failed;
    } else if (outcome.frozen) {
        summary = 'Fixed: this month was approved, so its earned leave decisions stand as they were.';
    } else if (outcome.reason) {
        summary = outcome.reason;
    } else {
        summary = `${outcome.granted ?? 0} granted (${outcome.days ?? 0} day${outcome.days === 1 ? '' : 's'}), ${outcome.withheld ?? 0} withheld`;
        if (outcome.changed) {
            summary += `, ${outcome.changed} changed since the last calculation`;
        }
    }

    return (
        <div className={cn(panelClass, 'px-4 py-3')}>
            <h3 className="text-sm font-medium">Earned leave for this cycle</h3>
            <p className={cn('mt-0.5 text-xs', outcome.failed ? 'text-destructive' : 'text-muted-foreground')}>{summary}</p>
            {notable.length > 0 && (
                <ul className="text-muted-foreground mt-1.5 space-y-0.5 text-xs">
                    {notable.map((row) => (
                        <li key={row.employee_code}>
                            <span className="text-foreground">{row.employee}</span> {row.employee_code}:{' '}
                            {row.withheld_reason ?? `${row.days} day granted`}
                            {row.changed && (
                                <Badge variant="outline" className="ml-2">
                                    changed
                                </Badge>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function PeopleIssues({ rows, field }: { rows: (ValidationRow | WarningRow)[]; field: 'issues' | 'warnings' }) {
    const { t } = useTranslation();

    return (
        <table className="mt-2 w-full text-xs">
            <tbody>
                {rows.map((row) => (
                    <tr key={row.employee_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0">
                        <td className="w-56 py-1.5 align-top">
                            {row.employee}
                            <span className="text-muted-foreground ml-2">{row.employee_code}</span>
                        </td>
                        <td className="py-1.5">
                            <ul className="text-muted-foreground ml-4 list-disc space-y-0.5">
                                {(field === 'issues' ? (row as ValidationRow).issues : (row as WarningRow).warnings).map((text, i) => (
                                    <li key={i}>{text}</li>
                                ))}
                            </ul>
                            {field === 'issues' && (row as ValidationRow).review_url && (
                                <Link
                                    href={(row as ValidationRow).review_url!}
                                    className="text-primary mt-1 ml-4 inline-block underline underline-offset-2"
                                >
                                    {t('early.review_days')}
                                </Link>
                            )}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function PayrollIndex({ year, month, period, run, payslips, periods, first_period, current_cycle }: Props) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const { t } = useTranslation();
    const label = monthLabel(year, month);
    const monthName = MONTHS[month - 1];

    const go = (ym: YearMonth) => router.get(route('hrm.payroll.index'), { year: ym.year, month: ym.month }, { preserveState: true, replace: true });

    const calculate = () => router.post(route('hrm.payroll.calculate'), { year, month }, { preserveScroll: true });

    const running = run?.queue_state === 'queued';
    const { progress, stale } = useCalculationProgress(run);

    const voided = run !== null && (run.status === 'rejected' || run.status === 'cancelled');
    const at = run === null || voided ? -1 : STEPS.findIndex((s) => s.status === run.status);
    const hasSlips = run !== null && !voided && run.status !== 'draft' && payslips.length > 0;
    const paid = payslips.reduce((sum, p) => sum + p.paid, 0);
    const issues = run?.validation_report?.issue_count ?? 0;
    const warnings = run?.validation_report?.warning_count ?? 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <MonthSwitcher value={{ year, month }} periods={periods} first={first_period} current={current_cycle} onPick={go} />
                    <div className="text-muted-foreground text-xs">
                        {period ? `${shortDate(period.start_date, false)} to ${shortDate(period.end_date, true)}` : 'No payroll period yet'}
                        {run && !voided && run.status !== 'draft' && ` · ${run.employee_count} staff · version ${run.version}`}
                        {run && run.calculated_by && ` · calculated by ${run.calculated_by}`}
                    </div>
                </div>

                <section className={cn(panelClass, 'px-5 py-4')}>
                    <Stepper at={at} running={running} />
                    <div className="border-sidebar-border/70 dark:border-sidebar-border mt-4 border-t pt-4">
                        <NextAction
                            run={run}
                            label={label}
                            monthName={monthName}
                            running={running}
                            progress={progress}
                            stale={stale}
                            calculate={calculate}
                        />
                    </div>
                </section>

                {run?.accrual_missing && <AccrualMissing run={run} />}

                {run && issues > 0 && (
                    <div className={cn(panelClass, 'px-4 py-3')}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h3 className="text-destructive text-sm font-medium">
                                {issues} issue{issues === 1 ? '' : 's'} must be fixed before payroll can run
                            </h3>
                            {run.validation_report?.review_url && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={run.validation_report.review_url}>{t('early.review_all_days')}</Link>
                                </Button>
                            )}
                        </div>
                        {payslips.length > 0 && (
                            <p className="text-muted-foreground mt-0.5 text-xs">
                                The payslips below are from the last successful calculation and do not reflect these issues.
                            </p>
                        )}
                        <PeopleIssues rows={run.validation_report?.employees ?? []} field="issues" />
                    </div>
                )}

                {/*
                 * Warnings never block a calculation, so they are the one panel
                 * that shows on a SUCCESSFUL run - and they sit directly under
                 * the approval action on purpose. A caveat placed after the
                 * action it should inform is decoration.
                 */}
                {run && warnings > 0 && (
                    <div className="rounded-xl border border-amber-500/50 bg-amber-50 px-4 py-3 dark:bg-amber-950/20">
                        <h3 className="text-sm font-medium text-amber-700 dark:text-amber-400">
                            {warnings} warning{warnings === 1 ? '' : 's'}, payroll still calculated
                        </h3>
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            These do not stop payroll and may be expected: go-live, a partial period, a mid-month joiner or a historical import.
                            Verify them before approving.
                        </p>
                        <PeopleIssues rows={run.validation_report?.warnings ?? []} field="warnings" />
                    </div>
                )}

                {run && run.status !== 'draft' && run.validation_report?.earned_leave && (
                    <EarnedLeavePanel outcome={run.validation_report.earned_leave} />
                )}

                {run && run.unlock_requests.length > 0 && (
                    <div className={cn(panelClass, 'px-4 py-3')}>
                        <h3 className="mb-1 text-sm font-medium">Unlock requests</h3>
                        {run.unlock_requests.map((u) => (
                            <div
                                key={u.id}
                                className="border-sidebar-border/40 flex flex-wrap items-center gap-2 border-b py-1.5 text-xs last:border-b-0"
                            >
                                <span className="text-muted-foreground">{u.reason}</span>
                                {u.is_emergency_override && <Badge variant="outline">Emergency override</Badge>}
                                {u.override_reason && <span className="text-destructive">{u.override_reason}</span>}
                                <Badge variant={u.is_applied ? 'default' : 'secondary'}>{u.is_applied ? 'applied' : u.status}</Badge>
                                {u.stage && !u.is_applied && <span className="text-muted-foreground">at {u.stage}</span>}
                                {u.requested_by && <span className="text-muted-foreground">· {u.requested_by}</span>}
                            </div>
                        ))}
                    </div>
                )}

                {hasSlips ? (
                    <>
                        <div className="flex flex-wrap gap-x-9 gap-y-2 px-1">
                            <div>
                                <div className="text-muted-foreground text-xs">Earnings</div>
                                <div className="text-xl font-semibold tabular-nums">{money(run.total_earnings)}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">Deductions</div>
                                <div className={cn('text-xl font-semibold tabular-nums', run.total_deductions > 0 && 'text-destructive')}>
                                    {money(run.total_deductions)}
                                </div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">Net payable</div>
                                <div className="text-xl font-semibold tabular-nums">{money(run.total_net)}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">Paid</div>
                                <div className={cn('text-xl font-semibold tabular-nums', paid > 0 && 'text-primary')}>{money(paid)}</div>
                            </div>
                        </div>

                        {/* ⚠ relative: the sr-only heading in the last column is absolutely
                            positioned, and without a positioned scroll box it escaped to the
                            page and gave a phone a sideways scroll (measured at 390px, 15 Oct). */}
                        <section className={cn(panelClass, 'relative overflow-x-auto')}>
                            <table className="w-full min-w-[48rem] text-sm">
                                <thead>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-xs font-medium">
                                        <th className="px-3 py-2.5 text-left font-medium">Employee</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Gross</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Earnings</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Deductions</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Net</th>
                                        <th className="px-3 py-2.5 text-right font-medium">Paid by</th>
                                        <th className="w-11 px-3 py-2.5">
                                            <span className="sr-only">Details</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payslips.map((p) => {
                                        const open = expanded === p.id;
                                        return (
                                            <Fragment key={p.id}>
                                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b tabular-nums">
                                                    <td className="px-3 py-2">
                                                        <span className="font-medium">{p.employee}</span>
                                                        <span className="text-muted-foreground ml-2 text-xs">{p.employee_code}</span>
                                                        {p.is_blocked && (
                                                            <Badge variant="outline" className="ml-2">
                                                                Blocked
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-3 py-2 text-right">{money(p.gross_salary)}</td>
                                                    <td className="px-3 py-2 text-right">{money(p.total_earnings)}</td>
                                                    <td
                                                        className={cn(
                                                            'px-3 py-2 text-right',
                                                            p.total_deductions > 0 ? 'text-destructive' : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {money(p.total_deductions)}
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-semibold">{money(p.net)}</td>
                                                    <td className="text-muted-foreground px-3 py-2 text-right text-xs">{p.method}</td>
                                                    <td className="px-2 py-1 text-right">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-expanded={open}
                                                            aria-label={`${open ? 'Hide' : 'Show'} payslip lines for ${p.employee ?? p.employee_code ?? ''}`}
                                                            onClick={() => setExpanded(open ? null : p.id)}
                                                        >
                                                            {open ? <ChevronUp /> : <ChevronDown />}
                                                        </Button>
                                                    </td>
                                                </tr>
                                                {open && (
                                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border bg-muted/30 border-b">
                                                        <td colSpan={7} className="px-4 py-3">
                                                            {p.is_blocked && (
                                                                <div className="text-destructive mb-2 text-xs">
                                                                    {p.block_reasons.map((r, i) => (
                                                                        <div key={i}>{r}</div>
                                                                    ))}
                                                                </div>
                                                            )}
                                                            <table className="w-full max-w-2xl text-xs">
                                                                <tbody>
                                                                    {p.lines.map((l, i) => (
                                                                        <tr key={i} className="border-sidebar-border/40 border-b last:border-b-0">
                                                                            <td className="py-1 pr-3">{l.label}</td>
                                                                            <td className="text-muted-foreground py-1 pr-3">{l.formula}</td>
                                                                            <td className="text-muted-foreground py-1 pr-3">{l.source}</td>
                                                                            <td
                                                                                className={cn(
                                                                                    'py-1 text-right tabular-nums',
                                                                                    l.type === 'deduction' && 'text-destructive',
                                                                                )}
                                                                            >
                                                                                {l.type === 'deduction' ? '-' : ''}
                                                                                {money(l.amount)}
                                                                            </td>
                                                                        </tr>
                                                                    ))}
                                                                </tbody>
                                                            </table>
                                                            {p.notes.length > 0 && (
                                                                <ul className="text-muted-foreground mt-2 ml-4 list-disc space-y-0.5 text-xs">
                                                                    {p.notes.map((n, i) => (
                                                                        <li key={i}>{n}</li>
                                                                    ))}
                                                                </ul>
                                                            )}
                                                        </td>
                                                    </tr>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </section>
                    </>
                ) : (
                    !running && (
                        <div className={cn(panelClass, 'text-muted-foreground px-4 py-9 text-center text-sm')}>No payslips for {label} yet.</div>
                    )
                )}
            </div>
        </AppLayout>
    );
}
