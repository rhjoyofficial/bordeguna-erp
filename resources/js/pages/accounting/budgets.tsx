import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

/**
 * The budget grid (Module 2, Slice 2): accounts down, the fiscal year's
 * twelve months across (July-June). Only the accounts somebody chose to
 * budget appear; everything else stays out of the way. The save sends every
 * visible cell - an emptied cell goes as 0, never as an absent row, so a
 * partial save can never silently wipe a month.
 */
interface AccountOption {
    id: number;
    code: string;
    name: string;
    type: string;
}

interface BudgetLine {
    account_id: number;
    month: string;
    amount: string | number;
}

interface BudgetsProps {
    fiscal_year: number;
    months: string[];
    accounts: AccountOption[];
    lines: BudgetLine[];
    note: string | null;
    can_edit: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Budgets', href: '/accounting/budgets' }];

const monthLabel = (iso: string) => new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { month: 'short' });

export default function Budgets({ fiscal_year, months, accounts, lines, note, can_edit }: BudgetsProps) {
    // cells keyed "accountId|month" -> amount string
    const initial = useMemo(() => {
        const cells: Record<string, string> = {};
        lines.forEach((line) => {
            cells[line.account_id + '|' + String(line.month).slice(0, 10)] = String(Number(line.amount));
        });
        return cells;
    }, [lines]);

    const [cells, setCells] = useState<Record<string, string>>(initial);
    const budgetedAccounts = useMemo(() => {
        const ids = new Set(Object.keys(cells).map((key) => Number(key.split('|')[0])));
        return accounts.filter((account) => ids.has(account.id));
    }, [accounts, cells]);

    const [adding, setAdding] = useState('');
    const form = useForm({ fiscal_year, note: note ?? '', lines: [] as { account_id: number; month: string; amount: number }[] });

    const addAccount = (id: string) => {
        if (!id) return;
        setCells((previous) => ({ ...previous, [id + '|' + months[0]]: previous[id + '|' + months[0]] ?? '0' }));
        setAdding('');
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const payload = budgetedAccounts.flatMap((account) =>
            months.map((month) => ({
                account_id: account.id,
                month,
                amount: Number(cells[account.id + '|' + month] ?? 0) || 0,
            })),
        );
        form.transform((data) => ({ ...data, lines: payload }));
        form.post('/accounting/budgets?fiscal_year=' + fiscal_year, { preserveScroll: true });
    };

    const rowTotal = (account: AccountOption) =>
        months.reduce((sum, month) => sum + (Number(cells[account.id + '|' + month] ?? 0) || 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Budgets" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Budget {fiscal_year}-{String(fiscal_year + 1).slice(-2)}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            July to June. The Budget vs actual report (in Reports) judges the ledger against these figures.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => router.get('/accounting/budgets', { fiscal_year: fiscal_year - 1 })}>
                            ← {fiscal_year - 1}-{String(fiscal_year).slice(-2)}
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => router.get('/accounting/budgets', { fiscal_year: fiscal_year + 1 })}>
                            {fiscal_year + 1}-{String(fiscal_year + 2).slice(-2)} →
                        </Button>
                    </div>
                </div>

                {can_edit && (
                    <div className="flex items-center gap-2">
                        <select
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={adding}
                            onChange={(e) => addAccount(e.target.value)}
                        >
                            <option value="">Add an account to the budget...</option>
                            {accounts
                                .filter((account) => !budgetedAccounts.some((budgeted) => budgeted.id === account.id))
                                .map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} · {account.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                )}

                {budgetedAccounts.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Nothing budgeted for this year yet{can_edit ? ' - add an account above to begin.' : '.'}
                    </p>
                ) : (
                    <form onSubmit={submit} className="space-y-3">
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-muted/50 text-left">
                                        <th className="min-w-44 px-3 py-2 font-medium">Account</th>
                                        {months.map((month) => (
                                            <th key={month} className="min-w-20 px-2 py-2 text-right font-medium">
                                                {monthLabel(month)}
                                            </th>
                                        ))}
                                        <th className="min-w-24 px-3 py-2 text-right font-semibold">Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {budgetedAccounts.map((account) => (
                                        <tr key={account.id} className="border-t">
                                            <td className="px-3 py-1.5">
                                                <span className="font-medium">{account.code}</span>
                                                <span className="text-muted-foreground"> {account.name}</span>
                                            </td>
                                            {months.map((month) => {
                                                const key = account.id + '|' + month;
                                                return (
                                                    <td key={month} className="px-1 py-1">
                                                        <Input
                                                            className="h-8 text-right"
                                                            inputMode="decimal"
                                                            disabled={!can_edit}
                                                            value={cells[key] ?? ''}
                                                            onChange={(e) => setCells((previous) => ({ ...previous, [key]: e.target.value }))}
                                                        />
                                                    </td>
                                                );
                                            })}
                                            <td className="px-3 py-1.5 text-right font-semibold tabular-nums">
                                                {rowTotal(account).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <InputError message={form.errors.fiscal_year} />
                        {can_edit && (
                            <Button type="submit" disabled={form.processing}>
                                Save budget
                            </Button>
                        )}
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
