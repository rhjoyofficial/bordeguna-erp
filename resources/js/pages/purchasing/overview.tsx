import { Chip, CompactTable, daysWord, Hint, Meter, Panel, PermissionNote, Stat, type Tone } from '@/components/assets/assets-ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

interface Chain {
    awaiting_approval: { count: number; ordered: string; numbers: string[]; url: string };
    awaiting_goods: { count: number; partly: number; url: string };
    received_not_billed: { count: number; oldest_days: number | null; numbers: string[]; url: string };
    bills_open: { count: number; amount: string; due_this_week: number; url: string };
    owed: { total: string; overdue: string; url: string } | null;
    open_orders: number;
    suppliers_open: number;
}

interface Need {
    key: string;
    title: string;
    hint: string;
    count: number;
    tone: Tone;
    url: string;
}

interface Payables {
    total: string;
    overdue: string;
    on_account: string;
    ties_out: boolean;
    buckets: { key: string; label: string; amount: string; share: number }[];
    top: { id: number; name: string; amount: string; overdue: boolean; url: string }[];
    url: string;
}

interface Spend {
    label: string;
    total: string;
    count: number;
    heads: { name: string; amount: string; share: number }[];
    paid_from: { name: string; percent: number }[];
    url: string;
}

interface Standing {
    active: number;
    monthly: string;
    annual: string;
    due: {
        id: number;
        name: string;
        period_label: string;
        due_on: string;
        days_away: number;
        expected_amount: string;
        amount_varies: boolean;
        url: string;
    }[];
    due_week: number;
    url: string;
}

interface Delivery {
    id: number;
    received_on: string | null;
    item: string | null;
    quantity: string;
    unit: string | null;
    po_number: string | null;
    batch: string | null;
    expires_on: string | null;
    days_left: number | null;
    url: string;
}

interface Suppliers {
    active: number;
    with_balance: number;
    certificate_expiring: number;
    rows: { id: number; name: string | null; note: string; tone: Tone; url: string }[];
    url: string;
}

type Range = 'month' | 'last' | 'quarter';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Purchases', href: '/purchasing/overview' }];

const NEED_BADGE: Record<Tone, string> = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
    muted: 'bg-muted text-muted-foreground',
    ok: 'bg-primary/10 text-primary',
};

const BUCKET_BAR: Record<string, string> = {
    current: 'bg-primary',
    days_1_30: 'bg-amber-500',
    days_31_60: 'bg-orange-600',
    days_61_90: 'bg-destructive',
    days_90_plus: 'bg-destructive/70',
};

function longDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function shortDate(iso: string | null): string {
    if (!iso) return '-';

    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function monthYear(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}

function plural(count: number, word: string, words = word + 's'): string {
    return `${count} ${count === 1 ? word : words}`;
}

/**
 * The Purchases overview (module redesign, 15 Oct 2026): where purchases
 * stand step by step, what needs somebody, what we owe and how old it is,
 * what we spent, the standing costs coming up, the latest deliveries and the
 * suppliers. Each number opens the list behind it, filtered where the list
 * has the filter. The payables card shows only to a ledger reader.
 */
export default function PurchasesOverview({
    today,
    can,
    chain,
    needs,
    payables,
    spend,
    standing,
    deliveries,
    suppliers,
}: {
    today: string;
    can: { accounting: boolean; create_order: boolean; record_expense: boolean; record_payment: boolean };
    chain: Chain;
    needs: Need[];
    payables: Payables | null;
    spend: Record<Range, Spend>;
    standing: Standing;
    deliveries: Delivery[];
    suppliers: Suppliers;
}) {
    const [range, setRange] = useState<Range>('month');
    const spent = spend[range];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchases" />
            <div className="flex flex-col gap-5 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs">{longDate(today)}</p>
                        <p className="text-2xl font-semibold tracking-tight">Purchases</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div role="group" aria-label="Period" className="flex flex-wrap gap-1.5">
                            {(
                                [
                                    ['month', 'This month'],
                                    ['last', 'Last month'],
                                    ['quarter', 'This quarter'],
                                ] as const
                            ).map(([key, label]) => (
                                <Chip key={key} active={range === key} onClick={() => setRange(key)}>
                                    {label}
                                </Chip>
                            ))}
                        </div>
                        {can.record_expense && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/purchasing/expenses?add=1">Record expense</Link>
                            </Button>
                        )}
                        {can.create_order && (
                            <Button size="sm" asChild>
                                <Link href="/purchasing/purchase-orders#new-order">New purchase order</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <ChainStrip chain={chain} />

                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    <Panel
                        title="Needs you"
                        aside={
                            <span className="text-muted-foreground text-xs">
                                {needs.length === 0 ? 'Nothing waiting' : plural(needs.length, 'item')}
                            </span>
                        }
                        flush
                    >
                        {needs.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing overdue, waiting or due this week.</p>
                        ) : (
                            <ul>
                                {needs.map((need) => (
                                    <li key={need.key}>
                                        <Link href={need.url} className="hover:bg-muted/50 flex items-center gap-3 border-t px-4 py-2.5">
                                            <span
                                                className={cn(
                                                    'grid size-8 shrink-0 place-items-center rounded-lg text-[13px] font-semibold tabular-nums',
                                                    NEED_BADGE[need.tone],
                                                )}
                                            >
                                                {need.count}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[13.5px] font-medium">{need.title}</span>
                                                <span className="text-muted-foreground block truncate text-xs">{need.hint}</span>
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {payables && <PayablesCard payables={payables} />}

                    <Panel
                        title={
                            <span className="inline-flex items-center gap-1.5">
                                Running costs
                                <Hint text="Expense vouchers by the day the money left. Reversed vouchers are not counted." />
                            </span>
                        }
                        aside={
                            <Link href={spent.url} className="text-primary text-xs hover:underline">
                                All expenses
                            </Link>
                        }
                    >
                        <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
                            <Stat label={spent.label} value={`Tk ${spent.total}`} size="lg" href={spent.url} />
                            <span className="text-muted-foreground text-xs">{plural(spent.count, 'voucher')}</span>
                        </div>
                        {spent.heads.length === 0 ? (
                            <p className="text-muted-foreground mt-3 text-sm">Nothing spent in this period.</p>
                        ) : (
                            <ul className="mt-3 grid gap-2.5">
                                {spent.heads.map((head) => (
                                    <li key={head.name}>
                                        <div className="flex justify-between gap-3 text-[13px]">
                                            <span className="truncate">{head.name}</span>
                                            <span className="font-medium tabular-nums">{head.amount}</span>
                                        </div>
                                        <Meter
                                            className="mt-1 h-1.5"
                                            label={head.amount}
                                            segments={[{ share: head.share, className: 'bg-primary' }]}
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {spent.paid_from.length > 0 && (
                            <p className="text-muted-foreground mt-3 border-t pt-3 text-xs">
                                Paid from:{' '}
                                {spent.paid_from.map((row, index) => (
                                    <span key={row.name}>
                                        {index > 0 && ', '}
                                        {row.name} <span className="tabular-nums">{row.percent}%</span>
                                    </span>
                                ))}
                            </p>
                        )}
                    </Panel>

                    <Panel
                        title={
                            <span className="inline-flex items-center gap-1.5">
                                Standing costs
                                <Hint text="Subscriptions and monthly bills the ERP predicts. Nothing is posted until somebody records the expense." />
                            </span>
                        }
                        aside={
                            <Link href={standing.url} className="text-primary text-xs hover:underline">
                                Recurring
                            </Link>
                        }
                    >
                        <div className="mt-3 flex flex-wrap gap-7">
                            <Stat label="Active schedules" value={standing.active} href={standing.url} />
                            <Stat label="Per month, Tk" value={standing.monthly} href={standing.url} />
                            <Stat label="Per year, Tk" value={standing.annual} href={standing.url} />
                        </div>
                        {standing.due.length > 0 && (
                            <ul className="mt-3 border-t">
                                {standing.due.map((row) => (
                                    <li key={row.id} className="flex items-center gap-3 border-b py-2 text-[13px] last:border-b-0">
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {row.name}, {row.period_label}
                                            </span>
                                            <span
                                                className={cn(
                                                    'block text-xs',
                                                    row.days_away < 0 ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground',
                                                )}
                                            >
                                                Due {shortDate(row.due_on)}, {daysWord(row.days_away)}
                                                {row.amount_varies && ', amount varies'}
                                            </span>
                                        </span>
                                        <span className="shrink-0 font-medium tabular-nums">
                                            {row.amount_varies && '~'}
                                            {row.expected_amount}
                                        </span>
                                        {can.record_expense && (
                                            <Button variant="outline" size="sm" className="shrink-0" asChild>
                                                <Link href={row.url}>Record</Link>
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel
                        title={
                            <span className="inline-flex items-center gap-1.5">
                                Recent deliveries
                                <Hint text="Goods receipts, newest first, with the batch and expiry each one created." />
                            </span>
                        }
                        aside={
                            <Link href="/purchasing/purchase-orders" className="text-primary text-xs hover:underline">
                                Purchase orders
                            </Link>
                        }
                        flush
                    >
                        {deliveries.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing received yet.</p>
                        ) : (
                            <CompactTable minWidth="26rem">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th>Received</th>
                                        <th>Item</th>
                                        <th>Batch</th>
                                        <th>Expires</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {deliveries.map((row) => (
                                        <tr key={row.id}>
                                            <td className="whitespace-nowrap tabular-nums">{shortDate(row.received_on)}</td>
                                            <td>
                                                <Link href={row.url} className="hover:underline">
                                                    {row.item ?? '-'} {row.quantity} {row.unit ?? ''}
                                                    {row.po_number && <span className="text-muted-foreground">, {row.po_number}</span>}
                                                </Link>
                                            </td>
                                            <td className="font-mono text-xs">{row.batch ?? <span className="text-muted-foreground">none</span>}</td>
                                            <td className="whitespace-nowrap">
                                                {row.expires_on === null ? (
                                                    <span className="text-muted-foreground">no expiry</span>
                                                ) : row.days_left !== null && row.days_left < 30 ? (
                                                    <span
                                                        className={cn(
                                                            'font-medium',
                                                            row.days_left < 0 ? 'text-destructive' : 'text-amber-700 dark:text-amber-400',
                                                        )}
                                                    >
                                                        {shortDate(row.expires_on)},{' '}
                                                        {row.days_left < 0 ? `${-row.days_left} days ago` : plural(row.days_left, 'day')}
                                                    </span>
                                                ) : (
                                                    monthYear(row.expires_on)
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Panel>

                    <Panel
                        title="Suppliers"
                        aside={
                            <Link href={suppliers.url} className="text-primary text-xs hover:underline">
                                All suppliers
                            </Link>
                        }
                    >
                        <div className="mt-3 flex flex-wrap gap-7">
                            <Stat label="Active" value={suppliers.active} href={`${suppliers.url}?status=active`} />
                            <Stat
                                label="With a balance"
                                value={suppliers.with_balance}
                                href={payables ? payables.url : '/purchasing/bills?status=open'}
                            />
                            <Stat
                                label="Certificate expiring"
                                value={suppliers.certificate_expiring}
                                tone={suppliers.certificate_expiring > 0 ? 'warning' : 'ok'}
                                hint="A trade licence, BSTI licence or other certificate ending within 60 days, or already ended."
                                href={suppliers.url}
                            />
                        </div>
                        {suppliers.rows.length > 0 && (
                            <ul className="mt-3 border-t">
                                {suppliers.rows.map((row, index) => (
                                    <li key={index} className="flex items-center justify-between gap-3 border-b py-2 text-[13px] last:border-b-0">
                                        <Link href={row.url} className="min-w-0 flex-1 truncate hover:underline">
                                            {row.name}
                                        </Link>
                                        <span
                                            className={cn(
                                                'shrink-0 text-xs',
                                                row.tone === 'danger'
                                                    ? 'text-destructive font-medium'
                                                    : 'font-medium text-amber-700 dark:text-amber-400',
                                            )}
                                        >
                                            {row.note}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </AppLayout>
    );
}

/** The purchase chain: one number per step, each a link to the list behind it. */
function ChainStrip({ chain }: { chain: Chain }) {
    const steps: { label: string; value: string | number; hint: string; url: string; tone: Tone; hintTone?: Tone }[] = [
        {
            label: '1. Awaiting approval',
            value: chain.awaiting_approval.count,
            hint: chain.awaiting_approval.count > 0 ? `Tk ${chain.awaiting_approval.ordered} ordered` : 'Nothing waiting',
            url: chain.awaiting_approval.url,
            tone: chain.awaiting_approval.count > 0 ? 'warning' : 'ok',
        },
        {
            label: '2. Awaiting goods',
            value: chain.awaiting_goods.count,
            hint: chain.awaiting_goods.partly > 0 ? `${chain.awaiting_goods.partly} partly received` : 'Approved, not yet delivered',
            url: chain.awaiting_goods.url,
            tone: 'ok',
        },
        {
            label: '3. Received, not billed',
            value: chain.received_not_billed.count,
            hint:
                chain.received_not_billed.oldest_days !== null
                    ? `${chain.received_not_billed.oldest_days} days without a bill`
                    : 'Every delivery has its bill',
            url: chain.received_not_billed.url,
            tone: chain.received_not_billed.count > 0 ? 'warning' : 'ok',
        },
        {
            label: '4. Bills open',
            value: chain.bills_open.count,
            hint: chain.bills_open.due_this_week > 0 ? `${chain.bills_open.due_this_week} due this week` : `Tk ${chain.bills_open.amount}`,
            url: chain.bills_open.url,
            tone: 'ok',
        },
    ];

    if (chain.owed) {
        steps.push({
            label: '5. We owe now',
            value: `Tk ${chain.owed.total}`,
            hint: chain.owed.overdue !== '0.00' ? `Tk ${chain.owed.overdue} overdue` : 'Nothing overdue',
            url: chain.owed.url,
            tone: 'ok',
            hintTone: chain.owed.overdue !== '0.00' ? 'danger' : 'muted',
        });
    }

    return (
        <section className="bg-card rounded-xl border p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="inline-flex items-center gap-1.5 text-[15px] font-semibold">
                    Where purchases stand
                    <Hint text="Order, approve, receive, bill, pay. Each number is a list; click it." />
                </p>
                <span className="text-muted-foreground text-xs">
                    {plural(chain.open_orders, 'open order')}, {plural(chain.suppliers_open, 'supplier')}
                </span>
            </div>
            <div className="mt-3 flex flex-wrap gap-2.5">
                {steps.map((step) => (
                    <Link
                        key={step.label}
                        href={step.url}
                        className="hover:bg-muted/50 bg-card flex min-w-36 flex-1 basis-40 flex-col gap-0.5 rounded-lg border px-3.5 py-3"
                    >
                        <span className="text-muted-foreground text-xs">{step.label}</span>
                        <span
                            className={cn(
                                'text-2xl font-semibold tabular-nums',
                                step.tone === 'warning' && 'text-amber-700 dark:text-amber-400',
                                step.tone === 'danger' && 'text-destructive',
                            )}
                        >
                            {step.value}
                        </span>
                        <span className={cn('text-xs', step.hintTone === 'danger' ? 'text-destructive font-medium' : 'text-muted-foreground')}>
                            {step.hint}
                        </span>
                    </Link>
                ))}
            </div>
        </section>
    );
}

/** What we owe suppliers: the payables ageing, summarised. Ledger readers only. */
function PayablesCard({ payables }: { payables: Payables }) {
    const shown = payables.buckets.filter((bucket) => bucket.amount !== '0.00');

    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    We owe suppliers
                    <Hint text="Bills minus payments minus credit notes, aged by due date. Same figure as the Payables ageing report." />
                </span>
            }
            aside={
                <span className="flex items-center gap-2">
                    <PermissionNote title="This card shows only to people who can read the ledger (Accounts)">Ledger readers</PermissionNote>
                    <Link href={payables.url} className="text-primary text-xs hover:underline">
                        Payables ageing
                    </Link>
                </span>
            }
        >
            <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
                <Stat label="Owed today, Tk" value={payables.total} size="lg" href={payables.url} />
                <span className={cn('text-xs font-medium', payables.ties_out ? 'text-primary' : 'text-destructive')}>
                    {payables.ties_out ? 'Ties out to the ledger' : 'Does not tie out to the ledger'}
                </span>
            </div>
            <Meter
                className="mt-3 h-2.5"
                label={payables.buckets.map((bucket) => `${bucket.label} ${bucket.amount}`).join(', ')}
                segments={payables.buckets.map((bucket) => ({ share: bucket.share, className: BUCKET_BAR[bucket.key] ?? 'bg-muted-foreground' }))}
            />
            <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                {(shown.length > 0 ? shown : payables.buckets.slice(0, 1)).map((bucket) => (
                    <Stat
                        key={bucket.key}
                        size="sm"
                        href={payables.url}
                        tone={bucket.key === 'current' || bucket.amount === '0.00' ? 'ok' : bucket.key === 'days_1_30' ? 'warning' : 'danger'}
                        label={
                            <>
                                <span aria-hidden="true" className={cn('inline-block size-2 rounded-full', BUCKET_BAR[bucket.key])} />
                                {bucket.label}
                            </>
                        }
                        value={bucket.amount}
                    />
                ))}
            </div>
            {payables.on_account !== '0.00' && (
                <p className="text-muted-foreground mt-2 text-xs">
                    Paid on account, not yet matched to a bill: <span className="tabular-nums">{payables.on_account}</span>
                </p>
            )}
            {payables.top.length > 0 && (
                <ul className="mt-3 grid gap-1.5 border-t pt-3 text-[13px]">
                    {payables.top.map((row) => (
                        <li key={row.id} className="flex justify-between gap-3">
                            <Link href={row.url} className="truncate hover:underline">
                                {row.name}
                            </Link>
                            <span className={cn('font-medium tabular-nums', row.overdue && 'text-destructive')}>{row.amount}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
