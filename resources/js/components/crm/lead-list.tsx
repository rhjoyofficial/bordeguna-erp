import { LeadActionsMenu } from '@/components/crm/lead-actions-menu';
import { dayMonth, whenLabel } from '@/components/crm/lead-format';
import { type Lead, type LeadAbilities, placeLine } from '@/components/crm/lead-types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { MapPin, Phone } from 'lucide-react';

/**
 * The leads, as a table on a desk and as cards on a phone.
 *
 * ⭐ Cards below the `md` breakpoint because the people this list is for are
 * field officers standing in a bazaar (CRM slice 2b). A table scrolled
 * sideways on a phone is a table nobody reads; a card with the shop, where it
 * is and a number to tap is the job.
 *
 * ⭐ Round 3 (16 Oct 2026): two columns an officer plans his day by (Next
 * follow-up, Last contact), and the row's actions become Call plus a More menu
 * gated line by line (LeadActionsMenu).
 *
 * Colour only in text, by meaning: red for overdue, amber for a lead going
 * quiet or not yet verified, green for verified.
 */
export function LeadList({
    leads,
    today,
    can,
    onEdit,
    onConvert,
}: {
    leads: Lead[];
    /** YYYY-MM-DD in the app's clock. */
    today: string;
    can: LeadAbilities;
    onEdit: (lead: Lead) => void;
    onConvert: (lead: Lead) => void;
}) {
    if (leads.length === 0) {
        return <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No leads match.</p>;
    }

    return (
        <>
            <ul className="flex flex-col gap-3 md:hidden">
                {leads.map((lead) => (
                    <li key={lead.id} className="bg-card rounded-xl border p-3 text-sm">
                        <div className="flex items-start justify-between gap-2">
                            <LeadName lead={lead} />
                            <StatusText lead={lead} />
                        </div>
                        <div className="text-muted-foreground mt-1.5 text-xs">{placeLine(lead) || 'No place yet'}</div>
                        <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                            <NextFollowUp lead={lead} today={today} inline />
                            <LastContact lead={lead} inline />
                        </div>
                        <div className="text-muted-foreground mt-1.5 text-xs">{lead.assigned_to ? `Held by ${lead.assigned_to}` : 'Unassigned'}</div>
                        <div className="mt-2.5 flex flex-wrap gap-1.5">
                            <RowActions lead={lead} can={can} onEdit={onEdit} onConvert={onConvert} />
                        </div>
                    </li>
                ))}
            </ul>

            <div className="bg-card hidden overflow-x-auto rounded-xl border md:block">
                <table className="w-full text-sm">
                    <thead className="text-muted-foreground border-b text-left text-xs">
                        <tr>
                            <th className="px-3 py-2.5 font-medium">Lead</th>
                            <th className="px-3 py-2.5 font-medium">Where</th>
                            <th className="px-3 py-2.5 font-medium">Status</th>
                            <th className="px-3 py-2.5 font-medium">Next follow-up</th>
                            <th className="px-3 py-2.5 font-medium">Last contact</th>
                            <th className="px-3 py-2.5 font-medium">Held by</th>
                            <th className="px-3 py-2.5 text-right font-medium">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {leads.map((lead) => (
                            <tr key={lead.id} className="border-t align-top first:border-t-0">
                                <td className="px-3 py-3">
                                    <LeadName lead={lead} />
                                    {lead.phone && <div className="text-muted-foreground mt-0.5 text-xs tabular-nums">{lead.phone}</div>}
                                </td>
                                <td className="px-3 py-3">
                                    <div>{lead.market || lead.area || <span className="text-muted-foreground">-</span>}</div>
                                    <div className="text-muted-foreground text-xs">{[lead.thana, lead.district].filter(Boolean).join(', ')}</div>
                                    {lead.map_url && (
                                        <a
                                            href={lead.map_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-0.5 inline-flex items-center gap-1 text-xs underline underline-offset-4"
                                        >
                                            <MapPin className="size-3" />
                                            Map
                                        </a>
                                    )}
                                </td>
                                <td className="px-3 py-3">
                                    <StatusText lead={lead} />
                                    <StateNote lead={lead} />
                                </td>
                                <td className="px-3 py-3">
                                    <NextFollowUp lead={lead} today={today} />
                                </td>
                                <td className="px-3 py-3">
                                    <LastContact lead={lead} />
                                </td>
                                <td className="px-3 py-3">{lead.assigned_to ?? <span className="text-muted-foreground">Unassigned</span>}</td>
                                <td className="px-3 py-3">
                                    <div className="flex justify-end gap-1.5">
                                        <RowActions lead={lead} can={can} onEdit={onEdit} onConvert={onConvert} />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function LeadName({ lead }: { lead: Lead }) {
    return (
        <div className="min-w-0">
            {/* ⚠⚠ The lead's own page: its history, its follow-ups, its details. */}
            <Link href={`/crm/lead/${lead.id}/timeline`} className="font-medium underline-offset-4 hover:underline">
                {lead.name ?? '(no name)'}
            </Link>
            <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                <span>{lead.lead_type_label}</span>
                {/* The officer's A/B/C (answer 6); nothing at all while Unrated. */}
                {lead.importance && <span title="Importance">{lead.importance.toUpperCase()}</span>}
                {/* ⭐ Not verified is a normal working state (Joy's decision 1):
                    shown as a fact, in amber, never as an error. */}
                {lead.lead_type !== 'customer' &&
                    (lead.is_verified ? (
                        <span className="text-emerald-700 dark:text-emerald-400">Verified</span>
                    ) : (
                        <span className="text-amber-700 dark:text-amber-400">Not verified</span>
                    ))}
                {lead.is_archived && <span>Archived</span>}
            </div>
        </div>
    );
}

function StatusText({ lead }: { lead: Lead }) {
    return <span className={cn('text-sm font-medium whitespace-nowrap', lead.status === 'lost' && 'text-destructive')}>{lead.status_label}</span>;
}

/** What the status alone does not say: converted into what, a duplicate of whom. */
function StateNote({ lead }: { lead: Lead }) {
    const { canRecordOrders } = usePage<SharedData>().props;

    if (lead.converted_customer) {
        return (
            <div className="text-muted-foreground mt-0.5 text-xs">
                now {lead.converted_customer}
                {/* ⭐ 26 Sep 2026 (Q21): the officer takes the order from here, customer already chosen. */}
                {lead.converted_customer_id && canRecordOrders && (
                    <>
                        {', '}
                        <Link href={`/sales/orders?new&customer=${lead.converted_customer_id}`} className="underline">
                            new order
                        </Link>
                    </>
                )}
            </div>
        );
    }

    if (lead.converted_supplier && lead.converted_supplier_id) {
        return (
            <Link href={`/purchasing/suppliers/${lead.converted_supplier_id}`} className="text-muted-foreground mt-0.5 block text-xs underline">
                now supplier {lead.converted_supplier}
            </Link>
        );
    }

    // Nothing was deleted; this one points at the lead to work instead.
    if (lead.duplicate_of) {
        return <div className="text-muted-foreground mt-0.5 text-xs">duplicate of {lead.duplicate_of}</div>;
    }

    return null;
}

function NextFollowUp({ lead, today, inline = false }: { lead: Lead; today: string; inline?: boolean }) {
    const next = lead.next_follow_up;
    const closed = ['converted', 'lost'].includes(lead.status) || lead.is_archived || !!lead.duplicate_of_lead_id;

    if (!next) {
        if (closed) return inline ? null : <span className="text-muted-foreground">-</span>;

        return <span className="text-amber-700 dark:text-amber-400">{inline ? 'Next: nothing planned' : 'Nothing planned'}</span>;
    }

    const label = whenLabel(next.at, today, next.overdue);

    return (
        <span className={inline ? '' : 'block'}>
            <span className={cn(next.overdue ? 'text-destructive font-medium' : next.today && 'font-medium')}>
                {inline ? `Next: ${label}` : label}
            </span>
            {!inline && next.title && <span className="text-muted-foreground block max-w-48 truncate text-xs">{next.title}</span>}
        </span>
    );
}

function LastContact({ lead, inline = false }: { lead: Lead; inline?: boolean }) {
    const last = lead.last_contact;
    const quiet = lead.is_cold ? `${lead.quiet_days} days quiet` : null;

    if (!last) {
        return <span className={cn('text-muted-foreground', inline || 'block')}>{inline ? 'Not contacted yet' : 'Not yet'}</span>;
    }

    return (
        <span className={inline ? '' : 'block'}>
            <span className={cn(lead.is_cold && 'text-amber-700 dark:text-amber-400')}>
                {inline ? `Last: ${dayMonth(last.at)}` : dayMonth(last.at)}
                {inline && last.label && `, ${last.label.toLowerCase()}`}
            </span>
            {!inline && <span className="text-muted-foreground block text-xs">{[last.label, quiet].filter(Boolean).join(', ')}</span>}
            {inline && quiet && <span className="text-amber-700 dark:text-amber-400">, {quiet}</span>}
        </span>
    );
}

function RowActions({
    lead,
    can,
    onEdit,
    onConvert,
}: {
    lead: Lead;
    can: LeadAbilities;
    onEdit: (lead: Lead) => void;
    onConvert: (lead: Lead) => void;
}) {
    return (
        <>
            {/* A number to TAP: the officer's next move is usually a call. */}
            {lead.phone && (
                <Button variant="outline" size="sm" asChild>
                    <a href={`tel:${lead.phone}`} aria-label={`Call ${lead.name ?? 'this lead'}`}>
                        <Phone className="size-3.5" />
                        Call
                    </a>
                </Button>
            )}
            <LeadActionsMenu lead={lead} can={can} onEdit={onEdit} onConvert={onConvert} followUpHref={`/crm/lead/${lead.id}/timeline#follow-up`} />
        </>
    );
}
