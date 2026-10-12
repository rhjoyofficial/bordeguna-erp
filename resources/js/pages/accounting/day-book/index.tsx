import { Amount, DateCell, DocNo, LedgerHead, PartyText, StatusPill } from '@/components/ledger-text';
import { DateRangeBar, ReportHeading } from '@/components/report/report-shell';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Fragment, useState, type FormEventHandler } from 'react';

interface Line {
    code: string | null;
    account: string | null;
    party: string | null;
    memo: string | null;
    debit: string;
    credit: string;
}

interface Row {
    id: number;
    date: string;
    number: string;
    kind: string;
    module: string;
    document_number: string | null;
    memo: string | null;
    posted_by: string | null;
    is_reversal: boolean;
    reversed: boolean;
    total: string;
    lines: Line[];
}

interface Paginated<T> {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/day-book' },
    { title: 'Day book', href: '/accounting/day-book' },
];

/**
 * The Day Book: every journal of every module in the window, in the order it
 * was posted. Opens on today. Each voucher shows its own debit and credit
 * lines, the way an accountant reads a day book on paper.
 */
export default function DayBook({
    from,
    to,
    kind,
    user,
    journals,
    count,
    total,
    kinds,
    users,
    fiscal_year_start_month,
    business_name,
}: {
    from: string;
    to: string;
    kind: string | null;
    user: number | null;
    journals: Paginated<Row>;
    count: number;
    total: string;
    kinds: string[];
    users: { id: number; name: string }[];
    fiscal_year_start_month: number | null;
    business_name: string | null;
}) {
    const [range, setRange] = useState({ from, to });

    const show = (next: { from?: string; to?: string; kind?: string | null; user?: string | null }) => {
        const query: Record<string, string> = { from: next.from ?? range.from, to: next.to ?? range.to };
        const nextKind = next.kind === undefined ? kind : next.kind;
        const nextUser = next.user === undefined ? (user === null ? null : String(user)) : next.user;

        if (nextKind) {
            query.kind = nextKind;
        }

        if (nextUser) {
            query.user = nextUser;
        }

        router.get(route('accounting.day-book'), query, { preserveState: true, preserveScroll: true });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        show(range);
    };

    const exportQuery = new URLSearchParams({ from, to, ...(kind ? { kind } : {}), ...(user ? { user: String(user) } : {}) }).toString();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Day book" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading
                    title="Day book"
                    actions={
                        <>
                            <Button type="button" variant="outline" onClick={() => window.print()}>
                                Print
                            </Button>
                            <Button asChild variant="outline">
                                <a href={`${route('accounting.day-book.export')}?${exportQuery}`}>Export</a>
                            </Button>
                        </>
                    }
                />

                {/* What a printed day book must say about itself. */}
                <div className="hidden text-sm print:block">
                    {business_name} · {from === to ? from : `${from} to ${to}`}
                    {kind ? ` · ${kind}` : ''}
                </div>

                <DateRangeBar
                    from={range.from}
                    to={range.to}
                    onChange={setRange}
                    onSubmit={submit}
                    onApply={(next) => {
                        setRange(next);
                        show(next);
                    }}
                    fiscalYearStartMonth={fiscal_year_start_month}
                />

                <div className="flex flex-wrap items-center gap-3 print:hidden">
                    <SearchableSelect
                        fit
                        clearable
                        value={kind ?? ''}
                        onChange={(next) => show({ kind: next === '' ? null : next })}
                        options={[{ value: '', label: 'All voucher types' }, ...kinds.map((k) => ({ value: k, label: k }))]}
                        placeholder="All voucher types"
                    />
                    <SearchableSelect
                        fit
                        clearable
                        value={user === null ? '' : String(user)}
                        onChange={(next) => show({ user: next === '' ? null : next })}
                        options={[{ value: '', label: 'Posted by anyone' }, ...users.map((u) => ({ value: String(u.id), label: u.name }))]}
                        placeholder="Posted by anyone"
                    />
                    <span className="text-muted-foreground text-sm">
                        {count} {count === 1 ? 'entry' : 'entries'}
                    </span>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="w-px py-2 pr-3 whitespace-nowrap">Date</th>
                                <th className="w-px py-2 pr-3 whitespace-nowrap">Voucher</th>
                                <th className="py-2 pr-3">Particulars</th>
                                <th className="w-px py-2 pr-3 text-right whitespace-nowrap">Debit</th>
                                <th className="w-px py-2 text-right whitespace-nowrap">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {journals.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground py-8 text-center">
                                        Nothing was posted {from === to ? 'on this day' : 'in this period'}.
                                    </td>
                                </tr>
                            )}
                            {journals.data.map((journal) => (
                                <Fragment key={journal.id}>
                                    <tr className="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                                        <td className="py-2 pr-3 whitespace-nowrap" rowSpan={journal.lines.length + 1}>
                                            <DateCell>{journal.date}</DateCell>
                                        </td>
                                        <td className="py-2 pr-3 whitespace-nowrap" rowSpan={journal.lines.length + 1}>
                                            <div className="font-medium">{journal.kind}</div>
                                            <DocNo href={route('accounting.journals.show', journal.id)} className="text-xs">
                                                {journal.number}
                                            </DocNo>
                                            {journal.document_number && (
                                                <div className="text-muted-foreground font-mono text-xs">{journal.document_number}</div>
                                            )}
                                            {journal.is_reversal && (
                                                <Badge variant="secondary" className="mt-1">
                                                    Reversal
                                                </Badge>
                                            )}
                                            {journal.reversed && <StatusPill status="reversed" label="Reversed" className="mt-1" />}
                                        </td>
                                        <td className="text-muted-foreground pt-2 pr-3 text-xs" colSpan={3}>
                                            {journal.memo}
                                            {journal.posted_by ? <span className="print:hidden"> · {journal.posted_by}</span> : null}
                                        </td>
                                    </tr>
                                    {journal.lines.map((line, index) => (
                                        <tr key={index} className="align-top">
                                            <td className={`py-0.5 pr-3 ${line.credit !== '' ? 'pl-6' : ''}`}>
                                                <LedgerHead code={line.code} name={line.account} />
                                                {line.party ? (
                                                    <>
                                                        <span className="text-muted-foreground"> · </span>
                                                        <PartyText>{line.party}</PartyText>
                                                    </>
                                                ) : null}
                                                {line.memo && line.memo !== journal.memo ? (
                                                    <div className="text-muted-foreground text-xs">{line.memo}</div>
                                                ) : null}
                                            </td>
                                            <td className="py-0.5 pr-3 text-right whitespace-nowrap">
                                                <Amount value={line.debit} tone="debit" />
                                            </td>
                                            <td className="py-0.5 text-right whitespace-nowrap">
                                                <Amount value={line.credit} tone="credit" />
                                            </td>
                                        </tr>
                                    ))}
                                </Fragment>
                            ))}
                        </tbody>
                        {count > 0 && (
                            <tfoot>
                                <tr className="border-sidebar-border dark:border-sidebar-border border-t-2 font-bold">
                                    <td className="py-2" colSpan={3}>
                                        Total for the period
                                    </td>
                                    <td className="py-2 pr-3 text-right">
                                        <Amount value={total} tone="debit" className="font-bold" />
                                    </td>
                                    <td className="py-2 text-right">
                                        <Amount value={total} tone="credit" className="font-bold" />
                                    </td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                {journals.links.length > 3 && (
                    <div className="flex flex-wrap gap-1 print:hidden">
                        {journals.links.map((link, index) =>
                            link.url === null ? (
                                <span
                                    key={index}
                                    className="text-muted-foreground px-3 py-1 text-sm"
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ) : (
                                <Link
                                    key={index}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ),
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
