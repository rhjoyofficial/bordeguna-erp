import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface PlanRow {
    employee_id: number;
    employee: string;
    employee_code: string;
    days: number;
    daily_rate: number | null;
    amount: number;
    unpriced: boolean;
    explanation: string;
}

interface RunRow {
    id: number;
    valued_on: string;
    is_dry_run: boolean;
    employees_affected: number;
    total_liability: string;
    delta: string;
    runner: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HRM', href: '/hrm/payroll' },
    { title: 'Leave provision', href: '/hrm/leave-provision' },
];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

/*
 * The liability for leave the staff have earned and not taken.
 *
 * ⚠ This page previews with the SAME computation that posts, so the figures
 * below are the figures that will land in the ledger - not an estimate of them.
 */
export default function LeaveProvisionIndex({
    plan,
    valuedOn,
    currentBalance,
    runs,
}: {
    plan: { valued_on: string; rows: PlanRow[]; totals: { employees: number; liability: number; unpriced: number } };
    valuedOn: string;
    currentBalance: string;
    runs: RunRow[];
}) {
    const { data, setData, post, errors, processing } = useForm({
        valued_on: valuedOn,
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('hrm.leave-provision.store'));
    };

    const delta = plan.totals.liability - parseFloat(currentBalance);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave provision" />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Computed liability</p>
                        <p className="font-medium tabular-nums">{money(plan.totals.liability)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Already in the ledger</p>
                        <p className="font-medium tabular-nums">{money(currentBalance)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">This run would post</p>
                        <p className="font-medium tabular-nums">{money(delta)}</p>
                    </div>
                    <div className="rounded-lg border p-3">
                        <p className="text-xs text-muted-foreground">Staff with a balance</p>
                        <p className="font-medium tabular-nums">{plan.totals.employees}</p>
                    </div>
                </div>

                {plan.totals.unpriced > 0 && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        {plan.totals.unpriced} employee(s) have encashable days that could NOT be priced because no salary structure is in
                        force for them. Their days are listed below and are deliberately NOT provided for - fix the structure and run
                        again rather than accepting a figure that leaves a real liability out.
                    </p>
                )}

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-lg border p-3">
                    <div className="grid gap-2">
                        <Label>Value the liability as at</Label>
                        <Input
                            type="date"
                            value={data.valued_on}
                            onChange={(e) => {
                                setData('valued_on', e.target.value);
                                router.get(route('hrm.leave-provision.index'), { valued_on: e.target.value }, { preserveState: false });
                            }}
                        />
                        <InputError message={errors.valued_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Note (optional)</Label>
                        <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                    </div>
                    <Button disabled={processing}>Record this provision</Button>
                    <p className="text-sm text-muted-foreground">
                        Posts the difference only. Running twice on one day is harmless - the second run finds nothing to post.
                    </p>
                </form>

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
                            {plan.rows.map((row) => (
                                <tr key={row.employee_id} className="border-t">
                                    <td className="p-3">
                                        {row.employee} <span className="text-muted-foreground">({row.employee_code})</span>
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{row.days}</td>
                                    <td className="p-3 text-right tabular-nums">{row.daily_rate === null ? '-' : money(row.daily_rate)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(row.amount)}</td>
                                    <td className="p-3 text-xs text-muted-foreground">{row.explanation}</td>
                                </tr>
                            ))}
                            {plan.rows.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        Nobody currently holds an encashable leave balance, so there is no liability to provide for.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {runs.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Past runs</h2>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Valued on</th>
                                    <th className="p-3">By</th>
                                    <th className="p-3 text-right">Staff</th>
                                    <th className="p-3 text-right">Liability</th>
                                    <th className="p-3 text-right">Posted</th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((run) => (
                                    <tr key={run.id} className="border-t">
                                        <td className="p-3">
                                            <Link href={`/hrm/leave-provision/${run.id}`} className="hover:underline">
                                                {run.valued_on?.slice(0, 10)}
                                            </Link>
                                            {run.is_dry_run && <span className="ml-2 text-xs text-muted-foreground">(preview)</span>}
                                        </td>
                                        <td className="p-3">{run.runner?.name ?? '-'}</td>
                                        <td className="p-3 text-right tabular-nums">{run.employees_affected}</td>
                                        <td className="p-3 text-right tabular-nums">{money(run.total_liability)}</td>
                                        <td className="p-3 text-right tabular-nums">{money(run.delta)}</td>
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
