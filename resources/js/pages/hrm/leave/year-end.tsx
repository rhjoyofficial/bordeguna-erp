import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface PlanRow {
    employee_id: number;
    employee: string;
    employee_code: string;
    leave_type_id: number;
    leave_type: string;
    closing_balance: number;
    cap: number;
    carry_forward: number;
    lapse: number;
    explanation: string;
}

interface Run {
    id: number;
    is_dry_run: boolean;
    employees_affected: number;
    total_carried: number;
    total_lapsed: number;
    run_by: string | null;
    run_at: string | null;
}

interface Props {
    year: number;
    plan: PlanRow[];
    totals: { employees: number; carried: number; lapsed: number };
    runs: Run[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Leave', href: '/hrm/leave' },
    { title: 'Year end', href: '/hrm/leave/year-end' },
];

export default function YearEnd({ year, plan, totals, runs }: Props) {
    const [busy, setBusy] = useState(false);
    // A year that has not ended is refused on commit (bug hunt 16 Oct 2026).
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const run = (commit: boolean) => {
        setBusy(true);
        router.post(route('hrm.leave.year-end.run'), { year, commit }, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const lapsing = plan.filter((row) => row.lapse > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Leave year end ${year}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Leave year end - {year}</h2>
                        <p className="text-muted-foreground text-sm">
                            This is a projection. Nothing changes until you commit, and committing posts exactly what is shown here.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Label htmlFor="year" className="text-muted-foreground text-sm" required>
                            Closing year
                        </Label>
                        <Input
                            id="year"
                            type="number"
                            className="w-28"
                            defaultValue={year}
                            onBlur={(e) =>
                                router.get(route('hrm.leave.year-end'), { year: Number(e.target.value) }, { preserveState: true, replace: true })
                            }
                        />
                        <Button variant="outline" size="sm" disabled={busy} onClick={() => run(false)}>
                            Save preview
                        </Button>
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" disabled={busy || plan.length === 0}>
                                    Commit
                                </Button>
                            }
                            title={`Commit the ${year} year end?`}
                            description={
                                totals.lapsed > 0
                                    ? `${totals.carried} day(s) will carry forward and ${totals.lapsed} day(s) will lapse across ${totals.employees} employee(s). Lapsed days cannot be recovered.`
                                    : `${totals.carried} day(s) will carry forward across ${totals.employees} employee(s). Nothing will lapse.`
                            }
                            confirmLabel="Commit"
                            destructive={totals.lapsed > 0}
                            onConfirm={() => run(true)}
                        />
                    </div>
                </div>
                {errors.year && <p className="text-destructive text-sm">{errors.year}</p>}

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <div className="text-muted-foreground text-xs">Employees affected</div>
                        <div className="text-2xl font-medium">{totals.employees}</div>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <div className="text-muted-foreground text-xs">Days carrying forward</div>
                        <div className="text-2xl font-medium">{totals.carried}</div>
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                        <div className="text-muted-foreground text-xs">Days lapsing</div>
                        <div className={`text-2xl font-medium ${totals.lapsed > 0 ? 'text-destructive' : ''}`}>{totals.lapsed}</div>
                    </div>
                </div>

                {lapsing.length > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {lapsing.length} employee-balance{lapsing.length === 1 ? '' : 's'} would lose days. Tell them before you commit - they may
                        still have time to take the leave.
                    </p>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Projection</h3>
                    {plan.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Nothing to carry forward for {year}. Only leave types that may carry forward are included.
                        </p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Leave type</th>
                                    <th className="py-2">Closing</th>
                                    <th className="py-2">Cap</th>
                                    <th className="py-2">Carry forward</th>
                                    <th className="py-2">Lapse</th>
                                    <th className="py-2">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                {plan.map((row) => (
                                    <tr
                                        key={`${row.employee_id}-${row.leave_type_id}`}
                                        className="border-sidebar-border/70 dark:border-sidebar-border border-b"
                                    >
                                        <td className="py-2">
                                            {row.employee}
                                            <span className="text-muted-foreground ml-2 text-xs">{row.employee_code}</span>
                                        </td>
                                        <td className="text-muted-foreground py-2">{row.leave_type}</td>
                                        <td className="py-2">{row.closing_balance}</td>
                                        <td className="text-muted-foreground py-2">{row.cap}</td>
                                        <td className="py-2">{row.carry_forward}</td>
                                        <td className="py-2">
                                            {row.lapse > 0 ? (
                                                <Badge variant="outline">{row.lapse}</Badge>
                                            ) : (
                                                <span className="text-muted-foreground">0</span>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">{row.explanation}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {runs.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">Run history</h3>
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            <Badge variant={r.is_dry_run ? 'secondary' : 'default'}>{r.is_dry_run ? 'Preview' : 'Committed'}</Badge>
                                        </td>
                                        <td className="text-muted-foreground py-2">
                                            {r.employees_affected} employee(s) · {r.total_carried} carried · {r.total_lapsed} lapsed
                                        </td>
                                        <td className="text-muted-foreground py-2 text-xs">
                                            {showDate(r.run_at)}
                                            {r.run_by && ` by ${r.run_by}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
