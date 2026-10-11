import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

// Courier hub slice 5: what each parcel was expected to cost (the ERP's
// estimate kept at booking), what the courier quoted when it took it, and what
// its statement charged. Three facts side by side; none overwrites another.
// Our COST only: the customer's delivery charge never appears here.

type Money3 = { delivery: number | null; cod: number | null; total: number | null };

interface Row {
    consignment_id: number;
    consignment_number: string;
    provider: string;
    booked_on: string | null;
    district: string | null;
    to_band: string | null;
    to_band_name: string | null;
    service: string;
    declared_grams: number | null;
    hub_grams: number | null;
    overridden: boolean;
    bracket: string | null;
    estimate: (Money3 & { method: string; version: string | null; confidence: string }) | null;
    quote: Money3 | null;
    actual: (Money3 & { gross: number; discount: number }) | null;
    excluded: string | null;
    excluded_label: string | null;
    estimate_diff: number | null;
    estimate_pct: number | null;
    estimate_outlier: boolean;
    quote_diff: number | null;
    quote_pct: number | null;
    quote_outlier: boolean;
}

interface Aggregate {
    count: number;
    mean_abs: number | null;
    mean_abs_pct: number | null;
    median_diff: number | null;
    median_pct: number | null;
    outliers: number;
}

interface Report {
    rows: Row[];
    summary: {
        parcels: number;
        clean: number;
        estimate: Aggregate;
        quote: Aggregate;
        excluded: { reason: string; label: string; count: number }[];
        unmatched_lines: number;
        min_samples: number;
    };
    by_band: { label: string; count: number; estimate_median_pct: number | null; quote_median_pct: number | null; actual_median: number | null }[];
    trend: { week: string; count: number; estimate_median_pct: number | null; quote_median_pct: number | null }[];
}

interface Filters {
    provider: string | null;
    band: string | null;
    bracket: string | null;
    from: string;
    to: string;
}

interface PageProps {
    filters: Filters;
    report: Report;
    providers: { code: string; name: string }[];
    bands: { provider: string; code: string; name: string }[];
    brackets: string[];
    cards: { provider: string; version: string | null }[];
    can_propose: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Courier fees', href: '/sales/courier-fees' },
];

const tk = (value: number | null | undefined) =>
    value === null || value === undefined ? '-' : value.toLocaleString('en-IN', { maximumFractionDigits: 2 });
