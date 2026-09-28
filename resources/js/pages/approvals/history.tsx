import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Detail {
    label: string;
    value: string;
}

interface CardLine {
    description: string;
    quantity: string;
    amount: string;
}

interface DecidedCard {
    id: number;
    type: string;
    summary: string;
    details: Detail[];
    reason: string | null;
    lines: CardLine[];
    view_url: string | null;
    stage: string | null;
    requested_by: string | null;
    requested_at: string;
    status: 'approved' | 'rejected' | 'returned';
    reviewed_by: string | null;
    reviewed_at: string | null;
    review_note: string | null;
}

interface Tab {
    key: string;
    label: string;
}

interface HistoryProps {
    requests: DecidedCard[];
    active: string;
    tabs: Tab[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Approvals', href: '/approvals' },
    { title: 'History', href: '/approvals/history' },
];

// The outcome, said plainly and coloured so a page of them scans at a glance.
const OUTCOME: Record<DecidedCard['status'], { label: string; variant: 'default' | 'destructive' | 'secondary' }> = {
    approved: { label: 'Approved', variant: 'default' },
    rejected: { label: 'Rejected', variant: 'destructive' },
    returned: { label: 'Sent back', variant: 'secondary' },
};

function DecidedRow({ card }: { card: DecidedCard }) {
    const outcome = OUTCOME[card.status];

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{card.type}</Badge>
                        <Badge variant={outcome.variant}>{outcome.label}</Badge>
                    </div>
                    <p className="mt-2 text-sm font-medium">{card.summary}</p>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Requested by {card.requested_by ?? 'unknown'} · {card.requested_at}
                    </p>
                    <p className="text-muted-foreground mt-0.5 text-xs">
                        {outcome.label} by {card.reviewed_by ?? 'unknown'}
                        {card.reviewed_at ? ` · ${card.reviewed_at}` : ''}
                    </p>
                </div>
                {card.view_url && (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={card.view_url}>Open record</Link>
                    </Button>
                )}
            </div>

            {card.details.length > 0 && (
                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    {card.details.map((detail) => (
                        <div key={detail.label}>
                            <dt className="text-muted-foreground text-xs">{detail.label}</dt>
                            <dd>{detail.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {card.lines.length > 0 && (
                <div className="mt-3 overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-1.5 font-medium">Item</th>
                                <th className="px-3 py-1.5 text-right font-medium">Qty × rate</th>
                                <th className="px-3 py-1.5 text-right font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {card.lines.map((line, index) => (
                                <tr key={index} className="border-t">
                                    <td className="px-3 py-1.5">{line.description}</td>
                                    <td className="px-3 py-1.5 text-right whitespace-nowrap">{line.quantity}</td>
                                    <td className="px-3 py-1.5 text-right whitespace-nowrap">{line.amount}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {card.review_note && (
                <blockquote className="border-muted-foreground/30 text-muted-foreground mt-3 border-l-2 pl-3 text-sm whitespace-pre-line">
                    <span className="font-medium">Note:</span> {card.review_note}
                </blockquote>
            )}
        </div>
    );
}

export default function ApprovalsHistory({ requests, active, tabs }: HistoryProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals history" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-1">
                        {tabs.map((tab) => (
                            <Button
                                key={tab.key}
                                variant={tab.key === active ? 'default' : 'outline'}
                                size="sm"
                                onClick={() =>
                                    router.get('/approvals/history', tab.key === 'all' ? {} : { status: tab.key }, {
                                        preserveScroll: true,
                                        preserveState: true,
                                    })
                                }
                            >
                                {tab.label}
                            </Button>
                        ))}
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/approvals">Back to pending</Link>
                    </Button>
                </div>

                {requests.map((card) => (
                    <DecidedRow key={`${card.type}-${card.id}`} card={card} />
                ))}

                {requests.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">Nothing settled here yet.</p>}
            </div>
        </AppLayout>
    );
}
