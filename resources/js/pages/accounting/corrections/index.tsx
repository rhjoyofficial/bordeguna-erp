import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Row {
    at: string;
    kind: string;
    kind_label: string;
    document: string;
    successor: string | null;
    actor: string | null;
    approver: string | null;
    amount_basis: string | null;
    reason: string;
    closed_period_exception: boolean;
    url: string | null;
}

interface Props {
    month: string;
    month_label: string;
    kind: string | null;
    kinds: Record<string, string>;
    summary: Record<string, number>;
    rows: Row[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/overview' },
    { title: 'Corrections', href: '/accounting/corrections' },
];

/**
 * The monthly Corrections report (owner's answer 3, 17 Oct 2026; edit,
 * delete and correct, Phase 0). Every correction and reversal, every request
 * and its decision, every refused attempt, every "saved despite a possible
 * duplicate" and every closed-month exception, by the moment it happened.
 * A closed-month exception is flagged red: that month's figures changed.
 */
export default function CorrectionsReport({ month, month_label, kind, kinds, summary, rows }: Props) {
    const show = (next: { month?: string; kind?: string | null }) => {
        const query: Record<string, string> = { month: next.month ?? month };
        const nextKind = next.kind === undefined ? kind : next.kind;

        if (nextKind) {
            query.kind = nextKind;
        }

        router.get('/accounting/corrections', query, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Corrections" />

            <div className="flex flex-col gap-4 p-4">
                <ReportHeading title={`Corrections, ${month_label}`} />

                <div className="flex flex-wrap items-end gap-4">
                    <div className="grid gap-1">
                        <Label htmlFor="month">Month</Label>
                        <Input
                            id="month"
                            type="month"
                            className="w-auto"
                            value={month}
                            onChange={(e) => e.target.value && show({ month: e.target.value })}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="kind">Show</Label>
                        <select
                            id="kind"
                            value={kind ?? ''}
                            onChange={(e) => show({ kind: e.target.value || null })}
                            className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                        >
                            <option value="">Everything</option>
                            {Object.entries(kinds).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {Object.keys(summary).length > 0 && (
                    <div className="flex flex-wrap gap-2 text-sm">
                        {Object.entries(summary).map(([key, count]) => (
                            <Badge key={key} variant={key === 'closed_month_exception' ? 'destructive' : 'secondary'}>
                                {kinds[key] ?? key}: {count}
                            </Badge>
                        ))}
                    </div>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">When</th>
                                <th className="p-3">What</th>
                                <th className="p-3">Record</th>
                                <th className="p-3">Who</th>
                                <th className="p-3">Approved by</th>
                                <th className="p-3 text-right">Judged on</th>
                                <th className="p-3">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        Nothing was corrected, reversed or refused in {month_label}.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row, index) => (
                                    <tr
                                        key={`${row.at}-${row.kind}-${index}`}
                                        className={`border-sidebar-border/70 dark:border-sidebar-border border-t align-top ${row.closed_period_exception ? 'bg-red-50 dark:bg-red-950/30' : ''}`}
                                    >
                                        <td className="p-3 whitespace-nowrap">{row.at}</td>
                                        <td className="p-3">
                                            {row.kind_label}
                                            {row.closed_period_exception && (
                                                <Badge variant="destructive" className="ml-1">
                                                    Closed month
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3 font-mono text-xs">
                                            {row.url ? (
                                                <a href={row.url} className="underline">
                                                    {row.document}
                                                </a>
                                            ) : (
                                                row.document
                                            )}
                                            {row.successor && <div className="text-muted-foreground">to {row.successor}</div>}
                                        </td>
                                        <td className="p-3">{row.actor ?? '-'}</td>
                                        <td className="p-3">{row.approver ?? '-'}</td>
                                        <td className="p-3 text-right whitespace-nowrap">{row.amount_basis ?? '-'}</td>
                                        <td className="p-3">{row.reason}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
