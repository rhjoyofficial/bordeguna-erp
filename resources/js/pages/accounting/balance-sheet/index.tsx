import { FilterBar, Footnote, ReportHeading, ReportPage, ReportTable, StatRow, StatTile, Verdict } from '@/components/report/report-shell';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment, FormEventHandler, useState } from 'react';

interface Line {
    code: string;
    name: string;
    amount: string;
    is_contra: boolean;
    group_code: string | null;
}

interface Group {
    code: string;
    name: string;
    total: string;
    is_contra: boolean;
}

interface Section {
    code: string;
    name: string;
    total: string;
    lines: Line[];
    groups: Record<string, Group>;
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

function LineRow({ line, nested }: { line: Line; nested: boolean }) {
    return (
        <tr className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b">
            <td className="text-muted-foreground py-1.5 font-mono text-xs">{line.code}</td>
            <td className={`text-muted-foreground py-1.5 ${nested ? 'pl-12' : 'pl-6'}`}>
                {line.name}
                {line.is_contra && <span className="ml-2 text-xs">(deduction)</span>}
            </td>
            <td className="text-muted-foreground py-1.5 text-right tabular-nums">{line.is_contra ? `(${line.amount})` : line.amount}</td>
        </tr>
    );
}

/**
 * ⚠ A sub-group (Bank Accounts inside Current Assets) prints as ONE line with
 * its subtotal, and opens to show every account in it. The subtotal is the
 * figure a reader of a balance sheet wants; the banks one by one are a click
 * away, and on the trial balance and general ledger they are always listed.
 */
function SectionBlock({ section, showAll }: { section: Section; showAll: boolean }) {
    const [open, setOpen] = useState<Record<string, boolean>>({});

    // Lines in chart order, with each sub-group's lines gathered behind it.
    const items: ({ kind: 'line'; line: Line } | { kind: 'group'; group: Group; lines: Line[] })[] = [];
    for (const line of section.lines) {
        const group = line.group_code ? section.groups[line.group_code] : undefined;
        if (!group) {
            items.push({ kind: 'line', line });
            continue;
        }
        const existing = items.find((item) => item.kind === 'group' && item.group.code === group.code);
        if (existing && existing.kind === 'group') {
            existing.lines.push(line);
        } else {
            items.push({ kind: 'group', group, lines: [line] });
        }
    }

    return (
        <>
            <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                <td className="text-muted-foreground py-2 font-mono text-xs">{section.code}</td>
                <td className="py-2 font-medium">{section.name}</td>
                <td className="py-2 text-right font-medium tabular-nums">{section.total}</td>
            </tr>
            {items.map((item) => {
                if (item.kind === 'line') {
                    return <LineRow key={item.line.code} line={item.line} nested={false} />;
                }
                const expanded = showAll || open[item.group.code];
                return (
                    <Fragment key={item.group.code}>
                        <tr className="border-sidebar-border/40 dark:border-sidebar-border/40 border-b">
                            <td className="text-muted-foreground py-1.5 font-mono text-xs">{item.group.code}</td>
                            <td className="py-1.5 pl-6">
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 text-left hover:underline"
                                    aria-expanded={expanded}
                                    onClick={() => setOpen((current) => ({ ...current, [item.group.code]: !current[item.group.code] }))}
                                    disabled={showAll}
                                >
                                    <ChevronRight className={`size-3.5 transition-transform ${expanded ? 'rotate-90' : ''}`} />
                                    {item.group.name}
                                    <span className="text-muted-foreground text-xs">
                                        ({item.lines.length} account{item.lines.length === 1 ? '' : 's'})
                                    </span>
                                </button>
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{item.group.is_contra ? `(${item.group.total})` : item.group.total}</td>
                        </tr>
                        {expanded && item.lines.map((line) => <LineRow key={line.code} line={line} nested />)}
                    </Fragment>
                );
            })}
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
    const [showAll, setShowAll] = useState(false);
    const hasGroups = [...assets, ...liabilities, ...equity].some((section) => Object.keys(section.groups ?? {}).length > 0);

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
                    {hasGroups && (
                        <label className="flex h-10 items-center gap-2 text-sm">
                            <input type="checkbox" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} />
                            Show every account
                        </label>
                    )}
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
                        <SectionBlock key={section.code} section={section} showAll={showAll} />
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
                        <SectionBlock key={section.code} section={section} showAll={showAll} />
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
                        <SectionBlock key={section.code} section={section} showAll={showAll} />
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
