import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface JournalLine {
    id: number;
    amount_minor: number;
    memo: string | null;
    account: { id: number; code: string; name: string } | null;
}

interface PlanRow {
    employee_id: number;
    employee: string;
    employee_code: string;
    days: number;
    daily_rate: number | null;
    amount: number;
    explanation: string;
}

interface Run {
    id: number;
    valued_on: string;
    is_dry_run: boolean;
    employees_affected: number;
    total_liability: string;
    opening_balance: string;
    delta: string;
    note: string | null;
    plan: PlanRow[];
    runner: { id: number; name: string } | null;
}

const taka = (minor: number) => (minor / 100).toLocaleString('en-BD', { minimumFractionDigits: 2 });
const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function LeaveProvisionShow({
    run,
    journal,
}: {
    run: Run;
    journal: { id: number; journal_date: string; memo: string | null; lines: JournalLine[] } | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Leave provision', href: '/hrm/leave-provision' },
        { title: run.valued_on?.slice(0, 10) ?? '', href: `/hrm/leave-provision/${run.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Leave provision ${run.valued_on?.slice(0, 10)}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Valued as at</p>
                        <p className="font-medium">{run.valued_on?.slice(0, 10)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Liability computed</p>
                        <p className="font-medium tabular-nums">{money(run.total_liability)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Was in the ledger</p>
                        <p className="font-medium tabular-nums">{money(run.opening_balance)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-muted-foreground text-xs">Posted</p>
                        <p className="font-medium tabular-nums">{money(run.delta)}</p>
                    </div>
                </div>

                {run.note && <p className="text-muted-foreground text-sm">{run.note}</p>}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Employee</th>
                                <th className="p-3 text-right">Encashable days</th>
                                <th className="p-3 text-right">One day's pay</th>
                                <th className="p-3 text-right">Liability</th>
                                <th className="p-3">Why this figure</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(run.plan ?? []).map((row) => (
                                <tr key={row.employee_id} className="border-t">
                                    <td className="p-3">
                                        {row.employee} <span className="text-muted-foreground">({row.employee_code})</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{row.days}</td>
                                    <td className="p-3 text-right tabular-nums">{row.daily_rate === null ? '-' : money(row.daily_rate)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(row.amount)}</td>
                                    <td className="text-muted-foreground p-3 text-xs">{row.explanation}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {journal && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">What this did to the books</h2>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Account</th>
                                    <th className="p-3">Memo</th>
                                    <th className="p-3 text-right">Debit</th>
                                    <th className="p-3 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                {journal.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.account?.code} {line.account?.name}
                                        </td>
                                        <td className="text-muted-foreground p-3">{line.memo}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor > 0 ? taka(line.amount_minor) : ''}</td>
                                        <td className="p-3 text-right tabular-nums">{line.amount_minor < 0 ? taka(-line.amount_minor) : ''}</td>
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
