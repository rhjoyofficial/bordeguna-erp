import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
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
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pipeline', href: '/crm/pipeline' }];

const money = (value: number | string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 0 });

function NewDealForm({ stages, leads, customers, users }: Pick<PipelineProps, 'stages' | 'leads' | 'customers' | 'users'>) {
    const form = useForm({
        name: '',
        party_kind: 'lead',
        lead_id: '',
        customer_id: '',
        stage_id: String(stages[0]?.id ?? ''),
        value: '',
        expected_close_on: '',
        assigned_to_user_id: '',
        next_action: '',
    });

    return (
        <form
            className="border-border bg-card grid gap-3 rounded-lg border p-4 sm:grid-cols-3 lg:grid-cols-6"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    name: data.name,
                    lead_id: data.party_kind === 'lead' ? data.lead_id || null : null,
                    customer_id: data.party_kind === 'customer' ? data.customer_id || null : null,
                    stage_id: data.stage_id,
                    value: data.value,
                    expected_close_on: data.expected_close_on || null,
                    assigned_to_user_id: data.assigned_to_user_id || null,
                    next_action: data.next_action || null,
                }));
                form.post('/crm/opportunities', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">Deal</Label>
                <Input placeholder="e.g. Dealer opening order" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Who</Label>
                <Select value={form.data.party_kind} onValueChange={(value) => form.setData('party_kind', value)}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="lead">A lead</SelectItem>
                        <SelectItem value="customer">A customer</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">{form.data.party_kind === 'lead' ? 'Lead' : 'Customer'}</Label>
                {form.data.party_kind === 'lead' ? (
                    <SearchableSelect
                        value={form.data.lead_id}
                        onChange={(value) => form.setData('lead_id', value)}
                        options={leads.map((lead) => ({ value: String(lead.id), label: lead.name }))}
                        placeholder="Pick the lead"
                        searchPlaceholder="Search leads…"
                    />
                ) : (
                    <SearchableSelect
                        value={form.data.customer_id}
                        onChange={(value) => form.setData('customer_id', value)}
                        options={customers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
                        placeholder="Pick the customer"
                        searchPlaceholder="Search customers…"
                    />
                )}
                {(form.errors.lead_id || form.errors.customer_id) && (
                    <p className="text-destructive text-xs">{form.errors.lead_id ?? form.errors.customer_id}</p>
                )}
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Value (BDT)</Label>
                <Input type="number" step="0.01" min="0" placeholder="0" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                {form.errors.value && <p className="text-destructive text-xs">{form.errors.value}</p>}
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Expected close</Label>
                <Input type="date" value={form.data.expected_close_on} onChange={(e) => form.setData('expected_close_on', e.target.value)} />
            </div>
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">Salesperson</Label>
                <Select value={form.data.assigned_to_user_id} onValueChange={(value) => form.setData('assigned_to_user_id', value)}>
                    <SelectTrigger>
                        <SelectValue placeholder="Unassigned" />
                    </SelectTrigger>
                    <SelectContent>
                        {users.map((user) => (
                            <SelectItem key={user.id} value={String(user.id)}>
                                {user.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">Next action</Label>
                <Input
                    placeholder="e.g. call after Friday prayers"
                    value={form.data.next_action}
                    onChange={(e) => form.setData('next_action', e.target.value)}
                />
            </div>
            <div className="flex items-end">
                <Button type="submit" disabled={form.processing}>
                    Add deal
                </Button>
            </div>
        </form>
    );
}

function DealCard({ card, stages, lostReasons }: { card: Card; stages: Stage[]; lostReasons: Option[] }) {
    const [losing, setLosing] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <div className="border-border bg-card space-y-2 rounded-lg border p-3 text-sm shadow-sm">
            <div className="flex items-start justify-between gap-2">
                <p className="font-medium">{card.name}</p>
                <p className="font-semibold tabular-nums whitespace-nowrap">{money(card.value)}</p>
            </div>
            <p className="text-muted-foreground text-xs">
                {card.party}
                {card.is_customer ? ' (customer)' : ' (lead)'}
                {card.expected_close_on && ` · closes ${card.expected_close_on}`}
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

export default function Pipeline({ stages, recentlyClosed, leads, customers, lostReasons, users }: PipelineProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pipeline" />
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Sales pipeline</h1>
                    <p className="text-muted-foreground text-sm">
                        Every deal being chased, one stage at a time. Won and lost are outcomes with a stamp - not columns - and losing
                        always keeps its reason.
                    </p>
                </div>

                <NewDealForm stages={stages} leads={leads} customers={customers} users={users} />

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
                                stage.opportunities.map((card) => (
                                    <DealCard key={card.id} card={card} stages={stages} lostReasons={lostReasons} />
                                ))
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
                                            <td className={'px-3 py-2 font-medium ' + (deal.status === 'won' ? 'text-emerald-600' : 'text-destructive')}>
                                                {deal.status === 'won' ? 'Won' : 'Lost'}
                                            </td>
                                            <td className="px-3 py-2 text-right tabular-nums">{money(deal.value)}</td>
                                            <td className="text-muted-foreground px-3 py-2">{deal.lost_reason ?? ''}</td>
                                            <td className="text-muted-foreground px-3 py-2">{deal.closed_at?.slice(0, 10)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
