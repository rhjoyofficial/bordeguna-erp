import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface LeaveRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    leave_type: string | null;
    start_date: string;
    end_date: string;
    days: number;
    unpaid_days: number;
    reason: string;
    status: string;
    stage: string | null;
    is_applied: boolean;
}

interface MovementRow {
    id: number;
    employee: string | null;
    type: string;
    movement_date: string;
    summary: string;
    reason: string;
    status: string;
    is_applied: boolean;
}

interface Balance {
    id: number;
    name: string;
    balance: number;
    taken: number;
    annual_quota: number;
    deducts_balance: boolean;
    can_carry_forward: boolean;
    can_encash: boolean;
    carry_forward_cap: number | null;
}

interface Props {
    year: number;
    filters: { employee_id: number | null };
    requests: LeaveRow[];
    movements: MovementRow[];
    balances: Balance[];
    ledger: { id: number; date: string; type: string | null; entry_type: string; days: number; note: string | null }[];
    employees: { id: number; name: string }[];
    leave_types: Balance[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Leave', href: '/hrm/leave' }];
const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

export default function LeaveIndex({ year, filters, requests, movements, balances, ledger, employees, leave_types }: Props) {
    const reload = (params: Record<string, string | number | null>) =>
        router.get(route('hrm.leave.index'), { year, ...filters, ...params }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-medium">Leave &amp; movement</h2>
                    <div className="flex flex-wrap items-center gap-3">
                        <Label htmlFor="year" className="text-sm text-muted-foreground">
                            Year
                        </Label>
                        <Input id="year" type="number" className="w-28" defaultValue={year} onBlur={(e) => reload({ year: Number(e.target.value) })} />
                        <select
                            className={selectClass}
                            value={filters.employee_id ?? ''}
                            onChange={(e) => reload({ employee_id: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">All employees</option>
                            {employees.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.post(route('hrm.leave.quota'), { year }, { preserveScroll: true })}
                        >
                            Grant {year} quota
                        </Button>
                        <Button size="sm" asChild>
                            <Link href={route('hrm.leave.year-end', { year: year - 1 })}>Year end</Link>
                        </Button>
                    </div>
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Leave types</h3>
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                <th className="py-2">Type</th>
                                <th className="py-2">Annual quota</th>
                                <th className="py-2">Draws on balance</th>
                                <th className="py-2">Carries forward</th>
                                <th className="py-2">Encashable</th>
                            </tr>
                        </thead>
                        <tbody>
                            {leave_types.map((t) => (
                                <tr key={t.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">{t.name}</td>
                                    <td className="py-2 text-muted-foreground">{t.annual_quota}</td>
                                    <td className="py-2 text-muted-foreground">{t.deducts_balance ? 'Yes' : 'No'}</td>
                                    <td className="py-2 text-muted-foreground">
                                        {t.can_carry_forward ? `Up to ${t.carry_forward_cap}` : 'No'}
                                    </td>
                                    <td className="py-2 text-muted-foreground">{t.can_encash ? 'On separation' : 'No'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {filters.employee_id !== null && balances.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {balances
                            .filter((b) => b.deducts_balance)
                            .map((b) => (
                                <div key={b.id} className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-4">
                                    <div className="text-sm font-medium">{b.name}</div>
                                    <div className="mt-1 text-2xl font-medium">{b.balance}</div>
                                    <div className="text-xs text-muted-foreground">
                                        available · {b.taken} taken of {b.annual_quota}
                                    </div>
                                </div>
                            ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Leave requests</h3>
                    {requests.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No leave requests yet.</p>
                    ) : (
                        <table className="w-full min-w-[60rem] text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Employee</th>
                                    <th className="py-2">Type</th>
                                    <th className="py-2">Dates</th>
                                    <th className="py-2">Days</th>
                                    <th className="py-2">Reason</th>
                                    <th className="py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {requests.map((r) => (
                                    <tr key={r.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {r.employee}
                                            <span className="ml-2 text-xs text-muted-foreground">{r.employee_code}</span>
                                        </td>
                                        <td className="py-2 text-muted-foreground">{r.leave_type}</td>
                                        <td className="py-2 whitespace-nowrap">
                                            {r.start_date}
                                            {r.end_date !== r.start_date && ` → ${r.end_date}`}
                                        </td>
                                        <td className="py-2">
                                            {r.days}
                                            {r.unpaid_days > 0 && <span className="ml-1 text-xs text-destructive">({r.unpaid_days} unpaid)</span>}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">{r.reason}</td>
                                        <td className="py-2">
                                            <Badge variant={r.is_applied ? 'default' : 'secondary'}>{r.is_applied ? 'Approved' : r.status}</Badge>
                                            {r.stage && !r.is_applied && <span className="ml-2 text-xs text-muted-foreground">at {r.stage}</span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                    <h3 className="mb-3 text-sm font-medium">Movements</h3>
                    {movements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No movements yet.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <tbody>
                                {movements.map((m) => (
                                    <tr key={m.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 text-muted-foreground">{m.summary}</td>
                                        <td className="py-2 text-xs text-muted-foreground">{m.reason}</td>
                                        <td className="py-2">
                                            <Badge variant={m.is_applied ? 'default' : 'secondary'}>{m.is_applied ? 'Approved' : m.status}</Badge>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {filters.employee_id !== null && ledger.length > 0 && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border p-4">
                        <h3 className="mb-3 text-sm font-medium">Balance ledger</h3>
                        <table className="w-full min-w-[44rem] text-sm">
                            <tbody>
                                {ledger.map((entry) => (
                                    <tr key={entry.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2 whitespace-nowrap">{entry.date}</td>
                                        <td className="py-2">{entry.type}</td>
                                        <td className="py-2 text-muted-foreground">{entry.entry_type}</td>
                                        <td className={`py-2 ${entry.days < 0 ? 'text-destructive' : ''}`}>
                                            {entry.days > 0 ? `+${entry.days}` : entry.days}
                                        </td>
                                        <td className="py-2 text-xs text-muted-foreground">{entry.note}</td>
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
