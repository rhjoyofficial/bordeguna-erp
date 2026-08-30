import { FilterBar, Footnote, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
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

interface Result {
    brought_forward: string;
    brought_forward_is_loss: boolean;
    for_the_year: string;
    for_the_year_is_loss: boolean;
    total: string;
}

interface Totals {
    assets: string;
    liabilities: string;
    equity_accounts: string;
    equity: string;
    liabilities_and_equity: string;
    difference: string;
    balances: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Balance sheet', href: '/accounting/balance-sheet' },
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
                        {line.is_contra && <span className="ml-2 text-xs">(deduction)</span>}
                    </td>
                    <td className="text-muted-foreground py-1.5 text-right tabular-nums">{line.is_contra ? `(${line.amount})` : line.amount}</td>
                </tr>
            ))}
        </>
    );
}

export default function BalanceSheetIndex({
    as_at,
    fiscal_year,
    fiscal_year_start,
    assets,
    liabilities,
    equity,
    result,
    totals,
    empty,
    ledger_empty,
}: {
    as_at: string;
    fiscal_year: string | null;
    fiscal_year_start?: string;
    assets: Section[];
    liabilities: Section[];
    equity: Section[];
    result?: Result;
    totals: Totals | null;
    empty: boolean;
    ledger_empty: boolean;
}) {
    const [date, setDate] = useState(as_at);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('accounting.balance-sheet'), { as_at: date }, { preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Balance sheet" />
            <ReportPage>
                <ReportHeading
                    title="Balance sheet"
                    description={
                        <>
                            What the business owns and owes on one day. Everything recorded up to and including the date shown.
                            {fiscal_year && <> Fiscal year {fiscal_year}.</>}
                        </>
                    }
                />

                <FilterBar onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="as_at">As at</Label>
                        <Input id="as_at" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                    </div>
                </FilterBar>

                {/* The one thing this page exists to say, said before the detail. */}
                {totals && !empty && (
                    <Verdict tone={totals.balances ? 'plain' : 'bad'}>
                        {totals.balances
                            ? `Balanced. Assets and the claims against them both total ${totals.assets}.`
                            : `This balance sheet does not balance. Assets differ from liabilities and equity by ${totals.difference}. The ledger is at fault, not the report: run accounting:verify-ledger.`}
                    </Verdict>
                )}

                {totals && !empty && (
                    <StatRow columns={3}>
                        <StatTile label="Total assets" value={totals.assets} />
                        <StatTile label="Total liabilities" value={totals.liabilities} />
                        <StatTile label="Total equity" value={totals.equity} />
                    </StatRow>
                )}

                <ReportTable minWidth="40rem">
                    <tr>
                        <td colSpan={3} className="text-muted-foreground pt-2 pb-1 text-xs tracking-wide uppercase">
                            Assets
                        </td>
                    </tr>
                    {assets.map((section) => (
                        <SectionBlock key={section.code} section={section} />
                    ))}
                    <tr className="border-sidebar-border border-b-2 text-base font-semibold">
                        <td className="py-2.5" colSpan={2}>
                            Total assets
                        </td>
                        <td className="py-2.5 text-right tabular-nums">{totals?.assets}</td>
                    </tr>

                    <tr>
                        <td colSpan={3} className="text-muted-foreground pt-5 pb-1 text-xs tracking-wide uppercase">
                            Liabilities
                        </td>
                    </tr>
                    {liabilities.map((section) => (
                        <SectionBlock key={section.code} section={section} />
                    ))}
                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b font-medium">
                        <td className="py-2" colSpan={2}>
                            Total liabilities
                        </td>
                        <td className="py-2 text-right tabular-nums">{totals?.liabilities}</td>
                    </tr>

                    <tr>
                        <td colSpan={3} className="text-muted-foreground pt-5 pb-1 text-xs tracking-wide uppercase">
                            Equity
                        </td>
                    </tr>
                    {equity.map((section) => (
                        <SectionBlock key={section.code} section={section} />
                    ))}

                    {/* ⚠ The line that makes the sheet balance. Income and expense
                    are never closed into equity by this system, so the result
                    still sits in those accounts and has to be shown here. */}
                    {result && (
                        <>
                            <tr className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b">
                                <td className="py-1.5" />
                                <td className="text-muted-foreground py-1.5">
                                    {result.brought_forward_is_loss ? 'Loss brought forward' : 'Result brought forward'}
                                    {fiscal_year_start && <span className="ml-2 text-xs">(before {fiscal_year_start})</span>}
                                </td>
                                <td className="text-muted-foreground py-1.5 text-right tabular-nums">{result.brought_forward}</td>
                            </tr>
                            <tr className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b">
                                <td className="py-1.5" />
                                <td className="text-muted-foreground py-1.5">
                                    {result.for_the_year_is_loss ? 'Loss for the year to date' : 'Result for the year to date'}
                                </td>
                                <td className="text-muted-foreground py-1.5 text-right tabular-nums">{result.for_the_year}</td>
                            </tr>
                        </>
                    )}

                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b font-medium">
                        <td className="py-2" colSpan={2}>
                            Total equity
                        </td>
                        <td className="py-2 text-right tabular-nums">{totals?.equity}</td>
                    </tr>

                    <tr className="text-base font-semibold">
                        <td className="py-3" colSpan={2}>
                            Total liabilities and equity
                        </td>
                        <td className="py-3 text-right tabular-nums">{totals?.liabilities_and_equity}</td>
                    </tr>

                    {empty && (
                        <tr>
                            <td colSpan={3} className="text-muted-foreground py-6 text-center">
                                {ledger_empty ? 'Nothing has been posted to the ledger yet.' : 'No account held a balance on this date.'}
                            </td>
                        </tr>
                    )}
                </ReportTable>

                <Footnote>
                    No year end has ever been closed on this ledger, so profit is not folded into retained earnings. It is shown inside equity as the
                    result brought forward and the result for the year, which is what makes the two sides agree.
                </Footnote>
            </ReportPage>
        </AppLayout>
    );
}
