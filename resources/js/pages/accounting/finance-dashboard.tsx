import { AreaTrend, Breakdown, PairedBars } from '@/components/charts/chart-primitives';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Point {
    label: string;
    value: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/accounting/overview' }];

function money(value: number): string {
    return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function Tile({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'good' | 'bad' }) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div
                className={`text-2xl font-semibold ${
                    tone === 'good' ? 'text-emerald-600 dark:text-emerald-400' : tone === 'bad' ? 'text-red-600 dark:text-red-400' : ''
                }`}
            >
                {value}
            </div>
            {hint && <div className="text-muted-foreground text-xs">{hint}</div>}
        </div>
    );
}

export default function FinanceDashboard({
    months,
    profitTrend,
    thisMonth,
    moneyAccounts,
    receivables,
    payables,
    owed,
    hasPostings,
}: {
    months: { label: string; a: number; b: number; profit: number }[];
    profitTrend: Point[];
    thisMonth: {
        income: number;
        costOfSales: number;
        grossProfit: number;
        expenses: number;
        netProfit: number;
        grossMargin: number | null;
    };
    moneyAccounts: { name: string; type: string }[];
    receivables: Point[];
    payables: Point[];
    owed: { to_us: number; by_us: number };
    hasPostings: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance overview" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">Finance overview</h2>
                    <p className="text-muted-foreground text-sm">
                        Every figure here is read from the same report that produces the statement of the same name, so this screen and the
                        statements can never disagree.
                    </p>
                </div>

                {!hasPostings && (
                    /* ⚠⚠ Said plainly. A dashboard of zeroes on a business that has
                       not started trading looks exactly like a broken dashboard,
                       and somebody would report it as a bug. */
                    <div className="rounded-lg border border-sky-300 bg-sky-50 p-4 text-sm dark:border-sky-900 dark:bg-sky-950">
                        Nothing has been posted to the ledger yet, so every figure below is zero. That is correct rather than broken: the numbers
                        appear as soon as the first invoice, bill or opening balance is entered.
                    </div>
                )}

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Tile label="Income this month" value={money(thisMonth.income)} />
                    <Tile
                        label="Gross profit"
                        value={money(thisMonth.grossProfit)}
                        hint={thisMonth.grossMargin !== null ? `${thisMonth.grossMargin.toFixed(1)}% margin` : 'No sales yet'}
                    />
                    <Tile label="Running costs" value={money(thisMonth.expenses)} />
                    <Tile
                        label="Net profit"
                        value={money(thisMonth.netProfit)}
                        tone={thisMonth.netProfit >= 0 ? 'good' : 'bad'}
                        hint="After cost of sales and running costs"
                    />
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Money in against money out</h3>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/accounting/income-statement">The statement</Link>
                            </Button>
                        </div>
                        <PairedBars points={months} aLabel="Income" bLabel="Cost and expenses" empty="No income or costs posted in the last six months." />
                    </div>

                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Net profit, month by month</h3>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/accounting/cash-flow">Where the money went</Link>
                            </Button>
                        </div>
                        <AreaTrend points={profitTrend} empty="Nothing to chart until the first month has figures in it." />
                    </div>
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Owed to us</h3>
                            <span className="font-medium">{money(owed.to_us)}</span>
                        </div>
                        {/* ⚠ Bars rather than a pie: this is a screen where somebody
                            is looking for WHICH bucket is the problem. */}
                        <Breakdown points={receivables} empty="Nobody owes us anything." />
                        <Button variant="ghost" size="sm" asChild className="self-start">
                            <Link href="/accounting/receivables-ageing">The ageing report</Link>
                        </Button>
                    </div>

                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Owed by us</h3>
                            <span className="font-medium">{money(owed.by_us)}</span>
                        </div>
                        <Breakdown points={payables} empty="We owe nobody anything." />
                    </div>
                </section>

                <section className="flex flex-col gap-3 rounded-lg border p-4">
                    <div className="flex items-center justify-between">
                        <h3 className="font-medium">Where the cash is kept</h3>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/accounting/cash-and-bank">The cash book</Link>
                        </Button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {moneyAccounts.map((account) => (
                            <Badge key={account.name} variant="outline">
                                {account.name}
                                <span className="text-muted-foreground ml-1">{account.type}</span>
                            </Badge>
                        ))}
                        {moneyAccounts.length === 0 && <p className="text-muted-foreground text-sm">No money accounts set up.</p>}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Balances live in the cash book, which reads them from the ledger. This is the list of places money is held, not a second
                        opinion about how much is in them.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}
