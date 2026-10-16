import { Chip, Hint, Meter, Panel, type Tone } from '@/components/assets/assets-ui';
import { PairedBars } from '@/components/charts/chart-primitives';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftRight, ChevronDown, ChevronRight, FileUp, HandCoins, Minus, PenLine, Plus } from 'lucide-react';
import { type ReactNode } from 'react';

type RangeKey = 'this_month' | 'last_month' | 'this_year';
type VoucherTypeKey = 'payment' | 'receipt' | 'contra' | 'journal';

interface Bucket {
    key: string;
    label: string;
    value: number;
}

interface MoneyAccountRow {
    id: number;
    name: string;
    type: string;
    kind: string;
    is_cash: boolean;
    balance: number;
    has_ledger: boolean;
}

interface Part {
    label: string;
    value: number | string;
    unit?: string;
}

interface Attention {
    key: string;
    tone: 'danger' | 'warning' | 'info';
    count: number;
    title: string;
    hint: string;
    url: string;
}

interface VoucherRow {
    type: VoucherTypeKey;
    draft: number;
    pending_approval: number;
    posted: number;
    reversed: number;
    posted_amount: number;
}

interface Props {
    today: string;
    range: { key: RangeKey; label: string; from: string; to: string };
    months: { label: string; a: number; b: number; profit: number }[];
    profit: { income: number; costOfSales: number; grossProfit: number; expenses: number; netProfit: number; grossMargin: number | null };
    moneyAccounts: MoneyAccountRow[];
    cash: { value?: number; parts?: Part[] };
    inTransit: { value?: number; parts?: Part[] };
    weOwe: { total: number; rows: { label: string; value: number }[] };
    receivables: Bucket[];
    payables: Bucket[];
    owed: { to_us: number; to_us_overdue: number; by_us: number; by_us_overdue: number; by_us_due_7_days: number };
    vouchers: { rows: VoucherRow[]; other_income: { count: number; amount: number }; today: { entered: number; posted: number } };
    flow: { points?: { date: string; label: string; in: number; out: number }[] };
    attention: Attention[];
    books: {
        period: { value?: string; tone?: string | null; parts?: Part[] };
        checks: { total: number; differ: number };
        vat: { regime: string; latest: { period: string; status: string } | null } | null;
        accruals: number;
        unmapped: number;
    };
    can: { prepare_voucher: boolean; record_income: boolean; view_loans: boolean };
    hasPostings: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Accounts', href: '/accounting/overview' }];

const RANGES: { key: RangeKey; label: string }[] = [
    { key: 'this_month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: 'this_year', label: 'This year' },
];

/** Each voucher type with the plain words for it (decision D5: the accounting word stays, explained). */
const TYPES: Record<VoucherTypeKey, { label: string; plain: string; start: string }> = {
    payment: { label: 'Payment', plain: 'money out', start: 'Pay money' },
    receipt: { label: 'Receipt', plain: 'money in', start: 'Receive money' },
    contra: { label: 'Contra', plain: 'own accounts', start: 'Move between accounts' },
    journal: { label: 'Journal', plain: 'no money moves', start: 'Correct or adjust' },
};

const ATTENTION_BADGE: Record<Attention['tone'], string> = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
};

const BUCKET_BAR: Record<string, string> = {
    current: 'bg-primary',
    days_1_30: 'bg-amber-500',
    days_31_60: 'bg-orange-600',
    days_61_90: 'bg-destructive',
    days_90_plus: 'bg-destructive/70',
};

/** Taka, whole, English digits: the overview reads in round figures; the lists behind it carry the paisa. */
function tk(value: number | undefined | null): string {
    return Math.round(Number(value ?? 0)).toLocaleString('en-US');
}

function longDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function TextLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link href={href} className="text-primary inline-flex items-center gap-0.5 text-xs whitespace-nowrap hover:underline">
            {children}
            <ChevronRight className="size-3" />
        </Link>
    );
}

function Title({ children, hint }: { children: ReactNode; hint?: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {children}
            {hint && <Hint text={hint} />}
        </span>
    );
}