const pct = (value: number | null | undefined) => (value === null || value === undefined ? '-' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`);

function selectClass() {
    return 'border-input bg-background h-9 rounded-md border px-2 text-sm';
}

function AggregateCard({ title, aggregate, note }: { title: string; aggregate: Aggregate; note: string }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-4">
            <p className="text-sm font-medium">{title}</p>
            <p className="text-muted-foreground text-xs">{note}</p>
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-muted-foreground text-xs">Parcels compared</dt>
                    <dd className="tabular-nums">{aggregate.count}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Average miss (Tk)</dt>
                    <dd className="tabular-nums">{tk(aggregate.mean_abs)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Average miss (%)</dt>
                    <dd className="tabular-nums">{aggregate.mean_abs_pct === null ? '-' : `${aggregate.mean_abs_pct.toFixed(1)}%`}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Median difference (Tk)</dt>
                    <dd className="tabular-nums">{tk(aggregate.median_diff)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Median difference (%)</dt>
                    <dd className="tabular-nums">{pct(aggregate.median_pct)}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground text-xs">Outliers (over 25%)</dt>
                    <dd className={cn('tabular-nums', aggregate.outliers > 0 && 'text-amber-700 dark:text-amber-400')}>{aggregate.outliers}</dd>
                </div>
            </dl>
        </div>
    );
}

export default function CourierFeesIndex({ filters, report, providers, bands, brackets, cards, can_propose }: PageProps) {
    const [form, setForm] = useState({
        provider: filters.provider ?? '',
        band: filters.band ?? '',
        bracket: filters.bracket ?? '',
        from: filters.from,
        to: filters.to,
    });
    const [proposing, setProposing] = useState<string | null>(null);

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== ''));
        router.get(route('sales.courier-fees.index'), query, { preserveState: true, preserveScroll: true });
    };

    const propose = (provider: string) => {
        setProposing(provider);
        router.post(route('couriers.ratecards.propose'), { provider }, { onFinish: () => setProposing(null) });
    };

    const bandOptions = bands.filter((b) => form.provider === '' || b.provider === form.provider);
    const { summary } = report;
    const small = summary.clean < summary.min_samples;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Courier fees" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Courier fees"
                    description="What each parcel was expected to cost, what the courier quoted when it took it, and what its statement charged (net fee plus COD fee). Our cost only: the customer's delivery charge is not part of this."
                    actions={
                        can_propose &&
                        providers.map((p) => (
                            <Button key={p.code} size="sm" variant="outline" disabled={proposing !== null} onClick={() => propose(p.code)}>
                                {proposing === p.code ? 'Proposing...' : `Propose a new ${p.name} card`}
                            </Button>
                        ))
                    }
                />

                <form
                    onSubmit={apply}
                    className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="fees-provider">Courier</Label>
                        <select
                            id="fees-provider"
                            className={selectClass()}
                            value={form.provider}
                            onChange={(e) => setForm({ ...form, provider: e.target.value, band: '' })}
                        >
                            <option value="">All couriers</option>
                            {providers.map((p) => (
                                <option key={p.code} value={p.code}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="fees-band">Band</Label>
                        <select
                            id="fees-band"
                            className={selectClass()}
                            value={form.band}
                            onChange={(e) => setForm({ ...form, band: e.target.value })}
                        >
                            <option value="">All bands</option>
                            {bandOptions.map((b) => (
                                <option key={`${b.provider}-${b.code}`} value={b.code}>
                                    {b.name}
                                    {form.provider === '' ? ` (${b.provider})` : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="fees-bracket">Weight</Label>
                        <select
                            id="fees-bracket"
                            className={selectClass()}
                            value={form.bracket}
                            onChange={(e) => setForm({ ...form, bracket: e.target.value })}
                        >
                            <option value="">Any weight</option>
                            {brackets.map((b) => (
                                <option key={b} value={b}>
                                    {b}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="fees-from">Booked from</Label>
                        <Input
                            id="fees-from"
                            type="date"
                            className="w-40"
                            value={form.from}
                            onChange={(e) => setForm({ ...form, from: e.target.value })}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="fees-to">to</Label>
                        <Input id="fees-to" type="date" className="w-40" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                    </div>
                    <Button size="sm">Show</Button>
                    <span className="text-muted-foreground text-xs">
                        Cards in force: {cards.map((c) => `${c.provider} ${c.version ?? 'none'}`).join(', ')}
                    </span>
                </form>

                <div className="text-sm">
                    <p>
                        <span className="font-medium tabular-nums">{summary.parcels}</span> parcels booked in the range,{' '}
                        <span className="font-medium tabular-nums">{summary.clean}</span> clean enough to learn from.
                        {summary.unmatched_lines > 0 && ` ${summary.unmatched_lines} statement lines matched no parcel of ours and are left out.`}
                    </p>
                    {summary.excluded.length > 0 && (
                        <p className="text-muted-foreground text-xs">Left out: {summary.excluded.map((e) => `${e.label} ${e.count}`).join(', ')}.</p>
                    )}
                    {small && (
                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                            Fewer than {summary.min_samples} clean parcels: read every figure below as a hint, not a measurement.
                        </p>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <AggregateCard
                        title="ERP estimate vs statement"
                        aggregate={summary.estimate}
                        note="Estimate kept at booking, against net fee plus COD fee."
                    />
                    <AggregateCard
                        title="Courier quote vs statement"
                        aggregate={summary.quote}
                        note="What the courier said when it took the parcel."
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-4">
                        <p className="text-sm font-medium">Median difference by band</p>
                        <p className="text-muted-foreground text-xs">Above zero: we expected more than the courier charged.</p>
                        {report.by_band.length === 0 ? (
                            <p className="text-muted-foreground mt-6 text-sm">No clean parcels to compare yet.</p>
                        ) : (
                            <div className="mt-3 h-64" role="img" aria-label="Median percent difference by destination band">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={report.by_band} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                                        <YAxis unit="%" tick={{ fontSize: 11 }} width={44} />
                                        <Tooltip formatter={(value) => pct(typeof value === 'number' ? value : null)} />
                                        <Legend wrapperStyle={{ fontSize: 12 }} />
                                        <ReferenceLine y={0} stroke="var(--muted-foreground)" />
                                        <Bar dataKey="estimate_median_pct" name="ERP estimate" fill="var(--chart-1)" radius={[3, 3, 0, 0]} />
                                        <Bar dataKey="quote_median_pct" name="Courier quote" fill="var(--chart-4)" radius={[3, 3, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-4">
                        <p className="text-sm font-medium">Median difference by week booked</p>
                        <p className="text-muted-foreground text-xs">Should move toward zero as cards are calibrated.</p>
                        {report.trend.length === 0 ? (
                            <p className="text-muted-foreground mt-6 text-sm">No clean parcels to compare yet.</p>
                        ) : (
                            <div className="mt-3 h-64" role="img" aria-label="Median percent difference by week">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={report.trend} margin={{ top: 8, right: 8, bottom: 8, left: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="week" tick={{ fontSize: 11 }} />
                                        <YAxis unit="%" tick={{ fontSize: 11 }} width={44} />
                                        <Tooltip formatter={(value) => pct(typeof value === 'number' ? value : null)} />
                                        <Legend wrapperStyle={{ fontSize: 12 }} />
                                        <ReferenceLine y={0} stroke="var(--muted-foreground)" />
                                        <Line
                                            type="monotone"
                                            dataKey="estimate_median_pct"
                                            name="ERP estimate"
                                            stroke="var(--chart-1)"
                                            strokeWidth={2}
                                            dot
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="quote_median_pct"
                                            name="Courier quote"
                                            stroke="var(--chart-4)"
                                            strokeWidth={2}
                                            dot
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>
                </div>

                {report.by_band.length > 0 && (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-2 pr-3">Band</th>
                                    <th className="py-2 pr-3 text-right">Clean parcels</th>
                                    <th className="py-2 pr-3 text-right">Median charged (Tk)</th>
                                    <th className="py-2 pr-3 text-right">Estimate median</th>
                                    <th className="py-2 pr-3 text-right">Quote median</th>
                                </tr>
                            </thead>
                            <tbody>
                                {report.by_band.map((b) => (
                                    <tr key={b.label} className="border-t">
                                        <td className="py-1.5 pr-3">{b.label}</td>
                                        <td
                                            className={cn(
                                                'py-1.5 pr-3 text-right tabular-nums',
                                                b.count < summary.min_samples && 'text-amber-700 dark:text-amber-400',
                                            )}
                                        >
                                            {b.count}
                                        </td>
                                        <td className="py-1.5 pr-3 text-right tabular-nums">{tk(b.actual_median)}</td>
                                        <td className="py-1.5 pr-3 text-right tabular-nums">{pct(b.estimate_median_pct)}</td>
                                        <td className="py-1.5 pr-3 text-right tabular-nums">{pct(b.quote_median_pct)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs">
                            <tr>
                                <th className="py-2 pr-3">Parcel</th>
                                <th className="py-2 pr-3">Booked</th>
                                <th className="py-2 pr-3">Courier / band</th>
                                <th className="py-2 pr-3 text-right">Weight (g)</th>
                                <th className="py-2 pr-3 text-right">Estimate</th>
                                <th className="py-2 pr-3 text-right">Quote</th>
                                <th className="py-2 pr-3 text-right">Charged</th>
                                <th className="py-2 pr-3 text-right">Est. diff</th>
                                <th className="py-2 pr-3 text-right">Quote diff</th>
                                <th className="py-2 pr-3">Learning</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.rows.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="text-muted-foreground py-6 text-center">
                                        No parcels booked in this range.
                                    </td>
                                </tr>
                            )}
                            {report.rows.map((row) => (
                                <tr key={row.consignment_id} className={cn('border-t', row.excluded && 'text-muted-foreground')}>
                                    <td className="py-1.5 pr-3 font-mono text-xs">{row.consignment_number}</td>
                                    <td className="py-1.5 pr-3 tabular-nums">{row.booked_on}</td>
                                    <td className="py-1.5 pr-3">
                                        {row.provider} / {row.to_band_name ?? row.to_band ?? '-'}
                                        {row.district ? `, ${row.district}` : ''}
                                    </td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                        {row.declared_grams?.toLocaleString('en-IN') ?? '-'}
                                        {row.hub_grams !== null && row.hub_grams !== row.declared_grams && (
                                            <span className="block text-xs">hub {row.hub_grams.toLocaleString('en-IN')}</span>
                                        )}
                                        {row.overridden && <span className="block text-xs">typed by hand</span>}
                                    </td>
                                    <td
                                        className="py-1.5 pr-3 text-right tabular-nums"
                                        title={row.estimate ? `${row.estimate.method} ${row.estimate.version ?? ''}` : ''}
                                    >
                                        {tk(row.estimate?.total)}
                                    </td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">{tk(row.quote?.total)}</td>
                                    <td className="py-1.5 pr-3 text-right tabular-nums">
                                        {tk(row.actual?.total)}
                                        {row.actual && row.actual.discount > 0 && (
                                            <span className="block text-xs">less {tk(row.actual.discount)} discount</span>
                                        )}
                                    </td>
                                    <td
                                        className={cn(
                                            'py-1.5 pr-3 text-right tabular-nums',
                                            row.estimate_outlier && 'font-medium text-amber-700 dark:text-amber-400',
                                        )}
                                    >
                                        {pct(row.estimate_pct)}
                                    </td>
                                    <td
                                        className={cn(
                                            'py-1.5 pr-3 text-right tabular-nums',
                                            row.quote_outlier && 'font-medium text-amber-700 dark:text-amber-400',
                                        )}
                                    >
                                        {pct(row.quote_pct)}
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        {row.excluded ? (
                                            <span className="text-xs">{row.excluded_label}</span>
                                        ) : (
                                            <Badge variant="outline">{row.estimate_outlier || row.quote_outlier ? 'Outlier' : 'Clean'}</Badge>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground text-xs">
                    Prices behind the estimates:{' '}
                    <Link href={route('couriers.ratecards.index')} className="underline">
                        Courier rate cards
                    </Link>
                    .
                </p>
            </div>
        </AppLayout>
    );
}
