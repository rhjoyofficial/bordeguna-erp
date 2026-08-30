/**
 * Hand-rolled SVG charts.
 *
 * ⚠⚠ WHY THERE IS NO CHARTING LIBRARY HERE. This codebase had none, and adding
 * recharts or chart.js for four panels means a dependency in every future
 * upgrade, a bundle several times the size of these files, and a second theming
 * system that has to be taught the application's colours. The same reasoning
 * produced the hand-rolled Code 128 barcode printer, and it held.
 *
 * ⚠ Everything here draws with `currentColor` or a CSS variable, so a chart is
 * correct in dark mode without knowing dark mode exists. No colour is hard-coded
 * except the deliberate money-in and money-out pair, which must stay distinct
 * from each other rather than from the theme.
 *
 * ⚠⚠ EVERY CHART RENDERS AN EMPTY STATE RATHER THAN AN EMPTY BOX. Production has
 * no journals and no invoices yet, so the honest first impression of these
 * panels is "nothing has happened", not an axis with nothing on it.
 */

const MONEY_IN = 'var(--chart-in, #16a34a)';
const MONEY_OUT = 'var(--chart-out, #dc2626)';

export interface Point {
    label: string;
    value: number;
}

function niceCeiling(max: number): number {
    if (max <= 0) {
        return 1;
    }

    // ⚠ Rounded up to a readable step so the top gridline is a number a person
    // would say out loud, rather than 43,817.
    const magnitude = Math.pow(10, Math.floor(Math.log10(max)));
    const normalised = max / magnitude;
    const step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10;

    return step * magnitude;
}

function compact(value: number): string {
    const abs = Math.abs(value);

    if (abs >= 10_000_000) {
        return (value / 10_000_000).toFixed(1) + 'cr';
    }

    if (abs >= 100_000) {
        return (value / 100_000).toFixed(1) + 'L';
    }

    if (abs >= 1_000) {
        return Math.round(value / 1_000) + 'k';
    }

    return String(Math.round(value));
}

function Empty({ message }: { message: string }) {
    return <p className="text-muted-foreground flex h-40 items-center justify-center text-sm">{message}</p>;
}

/**
 * Two series side by side, month by month. Money in against money out.
 */
export function PairedBars({
    points,
    aLabel,
    bLabel,
    empty = 'Nothing posted yet.',
}: {
    points: { label: string; a: number; b: number }[];
    aLabel: string;
    bLabel: string;
    empty?: string;
}) {
    if (points.length === 0 || points.every((p) => p.a === 0 && p.b === 0)) {
        return <Empty message={empty} />;
    }

    const top = niceCeiling(Math.max(...points.flatMap((p) => [p.a, p.b])));
    const width = 100;
    const height = 46;
    const slot = width / points.length;
    const barWidth = Math.min(slot / 3, 6);

    return (
        <figure className="flex flex-col gap-2">
            <div className="flex gap-4 text-xs">
                <span className="flex items-center gap-1">
                    <span className="h-2 w-2 rounded-sm" style={{ background: MONEY_IN }} /> {aLabel}
                </span>
                <span className="flex items-center gap-1">
                    <span className="h-2 w-2 rounded-sm" style={{ background: MONEY_OUT }} /> {bLabel}
                </span>
                <span className="text-muted-foreground ml-auto">top of scale {compact(top)}</span>
            </div>

            <svg viewBox={`0 0 ${width} ${height}`} className="h-48 w-full" role="img" aria-label={`${aLabel} against ${bLabel}`}>
                {/* Gridlines at quarters, drawn in the theme's border colour. */}
                {[0, 0.25, 0.5, 0.75, 1].map((fraction) => (
                    <line
                        key={fraction}
                        x1={0}
                        x2={width}
                        y1={height - fraction * (height - 6)}
                        y2={height - fraction * (height - 6)}
                        stroke="currentColor"
                        strokeWidth={0.15}
                        className="text-border"
                    />
                ))}

                {points.map((point, index) => {
                    const centre = index * slot + slot / 2;
                    const aHeight = top === 0 ? 0 : (point.a / top) * (height - 6);
                    const bHeight = top === 0 ? 0 : (point.b / top) * (height - 6);

                    return (
                        <g key={point.label}>
                            <rect x={centre - barWidth - 0.5} y={height - aHeight} width={barWidth} height={aHeight} fill={MONEY_IN} rx={0.6}>
                                <title>{`${point.label}: ${aLabel} ${point.a.toLocaleString()}`}</title>
                            </rect>
                            <rect x={centre + 0.5} y={height - bHeight} width={barWidth} height={bHeight} fill={MONEY_OUT} rx={0.6}>
                                <title>{`${point.label}: ${bLabel} ${point.b.toLocaleString()}`}</title>
                            </rect>
                        </g>
                    );
                })}
            </svg>

            <div className="text-muted-foreground flex justify-between text-xs">
                {points.map((point) => (
                    <span key={point.label}>{point.label}</span>
                ))}
            </div>
        </figure>
    );
}

