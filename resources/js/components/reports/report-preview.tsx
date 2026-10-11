import { SERIES } from '@/components/dashboards/palette';
import { useTranslation } from '@/lib/i18n';
import { useReducedMotion } from 'motion/react';
import { useMemo } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface PreviewColumn {
    key: string;
    label: string;
    type: string;
    align: string;
}

export interface PreviewCell {
    value: string | number | boolean | null;
    display: string;
}

export interface PreviewChart {
    type: 'bar' | 'stacked_bar';
    label: string;
    series: { key: string; label: string }[];
    title?: string;
    limit?: number;
}

export interface Preview {
    columns: PreviewColumn[];
    rows: Record<string, PreviewCell>[];
    row_count: number;
    shown: number;
    totals: Record<string, PreviewCell | null> | null;
    meta: { title: string; subtitle: string | null; note: string | null; stamp: string };
    chart: PreviewChart | null;
}

// The dashboards' validated series palette (light and dark), so a report
// chart and a dashboard chart read the same.
const SERIES_COLORS = SERIES;

const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 });

/**
 * A report on screen: a modest chart (when the report declares one) above the
 * table of exact figures, and the basis note under it.
 *
 * ⚠ The chart is drawn from the SAME rows as the table, grouped by its label
 * column (a report grouped three ways draws one bar per brand by summing its
 * rows). It never fetches anything of its own, so it cannot disagree with the
 * numbers beside it.
 */
export function ReportPreview({ preview }: { preview: Preview }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 className="text-sm font-semibold">{preview.meta.title}</h2>
                    {preview.meta.subtitle && <p className="text-muted-foreground text-xs">{preview.meta.subtitle}</p>}
                </div>
                <p className="text-muted-foreground text-xs">
                    {preview.shown < preview.row_count
                        ? t('reports.rows_capped', { shown: preview.shown, count: preview.row_count })
                        : t('reports.rows', { count: preview.row_count })}
                </p>
            </div>

            {preview.chart && preview.rows.length > 0 && <ReportChart chart={preview.chart} rows={preview.rows} />}

            {preview.rows.length === 0 ? (
                <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('reports.no_rows')}</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                {preview.columns.map((column) => (
                                    <th key={column.key} className={`px-3 py-2 text-xs font-medium whitespace-nowrap ${alignClass(column.align)}`}>
                                        {column.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {preview.rows.map((row, index) => (
                                <tr key={index} className="border-t">
                                    {preview.columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={`px-3 py-1.5 whitespace-nowrap ${alignClass(column.align)} ${isNegative(row[column.key]) ? 'text-destructive' : ''} ${column.align === 'right' ? 'tabular-nums' : ''}`}
                                        >
                                            {row[column.key]?.display ?? ''}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                        {preview.totals && (
                            <tfoot className="bg-muted/50 font-medium">
                                <tr className="border-t">
                                    {preview.columns.map((column, index) => (
                                        <td key={column.key} className={`px-3 py-2 whitespace-nowrap tabular-nums ${alignClass(column.align)}`}>
                                            {index === 0 ? t('reports.total') : (preview.totals?.[column.key]?.display ?? '')}
                                        </td>
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>
            )}

            {preview.meta.note && (
                <div className="bg-muted/40 rounded-md border p-3 text-xs leading-relaxed">
                    <p className="mb-1 font-medium">{t('reports.basis')}</p>
                    <p className="text-muted-foreground">{preview.meta.note}</p>
                </div>
            )}
        </div>
    );
}

function ReportChart({ chart, rows }: { chart: PreviewChart; rows: Record<string, PreviewCell>[] }) {
    const { t } = useTranslation();
    const reduce = useReducedMotion();

    const { points, cut } = useMemo(() => {
        const grouped = new Map<string, Record<string, string | number>>();

        rows.forEach((row) => {
            const label = String(row[chart.label]?.display ?? '');
            const point = grouped.get(label) ?? { label };

            chart.series.forEach((series) => {
                const value = Number(row[series.key]?.value ?? 0);
                point[series.key] = Number(point[series.key] ?? 0) + (Number.isFinite(value) ? value : 0);
            });

            grouped.set(label, point);
        });

        const all = [...grouped.values()];
        const limit = chart.limit ?? all.length;
        const first = chart.series[0]?.key;
        const sorted = limit < all.length && first ? [...all].sort((a, b) => Math.abs(Number(b[first])) - Math.abs(Number(a[first]))) : all;

        return { points: sorted.slice(0, limit), cut: limit < all.length ? limit : null };
    }, [chart, rows]);

    const stacked = chart.type === 'stacked_bar';

    return (
        <div className="rounded-md border p-3">
            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                {chart.title && <p className="text-xs font-medium">{chart.title}</p>}
                <p className="text-muted-foreground text-xs">
                    {cut !== null ? `${t('reports.chart_top', { count: cut })}. ` : ''}
                    {t('reports.chart_note')}
                </p>
            </div>
            <div className="h-56 w-full" role="img" aria-label={chart.title ?? chart.series.map((s) => s.label).join(', ')}>
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={points} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                        <XAxis
                            dataKey="label"
                            tickLine={false}
                            axisLine={false}
                            fontSize={11}
                            interval={0}
                            tickFormatter={(v: string) => (v.length > 16 ? `${v.slice(0, 15)}...` : v)}
                        />
                        <YAxis tickLine={false} axisLine={false} fontSize={11} width={56} tickFormatter={(v: number) => compact.format(v)} />
                        <Tooltip
                            formatter={(value) => full.format(Number(value))}
                            cursor={{ fill: 'var(--accent)' }}
                            contentStyle={{ fontSize: 12, borderRadius: 6, background: 'var(--popover)', borderColor: 'var(--border)' }}
                        />
                        {chart.series.length > 1 && <Legend wrapperStyle={{ fontSize: 11 }} />}
                        {chart.series.map((series, index) => (
                            <Bar
                                key={series.key}
                                dataKey={series.key}
                                name={series.label}
                                fill={SERIES_COLORS[index % SERIES_COLORS.length]}
                                stackId={stacked ? 'stack' : undefined}
                                radius={stacked ? 0 : [3, 3, 0, 0]}
                                isAnimationActive={!reduce}
                                animationDuration={500}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

function alignClass(align: string): string {
    return align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left';
}

function isNegative(cell: PreviewCell | undefined): boolean {
    return typeof cell?.value === 'number' && cell.value < 0;
}
