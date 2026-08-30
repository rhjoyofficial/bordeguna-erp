import { DateRangeBar, ReportHeading } from '@/components/report/report-shell';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Line {
    code: string;
    name: string;
    flow: string;
    minor: number;
    is_inflow: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/cash-flow' },
    { title: 'Cash flow', href: '/accounting/cash-flow' },
];

function Section({ title, blurb, lines, total }: { title: string; blurb: string; lines: Line[]; total: string }) {
    return (
        <div className="rounded-lg border">
            <div className="border-b p-3">
                <h2 className="font-medium">{title}</h2>
                <p className="text-muted-foreground text-xs">{blurb}</p>
            </div>
            <table className="w-full text-sm">
                <tbody>
                    {lines.length === 0 && (
                        <tr>
                            <td className="text-muted-foreground p-3" colSpan={2}>
                                Nothing in this period.
                            </td>
                        </tr>
                    )}
                    {lines.map((line) => (
                        <tr key={line.code} className="border-b last:border-b-0">
                            <td className="p-3">
                                <span className="text-muted-foreground mr-2 font-mono text-xs">{line.code}</span>
                                {line.name}
                            </td>
                            {/* Direction is in the number, not in a column heading. */}
                            <td className={`p-3 text-right tabular-nums ${line.is_inflow ? '' : 'text-muted-foreground'}`}>
                                {line.is_inflow ? line.flow : `(${line.flow})`}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="bg-muted/50 border-t font-medium">
                        <td className="p-3">Net {title.toLowerCase()}</td>
                        <td className="p-3 text-right tabular-nums">{total}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

export default function CashFlowIndex({
    from,
    to,
    fiscal_year,
    fiscal_year_start_month,
    operating,
    investing,
    financing,
    totals,
    proves,
    difference,
    any_cash_account_mapped,
}: {
    from: string;
    to: string;
    fiscal_year: string | null;
    fiscal_year_start_month: number | null;
    operating: Line[];
    investing: Line[];
    financing: Line[];
    totals: Record<string, string>;
    signs: Record<string, number>;
    proves: boolean;
    difference: string;
    any_cash_account_mapped: boolean;
}) {
    const show = (range: { from: string; to: string }) => {
        router.get('/accounting/cash-flow', range, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash flow" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Statement of cash flows"
                    description={`Where the money actually went${fiscal_year ? ` · fiscal year ${fiscal_year}` : ''}. Read from the cash itself rather than worked backwards from profit: every journal touching a cash account is a movement, and the accounts on the other side of it say what the movement was for.`}
                />

                {/* ⚠ This statement always reloads on a change, so the Show button
                    is only there for a date typed by hand. */}
                <DateRangeBar
                    from={from}
                    to={to}
                    onChange={show}
                    onSubmit={(e) => e.preventDefault()}
                    fiscalYearStartMonth={fiscal_year_start_month}
                />

                {!any_cash_account_mapped && (
                    <div className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">
                        <p className="font-medium">No bank or cash account has a place in the chart yet.</p>
                        <p className="text-muted-foreground">
                            Until one does there is no cash to follow, and every figure below would be a confident zero. Set "Reports under" on Bank
                            &amp; cash.
                        </p>
                    </div>
                )}

                <Section
                    title="Operating"
                    blurb="Running the business: what customers paid, what suppliers, staff and expenses took."
                    lines={operating}
                    total={totals.operating}
                />
                <Section
                    title="Investing"
                    blurb="Buying and selling the things the business keeps - equipment, vehicles, fixtures."
                    lines={investing}
                    total={totals.investing}
                />
                <Section
                    title="Financing"
                    blurb="Money from and to the owners and the lenders - capital, drawings, loans."
                    lines={financing}
                    total={totals.financing}
                />

                <div className="rounded-lg border">
                    <table className="w-full text-sm">
                        <tbody>
                            <tr className="border-b">
                                <td className="p-3">Cash at the start</td>
                                <td className="p-3 text-right tabular-nums">{totals.opening}</td>
                            </tr>
                            <tr className="border-b">
                                <td className="p-3">Net movement</td>
                                <td className="p-3 text-right tabular-nums">{totals.net_movement}</td>
                            </tr>
                            <tr className="bg-muted/50 font-medium">
                                <td className="p-3">Cash at the end</td>
                                <td className="p-3 text-right tabular-nums">{totals.closing}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {/* ⚠⚠ Shown either way. The three sections are built by classifying
                    journal lines; the closing balance is read straight from the
                    ledger. Two independent routes to one number, so agreement is
                    evidence - and a statement that quietly did not prove would be
                    trusted exactly as much as one that did. */}
                {proves ? (
                    <p className="text-muted-foreground text-sm">
                        ✓ The movements above account for the change in cash exactly. Opening plus net movement equals the closing balance read from
                        the ledger.
                    </p>
                ) : (
                    <div className="rounded-md border border-red-500/50 bg-red-500/10 p-3 text-sm">
                        <p className="font-medium">This statement does not prove.</p>
                        <p className="text-muted-foreground">
                            Opening plus net movement is out by {difference} against the closing balance in the ledger. Something posted to a cash
                            account in a way this statement cannot classify - do not rely on the figures above until it is explained.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
