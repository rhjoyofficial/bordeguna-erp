import { Chip, Hint, Meter, money0, money2, Panel, Pill, Stat, type Tone } from '@/components/sales/sales-ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { type ReactNode } from 'react';

/*
 * The Sales overview (module redesign, 15 Oct 2026): today's numbers, where
 * every sale stands, what needs somebody, parcels by the courier's word,
 * money owed, orders by state, top products, statements to post and the
 * courier connections. Every card is a role-dashboard widget's own payload
 * (SalesOverviewController), sent only with that widget's permission, so a
 * card the person may not see is simply absent and the page renders with
 * any subset. Each number opens the list behind it.
 */

type WidgetTone = 'good' | 'warn' | 'bad' | null | undefined;

interface BreakdownItem {
    label: string;
    value: number;
    tone?: WidgetTone;
    url?: string;
    hint?: string;
    key?: string;
    count?: number;
}

interface Part {
    label: string;
    value: number | string | null;
    unit?: string;
    tone?: WidgetTone;
}

interface Breakdown {
    kind: 'breakdown';
    unit: 'money' | 'count';
    items: BreakdownItem[];
    total?: number;
    parts?: Part[];
    caption?: string | null;
    drill_down?: string | null;
}

interface StatPayload {
    kind: 'stat';
    value: number;
    parts?: Part[];
    drill_down?: string | null;
}

interface Row {
    id: number | string;
    url?: string;
    tone?: WidgetTone;
    cell_tones?: Record<string, WidgetTone>;
    cells: Record<string, string | number | null>;
}

interface ListPayload {
    kind: 'list';
    rows: Row[];
    total_count?: number;
    drill_down?: string | null;
}

interface SeriesPayload {
    kind: 'series';
    points: ({ date: string; label: string } & Record<string, number | string>)[];
    total?: number;
    drill_down?: string | null;
}

interface EmptyPayload {
    kind: 'empty';
    message: string;
    drill_down?: string | null;
}

interface ErrorPayload {
    kind: 'error';
    message: string;
}

type Payload = Breakdown | StatPayload | ListPayload | SeriesPayload | EmptyPayload | ErrorPayload;

interface Invoiced {
    net: number;
    gross: number;
    returned: number;
    invoices: number;
    channels: { key: string; label: string; net: number; invoices: number }[];
    brands: { id: number | null; label: string; net: number; invoices: number }[];
}

interface Stage {
    key: string;
    label: string;
    value: number;
    unit: string;
    note: string | null;
    tone: 'warning' | 'danger' | null;
    url: string;
}

interface AttentionRow {
    key: string;
    title: string;
    hint: string;
    count: number;
    items: number;
    tone: Tone;
    url: string;
}

interface Till {
    id: number;
    till: string | null;
    opened_by: string | null;
    opened_at: string;
}

interface Props {
    today: string;
    range: { preset: string; from: string; to: string; label: string; query: Record<string, string> };
    range_presets: { value: string; label: string }[];
    range_error: string | null;
    brand: { selected: number | null; options: { id: number; name: string }[] };
    can: {
        sales: boolean;
        sell: boolean;
        orders: boolean;
        parcels: boolean;
        book: boolean;
        books: boolean;
        receivables: boolean;
        courier_settings: boolean;
    };
    today_strip: {
        invoiced: Invoiced | null;
        orders_in: number | null;
        delivered: number | null;
        came_back: number | null;
        received: number | null;
        till: Till[] | null;
    };
    flow: Stage[] | null;
    invoiced: Invoiced | null;
    income_trend: Payload | null;
    attention: { total: number; rows: AttentionRow[]; failed: string[] } | null;
    parcels: Payload | null;
    money: { receivables: Payload | null; in_transit: Payload | null; courier_balances: Payload | null };
    orders: Payload | null;
    top_products: Payload | null;
    statements: Payload | null;
    connections: Payload | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales', href: '/sales/overview' }];

const CHANNEL_BAR: Record<string, string> = { website: 'bg-primary', internal: 'bg-sky-500', counter: 'bg-amber-500' };
const BRAND_BAR = ['bg-primary', 'bg-sky-500', 'bg-amber-500', 'bg-violet-500', 'bg-muted-foreground/50'];

const TONE_OF: Record<string, Tone> = { good: 'ok', warn: 'warning', bad: 'danger' };
const BAR_OF: Record<string, string> = { good: 'bg-primary', warn: 'bg-amber-500', bad: 'bg-destructive' };
const TEXT_OF: Record<string, string> = { warn: 'text-amber-700 dark:text-amber-400', bad: 'text-destructive' };
const NEED_BADGE: Record<Tone, string> = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
    muted: 'bg-muted text-muted-foreground',
    ok: 'bg-primary/10 text-primary',
};

function longDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function toneOf(tone: WidgetTone): Tone {
    return tone ? TONE_OF[tone] : 'ok';
}

/** The quiet states every card shares: could not be read, nothing to show. */
function WidgetState({ payload }: { payload: EmptyPayload | ErrorPayload }) {
    return <p className={cn('mt-3 text-sm', payload.kind === 'error' ? 'text-destructive' : 'text-muted-foreground')}>{payload.message}</p>;
}

function More({ href, children }: { href: string | null | undefined; children: ReactNode }) {
    if (!href) return null;

    return (
        <Link href={href} className="text-primary inline-flex items-center gap-0.5 text-xs underline-offset-4 hover:underline">
            {children}
            <ChevronRight className="size-3.5" />
        </Link>
    );
}

/** One horizontal bar per item, the longest at full width. */
function Bars({ items, unit, max }: { items: BreakdownItem[]; unit: 'money' | 'count'; max: number }) {
    return (
        <div className="mt-3 flex flex-col gap-2">
            {items.map((item) => {
                const body = (
                    <>
                        <span className="truncate text-[12.5px]">{item.label}</span>
                        <span className="bg-muted h-2 overflow-hidden rounded-full">
                            <span
                                className={cn('block h-full rounded-full', item.tone ? BAR_OF[item.tone] : 'bg-sky-500')}
                                style={{ width: `${max > 0 ? (item.value / max) * 100 : 0}%` }}
                            />
                        </span>
                        <span className={cn('text-right text-[12.5px] font-semibold tabular-nums', item.tone && TEXT_OF[item.tone])}>
                            {unit === 'money' ? money0(item.value) : item.value}
                        </span>
                    </>
                );
                const className = 'grid grid-cols-[minmax(0,10rem)_1fr_4.5rem] items-center gap-2.5';

                return item.url ? (
                    <Link key={item.label} href={item.url} className={cn(className, 'rounded-md hover:underline')}>
                        {body}
                    </Link>
                ) : (
                    <div key={item.label} className={className}>
                        {body}
                    </div>
                );
            })}
        </div>
    );
}

