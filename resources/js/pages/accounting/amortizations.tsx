import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * Accruals & prepayments (Module 2, Slice 4): each schedule spreads one
 * amount over its months; the engine posts a real journal per month, on the
 * 1st or with the button. Cancelling stops future months only - what was
 * released is posted ledger and stays.
 */
interface Schedule {
    id: number;
    kind: string;
    name: string;
    source: string;
    target: string;
    total_amount: number;
    months: number;
    start_month: string;
    released_through: string | null;
    released_count: number;
    status: string;
    note: string | null;
}

interface AccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface AmortizationsProps {
    schedules: Schedule[];
    accounts: AccountOption[];
    can_act: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accruals & prepayments', href: '/accounting/amortizations' }];

const KIND_LABELS: Record<string, string> = {
    prepaid_expense: 'Prepaid expense',
    accrued_expense: 'Accrued expense',
    deferred_income: 'Deferred income',
};

export default function Amortizations({ schedules, accounts, can_act }: AmortizationsProps) {
    const [creating, setCreating] = useState(false);
    const form = useForm({
        kind: 'prepaid_expense',
        name: '',
        source_account_id: '',
        target_account_id: '',
        total_amount: '',
        start_month: '',
        months: '12',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/accounting/amortizations', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accruals & prepayments" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Accruals & prepayments</h1>
                        <p className="text-muted-foreground text-sm">
                            One amount, spread over its months - a real journal posts each month, automatically on the 1st.
                        </p>
                    </div>
                    {can_act && (
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" onClick={() => router.post('/accounting/amortizations/release', {}, { preserveScroll: true })}>
                                Release due months now
                            </Button>
                            <Button size="sm" onClick={() => setCreating((value) => !value)}>
                                {creating ? 'Close' : 'New schedule'}
                            </Button>
                        </div>
                    )}
                </div>

                {creating && can_act && (
                    <form onSubmit={submit} className="border-border bg-card grid gap-3 rounded-lg border p-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="kind">Kind</Label>
                            <select
                                id="kind"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                value={form.data.kind}
                                onChange={(e) => form.setData('kind', e.target.value)}
                            >
                                <option value="prepaid_expense">Prepaid expense (paid first, cost spreads)</option>
                                <option value="accrued_expense">Accrued expense (cost first, bill later)</option>
                                <option value="deferred_income">Deferred income (money first, earned monthly)</option>
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Annual hosting paid in advance" />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="source">Balance-sheet account (where it waits)</Label>
                            <select
                                id="source"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                value={form.data.source_account_id}
                                onChange={(e) => form.setData('source_account_id', e.target.value)}
                            >
                                <option value="">Choose...</option>
                                {accounts
                                    .filter((account) => account.type === 'asset' || account.type === 'liability')
                                    .map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.code} · {account.name}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.source_account_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="target">P&L account (where each month lands)</Label>
                            <select
                                id="target"
                                className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                value={form.data.target_account_id}
                                onChange={(e) => form.setData('target_account_id', e.target.value)}
                            >
                                <option value="">Choose...</option>
                                {accounts
                                    .filter((account) => account.type === 'expense' || account.type === 'income')
                                    .map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.code} · {account.name}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.target_account_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="total_amount">Total amount (BDT)</Label>
                            <Input id="total_amount" inputMode="decimal" value={form.data.total_amount} onChange={(e) => form.setData('total_amount', e.target.value)} />
                            <InputError message={form.errors.total_amount} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="start_month">First month</Label>
                                <Input id="start_month" type="date" value={form.data.start_month} onChange={(e) => form.setData('start_month', e.target.value)} />
                                <InputError message={form.errors.start_month} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="months">Months</Label>
                                <Input id="months" type="number" min={1} max={120} value={form.data.months} onChange={(e) => form.setData('months', e.target.value)} />
                                <InputError message={form.errors.months} />
                            </div>
                        </div>
                        <div className="md:col-span-2">
                            <Button type="submit" disabled={form.processing}>
                                Create schedule
                            </Button>
                        </div>
                    </form>
                )}

                {schedules.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No schedules yet. The Nikola-style case - a year paid in advance - belongs here.</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-left">
                                    <th className="px-3 py-2 font-medium">Schedule</th>
                                    <th className="px-3 py-2 font-medium">From → to</th>
                                    <th className="px-3 py-2 text-right font-medium">Total</th>
                                    <th className="px-3 py-2 text-right font-medium">Progress</th>
                                    <th className="px-3 py-2 font-medium">Status</th>
                                    {can_act && <th className="px-3 py-2" />}
                                </tr>
                            </thead>
                            <tbody>
                                {schedules.map((schedule) => (
                                    <tr key={schedule.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <p className="font-medium">{schedule.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {KIND_LABELS[schedule.kind] ?? schedule.kind} · from {schedule.start_month}
                                            </p>
                                        </td>
                                        <td className="text-muted-foreground px-3 py-2 text-xs">
                                            {schedule.source}
                                            <br />→ {schedule.target}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">{schedule.total_amount.toLocaleString()}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {schedule.released_count}/{schedule.months}
                                        </td>
                                        <td className="px-3 py-2">{schedule.status}</td>
                                        {can_act && (
                                            <td className="px-3 py-2 text-right">
                                                {schedule.status === 'active' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => router.post(`/accounting/amortizations/${schedule.id}/cancel`, {}, { preserveScroll: true })}
                                                    >
                                                        Cancel future months
                                                    </Button>
                                                )}
                                            </td>
                                        )}
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