/**
 * A single series over time, as an area.
 */
export function AreaTrend({ points, empty = 'Nothing to chart yet.' }: { points: Point[]; empty?: string }) {
    if (points.length < 2 || points.every((p) => p.value === 0)) {
        return <Empty message={empty} />;
    }

    const width = 100;
    const height = 40;
    const top = niceCeiling(Math.max(...points.map((p) => p.value)));
    const step = width / (points.length - 1);

    const coords = points.map((point, index) => ({
        x: index * step,
        y: height - (top === 0 ? 0 : (point.value / top) * (height - 4)),
    }));

    const line = coords.map((c, i) => `${i === 0 ? 'M' : 'L'}${c.x.toFixed(2)},${c.y.toFixed(2)}`).join(' ');
    const area = `${line} L${width},${height} L0,${height} Z`;

    return (
        <figure className="flex flex-col gap-2">
            <svg viewBox={`0 0 ${width} ${height}`} className="h-40 w-full" role="img" aria-label="Trend over time">
                <path d={area} fill="currentColor" className="text-primary/15" />
                <path d={line} fill="none" stroke="currentColor" strokeWidth={0.7} className="text-primary" />
                {coords.map((c, i) => (
                    <circle key={i} cx={c.x} cy={c.y} r={0.8} fill="currentColor" className="text-primary">
                        <title>{`${points[i].label}: ${points[i].value.toLocaleString()}`}</title>
                    </circle>
                ))}
            </svg>
            <div className="text-muted-foreground flex justify-between text-xs">
                <span>{points[0].label}</span>
                <span>top of scale {compact(top)}</span>
                <span>{points[points.length - 1].label}</span>
            </div>
        </figure>
    );
}

/**
 * Parts of a whole, as horizontal bars.
 *
 * ⚠ Bars rather than a pie. A pie of five ageing buckets is harder to read than
 * five bars and impossible to label without a legend nobody consults, and this
 * is a screen where somebody is trying to see which bucket is the problem.
 */
export function Breakdown({ points, empty = 'Nothing outstanding.' }: { points: Point[]; empty?: string }) {
    const total = points.reduce((sum, p) => sum + Math.abs(p.value), 0);

    if (total === 0) {
        return <Empty message={empty} />;
    }

    return (
        <ul className="flex flex-col gap-2">
            {points.map((point) => {
                const share = Math.abs(point.value) / total;

                return (
                    <li key={point.label} className="flex flex-col gap-1">
                        <div className="flex justify-between text-sm">
                            <span>{point.label}</span>
                            <span className="font-medium">{point.value.toLocaleString()}</span>
                        </div>
                        <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                            <div className="bg-primary h-full rounded-full" style={{ width: `${Math.max(share * 100, share > 0 ? 1.5 : 0)}%` }} />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
