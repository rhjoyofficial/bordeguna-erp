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

// ⚠ F34 (17 Oct 2026): a missing or non-numeric figure prints 0.00 rather than
// throwing, so one empty widget can never blank the whole page. Fixed en-US
// grouping keeps the digits English whatever the browser's locale.
function money(value: number | null | undefined): string {
    const figure = typeof value === 'number' && Number.isFinite(value) ? value : 0;

    return figure.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const NO_MONTH = { income: 0, costOfSales: 0, grossProfit: 0, expenses: 0, netProfit: 0, grossMargin: null };
const NO_OWED = { to_us: 0, by_us: 0, customers_credit: 0, suppliers_credit: 0 };

/** A money account on the overview, with its balance from the posted journal lines (F32). */
interface MoneyAccountRow {
    id: number;
    name: string;
    type: string;
    balance: number;
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
    months = [],
    profitTrend = [],
    thisMonth: thisMonthProp,
    moneyAccounts = [],
    receivables = [],
    payables = [],
    owed: owedProp,
    hasPostings = false,
}: {
    months: { label: string; a: number; b: number; profit: number }[];
    profitTrend: Point[];
    thisMonth?: {
        income: number;
        costOfSales: number;
        grossProfit: number;
        expenses: number;
        netProfit: number;
        grossMargin: number | null;
    } | null;
    moneyAccounts?: MoneyAccountRow[];
    receivables?: Point[];
    payables?: Point[];
    owed?: { to_us: number; by_us: number; customers_credit?: number; suppliers_credit?: number } | null;
    hasPostings?: boolean;
}) {
    const thisMonth = thisMonthProp ?? NO_MONTH;
    const owed = { ...NO_OWED, ...(owedProp ?? {}) };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance overview" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h2 className="text-lg font-medium">Finance overview</h2>
                </div>

                {!hasPostings && (
                    /* ⚠⚠ Said plainly. A dashboard of zeroes on a business that has
                       not started trading looks exactly like a broken dashboard,
                       and somebody would report it as a bug. */
                    <div className="rounded-lg border border-sky-300 bg-sky-50 p-4 text-sm dark:border-sky-900 dark:bg-sky-950">
                        Nothing has been posted to the ledger yet, so every figure below is zero.
                    </div>
                )}

                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Tile label="Income this month" value={money(thisMonth.income)} />
                    <Tile
                        label="Gross profit"
                        value={money(thisMonth.grossProfit)}
                        hint={typeof thisMonth.grossMargin === 'number' ? `${thisMonth.grossMargin.toFixed(1)}% margin` : 'No sales yet'}
                    />
                    <Tile label="Running costs" value={money(thisMonth.expenses)} />
                    <Tile label="Net profit" value={money(thisMonth.netProfit)} tone={thisMonth.netProfit >= 0 ? 'good' : 'bad'} />
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <h3 className="font-medium">Money in against money out</h3>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/accounting/income-statement">The statement</Link>
                            </Button>
                        </div>
                        <PairedBars
                            points={months}
                            aLabel="Income"
                            bLabel="Cost and expenses"
                            empty="No income or costs posted in the last six months."
                        />
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
                        {/* F33: customers in credit (a return not refunded) are money we
                            owe them, shown positive in a plain colour, never a red minus here. */}
                        {owed.customers_credit > 0 && (
                            <div className="text-muted-foreground flex items-center justify-between text-sm">
                                <span>We owe customers</span>
                                <span className="text-foreground font-medium">{money(owed.customers_credit)}</span>
                            </div>
                        )}
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
                        {owed.suppliers_credit > 0 && (
                            <div className="text-muted-foreground flex items-center justify-between text-sm">
                                <span>Suppliers owe us</span>
                                <span className="text-foreground font-medium">{money(owed.suppliers_credit)}</span>
                            </div>
                        )}
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
                            <Badge key={account.id ?? account.name} variant="outline">
                                {account.name}
                                <span className="text-muted-foreground ml-1">{account.type}</span>
                                {/* F32: the balance from the posted journal lines, as Cash position shows it. */}
                                <span className="ml-2 font-semibold tabular-nums">{money(account.balance)}</span>
                            </Badge>
                        ))}
                        {moneyAccounts.length === 0 && <p className="text-muted-foreground text-sm">No money accounts set up.</p>}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