/** A label and its figure on one line, the figure right-aligned. */
function Line({ label, value, href, tone }: { label: ReactNode; value: ReactNode; href?: string; tone?: Tone }) {
    const figure = (
        <span
            className={cn(
                'shrink-0 font-medium tabular-nums',
                tone === 'danger' && 'text-destructive',
                tone === 'warning' && 'text-amber-700 dark:text-amber-400',
            )}
        >
            {value}
        </span>
    );

    return (
        <li className="flex items-baseline justify-between gap-3 py-1.5 text-[13px]">
            <span className="min-w-0 truncate">{label}</span>
            {href ? (
                <Link href={href} className="hover:underline">
                    {figure}
                </Link>
            ) : (
                figure
            )}
        </li>
    );
}

/**
 * Accounts > Overview (redesign round 3, 16 Oct 2026, D10). Where the money is
 * as at now, what needs somebody, the profit for the range, the vouchers, who
 * owes whom, money in and out by day, and the state of the books. Every
 * number is one the ERP already computes (the controller says where from) and
 * opens the list behind it. Calm base; colour only on text and small badges,
 * by meaning: red differs or is past due, amber waits on somebody, blue is
 * routine.
 */
export default function AccountsOverview(props: Props) {
    const {
        today,
        range,
        months,
        profit,
        moneyAccounts,
        cash,
        inTransit,
        weOwe,
        receivables,
        payables,
        owed,
        vouchers,
        flow,
        attention,
        books,
        can,
    } = props;

    const cashAccounts = moneyAccounts.filter((account) => account.is_cash);
    const notCashAccounts = moneyAccounts.filter((account) => !account.is_cash && account.balance !== 0);
    const pick = (key: RangeKey) =>
        router.get('/accounting/overview', key === 'this_month' ? {} : { range: key }, { preserveScroll: true, preserveState: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounts overview" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs">{longDate(today)}</p>
                        <p className="text-2xl font-semibold tracking-tight">Accounts</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div role="group" aria-label="Period" className="flex flex-wrap gap-1.5">
                            {RANGES.map((option) => (
                                <Chip key={option.key} active={range.key === option.key} onClick={() => pick(option.key)}>
                                    {option.label}
                                </Chip>
                            ))}
                        </div>
                        {can.prepare_voucher && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button size="sm">
                                        New voucher <ChevronDown className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {(Object.keys(TYPES) as VoucherTypeKey[]).map((type) => (
                                        <DropdownMenuItem key={type} asChild>
                                            <Link href={`/accounting/vouchers/${type}/create`}>
                                                {TYPES[type].label}
                                                <span className="text-muted-foreground ml-auto pl-3 text-xs">{TYPES[type].plain}</span>
                                            </Link>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                {!props.hasPostings && (
                    /* ⚠⚠ Said plainly. A dashboard of zeroes on a business that has
                       not started trading looks exactly like a broken dashboard,
                       and somebody would report it as a bug. */
                    <p className="text-muted-foreground rounded-xl border border-dashed px-4 py-3 text-sm">
                        Nothing has been posted to the ledger yet, so every figure below is zero.
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel
                        className="lg:col-span-2"
                        title={
                            <Title hint="Balances posted to the ledger as at now. Courier and gateway money is owed to us, not cash; what we owe couriers and customers' advances are kept apart too.">
                                Where the money is
                            </Title>
                        }
                        aside={<TextLink href="/hrm/reports/cash_position">Cash position report</TextLink>}
                    >
                        <div className="mt-3 grid gap-5 md:grid-cols-[1.4fr_1fr]">
                            <div>
                                <p className="text-muted-foreground text-xs">Cash and bank</p>
                                <Link
                                    href="/accounting/cash-and-bank"
                                    className={cn(
                                        'text-3xl font-semibold tracking-tight tabular-nums hover:underline',
                                        (cash.value ?? 0) < 0 && 'text-destructive',
                                    )}
                                >
                                    {tk(cash.value)}
                                </Link>
                                {cash.parts && (
                                    <p className="text-muted-foreground text-xs">
                                        {cash.parts.map((part) => `${part.label} ${tk(Number(part.value))}`).join(', ')}
                                    </p>
                                )}
                                {cashAccounts.length === 0 ? (
                                    <p className="text-muted-foreground mt-3 text-sm">No cash box, bank or wallet set up yet.</p>
                                ) : (
                                    <ul className="mt-2 divide-y">
                                        {cashAccounts.map((account) => (
                                            <Line
                                                key={account.id}
                                                label={
                                                    <>
                                                        {account.name} <span className="text-muted-foreground text-xs">{account.kind}</span>
                                                    </>
                                                }
                                                value={tk(account.balance)}
                                                tone={account.balance < 0 ? 'danger' : undefined}
                                                href="/accounting/cash-and-bank"
                                            />
                                        ))}
                                    </ul>
                                )}
                            </div>
                            <div className="flex flex-col gap-4">
                                <div>
                                    <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                                        Not cash yet: owed to us
                                        <Hint text="Money couriers and the payment gateway hold for us. It becomes cash when they pay out." />
                                    </p>
                                    <p className="text-xl font-semibold tabular-nums">{tk(inTransit.value)}</p>
                                    {notCashAccounts.length > 0 && (
                                        <ul className="divide-y">
                                            {notCashAccounts.map((account) => (
                                                <Line
                                                    key={account.id}
                                                    label={
                                                        <>
                                                            {account.name}{' '}
                                                            <span className="text-muted-foreground text-xs">({account.kind.toLowerCase()})</span>
                                                        </>
                                                    }
                                                    value={tk(account.balance)}
                                                />
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div>
                                    <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                                        Not cash: we owe
                                        <Hint text="Courier fees we still owe, and money customers paid before they were invoiced." />
                                    </p>
                                    <p className="text-xl font-semibold tabular-nums">{tk(weOwe.total)}</p>
                                    {weOwe.rows.length > 0 && (
                                        <ul className="divide-y">
                                            {weOwe.rows.map((row) => (
                                                <Line key={row.label} label={row.label} value={tk(row.value)} />
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </div>
                    </Panel>

                    <Panel
                        title={
                            <Title hint="What is waiting on somebody, most serious first. Each line opens the list behind it.">Needs attention</Title>
                        }
                        aside={
                            <span className="text-muted-foreground text-xs">
                                {attention.length === 0 ? 'Nothing waiting' : `${attention.length} ${attention.length === 1 ? 'item' : 'items'}`}
                            </span>
                        }
                        flush
                    >
                        {attention.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">
                                The books are in order: nothing to approve, post, match or clear.
                            </p>
                        ) : (
                            <ul>
                                {attention.map((item) => (
                                    <li key={item.key}>
                                        <Link href={item.url} className="hover:bg-muted/50 flex items-center gap-3 border-t px-4 py-2.5">
                                            <span
                                                className={cn(
                                                    'grid size-8 shrink-0 place-items-center rounded-lg text-[13px] font-semibold tabular-nums',
                                                    ATTENTION_BADGE[item.tone],
                                                )}
                                            >
                                                {item.count}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[13.5px] font-medium">{item.title}</span>
                                                <span className="text-muted-foreground block truncate text-xs">{item.hint}</span>
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={<Title hint="From the income statement for the range picked above.">Profit, {range.label}</Title>}
                        aside={<TextLink href="/accounting/income-statement">Profit &amp; loss</TextLink>}
                    >
                        <ul className="mt-2 divide-y">
                            <Line label="Income" value={tk(profit.income)} />
                            <Line label="Cost of sales" value={tk(profit.costOfSales)} />
                            <Line
                                label={
                                    <>
                                        Gross profit{' '}
                                        {profit.grossMargin !== null && (
                                            <span className="text-muted-foreground text-xs">{profit.grossMargin.toFixed(1)}% margin</span>
                                        )}
                                    </>
                                }
                                value={tk(profit.grossProfit)}
                            />
                            <Line label="Expenses" value={tk(profit.expenses)} />
                            <li className="flex items-baseline justify-between gap-3 py-2">
                                <span className="text-[13px] font-semibold">Net profit</span>
                                <span className={cn('text-xl font-semibold tabular-nums', profit.netProfit < 0 && 'text-destructive')}>
                                    {tk(profit.netProfit)}
                                </span>
                            </li>
                        </ul>
                        <p className="text-muted-foreground mt-3 mb-1 text-xs">Last 6 months</p>
                        <PairedBars
                            points={months}
                            aLabel="Income"
                            bLabel="Costs and expenses"
                            empty="No income or costs posted in the last six months."
                        />
                    </Panel>

                    <Panel
                        title={
                            <Title hint="Vouchers dated in the range, by state. Drafts and approvals waiting are in Needs attention whatever their date.">
                                Vouchers, {range.label}
                            </Title>
                        }
                        aside={
                            <span className="text-muted-foreground text-xs">
                                Today: {vouchers.today.entered} entered, {vouchers.today.posted} posted
                            </span>
                        }
                        flush
                    >
                        <div className="overflow-x-auto">
                            <table className="w-full text-[13px]">
                                <thead>
                                    <tr className="text-muted-foreground text-xs">
                                        <th className="px-4 py-2 text-left font-medium">Type</th>
                                        <th className="px-2 py-2 text-right font-medium">Posted</th>
                                        <th className="px-2 py-2 text-right font-medium">Draft</th>
                                        <th className="px-2 py-2 text-right font-medium">Awaiting</th>
                                        <th className="hidden px-2 py-2 text-right font-medium sm:table-cell">Reversed</th>
                                        <th className="px-4 py-2 text-right font-medium">Posted, Tk</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {vouchers.rows.map((row) => {
                                        const list = `/accounting/vouchers/${row.type}`;
                                        return (
                                            <tr key={row.type} className="border-t">
                                                <td className="px-4 py-2">
                                                    <Link href={list} className="font-medium hover:underline">
                                                        {TYPES[row.type].label}
                                                    </Link>{' '}
                                                    <span className="text-muted-foreground hidden text-xs sm:inline">{TYPES[row.type].plain}</span>
                                                </td>
                                                <td className="px-2 py-2 text-right tabular-nums">
                                                    <Link href={`${list}?status=posted`} className="hover:underline">
                                                        {row.posted}
                                                    </Link>
                                                </td>
                                                <td className="text-muted-foreground px-2 py-2 text-right tabular-nums">
                                                    <Link href={`${list}?status=draft`} className="hover:underline">
                                                        {row.draft}
                                                    </Link>
                                                </td>
                                                <td
                                                    className={cn(
                                                        'px-2 py-2 text-right tabular-nums',
                                                        row.pending_approval > 0
                                                            ? 'font-medium text-amber-700 dark:text-amber-400'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    <Link href={`${list}?status=pending_approval`} className="hover:underline">
                                                        {row.pending_approval}
                                                    </Link>
                                                </td>
                                                <td className="text-muted-foreground hidden px-2 py-2 text-right tabular-nums sm:table-cell">
                                                    {row.reversed}
                                                </td>
                                                <td className="px-4 py-2 text-right font-medium tabular-nums">{tk(row.posted_amount)}</td>
                                            </tr>
                                        );
                                    })}
                                    <tr className="border-t">
                                        <td className="px-4 py-2">
                                            <Link href="/accounting/other-income" className="font-medium hover:underline">
                                                Other income
                                            </Link>
                                        </td>
                                        <td className="px-2 py-2 text-right tabular-nums">{vouchers.other_income.count}</td>
                                        <td className="text-muted-foreground px-2 py-2 text-right">-</td>
                                        <td className="text-muted-foreground px-2 py-2 text-right">-</td>
                                        <td className="text-muted-foreground hidden px-2 py-2 text-right sm:table-cell">-</td>
                                        <td className="px-4 py-2 text-right font-medium tabular-nums">{tk(vouchers.other_income.amount)}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Panel>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title={<Title hint="Open invoices and bills as at today, by how far past due they are.">Who owes whom</Title>}>
                        <div className="mt-3 grid gap-5 sm:grid-cols-2">
                            <Owed
                                label="Customers owe us"
                                total={owed.to_us}
                                second={{ label: 'Overdue', value: owed.to_us_overdue, tone: owed.to_us_overdue > 0 ? 'danger' : undefined }}
                                buckets={receivables}
                                href="/accounting/receivables-ageing"
                                link="Receivables ageing"
                            />
                            <Owed
                                label="We owe suppliers"
                                total={owed.by_us}
                                second={{
                                    label: 'Due in the next 7 days',
                                    value: owed.by_us_due_7_days,
                                    tone: owed.by_us_due_7_days > 0 ? 'warning' : undefined,
                                }}
                                buckets={payables}
                                href="/accounting/payables-ageing"
                                link="Payables ageing"
                            />
                        </div>
                    </Panel>

                    <MoneyByDay points={flow.points ?? []} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel
                        title={<Title hint="Where month end stands: the lock, the ledger checks, VAT and what is still to release.">The books</Title>}
                        aside={<TextLink href="/accounting/period-close">Month end</TextLink>}
                    >
                        <ul className="mt-2 divide-y">
                            <Line
                                label="Closed through"
                                value={
                                    <>
                                        {books.period.value ?? 'Never closed'}
                                        {books.period.parts?.[0] && (
                                            <span className="text-muted-foreground ml-1.5 text-xs font-normal">
                                                {String(books.period.parts[0].value)}
                                            </span>
                                        )}
                                    </>
                                }
                                tone={books.period.tone === 'bad' ? 'danger' : books.period.tone === 'warn' ? 'warning' : undefined}
                                href="/accounting/period-close"
                            />
                            <Line
                                label="Ledger checks"
                                value={
                                    books.checks.total === 0
                                        ? 'Nothing to check yet'
                                        : books.checks.differ === 0
                                          ? `All ${books.checks.total} agree`
                                          : `${books.checks.differ} of ${books.checks.total} differ`
                                }
                                tone={books.checks.differ > 0 ? 'danger' : undefined}
                                href="/accounting/reconciliation"
                            />
                            {books.vat && (
                                <Line
                                    label={`VAT return (${books.vat.regime})`}
                                    value={books.vat.latest ? `${books.vat.latest.period}, ${books.vat.latest.status}` : 'None filed yet'}
                                    href="/accounting/vat-return"
                                />
                            )}
                            <Line
                                label="Accruals and prepayments"
                                value={books.accruals === 0 ? 'Nothing to release' : `${books.accruals} to release this month`}
                                tone={books.accruals > 0 ? 'warning' : undefined}
                                href="/accounting/amortizations"
                            />
                            <Line
                                label="Money accounts without a ledger head"
                                value={books.unmapped}
                                tone={books.unmapped > 0 ? 'danger' : undefined}
                                href="/accounting/money-accounts"
                            />
                        </ul>
                    </Panel>

                    <StartSomething can={can} />
                </div>
            </div>
        </AppLayout>
    );
}

function Owed({
    label,
    total,
    second,
    buckets,
    href,
    link,
}: {
    label: string;
    total: number;
    second: { label: string; value: number; tone?: Tone };
    buckets: Bucket[];
    href: string;
    link: string;
}) {
    const sum = buckets.reduce((carry, bucket) => carry + Math.max(bucket.value, 0), 0);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap gap-x-6 gap-y-1">
                <div>
                    <p className="text-muted-foreground text-xs">{label}</p>
                    <Link href={href} className="text-xl font-semibold tabular-nums hover:underline">
                        {tk(total)}
                    </Link>
                </div>
                <div>
                    <p className="text-muted-foreground text-xs">{second.label}</p>
                    <p
                        className={cn(
                            'text-xl font-semibold tabular-nums',
                            second.tone === 'danger' && 'text-destructive',
                            second.tone === 'warning' && 'text-amber-700 dark:text-amber-400',
                        )}
                    >
                        {tk(second.value)}
                    </p>
                </div>
            </div>
            <Meter
                label={`${label} by age`}
                segments={buckets.map((bucket) => ({
                    share: sum === 0 ? 0 : (Math.max(bucket.value, 0) / sum) * 100,
                    className: BUCKET_BAR[bucket.key] ?? 'bg-muted-foreground',
                }))}
            />
            <ul className="text-muted-foreground flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                {buckets.map((bucket) => (
                    <li key={bucket.key}>
                        {bucket.label} <span className="text-foreground tabular-nums">{tk(bucket.value)}</span>
                    </li>
                ))}
            </ul>
            <TextLink href={href}>{link}</TextLink>
        </div>
    );
}

/** Money in and out of cash, bank and wallets, day by day; transfers between our own accounts are left out. */
function MoneyByDay({ points }: { points: { date: string; label: string; in: number; out: number }[] }) {
    const totalIn = points.reduce((carry, point) => carry + point.in, 0);
    const totalOut = points.reduce((carry, point) => carry + point.out, 0);
    const top = Math.max(1, ...points.flatMap((point) => [point.in, point.out]));
    const every = Math.max(1, Math.ceil(points.length / 8));

    return (
        <Panel
            title={
                <Title hint="Money into and out of cash, bank and wallets by the journal date. Moving money between our own accounts is left out.">
                    Money in and out, by day
                </Title>
            }
            aside={<TextLink href="/accounting/cash-and-bank">Cash &amp; bank book</TextLink>}
        >
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-[13px]">
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-sm bg-[var(--chart-in,#16a34a)]" /> In{' '}
                    <span className="font-semibold tabular-nums">{tk(totalIn)}</span>
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-sm bg-[var(--chart-out,#dc2626)]" /> Out{' '}
                    <span className="font-semibold tabular-nums">{tk(totalOut)}</span>
                </span>
            </div>
            {totalIn === 0 && totalOut === 0 ? (
                <p className="text-muted-foreground flex h-36 items-center justify-center text-sm">No money moved in this range.</p>
            ) : (
                <>
                    <div role="img" aria-label={`In ${tk(totalIn)}, out ${tk(totalOut)}`} className="mt-3 flex h-36 items-end gap-px border-b">
                        {points.map((point) => (
                            <div
                                key={point.date}
                                title={`${point.label}: in ${tk(point.in)}, out ${tk(point.out)}`}
                                className="flex h-full min-w-0 flex-1 items-end gap-px"
                            >
                                <div
                                    className="flex-1 rounded-t-[2px] bg-[var(--chart-in,#16a34a)]"
                                    style={{ height: `${(point.in / top) * 100}%` }}
                                />
                                <div
                                    className="flex-1 rounded-t-[2px] bg-[var(--chart-out,#dc2626)]"
                                    style={{ height: `${(point.out / top) * 100}%` }}
                                />
                            </div>
                        ))}
                    </div>
                    <div className="text-muted-foreground mt-1 flex overflow-hidden text-[11px]">
                        {points.map((point, index) => (
                            <span key={point.date} className="min-w-0 flex-1 overflow-visible whitespace-nowrap">
                                {index % every === 0 ? point.label : ''}
                            </span>
                        ))}
                    </div>
                </>
            )}
        </Panel>
    );
}

/** The everyday starts, each shown only to somebody who may do it. */
function StartSomething({ can }: { can: Props['can'] }) {
    const items: { href: string; icon: ReactNode; title: string; sub: string; show: boolean }[] = [
        {
            href: '/accounting/vouchers/payment/create',
            icon: <Minus className="size-4" />,
            title: TYPES.payment.start,
            sub: 'Payment voucher',
            show: can.prepare_voucher,
        },
        {
            href: '/accounting/vouchers/receipt/create',
            icon: <Plus className="size-4" />,
            title: TYPES.receipt.start,
            sub: 'Receipt voucher',
            show: can.prepare_voucher,
        },
        {
            href: '/accounting/vouchers/contra/create',
            icon: <ArrowLeftRight className="size-4" />,
            title: TYPES.contra.start,
            sub: 'Contra voucher',
            show: can.prepare_voucher,
        },
        {
            href: '/accounting/vouchers/journal/create',
            icon: <PenLine className="size-4" />,
            title: TYPES.journal.start,
            sub: 'Journal voucher',
            show: can.prepare_voucher,
        },
        {
            href: '/accounting/other-income',
            icon: <HandCoins className="size-4" />,
            title: 'Other income',
            sub: 'Rent, interest, scrap',
            show: can.record_income,
        },
        {
            href: '/accounting/bank-statements',
            icon: <FileUp className="size-4" />,
            title: 'Import bank statement',
            sub: 'Then match the lines',
            show: true,
        },
    ];

    return (
        <Panel title="Start something">
            <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                {items
                    .filter((item) => item.show)
                    .map((item) => (
                        <li key={item.href}>
                            <Link href={item.href} className="hover:bg-muted/50 flex items-center gap-3 rounded-lg border px-3 py-2.5">
                                <span className="bg-muted text-muted-foreground grid size-8 shrink-0 place-items-center rounded-md">{item.icon}</span>
                                <span className="min-w-0">
                                    <span className="block text-[13.5px] font-medium">{item.title}</span>
                                    <span className="text-muted-foreground block text-xs">{item.sub}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
            </ul>
        </Panel>
    );
}
