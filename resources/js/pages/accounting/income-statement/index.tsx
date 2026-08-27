import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Line {
    code: string;
    name: string;
    amount: string;
    is_contra: boolean;
}

interface Section {
    code: string;
    name: string;
    total: string;
    lines: Line[];
}

interface Totals {
    income: string;
    cost_of_sales: string;
    gross_profit: string;
    operating_expenses: string;
    net_profit: string;
    is_loss: boolean;
    net_profit_absolute: string;
    gross_margin_percent: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Profit & loss', href: '/accounting/income-statement' },
];

function SectionBlock({ section }: { section: Section }) {
    return (
        <>
            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                <td className="text-muted-foreground py-2 font-mono text-xs">{section.code}</td>
                <td className="py-2 font-medium">{section.name}</td>
                <td className="py-2 text-right font-medium tabular-nums">{section.total}</td>
            </tr>
            {section.lines.map((line) => (
                <tr key={line.code} className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b">
                    <td className="text-muted-foreground py-1.5 font-mono text-xs">{line.code}</td>
                    <td className="text-muted-foreground py-1.5 pl-6">
                        {line.name}
                        {/* A line sitting on the opposite side from its type is worth
                            seeing: a return, a discount, a refund. Saying so beats
                            leaving the reader to wonder why a total shrank. */}
                        {line.is_contra && <span className="ml-2 text-xs">(deduction)</span>}
                    </td>
                    <td className="text-muted-foreground py-1.5 text-right tabular-nums">{line.is_contra ? `(${line.amount})` : line.amount}</td>
                </tr>
            ))}
        </>
    );
}

export default function IncomeStatementIndex({
    from,
    to,
    fiscal_year,
    income,
    cost_of_sales,
    operating_expenses,
    totals,
    empty,
    ledger_empty,
}: {
    from: string;
    to: string;
    fiscal_year: string | null;
    income: Section[];
    cost_of_sales: Section[];
    operating_expenses: Section[];
    totals: Totals;
    empty: boolean;
    ledger_empty: boolean;
}) {
    const [range, setRange] = useState({ from, to });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.income-statement'), range, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profit & loss" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Profit &amp; loss</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        What was earned and what it cost, for the period shown. Figures are the movement in this period only, not the balance carried
                        since the ledger began.
                        {fiscal_year && <> Fiscal year {fiscal_year}.</>}
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={range.from}
                            onChange={(e) => setRange({ ...range, from: e.target.value })}
                            className="w-44"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to">To</Label>
                        <Input id="to" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} className="w-44" />
                    </div>
                    <Button variant="outline">Show</Button>
                </form>

                {!empty && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap gap-6 rounded-md border px-4 py-3">
                        <div>
                            <div className="text-muted-foreground text-xs">Gross profit</div>
                            <div className="text-lg font-semibold tabular-nums">{totals.gross_profit}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">Gross margin</div>
                            <div className="text-lg font-semibold tabular-nums">
                                {totals.gross_margin_percent === null ? '--' : `${totals.gross_margin_percent}%`}
                            </div>
                        </div>
                        <div>
                            <div className="text-muted-foreground text-xs">{totals.is_loss ? 'Net loss' : 'Net profit'}</div>
                            <div className={`text-lg font-semibold tabular-nums ${totals.is_loss ? 'text-destructive' : ''}`}>
                                {totals.net_profit_absolute}
                            </div>
                        </div>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <tbody>
                            {income.map((section) => (
                                <SectionBlock key={section.code} section={section} />
                            ))}
                            <tr className="border-sidebar-border border-b-2 font-medium">
                                <td className="py-2" colSpan={2}>
                                    Total income
                                </td>
                                <td className="py-2 text-right tabular-nums">{totals.income}</td>
                            </tr>

                            {cost_of_sales.map((section) => (
                                <SectionBlock key={section.code} section={section} />
                            ))}
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b font-medium">
                                <td className="py-2" colSpan={2}>
                                    Total cost of sales
                                </td>
                                <td className="py-2 text-right tabular-nums">{totals.cost_of_sales}</td>
                            </tr>
                            <tr className="border-sidebar-border border-b-2 text-base font-semibold">
                                <td className="py-2.5" colSpan={2}>
                                    Gross profit
                                </td>
                                <td className="py-2.5 text-right tabular-nums">{totals.gross_profit}</td>
                            </tr>

                            {operating_expenses.map((section) => (
                                <SectionBlock key={section.code} section={section} />
                            ))}
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b font-medium">
                                <td className="py-2" colSpan={2}>
                                    Total operating expenses
                                </td>
                                <td className="py-2 text-right tabular-nums">{totals.operating_expenses}</td>
                            </tr>

                            <tr className="text-base font-semibold">
                                <td className="py-3" colSpan={2}>
                                    {totals.is_loss ? 'Net loss for the period' : 'Net profit for the period'}
                                </td>
                                <td className={`py-3 text-right tabular-nums ${totals.is_loss ? 'text-destructive' : ''}`}>
                                    {totals.net_profit_absolute}
                                </td>
                            </tr>

                            {empty && (
                                <tr>
                                    <td colSpan={3} className="text-muted-foreground py-6 text-center">
                                        {ledger_empty
                                            ? 'Nothing has been posted to the ledger yet.'
                                            : 'No income or expense was recorded in this period.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground max-w-2xl text-sm">
                    Income and expense are never closed into equity by this system, so a period you choose that spans a year end will simply add the
                    two years together rather than starting again from zero.
                </p>
            </div>
        </AppLayout>
    );
}
