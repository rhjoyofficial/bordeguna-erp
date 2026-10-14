import { TicketDialog } from '@/components/crm/ticket-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Customer service tickets (Module 4, Slice 4). The resolve-by clock was
 * stamped when the ticket was born; a breach is computed, never typed.
 * Overdue-and-open sorts first, because that is what the screen is for.
 */
interface Reply {
    id: number;
    body: string;
    is_internal: boolean;
    by: string | null;
    at: string;
}

interface TicketRow {
    id: number;
    ticket_number: string;
    subject: string;
    party: string;
    category: string;
    priority: string;
    status: string;
    resolve_by: string;
    resolved_at: string | null;
    assigned_to: string | null;
    breached: boolean;
    replies: Reply[];
    resolution: string | null;
}

interface Option {
    id: number;
    name: string;
}

interface TicketsProps {
    tickets: { data: TicketRow[] };
    customers: Option[];
    users: Option[];
    slaHours: Record<string, number>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tickets', href: '/crm/tickets' }];

function TicketCard({ ticket, users }: { ticket: TicketRow; users: Option[] }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    // CRM-011 (14 Oct 2026): the assign route existed with nothing calling it.
    const assign = useForm({ assigned_to_user_id: '' });
    const reply = useForm<{ body: string; is_internal: boolean }>({ body: '', is_internal: false });
    const resolve = useForm({ resolution: '' });
    const done = ticket.status === 'resolved' || ticket.status === 'closed';

    return (
        <div className={'rounded-lg border p-3 ' + (ticket.breached && !done ? 'border-destructive/50 bg-destructive/5' : 'border-border bg-card')}>
            <button type="button" className="flex w-full items-start justify-between gap-3 text-left" onClick={() => setOpen(!open)}>
                <div>
                    <p className="text-sm font-medium">
                        {ticket.ticket_number} · {ticket.subject}
                    </p>
                    <p className="text-muted-foreground text-xs">
                        {ticket.party} · {ticket.category} · {ticket.priority}
                        {ticket.assigned_to && ` · ${ticket.assigned_to}`}
                    </p>
                </div>
                <div className="text-right text-xs">
                    <p
                        className={
                            'font-medium ' +
                            (done ? (ticket.breached ? 'text-destructive' : 'text-emerald-600') : ticket.breached ? 'text-destructive' : '')
                        }
                    >
                        {done ? (ticket.breached ? 'Resolved LATE' : 'Resolved in time') : ticket.breached ? 'OVERDUE' : 'Open'}
                    </p>
                    <p className="text-muted-foreground">due {ticket.resolve_by?.slice(0, 16).replace('T', ' ')}</p>
                </div>
            </button>

            {open && (
                <div className="mt-3 space-y-3 border-t pt-3">
                    {ticket.replies.map((item) => (
                        <div key={item.id} className={'rounded-md p-2 text-sm ' + (item.is_internal ? 'bg-amber-500/10' : 'bg-muted/40')}>
                            <p className="text-muted-foreground text-xs">
                                {item.by} · {item.at?.slice(0, 16).replace('T', ' ')}
                                {item.is_internal && ' · internal note'}
                            </p>
                            <p>{item.body}</p>
                        </div>
                    ))}
                    {ticket.resolution && (
                        <div className="rounded-md bg-emerald-500/10 p-2 text-sm">
                            <p className="text-muted-foreground text-xs">Resolution</p>
                            <p>{ticket.resolution}</p>
                        </div>
                    )}

                    {!done && (
                        <>
                            <form
                                className="flex gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    reply.post(`/crm/tickets/${ticket.id}/reply`, { preserveScroll: true, onSuccess: () => reply.reset() });
                                }}
                            >
                                <Input
                                    className="flex-1"
                                    aria-required
                                    placeholder="what was said or done…"
                                    value={reply.data.body}
                                    onChange={(e) => reply.setData('body', e.target.value)}
                                />
                                <label className="text-muted-foreground flex items-center gap-1 text-xs">
                                    <input
                                        type="checkbox"
                                        checked={reply.data.is_internal}
                                        onChange={(e) => reply.setData('is_internal', e.target.checked)}
                                    />
                                    internal
                                </label>
                                <Button type="submit" variant="outline" size="sm" disabled={reply.processing}>
                                    Reply
                                </Button>
                            </form>
                            <form
                                className="flex gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    resolve.post(`/crm/tickets/${ticket.id}/resolve`, { preserveScroll: true });
                                }}
                            >
                                <Input
                                    className="flex-1"
                                    aria-required
                                    placeholder="what fixed it? (required to resolve)"
                                    value={resolve.data.resolution}
                                    onChange={(e) => resolve.setData('resolution', e.target.value)}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="bg-emerald-600 text-white hover:bg-emerald-700"
                                    disabled={resolve.processing}
                                >
                                    Resolve
                                </Button>
                            </form>
                            {resolve.errors.resolution && <p className="text-destructive text-xs">{resolve.errors.resolution}</p>}
                            <form
                                className="flex items-end gap-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    assign.post(`/crm/tickets/${ticket.id}/assign`, { preserveScroll: true, onSuccess: () => assign.reset() });
                                }}
                            >
                                <div className="space-y-1">
                                    <Label className="text-xs">{t('tickets.reassign.label')}</Label>
                                    <Select
                                        value={assign.data.assigned_to_user_id}
                                        onValueChange={(value) => assign.setData('assigned_to_user_id', value)}
                                    >
                                        <SelectTrigger className="w-56">
                                            <SelectValue placeholder={t('tickets.reassign.placeholder')} />
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
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={assign.processing || assign.data.assigned_to_user_id === ''}
                                >
                                    {t('tickets.reassign.save')}
                                </Button>
                            </form>
                            {assign.errors.assigned_to_user_id && <p className="text-destructive text-xs">{assign.errors.assigned_to_user_id}</p>}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Tickets({ tickets, customers, users, slaHours }: TicketsProps) {
    const { t } = useTranslation();
    // ⚠ Opening a ticket happens in a popup (Joy, 14 Oct 2026: "click add button
    // pop up will add"), not in a form that sat open above the list. Everyone
    // who may open this page holds crm.leads.manage today, so the button shows
    // for all of them, exactly as the form did.
    const [opening, setOpening] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tickets" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold">Customer service tickets</h1>
                    <Button type="button" onClick={() => setOpening(true)}>
                        {t('tickets.new.title')}
                    </Button>
                </div>

                <div className="space-y-2">
                    {tickets.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No tickets. May it stay that way.</p>
                    ) : (
                        tickets.data.map((ticket) => <TicketCard key={ticket.id} ticket={ticket} users={users} />)
                    )}
                </div>
            </div>

            <TicketDialog open={opening} onOpenChange={setOpening} customers={customers} users={users} slaHours={slaHours} />
        </AppLayout>
    );
}
