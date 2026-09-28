import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Detail {
    label: string;
    value: string;
}

interface CardLine {
    description: string;
    quantity: string;
    amount: string;
}

interface ApprovalCard {
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
    approve_url: string | null;
    reject_url: string | null;
    /** Only on document types with a way home. See ApprovalCards::card(). */
    send_back_url: string | null;
}

interface ApprovalsIndexProps {
    requests: ApprovalCard[];
    counts: { label: string; count: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Approvals', href: '/approvals' }];

/**
 * One request, presented as a DECISION CARD (Joy, 31 Aug 2026: "if I do not
 * get enough details information, why I approve or reject it?"). The facts,
 * the document lines, the requester's own words and a link to the full record
 * all sit on the card, so the decision can be taken right here.
 */
function RequestCard({ card }: { card: ApprovalCard }) {
    // ⚠ Once a decision is on its way, every button on THIS card is disabled and
    // shows it is working (Joy, 28 Sep 2026: "everywhere the loding statce ...
    // until request complete, resubmit not be possible"). The global guard in
    // app.tsx already drops the duplicate network call; this is the visible half
    // of the same promise, so the approver can see their tap landed and does not
    // reach for the button again. It stays disabled until the page reloads with
    // this row gone (or the "already decided" flash brings it back with the note
    // recorded), so there is no window in which a second decision can be sent.
    const [processing, setProcessing] = useState(false);

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    const approve = () => {
        if (card.approve_url) {
            router.post(card.approve_url, {}, options);
        }
    };

    const reject = (review_note: string) => {
        if (card.reject_url) {
            router.post(card.reject_url, { review_note }, options);
        }
    };

    // ⚠ `note`, not `review_note`: sending back requires words, and the server
    // validates the field by that name so a silent send-back is impossible.
    const sendBack = (note: string) => {
        if (card.send_back_url) {
            router.post(card.send_back_url, { note }, options);
        }
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline">{card.type}</Badge>
                        {card.stage && <span className="text-muted-foreground text-xs">waiting on: {card.stage}</span>}
                    </div>
                    <p className="mt-2 text-sm font-medium">{card.summary}</p>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Requested by {card.requested_by ?? 'unknown'} · {card.requested_at}
                    </p>
                </div>
                <div className="flex items-center gap-1 whitespace-nowrap">
                    {card.view_url && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={card.view_url}>Open record</Link>
                        </Button>
                    )}
                    {card.approve_url && (
                        <ConfirmDialog
                            trigger={
                                <Button size="sm" disabled={processing}>
                                    {processing ? 'Working…' : 'Approve'}
                                </Button>
                            }
                            title={`Approve ${card.type.toLowerCase()}`}
                            description={card.summary}
                            confirmLabel="Approve"
                            onConfirm={() => approve()}
                        />
                    )}
                    {card.send_back_url && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="outline" size="sm" disabled={processing}>
                                    Send back
                                </Button>
                            }
                            title={`Send the ${card.type.toLowerCase()} back for correction`}
                            description={`${card.summary}. It returns to the person who raised it, keeps its number, and they can fix it and send it again.`}
                            confirmLabel="Send back"
                            withNote
                            noteRequired
                            noteLabel="What needs correcting"
                            notePlaceholder="The rickshaw fare has no receipt, and the courier amount looks like 350 not 550…"
                            onConfirm={(note) => sendBack(note)}
                        />
                    )}
                    {card.reject_url && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost" size="sm" disabled={processing}>
                                    Reject
                                </Button>
                            }
                            title={`Reject ${card.type.toLowerCase()}`}
                            description={`${card.summary}. This cannot be undone.`}
                            confirmLabel="Reject"
                            destructive
                            withNote
                            noteLabel="Reason for rejecting (optional)"
                            notePlaceholder="Add a note for the requester…"
                            onConfirm={(note) => reject(note ?? '')}
                        />
                    )}
                </div>
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

            {card.reason && (
                <blockquote className="border-muted-foreground/30 text-muted-foreground mt-3 border-l-2 pl-3 text-sm whitespace-pre-line">
                    {card.reason}
                </blockquote>
            )}
        </div>
    );
}

export default function ApprovalsIndex({ requests, counts }: ApprovalsIndexProps) {
    // A request settled by one approver leaves EVERY open inbox on its own (Joy,
    // 28 Sep 2026: "remove it from every user"). The app-wide auto-refresh in
    // app.tsx re-reads every page on an interval, so a decided row drops out here
    // without anyone refreshing - no per-page poll needed.
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Approvals" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        {counts.length === 0 ? (
                            <Badge variant="secondary">0 pending</Badge>
                        ) : (
                            counts.map((entry) => (
                                <Badge key={entry.label} variant="secondary">
                                    {entry.count} {entry.label.toLowerCase()}
                                    {entry.count === 1 ? '' : 's'}
                                </Badge>
                            ))
                        )}
                    </div>
                    {/* Where a decided request goes: the read-only record of what
                        has been settled and by whom. */}
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/approvals/history">History</Link>
                    </Button>
                </div>

                {requests.map((card) => (
                    <RequestCard key={`${card.type}-${card.id}`} card={card} />
                ))}

                {requests.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">Nothing pending - you're all caught up.</p>}
            </div>
        </AppLayout>
    );
}
