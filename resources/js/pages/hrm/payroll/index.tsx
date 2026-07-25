import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

interface Props {
    year: number;
    month: number;
    period: { id: number; label: string; start_date: string; end_date: string; divisor_days: number } | null;
    run: {
        id: number;
        version: number;
        status: string;
        employee_count: number;
        blocked_count: number;
        total_earnings: number;
        total_deductions: number;
        total_net: number;
        calculated_at: string | null;
        calculated_by: string | null;
        is_rerunnable: boolean;
    } | null;
    payslips: Payslip[];
    periods: { year: number; month: number; label: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payroll', href: '/hrm/payroll' }];
const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function PayrollIndex({ year, month, period, run, payslips, periods }: Props) {
    const [expanded, setExpanded] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [y, setY] = useState(String(year));
    const [m, setM] = useState(String(month));

    const go = (nextYear: number, nextMonth: number) =>
        router.get(route('hrm.payroll.index'), { year: nextYear, month: nextMonth }, { preserveState: true, replace: true });

    const calculate = () => {
        setBusy(true);
        router.post(
            route('hrm.payroll.calculate'),
            { year: Number(y), month: Number(m) },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payroll" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">Payroll</h2>
                        {period ? (
                            <p className="text-sm text-muted-foreground">
                                {period.label} covers {period.start_date} to {period.end_date}, on a fixed {period.divisor_days}-day divisor.
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">Nothing calculated for this month yet.</p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-1">
                            <Label htmlFor="year" className="text-xs text-muted-foreground">
                                Year
                            </Label>
                            <Input id="year" type="number" className="w-24" value={y} onChange={(e) => setY(e.target.value)} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="month" className="text-xs text-muted-foreground">
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
                            <div className="text-xs text-muted-foreground">Employees</div>
                            <div className="text-2xl font-medium">{run.employee_count}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-xs text-muted-foreground">Total earnings</div>
                            <div className="text-2xl font-medium">{money(run.total_earnings)}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-xs text-muted-foreground">Total deductions</div>
                            <div className="text-2xl font-medium">{money(run.total_deductions)}</div>
                        </div>
                        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                            <div className="text-xs text-muted-foreground">Net payable</div>
                            <div className="text-2xl font-medium">{money(run.total_net)}</div>
                        </div>
                    </div>
                )}

                {run && (
                    <p className="text-xs text-muted-foreground">
                        Version {run.version} · {run.status}
                        {run.calculated_at && ` · calculated ${run.calculated_at}`}
                        {run.calculated_by && ` by ${run.calculated_by}`}
                        {run.blocked_count > 0 && (
                            <span className="ml-2 text-destructive">
                                {run.blocked_count} line{run.blocked_count === 1 ? '' : 's'} blocked
                            </span>
                        )}
                    </p>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Payslips</h3>
                    {payslips.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing calculated yet. Use “Calculate” to run payroll for this month.
                        </p>
                    ) : (
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
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
                                                <span className="ml-2 text-xs text-muted-foreground">{p.employee_code}</span>
                                                {p.is_blocked && (
                                                    <Badge variant="outline" className="ml-2">
                                                        Blocked
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="py-2 text-right text-muted-foreground">{money(p.gross_salary)}</td>
                                            <td className="py-2 text-right">{money(p.total_earnings)}</td>
                                            <td className="py-2 text-right text-destructive">{money(p.total_deductions)}</td>
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
                                                        <div className="mb-3 text-xs text-destructive">
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
                                                                    <td className="py-1 text-muted-foreground">{l.formula}</td>
                                                                    <td className="py-1 text-muted-foreground">{l.source}</td>
                                                                    <td className={`py-1 text-right ${l.type === 'deduction' ? 'text-destructive' : ''}`}>
                                                                        {l.type === 'deduction' ? '−' : ''}
                                                                        {money(l.amount)}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                    {p.notes.length > 0 && (
                                                        <ul className="mt-3 ml-4 list-disc space-y-1 text-xs text-muted-foreground">
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

                <p className="text-xs text-muted-foreground">
                    Payroll reads attendance, leave and salary as they were decided — it never recalculates them. Review, approval, disbursement and
                    locking arrive in the next slice.
                </p>
            </div>
        </AppLayout>
    );
}
