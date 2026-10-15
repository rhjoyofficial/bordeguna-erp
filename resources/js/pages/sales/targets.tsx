import { TargetDialog } from '@/components/sales/target-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Sales targets + commission (Module 4, Slice 5). Calculation only: this
 * screen shows what commission WOULD be. Paying it stays with payroll, by a
 * human hand - one payroll engine is the right number of payroll engines.
 */
interface TargetRow {
    id: number;
    user: string | null;
    target_amount: number;
    actual: number;
    achievement_percent: number;
    commission_percent: number;
    commission: number;
}

interface TargetsProps {
    month: string;
    rows: TargetRow[];
    users: { id: number; name: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Targets', href: '/sales/targets' },
];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function Targets({ month, rows, users }: TargetsProps) {
    // ⭐ Setting a target is a popup over the table (14 Oct 2026, Joy: "add
    // edit will from the pop up, not like current above the list"). ⚠ The whole
    // page sits behind sales.prices.manage, so whoever sees it may set one.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales targets" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground self-center text-sm">Targets and commission by month.</p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="space-y-1">
                            <Label className="text-xs">Month</Label>
                            <Input
                                type="month"
                                value={month}
                                onChange={(e) => router.get('/sales/targets', { month: e.target.value }, { preserveState: false })}
                            />
                        </div>
                        <Button onClick={() => setAdding(true)}>Add target</Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Salesperson</th>
                                <th className="px-3 py-2 text-right font-medium">Target</th>
                                <th className="px-3 py-2 text-right font-medium">Invoiced</th>
                                <th className="px-3 py-2 text-right font-medium">Achievement</th>
                                <th className="px-3 py-2 text-right font-medium">Rate</th>
                                <th className="px-3 py-2 text-right font-medium">Commission (would be)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-4 text-center">
                                        No targets set for this month.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr key={row.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{row.user}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{money(row.target_amount)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{money(row.actual)}</td>
                                        <td
                                            className={
                                                'px-3 py-2 text-right font-medium tabular-nums ' +
                                                (row.achievement_percent >= 100 ? 'text-emerald-600' : '')
                                            }
                                        >
                                            {row.achievement_percent}%
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{row.commission_percent}%</td>
                                        <td className="px-3 py-2 text-right font-semibold tabular-nums">{money(row.commission)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <TargetDialog open={adding} onOpenChange={setAdding} month={month} users={users} />
            </div>
        </AppLayout>
    );
}
