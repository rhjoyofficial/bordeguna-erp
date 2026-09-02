import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Targets', href: '/sales/targets' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function Targets({ month, rows, users }: TargetsProps) {
    const form = useForm({
        user_id: '',
        month,
        target_amount: '',
        commission_percent: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales targets" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Sales targets and commission</h1>
                        <p className="text-muted-foreground text-sm">
                            Target vs the invoices each person actually wrote, and what the commission would be. The number is advice for
                            payroll - nothing here pays anyone.
                        </p>
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Month</Label>
                        <Input
                            type="month"
                            value={month}
                            onChange={(e) => router.get('/sales/targets', { month: e.target.value }, { preserveState: false })}
                        />
                    </div>
                </div>

                <form
                    className="border-border bg-card flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/sales/targets', { preserveScroll: true, onSuccess: () => form.reset('target_amount', 'commission_percent') });
                    }}
                >
                    <div className="min-w-48 space-y-1">
                        <Label className="text-xs">Salesperson</Label>
                        <Select value={form.data.user_id} onValueChange={(value) => form.setData('user_id', value)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Pick" />
                            </SelectTrigger>
                            <SelectContent>
                                {users.map((user) => (
                                    <SelectItem key={user.id} value={String(user.id)}>
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.user_id && <p className="text-destructive text-xs">{form.errors.user_id}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Month</Label>
                        <Input type="month" value={form.data.month} onChange={(e) => form.setData('month', e.target.value)} />
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Target (BDT)</Label>
                        <Input
                            className="w-36"
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.target_amount}
                            onChange={(e) => form.setData('target_amount', e.target.value)}
                        />
                        {form.errors.target_amount && <p className="text-destructive text-xs">{form.errors.target_amount}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">Commission %</Label>
                        <Input
                            className="w-24"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            placeholder="0"
                            value={form.data.commission_percent}
                            onChange={(e) => form.setData('commission_percent', e.target.value)}
                        />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Set target
                    </Button>
                    <p className="text-muted-foreground w-full text-xs">Setting a target again for the same person and month replaces it.</p>
                </form>

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

                <p className="text-muted-foreground text-xs">
                    To actually pay a commission, add it in the payroll month as an allowance or bonus - this table is the calculation, the
                    payroll run is the payment.
                </p>
            </div>
        </AppLayout>
    );
}
