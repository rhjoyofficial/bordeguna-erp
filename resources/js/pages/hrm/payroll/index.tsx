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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';

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
    is_rerunnable: boolean;
    is_reopenable: boolean;
    closed_reason: string | null;
    queue_state: string | null;
    failure_message: string | null;
    validation_report: {
        issue_count: number;
        checked: number;
        employees: ValidationRow[];
        warning_count?: number;
        warnings?: WarningRow[];
    } | null;
    awaiting_approval: boolean;
    approval_stage: string | null;
    unlock_requests: UnlockRequest[];
}

interface Props {
    year: number;
    month: number;
    period: { id: number; label: string; start_date: string; end_date: string; divisor_days: number } | null;
    run: Run | null;
    payslips: Payslip[];
    periods: { year: number; month: number; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payroll', href: '/hrm/payroll' }];
const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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
                    <Label htmlFor="reason">Reason</Label>
                    <Input id="reason" value={reason} onChange={(e) => setReason(e.target.value)} />
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

/**
 * The WFL-02 lifecycle, offering only the transitions the run's current state
 * actually permits.
 */
function LifecycleActions({ run }: { run: Run }) {
    const post = (routeName: string) => router.post(route(routeName, run.id), {}, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-3">
            {run.status === 'calculated' && (
                <>
                    <BusyButton size="sm" onClick={() => post('hrm.payroll.review')} disabled={run.blocked_count > 0}>
                        Review &amp; send for approval
                    </BusyButton>
                    {run.blocked_count > 0 && <span className="text-destructive text-xs">Resolve the blocked lines first.</span>}
                </>
            )}
            {run.awaiting_approval && (
                <>
                    {/* ⚠⚠ The single most expensive double press in this system:
                        approving authorises nine salaries. */}
                    <BusyButton size="sm" busyLabel="Approving…" onClick={() => post('hrm.payroll.approve')}>
                        Approve
                    </BusyButton>
                    <BusyButton size="sm" variant="outline" onClick={() => post('hrm.payroll.reject')}>
                        Reject
                    </BusyButton>
                </>
            )}
            {run.status === 'approved' && (
                <BusyButton size="sm" busyLabel="Recording…" onClick={() => post('hrm.payroll.disburse')}>
                    Mark disbursed
                </BusyButton>
            )}
            {(run.status === 'disbursed' || run.status === 'locked' || run.status === 'approved') && (
                <a href={route('hrm.payroll.disbursement', run.id)}>
                    <Button size="sm" variant="outline">
                        Disbursement &amp; payslips
                    </Button>
                </a>
            )}
            {run.status === 'disbursed' && (
                <BusyButton size="sm" onClick={() => post('hrm.payroll.lock')}>
                    Lock
                </BusyButton>
            )}
            {run.is_reopenable && (
                <ReasonDialog
                    label="Reopen"
                    title="Reopen this payroll"
                    description="A new version is created and this one is superseded. Its figures are kept exactly as they are."
                    routeName="hrm.payroll.reopen"
                    runId={run.id}
                />
            )}
            {run.status === 'locked' && (
                <ReasonDialog
                    label="Request unlock"
                    title="Request to reopen a locked payroll"
                    description="This needs HR Head, Finance and Management approval in turn. The locked figures are never altered - an approved unlock opens a new version."
                    routeName="hrm.payroll.unlock.store"
                    runId={run.id}
                    destructive
                />
            )}
            {(run.status === 'draft' || run.status === 'calculated') && (
                <ReasonDialog
                    label="Cancel"
                    title="Cancel this payroll"
                    description="The run is closed with your reason and can no longer be calculated."
                    routeName="hrm.payroll.cancel"
                    runId={run.id}
                    destructive
                />
            )}
        </div>
    );
}

export default function PayrollIndex({ year, month, period, run, payslips, periods }: Props) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [y, setY] = useState(String(year));
    const [m, setM] = useState(String(month));

    const go = (nextYear: number, nextMonth: number) =>
        router.get(route('hrm.payroll.index'), { year: nextYear, month: nextMonth }, { preserveState: true, replace: true });

    const calculate = () => {
        setBusy(true);
        router.post(route('hrm.payroll.calculate'), { year: Number(y), month: Number(m) }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Payroll</h2>
                        {period ? (
                            <p className="text-muted-foreground text-sm">
                                {period.label} covers {period.start_date} to {period.end_date}, on a fixed {period.divisor_days}-day divisor.
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-sm">Nothing calculated for this month yet.</p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="year" className="text-muted-foreground text-xs">
                                Year
                            </Label>
                            <Input id="year" type="number" className="w-24" value={y} onChange={(e) => setY(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="month" className="text-muted-foreground text-xs">
                                Month
                            </Label>
                            <Input id="month" type="number" min={1} max={12} className="w-20" value={m} onChange={(e) => setM(e.target.value)} />
                        </div>
                        <Button variant="outline" size="sm" onClick={() => go(Number(y), Number(m))}>
                            View
                        </Button>
                        <Button size="sm" disabled={busy} onClick={calculate}>
                            {busy ? 'Calculating…' : run ? 'Recalculate' : 'Calculate'}
                        </Button>
                    </div>
                </div>

                {periods.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {periods.map((p) => (
                            <Button
                                key={`${p.year}-${p.month}`}
                                variant={p.year === year && p.month === month ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => go(p.year, p.month)}
                            >
                                {p.label}
                            </Button>
                        ))}
                    </div>
                )}

                {run && (
                    <div className="grid gap-4 sm:grid-cols-4">
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-muted-foreground text-xs">Employees</div>
                            <div className="text-2xl font-medium">{run.employee_count}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-muted-foreground text-xs">Total earnings</div>
                            <div className="text-2xl font-medium">{money(run.total_earnings)}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-muted-foreground text-xs">Total deductions</div>
                            <div className="text-2xl font-medium">{money(run.total_deductions)}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-muted-foreground text-xs">Net payable</div>
                            <div className="text-2xl font-medium">{money(run.total_net)}</div>
                        </div>
                    </div>
                )}

                {run && (
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge variant={run.status === 'locked' || run.status === 'approved' ? 'default' : 'secondary'}>{run.status_label}</Badge>
                        <span className="text-muted-foreground text-xs">
                            Version {run.version}
                            {run.calculated_at && ` · calculated ${run.calculated_at}`}
                            {run.calculated_by && ` by ${run.calculated_by}`}
                            {run.approved_at && ` · approved ${run.approved_at} by ${run.approved_by}`}
                        </span>
                        {run.blocked_count > 0 && (
                            <span className="text-destructive text-xs">
                                {run.blocked_count} line{run.blocked_count === 1 ? '' : 's'} blocked
                            </span>
                        )}
                        {run.awaiting_approval && (
                            <Badge variant="outline">Awaiting approval{run.approval_stage ? ` - ${run.approval_stage}` : ''}</Badge>
                        )}
                    </div>
                )}

                {run?.closed_reason && <p className="text-muted-foreground text-xs">{run.closed_reason}</p>}
                {run?.failure_message && <p className="text-destructive text-sm">Calculation failed: {run.failure_message}</p>}

                {run && run.validation_report && run.validation_report.issue_count > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <h3 className="text-destructive text-sm font-medium">
                            {run.validation_report.issue_count} issue{run.validation_report.issue_count === 1 ? '' : 's'} must be fixed before payroll
                            can run
                        </h3>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Nothing was calculated. All problems are listed together so they can be cleared in one pass, then recalculated.
                            {payslips.length > 0 && ' The payslips below are from the last successful calculation and do not reflect these issues.'}
                        </p>
                        <table className="mt-3 w-full min-w-[36rem] text-sm">
                            <tbody>
                                {run.validation_report.employees.map((row) => (
                                    <tr key={row.employee_id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 align-top">
                                            {row.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{row.employee_code}</span>
                                        </td>
                                        <td className="py-2">
                                            <ul className="text-muted-foreground ml-4 list-disc space-y-1 text-xs">
                                                {row.issues.map((issue, i) => (
                                                    <li key={i}>{issue}</li>
                                                ))}
                                            </ul>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/*
                 * Warnings never block a calculation, so they are the one panel
                 * that shows on a SUCCESSFUL run - and they sit immediately
                 * above the review and approval buttons on purpose. A caveat
                 * placed after the action it should inform is decoration.
                 */}
                {run && run.validation_report && (run.validation_report.warning_count ?? 0) > 0 && (
                    <div className="rounded-xl border border-amber-500/50 bg-amber-50 p-4 dark:bg-amber-950/20">
                        <h3 className="text-sm font-medium text-amber-700 dark:text-amber-400">
                            {run.validation_report.warning_count} warning{run.validation_report.warning_count === 1 ? '' : 's'} - payroll still
                            calculated
                        </h3>
                        <p className="text-muted-foreground mt-1 text-xs">
                            These do not stop payroll and may be entirely expected, for instance during go-live, a partial period, a mid-month joiner
                            or a historical import. Please verify them before approving.
                        </p>
                        <table className="mt-3 w-full min-w-[36rem] text-sm">
                            <tbody>
                                {(run.validation_report.warnings ?? []).map((row) => (
                                    <tr
                                        key={row.employee_id}
                                        className="border-sidebar-border/70 dark:border-sidebar-border border-b last:border-b-0"
                                    >
                                        <td className="py-2 align-top">
                                            {row.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{row.employee_code}</span>
                                        </td>
                                        <td className="py-2">
                                            <ul className="text-muted-foreground ml-4 list-disc space-y-1 text-xs">
                                                {row.warnings.map((warning, i) => (
                                                    <li key={i}>{warning}</li>
                                                ))}
                                            </ul>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {run && <LifecycleActions run={run} />}

                {run && run.unlock_requests.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <h3 className="mb-2 text-sm font-medium">Unlock requests</h3>
                        {run.unlock_requests.map((u) => (
                            <div key={u.id} className="border-sidebar-border/40 border-b py-2 text-xs last:border-b-0">
                                <span className="text-muted-foreground">{u.reason}</span>
                                {u.is_emergency_override && (
                                    <Badge variant="outline" className="ml-2">
                                        Emergency override
                                    </Badge>
                                )}
                                {u.override_reason && <span className="text-destructive ml-2">{u.override_reason}</span>}
                                <Badge variant={u.is_applied ? 'default' : 'secondary'} className="ml-2">
                                    {u.is_applied ? 'applied' : u.status}
                                </Badge>
                                {u.stage && !u.is_applied && <span className="text-muted-foreground ml-2">at {u.stage}</span>}
                                {u.requested_by && <span className="text-muted-foreground ml-2">· {u.requested_by}</span>}
                            </div>
                        ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Payslips</h3>
                    {payslips.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing calculated yet. Use “Calculate” to run payroll for this month.</p>
                    ) : (
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2 text-right">Gross</th>
                                    <th className="py-2 text-right">Earnings</th>
                                    <th className="py-2 text-right">Deductions</th>
                                    <th className="py-2 text-right">Net</th>
                                    <th className="py-2 text-right">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {payslips.map((p) => (
                                    <Fragment key={p.id}>
                                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                            <td className="py-2">
                                                {p.employee}
                                                <span className="text-muted-foreground ml-2 text-xs">{p.employee_code}</span>
                                                {p.is_blocked && (
                                                    <Badge variant="outline" className="ml-2">
                                                        Blocked
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground py-2 text-right">{money(p.gross_salary)}</td>
                                            <td className="py-2 text-right">{money(p.total_earnings)}</td>
                                            <td className="text-destructive py-2 text-right">{money(p.total_deductions)}</td>
                                            <td className="py-2 text-right font-medium">{money(p.net)}</td>
                                            <td className="py-2 text-right">
                                                <Button variant="ghost" size="sm" onClick={() => setExpanded(expanded === p.id ? null : p.id)}>
                                                    {expanded === p.id ? 'Hide' : 'Explain'}
                                                </Button>
                                            </td>
                                        </tr>
                                        {expanded === p.id && (
                                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td colSpan={6} className="py-3">
                                                    {p.is_blocked && (
                                                        <div className="text-destructive mb-3 text-xs">
                                                            {p.block_reasons.map((r, i) => (
                                                                <div key={i}>{r}</div>
                                                            ))}
                                                        </div>
                                                    )}
                                                    <table className="w-full text-xs">
                                                        <tbody>
                                                            {p.lines.map((l, i) => (
                                                                <tr key={i} className="border-sidebar-border/40 border-b">
                                                                    <td className="py-1">{l.label}</td>
                                                                    <td className="text-muted-foreground py-1">{l.formula}</td>
                                                                    <td className="text-muted-foreground py-1">{l.source}</td>
                                                                    <td
                                                                        className={`py-1 text-right ${l.type === 'deduction' ? 'text-destructive' : ''}`}
                                                                    >
                                                                        {l.type === 'deduction' ? '−' : ''}
                                                                        {money(l.amount)}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                    {p.notes.length > 0 && (
                                                        <ul className="text-muted-foreground mt-3 ml-4 list-disc space-y-1 text-xs">
                                                            {p.notes.map((n, i) => (
                                                                <li key={i}>{n}</li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <p className="text-muted-foreground text-xs">
                    Payroll reads attendance, leave and salary as they were decided - it never recalculates them. Review, approval, disbursement and
                    locking arrive in the next slice.
                </p>
            </div>
        </AppLayout>
    );
}
