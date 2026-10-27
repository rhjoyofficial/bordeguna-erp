import { ConfirmDialog } from '@/components/confirm-dialog';
import { CorrectionReasonDialog, type ReasonOption } from '@/components/purchasing/correction-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { datesInText, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
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
    /** Rule #22: the viewer would be approving their own money; a reason is required. */
    self_approval: boolean;
    /** ⭐ 27 Oct 2026: a leave's pay, choosable at the last approval by someone who manages leave. */
    pay_choice?: { days: number; unpaid_days: number; half_day: boolean } | null;
}

/**
 * ⭐ 27 Oct 2026 (Joy): the approver may choose the pay as they approve. Left on
 * "As the leave type and balance say", nothing extra is sent and approval means
 * exactly what it always did; HR can still decide the pay afterwards.
 */
function PayChoice({
    choice,
    unpaid,
    setUnpaid,
    reason,
    setReason,
}: {
    choice: NonNullable<ApprovalCard['pay_choice']>;
    unpaid: string;
    setUnpaid: (value: string) => void;
    reason: string;
    setReason: (value: string) => void;
}) {
    const { t } = useTranslation();
    // Every half-day step strictly between Paid and Unpaid: 0.5, 1, ... days - 0.5.
    const halves = Array.from({ length: Math.max(0, Math.round(choice.days * 2) - 1) }, (_, i) => (i + 1) / 2);

    return (
        <div className="mt-3 flex flex-wrap items-end gap-2 text-sm">
            <label className="grid gap-1">
                <span className="text-muted-foreground text-xs">{t('pay.card_label')}</span>
                <select
                    className="border-input bg-background h-8 w-fit rounded-md border px-2 text-sm"
                    value={unpaid}
                    onChange={(e) => setUnpaid(e.target.value)}
                >
                    <option value="">{t('pay.card_as_rules')}</option>
                    <option value="0">{t('pay.paid')}</option>
                    <option value={String(choice.days)}>{t('pay.unpaid')}</option>
                    {halves.map((d) => (
                        <option key={d} value={String(d)}>
                            {`${t('pay.part')}: ${d} / ${choice.days}`}
                        </option>
                    ))}
                </select>
            </label>
            {unpaid !== '' && (
                <label className="grid gap-1">
                    <span className="text-muted-foreground text-xs">{t('pay.card_reason_needed')}</span>
                    <input
                        className="border-input bg-background h-8 w-64 rounded-md border px-2 text-sm"
                        aria-required="true"
                        value={reason}
                        placeholder={t('pay.reason_placeholder')}
                        onChange={(e) => setReason(e.target.value)}
                    />
                </label>
            )}
        </div>
    );
}

interface ApprovalsIndexProps {
    requests: ApprovalCard[];
    counts: { label: string; count: number }[];
    /** ⭐ Corrections wave 2 (24 Oct 2026): what the viewer sent that still waits. */
    mine?: {
        id: number;
        type: string;
        summary: string;
        view_url: string | null;
        requested_at: string | null;
        withdraw_blocked: string | null;
        withdraw_url: string;
        /** B13 (24 Oct 2026): back to a draft, or closed as cancelled. */
        withdraw_outcome: 'draft' | 'cancelled' | null;
    }[];
    withdrawalReasons?: ReasonOption[];
}

/** Requests the viewer sent, still waiting, each with Withdraw (or, greyed, why not). */
function SentByMe({ rows, reasons }: { rows: NonNullable<ApprovalsIndexProps['mine']>; reasons: ReasonOption[] }) {
    const { t } = useTranslation();

    if (rows.length === 0) {
        return null;
    }

    return (
        <section className="rounded-lg border p-4">
            <h2 className="font-semibold">{t('corr.acc.sent_by_me')}</h2>
            <p className="text-muted-foreground mb-2 text-sm">{t('corr.acc.sent_by_me_help')}</p>
            <ul className="divide-y text-sm">
                {rows.map((row) => (
                    <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <span className="text-muted-foreground">{row.type}</span> {row.summary}
                            {row.requested_at && <span className="text-muted-foreground"> ({showDate(row.requested_at)})</span>}{' '}
                            {row.view_url && (
                                <Link href={row.view_url} className="underline">
                                    {t('corr.acc.open')}
                                </Link>
                            )}
                        </span>
                        {row.withdraw_blocked ? (
                            <span className="flex max-w-72 flex-col items-start gap-0.5">
                                <Button size="sm" variant="outline" disabled title={row.withdraw_blocked}>
                                    {t('corr.acc.withdraw')}
                                </Button>
                                <span className="text-muted-foreground text-[11px] leading-tight">{row.withdraw_blocked}</span>
                            </span>
                        ) : (
                            <CorrectionReasonDialog
                                trigger={
                                    <Button size="sm" variant="outline">
                                        {t('corr.acc.withdraw')}
                                    </Button>
                                }
                                title={t('corr.acc.withdraw_title', { number: row.summary })}
                                description={
                                    row.withdraw_outcome === 'draft'
                                        ? t('corr.fu.withdraw_draft')
                                        : row.withdraw_outcome === 'cancelled'
                                          ? t('corr.fu.withdraw_cancelled')
                                          : t('corr.acc.withdraw_help')
                                }
                                reasons={reasons}
                                submitUrl={row.withdraw_url}
                                confirmLabel={t('corr.acc.withdraw')}
                            />
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
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
    const { t } = useTranslation();

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    // ⭐ 27 Oct 2026: a leave's pay chosen on the card ('' = as the rules say).
    const [payUnpaid, setPayUnpaid] = useState('');
    const [payReason, setPayReason] = useState('');
    const payMissingReason = payUnpaid !== '' && payReason.trim() === '';

    // Rule #22 (14 Oct 2026): a self-approval carries its reason as the note.
    const approve = (note?: string) => {
        if (card.approve_url) {
            const pay = payUnpaid === '' ? {} : { pay_unpaid_days: Number(payUnpaid), pay_reason: payReason };
            router.post(card.approve_url, { ...(note ? { note } : {}), ...pay }, options);
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
                    <p className="mt-2 text-sm font-medium">{datesInText(card.summary)}</p>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Requested by {card.requested_by ?? 'unknown'} · {showDate(card.requested_at)}
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
                                <Button
                                    size="sm"
                                    disabled={processing || payMissingReason}
                                    title={payMissingReason ? t('pay.card_reason_needed') : undefined}
                                >
                                    {processing ? 'Working…' : 'Approve'}
                                </Button>
                            }
                            title={`Approve ${card.type.toLowerCase()}`}
                            description={card.self_approval ? `${card.summary}. ${t('hr_rules.self_approval_notice')}` : card.summary}
                            confirmLabel="Approve"
                            withNote={card.self_approval}
                            noteRequired={card.self_approval}
                            noteLabel={t('hr_rules.self_approval_reason')}
                            onConfirm={(note) => approve(card.self_approval ? note : undefined)}
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
                            <dd>{typeof detail.value === 'string' ? datesInText(detail.value) : detail.value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {card.pay_choice && card.approve_url && (
                <PayChoice choice={card.pay_choice} unpaid={payUnpaid} setUnpaid={setPayUnpaid} reason={payReason} setReason={setPayReason} />
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

export default function ApprovalsIndex({ requests, counts, mine = [], withdrawalReasons = [] }: ApprovalsIndexProps) {
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

                <SentByMe rows={mine} reasons={withdrawalReasons} />

                {requests.map((card) => (
                    <RequestCard key={`${card.type}-${card.id}`} card={card} />
                ))}

                {requests.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">Nothing pending - you're all caught up.</p>}
            </div>
        </AppLayout>
    );
}