export default function SalesOverview({
    today,
    range,
    range_presets,
    range_error,
    brand,
    can,
    today_strip,
    flow,
    invoiced,
    income_trend,
    attention,
    parcels,
    money,
    orders,
    top_products,
    statements,
    connections,
}: Props) {
    const go = (next: Record<string, string | null>) => {
        const query = { ...range.query, ...next };
        for (const key of Object.keys(query)) if (query[key] === null) delete query[key];
        router.get('/sales/overview', query as Record<string, string>, { preserveScroll: true, preserveState: true });
    };

    const invoicedToday = today_strip.invoiced;
    const channelMax = invoiced ? Math.max(...invoiced.channels.map((c) => c.net), 0) : 0;
    const brandTotal = invoiced ? invoiced.brands.reduce((sum, b) => sum + Math.max(b.net, 0), 0) : 0;
    const trend = income_trend && income_trend.kind === 'series' ? income_trend : null;
    const trendMax = trend ? Math.max(...trend.points.map((p) => Number(p.income ?? 0)), 0) : 0;
    const receivables = money.receivables && money.receivables.kind === 'breakdown' ? money.receivables : null;
    const receivablesTotal = receivables ? receivables.items.reduce((sum, item) => sum + Math.max(item.value, 0), 0) : 0;
    const inTransit = money.in_transit && money.in_transit.kind === 'stat' ? money.in_transit : null;
    const balances = money.courier_balances && money.courier_balances.kind === 'list' ? money.courier_balances : null;
    const drafts = statements && statements.kind === 'list' ? statements : null;
    const showMoney = money.receivables || money.in_transit || money.courier_balances || drafts;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Sales overview" />
            <div className="flex flex-col gap-4 p-4">
                {/* Date, range and brand chips, the two doors into selling. */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs">{longDate(today)}</p>
                        <p className="text-muted-foreground mt-0.5 text-sm">
                            Range <span className="text-foreground font-medium">{range.label}</span>
                            {brand.selected !== null && (
                                <>
                                    {' '}
                                    · <span className="text-foreground font-medium">{brand.options.find((b) => b.id === brand.selected)?.name}</span>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {range_presets.map((preset) => (
                            <Chip
                                key={preset.value}
                                active={range.preset === preset.value}
                                onClick={() => go({ range: preset.value, from: null, to: null })}
                            >
                                {preset.label}
                            </Chip>
                        ))}
                        {brand.options.length > 0 && (
                            <>
                                <span className="bg-border mx-1 hidden h-5 w-px sm:block" aria-hidden="true" />
                                <Chip active={brand.selected === null} onClick={() => go({ brand: null })}>
                                    All brands
                                </Chip>
                                {brand.options.map((option) => (
                                    <Chip key={option.id} active={brand.selected === option.id} onClick={() => go({ brand: String(option.id) })}>
                                        {option.name}
                                    </Chip>
                                ))}
                            </>
                        )}
                        {can.sell && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/sales/counter">Counter sale</Link>
                            </Button>
                        )}
                        {can.orders && (
                            <Button size="sm" asChild>
                                <Link href="/sales/orders?new=1">New order</Link>
                            </Button>
                        )}
                    </div>
                </div>
                {range_error && <p className="text-destructive text-sm">{range_error}</p>}

                {/* Today strip */}
                <section className="bg-card text-card-foreground flex flex-wrap items-end gap-x-10 gap-y-4 rounded-xl border px-5 py-4">
                    {invoicedToday && (
                        <div>
                            <Stat
                                label="Invoiced today, ex-VAT"
                                hint="Invoices dated today, after discounts, before VAT, less returns dated today. The same sum the Sales by channel report shows for today."
                                value={money0(invoicedToday.net)}
                                size="lg"
                                href="/hrm/reports/sales_by_channel"
                            />
                            <p className="text-muted-foreground mt-0.5 text-xs tabular-nums">
                                {invoicedToday.invoices} {invoicedToday.invoices === 1 ? 'invoice' : 'invoices'}
                                {invoicedToday.channels.map((channel) => (
                                    <span key={channel.key}>
                                        {' '}
                                        · {channel.label.replace(/ \(.*\)$/, '')} {money0(channel.net)}
                                    </span>
                                ))}
                            </p>
                        </div>
                    )}
                    <div className="flex flex-wrap gap-x-8 gap-y-3">
                        {today_strip.orders_in !== null && <Stat label="Orders in today" value={today_strip.orders_in} href="/sales/orders" />}
                        {today_strip.delivered !== null && (
                            <Stat
                                label="Parcels delivered today"
                                value={today_strip.delivered}
                                tone={today_strip.delivered > 0 ? 'ok' : 'muted'}
                                href="/sales/consignments"
                            />
                        )}
                        {today_strip.came_back !== null && (
                            <Stat
                                label="Came back today"
                                value={today_strip.came_back}
                                tone={today_strip.came_back > 0 ? 'warning' : 'muted'}
                                href="/sales/consignments"
                            />
                        )}
                        {today_strip.received !== null && (
                            <Stat
                                label="Received today"
                                hint="Money in through cash, bank and wallets today, by journal date. Posted journals only."
                                value={money0(today_strip.received)}
                                href="/sales/receipts"
                            />
                        )}
                    </div>
                    {today_strip.till !== null && (
                        <p className="text-muted-foreground ml-auto self-center text-xs">
                            {today_strip.till.length === 0
                                ? 'No till open.'
                                : today_strip.till
                                      .map((shift) => `${shift.till ?? 'Till'} open since ${shift.opened_at} (${shift.opened_by ?? 'cashier'})`)
                                      .join(' · ')}{' '}
                            <Link href="/sales/shifts" className="text-primary underline-offset-4 hover:underline">
                                Cashier shifts
                            </Link>
                        </p>
                    )}
                </section>

                {/* The flow */}
                {flow && (
                    <Panel
                        title={
                            <span className="inline-flex items-center gap-1.5">
                                Where every sale stands
                                <Hint text="One order moves left to right: approved, booked with a courier, delivered, paid out by the courier. Each box is the queue waiting at that step, as at now." />
                            </span>
                        }
                        aside={<span className="text-muted-foreground text-xs">Open queues, as at now</span>}
                    >
                        <div className="mt-3 flex flex-wrap gap-2.5">
                            {flow.map((stage, index) => (
                                <Link
                                    key={stage.key}
                                    href={stage.url}
                                    className={cn(
                                        'bg-card hover:bg-muted/40 min-w-40 flex-1 rounded-lg border px-3.5 py-3',
                                        stage.tone === 'danger' && 'border-destructive/40',
                                    )}
                                >
                                    <p className="text-muted-foreground text-xs">
                                        {index + 1} · {stage.label}
                                    </p>
                                    <p
                                        className={cn(
                                            'mt-1 text-2xl font-semibold tabular-nums',
                                            stage.tone === 'danger' && 'text-destructive',
                                            stage.tone === 'warning' && 'text-amber-700 dark:text-amber-400',
                                        )}
                                    >
                                        {stage.value} <span className="text-muted-foreground text-xs font-normal">{stage.unit}</span>
                                    </p>
                                    {stage.note && <p className="text-muted-foreground text-xs tabular-nums">{stage.note}</p>}
                                </Link>
                            ))}
                        </div>
                    </Panel>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Invoiced by channel and brand, in the range */}
                    {invoiced && (
                        <Panel
                            className="min-w-0 lg:col-span-2"
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Invoiced, {range.label.toLowerCase()}
                                    <Hint text="Invoice date, ex-VAT, after discounts, net of returns dated in the range. Brand and channel are the order's; a sale with no order behind it is Counter with no brand." />
                                </span>
                            }
                            aside={<More href="/hrm/reports/sales_by_channel">Sales by channel</More>}
                        >
                            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                                <Stat label="invoiced" value={money0(invoiced.net)} />
                                <Stat label="invoices" value={invoiced.invoices} />
                                <Stat label="returned" value={money0(invoiced.returned)} tone={invoiced.returned > 0 ? 'warning' : 'muted'} />
                            </div>
                            <div className="mt-4 grid gap-5 md:grid-cols-2">
                                <div>
                                    <p className="text-muted-foreground text-xs">By channel</p>
                                    {invoiced.channels.length === 0 ? (
                                        <p className="text-muted-foreground mt-2 text-sm">Nothing invoiced in the range.</p>
                                    ) : (
                                        <div className="mt-2 flex flex-col gap-2">
                                            {invoiced.channels.map((channel) => (
                                                <div key={channel.key} className="grid grid-cols-[minmax(0,10rem)_1fr_5.5rem] items-center gap-2.5">
                                                    <span className="truncate text-[12.5px]">{channel.label}</span>
                                                    <span className="bg-muted h-2 overflow-hidden rounded-full">
                                                        <span
                                                            className={cn(
                                                                'block h-full rounded-full',
                                                                CHANNEL_BAR[channel.key] ?? 'bg-muted-foreground/50',
                                                            )}
                                                            style={{
                                                                width: `${channelMax > 0 ? (Math.max(channel.net, 0) / channelMax) * 100 : 0}%`,
                                                            }}
                                                        />
                                                    </span>
                                                    <span className="text-right text-[12.5px] font-semibold tabular-nums">{money0(channel.net)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <p className="text-muted-foreground text-xs">By brand</p>
                                    {invoiced.brands.length === 0 ? (
                                        <p className="text-muted-foreground mt-2 text-sm">Nothing invoiced in the range.</p>
                                    ) : (
                                        <>
                                            <Meter
                                                className="mt-2"
                                                label={invoiced.brands.map((b) => `${b.label} ${money0(b.net)}`).join('; ')}
                                                segments={invoiced.brands.map((b, index) => ({
                                                    share: brandTotal > 0 ? (Math.max(b.net, 0) / brandTotal) * 100 : 0,
                                                    className: BRAND_BAR[index % BRAND_BAR.length],
                                                }))}
                                            />
                                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                                {invoiced.brands.map((b, index) => (
                                                    <span key={b.label} className="inline-flex items-center gap-1.5 tabular-nums">
                                                        <span
                                                            aria-hidden="true"
                                                            className={cn('inline-block size-2 rounded-sm', BRAND_BAR[index % BRAND_BAR.length])}
                                                        />
                                                        {b.label} <span className="font-semibold">{money0(b.net)}</span>
                                                        <span className="text-muted-foreground">({b.invoices})</span>
                                                    </span>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                            {trend && trend.points.length > 1 && (
                                <div className="mt-5 border-t pt-4">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                                            Posted income by day
                                            <Hint text="Sales income by journal date from the ledger (ex-VAT, net of returns and discounts), the whole business. Posted journals only." />
                                        </p>
                                        <More href={trend.drill_down}>Profit and loss</More>
                                    </div>
                                    <div className="mt-2 overflow-x-auto">
                                        <div className="flex h-28 min-w-[32rem] items-end gap-1 border-b pb-1">
                                            {trend.points.map((point) => (
                                                <div
                                                    key={point.date}
                                                    title={`${point.label}: ${money2(Number(point.income ?? 0))}`}
                                                    className="flex h-full flex-1 flex-col justify-end"
                                                >
                                                    <div
                                                        className="bg-primary rounded-t-sm"
                                                        style={{
                                                            height: `${trendMax > 0 ? (Math.max(Number(point.income ?? 0), 0) / trendMax) * 100 : 0}%`,
                                                        }}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                        <div className="mt-1 flex min-w-[32rem] gap-1">
                                            {trend.points.map((point, index) => (
                                                <div key={point.date} className="text-muted-foreground flex-1 text-center text-[10.5px]">
                                                    {index % Math.max(1, Math.ceil(trend.points.length / 10)) === 0 ? point.label : ''}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </Panel>
                    )}

                    {/* Needs attention */}
                    {attention && (
                        <Panel title="Needs attention" aside={<More href="/attention">All {attention.total}</More>} flush>
                            {attention.rows.length === 0 && attention.failed.length === 0 && (
                                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing on the courier side is waiting on anybody.</p>
                            )}
                            {attention.failed.map((area) => (
                                <p key={area} className="text-destructive px-4 pb-3 text-sm">
                                    The {area} list could not be read.
                                </p>
                            ))}
                            <ul>
                                {attention.rows.map((row) => (
                                    <li key={row.key} className="border-t">
                                        <Link href={row.url} className="hover:bg-muted/40 flex items-center gap-3 px-4 py-2.5">
                                            <span
                                                className={cn(
                                                    'grid size-7 shrink-0 place-items-center rounded-md text-[13px] font-semibold tabular-nums',
                                                    NEED_BADGE[row.tone],
                                                )}
                                            >
                                                {row.count}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[13.5px] font-medium">{row.title}</span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {row.hint}
                                                    {row.items > 1 && ` (+${row.items - 1} more)`}
                                                </span>
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    {/* Parcels by courier status */}
                    {parcels && (
                        <Panel
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Parcels by courier status
                                    <Hint text="The courier's own word for each open parcel, as at now. Delivered and returned are counted by the date the courier said it, in the range." />
                                </span>
                            }
                            aside={<More href="/sales/consignments">Parcels</More>}
                        >
                            {parcels.kind === 'breakdown' ? (
                                <Bars items={parcels.items} unit="count" max={Math.max(...parcels.items.map((i) => i.value), 0)} />
                            ) : (
                                <WidgetState payload={parcels as EmptyPayload | ErrorPayload} />
                            )}
                        </Panel>
                    )}

                    {/* Money owed */}
                    {showMoney && (
                        <Panel
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Money owed to us
                                    <Hint text="Receivables from posted invoices as at today, by how overdue. With couriers = cash on delivery collected and not paid out (1145); with the gateway = online payments not settled (1146). Posted journals only." />
                                </span>
                            }
                            aside={can.receivables ? <More href="/accounting/receivables-ageing">Receivables</More> : undefined}
                        >
                            {receivables && (
                                <>
                                    <div className="mt-2 flex flex-wrap items-end justify-between gap-2">
                                        <Stat
                                            label="From customers"
                                            value={money0(receivables.total ?? receivablesTotal)}
                                            size="lg"
                                            href="/accounting/receivables-ageing"
                                        />
                                        {receivables.parts?.map((part) => (
                                            <Stat
                                                key={part.label}
                                                label={part.label}
                                                value={money0(Number(part.value ?? 0))}
                                                tone={part.label === 'Overdue' && Number(part.value) > 0 ? 'danger' : 'ok'}
                                                size="sm"
                                            />
                                        ))}
                                    </div>
                                    <Meter
                                        className="mt-2"
                                        label={receivables.items.map((i) => `${i.label} ${money0(i.value)}`).join('; ')}
                                        segments={receivables.items.map((item) => ({
                                            share: receivablesTotal > 0 ? (Math.max(item.value, 0) / receivablesTotal) * 100 : 0,
                                            className: item.tone === 'bad' ? 'bg-destructive' : item.tone === 'warn' ? 'bg-amber-500' : 'bg-primary',
                                        }))}
                                    />
                                    <div className="text-muted-foreground mt-1.5 flex flex-wrap justify-between gap-x-3 gap-y-1 text-[11.5px] tabular-nums">
                                        {receivables.items.map((item) => (
                                            <span key={item.label}>
                                                {item.label} {money0(item.value)}
                                            </span>
                                        ))}
                                    </div>
                                    {receivables.caption && <p className="text-destructive mt-2 text-xs">{receivables.caption}</p>}
                                </>
                            )}
                            {money.receivables && money.receivables.kind !== 'breakdown' && (
                                <WidgetState payload={money.receivables as EmptyPayload | ErrorPayload} />
                            )}
                            {(inTransit || balances || drafts) && (
                                <div className={cn('grid gap-3 sm:grid-cols-3', receivables && 'mt-4 border-t pt-3')}>
                                    {inTransit?.parts?.map((part) => (
                                        <div key={part.label}>
                                            <Stat
                                                label={part.label}
                                                value={money0(Number(part.value ?? 0))}
                                                size="sm"
                                                href={inTransit.drill_down ?? undefined}
                                            />
                                            {part.label === 'With couriers' && balances && (
                                                <p className="text-muted-foreground mt-0.5 text-xs tabular-nums">
                                                    {balances.rows
                                                        .map((row) => `${row.cells.courier} ${money0(Number(row.cells.with_courier ?? 0))}`)
                                                        .join(' · ')}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                    {drafts && (
                                        <div>
                                            <Stat
                                                label="Statements to post"
                                                value={drafts.total_count ?? drafts.rows.length}
                                                tone={(drafts.total_count ?? 0) > 0 ? 'warning' : 'muted'}
                                                size="sm"
                                                href="/sales/courier-statements"
                                            />
                                            <p className="text-muted-foreground mt-0.5 text-xs tabular-nums">
                                                {drafts.rows.reduce((sum, row) => sum + Number(row.cells.lines ?? 0), 0)} parcels in draft
                                            </p>
                                        </div>
                                    )}
                                </div>
                            )}
                        </Panel>
                    )}

                    {/* Orders by state */}
                    {orders && (
                        <Panel
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Orders by state
                                    <Hint text="Open queues as at now; delivered and cancelled by order date in the range. Orders in the ERP only, both typed in and synced from the shops." />
                                </span>
                            }
                            aside={<More href="/sales/orders">Orders</More>}
                        >
                            {orders.kind === 'breakdown' ? (
                                <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    {orders.items.map((item) => (
                                        <Stat
                                            key={item.label}
                                            label={item.label}
                                            value={item.value}
                                            tone={item.value > 0 ? toneOf(item.tone) : 'muted'}
                                            href={item.url}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <WidgetState payload={orders as EmptyPayload | ErrorPayload} />
                            )}
                        </Panel>
                    )}

                    {/* Top products */}
                    {top_products && (
                        <Panel
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Top products, {range.label.toLowerCase()}
                                    <Hint text="Invoiced value per product, line totals after discounts, before VAT. Opening invoices excluded." />
                                </span>
                            }
                            aside={<More href="/sales/invoices">Invoices</More>}
                        >
                            {top_products.kind === 'breakdown' && top_products.items.length > 0 ? (
                                <Bars
                                    items={top_products.items.map((i) => ({ ...i, tone: 'good' }))}
                                    unit="money"
                                    max={Math.max(...top_products.items.map((i) => i.value), 0)}
                                />
                            ) : top_products.kind === 'breakdown' ? (
                                <p className="text-muted-foreground mt-3 text-sm">Nothing invoiced in the range.</p>
                            ) : (
                                <WidgetState payload={top_products as EmptyPayload | ErrorPayload} />
                            )}
                        </Panel>
                    )}

                    {/* Courier statements to post, and the connections */}
                    {(statements || connections) && (
                        <Panel
                            title={
                                <span className="inline-flex items-center gap-1.5">
                                    Courier statements to post
                                    <Hint text="Imported from a courier and still in draft, so the payout is not in the books yet." />
                                </span>
                            }
                            aside={statements ? <More href="/sales/courier-statements">Courier payouts</More> : undefined}
                            flush
                        >
                            {drafts && drafts.rows.length === 0 && (
                                <p className="text-muted-foreground px-4 pb-3 text-sm">Every imported statement is posted.</p>
                            )}
                            {statements && statements.kind !== 'list' && (
                                <div className="px-4 pb-3">
                                    <WidgetState payload={statements as EmptyPayload | ErrorPayload} />
                                </div>
                            )}
                            {drafts && drafts.rows.length > 0 && (
                                <ul>
                                    {drafts.rows.map((row) => (
                                        <li key={row.id} className="border-t">
                                            <Link
                                                href={row.url ?? '/sales/courier-statements'}
                                                className="hover:bg-muted/40 flex items-center gap-3 px-4 py-2.5"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-[13.5px] font-medium">
                                                        {row.cells.courier} · {row.cells.reference}
                                                    </span>
                                                    <span className="text-muted-foreground block text-xs tabular-nums">
                                                        {row.cells.lines} parcels · {row.cells.date}
                                                    </span>
                                                </span>
                                                <Pill tone="warning">Draft</Pill>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {connections && (
                                <div className="border-t px-4 pt-3 pb-4">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <span className="text-[13.5px] font-semibold">Courier connections</span>
                                        {can.courier_settings && <More href="/settings/couriers">Settings</More>}
                                    </div>
                                    {connections.kind === 'list' ? (
                                        <ul className="mt-2 flex flex-col gap-1.5 text-[12.5px]">
                                            {connections.rows.map((row) => (
                                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2">
                                                    <span>{row.cells.account}</span>
                                                    <span className="inline-flex items-center gap-2">
                                                        <Pill tone={row.tone === 'bad' ? 'danger' : row.tone === 'warn' ? 'warning' : 'ok'}>
                                                            {row.cells.state}
                                                        </Pill>
                                                        <Pill tone={row.cell_tones?.webhook === 'bad' ? 'danger' : 'muted'}>{row.cells.webhook}</Pill>
                                                        <span className="text-muted-foreground text-xs">
                                                            pushed {String(row.cells.last_webhook ?? '').toLowerCase()}
                                                        </span>
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <WidgetState payload={connections as EmptyPayload | ErrorPayload} />
                                    )}
                                </div>
                            )}
                        </Panel>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
