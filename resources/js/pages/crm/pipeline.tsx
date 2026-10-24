import { DealReopen } from '@/components/crm/crm-corrections';
import { DealDialog } from '@/components/crm/deal-dialog';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDate, showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * The pipeline board (Module 4, Slice 1). Open deals only: won and lost are
 * outcomes shown as history below, never columns to park a deal in. Losing
 * asks for a reason - the same reasons the lead flow keeps.
 */
interface Card {
    id: number;
    name: string;
    party: string;
    is_customer: boolean;
    value: string;
    expected_close_on: string | null;
    assigned_to: string | null;
    next_action: string | null;
}

interface Stage {
    id: number;
    name: string;
    total_value: number;
    opportunities: Card[];
}

interface ClosedDeal {
    id: number;
    name: string;
    status: string;
    value: string;
    closed_at: string | null;
    lost_reason: string | null;
}

interface Option {
    id: number;
    name: string;
}

interface PipelineProps {
    stages: Stage[];
    recentlyClosed: ClosedDeal[];
    leads: Option[];
    customers: Option[];
    lostReasons: Option[];
    users: Option[];
    /** ⭐ Corrections wave 3: Reopen a won or lost deal, with a reason. */
    reopenReasons: { id: number; name: string }[];
    canReopen: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'CRM', href: '/crm/overview' },
    { title: 'Pipeline', href: '/crm/pipeline' },
];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 0 });

function DealCard({ card, stages, lostReasons }: { card: Card; stages: Stage[]; lostReasons: Option[] }) {
    const [losing, setLosing] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3 text-sm shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <p className="font-medium">{card.name}</p>
                <p className="font-semibold whitespace-nowrap tabular-nums">{money(card.value)}</p>
            </div>
            <p className="text-muted-foreground text-xs">
                {card.party}
                {card.is_customer ? ' (customer)' : ' (lead)'}
                {card.expected_close_on && ` · closes ${showDate(card.expected_close_on)}`}
                {card.assigned_to && ` · ${card.assigned_to}`}
            </p>
            {card.next_action && <p className="text-xs">Next: {card.next_action}</p>}

            <div className="flex flex-wrap items-center gap-1.5">
                <Select
                    value=""
                    onValueChange={(value) => router.post(`/crm/opportunities/${card.id}/stage`, { stage_id: value }, { preserveScroll: true })}
                >
                    <SelectTrigger className="h-7 w-28 text-xs">
                        <SelectValue placeholder="Move to…" />
                    </SelectTrigger>
                    <SelectContent>
                        {stages.map((stage) => (
                            <SelectItem key={stage.id} value={String(stage.id)}>
                                {stage.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    type="button"
                    size="sm"
                    className="h-7 bg-emerald-600 text-white hover:bg-emerald-700"
                    onClick={() => router.post(`/crm/opportunities/${card.id}/won`, {}, { preserveScroll: true })}
                >
                    Won
                </Button>
                {!losing ? (
                    <Button type="button" variant="ghost" size="sm" className="text-destructive h-7" onClick={() => setLosing(true)}>
                        Lost
                    </Button>
                ) : (
                    <span className="flex items-center gap-1.5">
                        <Select value={reason} onValueChange={setReason}>
                            <SelectTrigger className="h-7 w-36 text-xs">
                                <SelectValue placeholder="Why lost?" />
                            </SelectTrigger>
                            <SelectContent>
                                {lostReasons.map((item) => (
                                    <SelectItem key={item.id} value={String(item.id)}>
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            className="h-7"
                            disabled={reason === ''}
                            onClick={() => router.post(`/crm/opportunities/${card.id}/lost`, { lost_reason_id: reason }, { preserveScroll: true })}
                        >
                            Confirm
                        </Button>
                    </span>
                )}
            </div>
        </div>
    );
}

export default function Pipeline({
    stages,
    recentlyClosed,
    leads,
    customers,
    lostReasons,
    users,
    reopenReasons = [],
    canReopen = false,
}: PipelineProps) {
    // ⚠ Adding a deal happens in a popup (Joy, 14 Oct 2026: "click add button
    // pop up will add"), not in a form that sat open above the board. Everyone
    // who may open the board holds crm.leads.manage today, so the button shows
    // for all of them, exactly as the form did.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pipeline" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-muted-foreground text-sm">Open deals by stage. Move a card when the deal moves.</p>
                    <Button type="button" onClick={() => setAdding(true)}>
                        Add a deal
                    </Button>
                </div>

                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {stages.map((stage) => (
                        <div key={stage.id} className="bg-muted/30 space-y-2 rounded-lg p-2.5">
                            <div className="flex items-baseline justify-between px-1">
                                <h2 className="text-sm font-semibold">{stage.name}</h2>
                                <span className="text-muted-foreground text-xs tabular-nums">
                                    {stage.opportunities.length} · {money(stage.total_value)}
                                </span>
                            </div>
                            {stage.opportunities.length === 0 ? (
                                <p className="text-muted-foreground px-1 py-3 text-center text-xs">Empty</p>
                            ) : (
                                stage.opportunities.map((card) => <DealCard key={card.id} card={card} stages={stages} lostReasons={lostReasons} />)
                            )}
                        </div>
                    ))}
                </div>

                {recentlyClosed.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="font-semibold">Recently decided</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {recentlyClosed.map((deal) => (
                                        <tr key={deal.id} className="border-t first:border-t-0">
                                            <td className="px-3 py-2">{deal.name}</td>
                                            <td
                                                className={
                                                    'px-3 py-2 font-medium ' + (deal.status === 'won' ? 'text-emerald-600' : 'text-destructive')
                                                }
                                            >
                                                {deal.status === 'won' ? 'Won' : 'Lost'}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">{money(deal.value)}</td>
                                            <td className="text-muted-foreground px-3 py-2">{deal.lost_reason ?? ''}</td>
                                            <td className="text-muted-foreground px-3 py-2">{formatDate(deal.closed_at)}</td>
                                            <td className="px-3 py-2 text-right">
                                                {canReopen && <DealReopen dealId={deal.id} name={deal.name} reasons={reopenReasons} />}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            <DealDialog
                open={adding}
                onOpenChange={setAdding}
                firstStageId={stages[0]?.id ?? null}
                leads={leads}
                customers={customers}
                users={users}
            />
        </AppLayout>
    );
}
