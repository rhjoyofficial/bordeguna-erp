import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
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

function NewTicketForm({ customers, users, slaHours }: Pick<TicketsProps, 'customers' | 'users' | 'slaHours'>) {
    const form = useForm({
        subject: '',
        description: '',
        party_kind: 'customer',
        customer_id: '',
        caller_name: '',
        caller_phone: '',
        category: 'complaint',
        priority: 'normal',
        assigned_to_user_id: '',
    });

    return (
        <form
            className="border-border bg-card grid gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    subject: data.subject,
                    description: data.description || null,
                    customer_id: data.party_kind === 'customer' ? data.customer_id || null : null,
                    caller_name: data.party_kind === 'walkin' ? data.caller_name || null : null,
                    caller_phone: data.party_kind === 'walkin' ? data.caller_phone || null : null,
                    category: data.category,
                    priority: data.priority,
                    assigned_to_user_id: data.assigned_to_user_id || null,
                }));
                form.post('/crm/tickets', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <div className="space-y-1 sm:col-span-2">
                <Label className="text-xs">What happened</Label>
                <Input placeholder="e.g. Broken jar in the carton" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                {form.errors.subject && <p className="text-destructive text-xs">{form.errors.subject}</p>}
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Who is complaining</Label>
                <Select value={form.data.party_kind} onValueChange={(value) => form.setData('party_kind', value)}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="customer">A customer on file</SelectItem>
                        <SelectItem value="walkin">Walk-in / phone caller</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            {form.data.party_kind === 'customer' ? (
                <div className="space-y-1">
                    <Label className="text-xs">Customer</Label>
                    <SearchableSelect
                        value={form.data.customer_id}
                        onChange={(value) => form.setData('customer_id', value)}
                        options={customers.map((customer) => ({ value: String(customer.id), label: customer.name }))}
                        placeholder="Pick the customer"
                        searchPlaceholder="Search customers…"
                    />
                    {form.errors.customer_id && <p className="text-destructive text-xs">{form.errors.customer_id}</p>}
                </div>
            ) : (
                <div className="flex gap-2">
                    <div className="flex-1 space-y-1">
                        <Label className="text-xs">Name</Label>
                        <Input value={form.data.caller_name} onChange={(e) => form.setData('caller_name', e.target.value)} />
                        {form.errors.caller_name && <p className="text-destructive text-xs">{form.errors.caller_name}</p>}
                    </div>
                    <div className="flex-1 space-y-1">
                        <Label className="text-xs">Phone</Label>
                        <Input value={form.data.caller_phone} onChange={(e) => form.setData('caller_phone', e.target.value)} />
                    </div>
                </div>
            )}
            <div className="space-y-1">
                <Label className="text-xs">Category</Label>
                <Select value={form.data.category} onValueChange={(value) => form.setData('category', value)}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="complaint">Complaint</SelectItem>
                        <SelectItem value="quality">Product quality</SelectItem>
                        <SelectItem value="delivery">Delivery</SelectItem>
                        <SelectItem value="question">Question</SelectItem>
                        <SelectItem value="other">Other</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Priority (sets the clock)</Label>
                <Select value={form.data.priority} onValueChange={(value) => form.setData('priority', value)}>
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {Object.entries(slaHours).map(([priority, hours]) => (
                            <SelectItem key={priority} value={priority}>
                                {priority} ({hours}h to resolve)
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="space-y-1">
                <Label className="text-xs">Assign to</Label>
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
            <div className="space-y-1 sm:col-span-3">
                <Label className="text-xs">Details</Label>
                <Input placeholder="what, when, which delivery…" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </div>
            <div className="flex items-end">
                <Button type="submit" disabled={form.processing}>
                    Open ticket
                </Button>
            </div>
        </form>
    );
}

function TicketCard({ ticket }: { ticket: TicketRow }) {
    const [open, setOpen] = useState(false);
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
                    <p className={'font-medium ' + (done ? (ticket.breached ? 'text-destructive' : 'text-emerald-600') : ticket.breached ? 'text-destructive' : '')}>
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
                                    placeholder="what fixed it? (required to resolve)"
                                    value={resolve.data.resolution}
                                    onChange={(e) => resolve.setData('resolution', e.target.value)}
                                />
                                <Button type="submit" size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" disabled={resolve.processing}>
                                    Resolve
                                </Button>
                            </form>
                            {resolve.errors.resolution && <p className="text-destructive text-xs">{resolve.errors.resolution}</p>}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default function Tickets({ tickets, customers, users, slaHours }: TicketsProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tickets" />
            <div className="space-y-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Customer service tickets</h1>
                    <p className="text-muted-foreground text-sm">
                        Every complaint with a running clock: the resolve-by was stamped when the ticket was opened, and late is late -
                        computed, never edited. Overdue tickets sort to the top.
                    </p>
                </div>

                <NewTicketForm customers={customers} users={users} slaHours={slaHours} />

                <div className="space-y-2">
                    {tickets.data.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No tickets. May it stay that way.</p>
                    ) : (
                        tickets.data.map((ticket) => <TicketCard key={ticket.id} ticket={ticket} />)
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
