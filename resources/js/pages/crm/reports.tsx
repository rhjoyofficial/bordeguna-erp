import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Stage {
    status: string;
    label: string;
    count: number;
}

interface SourceRow {
    source: string;
    campaign: string;
    leads: number;
    converted: number;
    rate: number | null;
}

interface DemandRow {
    what: string;
    kind: string;
    sku: string | null;
    people: number;
    requests: number;
    not_currently_sold: boolean;
}

interface LoadRow {
    who: string;
    open: number;
    overdue: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/leads' },
    { title: 'Reports', href: '/crm/reports' },
];

export default function CrmReports({
    funnel,
    sources,
    demand,
    followUps,
    canSeeAllTasks,
}: {
    funnel: {
        stages: Stage[];
        workable: number;
        converted: number;
        duplicatesExcluded: number;
        conversionRate: number | null;
    };
    sources: SourceRow[];
    demand: DemandRow[];
    followUps: LoadRow[];
    canSeeAllTasks: boolean;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="CRM reports" />
            <div className="flex flex-col gap-8 p-4">
                <div>
                    <h1 className="text-lg font-semibold">CRM reports</h1>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        Every figure is worked out when you open this page, so nothing here can drift from the records it came from. Revenue and
                        campaign return arrive with Sales - there are no invoices yet, and a column of zeros would be worse than an absent one.
                    </p>
                </div>

                {/* ── Funnel ─────────────────────────────────────────────── */}
                <section className="flex flex-col gap-3">
                    <div>
                        <h2 className="text-sm font-medium">Where leads stand</h2>
                        <p className="text-muted-foreground text-sm">
                            {/* ⚠ The denominator is stated, not implied. */}
                            {funnel.converted} of {funnel.workable} leads converted
                            {funnel.conversionRate !== null && ` - ${funnel.conversionRate}%`}
                            {funnel.duplicatesExcluded > 0 &&
                                `. ${funnel.duplicatesExcluded} duplicate${funnel.duplicatesExcluded === 1 ? '' : 's'} excluded, so nobody is counted twice.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        {funnel.stages.map((stage) => (
                            <div key={stage.status} className="min-w-32 rounded-md border p-3">
                                <p className="text-2xl font-semibold tabular-nums">{stage.count}</p>
                                <p className="text-muted-foreground text-xs">{stage.label}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* ── Sources ────────────────────────────────────────────── */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium">Which source and campaign produced customers</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Source</th>
                                    <th className="p-3">Campaign</th>
                                    <th className="p-3 text-right">Leads</th>
                                    <th className="p-3 text-right">Converted</th>
                                    <th className="p-3 text-right">Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sources.map((row) => (
                                    <tr key={`${row.source}-${row.campaign}`} className="border-t">
                                        <td className="p-3">{row.source}</td>
                                        <td className="text-muted-foreground p-3">{row.campaign}</td>
                                        <td className="p-3 text-right tabular-nums">{row.leads}</td>
                                        <td className="p-3 text-right tabular-nums">{row.converted}</td>
                                        <td className="p-3 text-right tabular-nums">{row.rate === null ? '-' : `${row.rate}%`}</td>
                                    </tr>
                                ))}
                                {sources.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                            No lead has an attribution yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* ── Demand ─────────────────────────────────────────────── */}
                <section className="flex flex-col gap-2">
                    <div>
                        <h2 className="text-sm font-medium">What people are asking for</h2>
                        <p className="text-muted-foreground text-sm">
                            {/* ⚠⚠ People, not requests. Six people wanting mango and one
                                person asking six times are different businesses. */}
                            Counted by how many <span className="font-medium">people</span> asked, not how many times.
                        </p>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[34rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Wanted</th>
                                    <th className="p-3">Kind</th>
                                    <th className="p-3 text-right">People</th>
                                    <th className="p-3 text-right">Times asked</th>
                                </tr>
                            </thead>
                            <tbody>
                                {demand.map((row) => (
                                    <tr key={`${row.kind}-${row.what}`} className="border-t">
                                        <td className="p-3">
                                            {row.what}
                                            {row.sku && <span className="text-muted-foreground ml-2 text-xs">{row.sku}</span>}
                                            {row.not_currently_sold && (
                                                <Badge variant="destructive" className="ml-2">
                                                    not currently sold
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground p-3">{row.kind}</td>
                                        <td className="p-3 text-right font-medium tabular-nums">{row.people}</td>
                                        <td className="text-muted-foreground p-3 text-right tabular-nums">{row.requests}</td>
                                    </tr>
                                ))}
                                {demand.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="text-muted-foreground p-6 text-center">
                                            Nobody has asked for anything yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                {/* ── Follow-up load ─────────────────────────────────────── */}
                <section className="flex flex-col gap-2">
                    <div>
                        <h2 className="text-sm font-medium">Follow-up load</h2>
                        <p className="text-muted-foreground text-sm">
                            {canSeeAllTasks ? 'Everyone’s open follow-ups.' : 'Your open follow-ups only.'}
                        </p>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[24rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Who</th>
                                    <th className="p-3 text-right">Open</th>
                                    <th className="p-3 text-right">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                {followUps.map((row) => (
                                    <tr key={row.who} className="border-t">
                                        <td className="p-3">{row.who}</td>
                                        <td className="p-3 text-right tabular-nums">{row.open}</td>
                                        <td className="p-3 text-right tabular-nums">
                                            {row.overdue > 0 ? <span className="text-destructive font-medium">{row.overdue}</span> : row.overdue}
                                        </td>
                                    </tr>
                                ))}
                                {followUps.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="text-muted-foreground p-6 text-center">
                                            Nothing outstanding.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
