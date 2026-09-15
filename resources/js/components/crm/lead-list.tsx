import { ConfirmDialog } from '@/components/confirm-dialog';
import { type Lead, placeLine } from '@/components/crm/lead-types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { MapPin } from 'lucide-react';

/**
 * The leads, as a table on a desk and as cards on a phone.
 *
 * ⭐ Cards below the `md` breakpoint because the people this list is for are
 * field officers standing in a bazaar (CRM slice 2b). A seven-column table
 * scrolled sideways on a phone is a table nobody reads; a card with the shop,
 * where it is and a number to tap is the job.
 *
 * ⚠ Verify, archive and their undo are the ASM's (`can_verify`), and each
 * refuses on the server what it would refuse here: a converted lead cannot be
 * archived, an archived one cannot be verified. The buttons are hidden in the
 * same cases so nobody is offered a click that fails.
 */
export function LeadList({
    leads,
    canConvert,
    canVerify,
    onEdit,
    onConvert,
}: {
    leads: Lead[];
    canConvert: boolean;
    canVerify: boolean;
    onEdit: (lead: Lead) => void;
    onConvert: (lead: Lead) => void;
}) {
    if (leads.length === 0) {
        return <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No leads match.</p>;
    }

    return (
        <>
            <div className="flex flex-col gap-3 md:hidden">
                {leads.map((lead) => (
                    <div key={lead.id} className="rounded-lg border p-3 text-sm">
                        <div className="flex items-start justify-between gap-2">
                            <LeadName lead={lead} />
                            <Badge variant="secondary">{lead.status_label}</Badge>
                        </div>
                        <LeadFacts lead={lead} />
                        <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-3 text-xs">
                            <span>{lead.assigned_to ? `Held by ${lead.assigned_to}` : 'Unassigned'}</span>
                            {lead.created_at && <span>Added {lead.created_at}</span>}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1">
                            <LeadActions lead={lead} canConvert={canConvert} canVerify={canVerify} onEdit={onEdit} onConvert={onConvert} />
                        </div>
                    </div>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3">Lead</th>
                            <th className="p-3">Where</th>
                            <th className="p-3">Status</th>
                            <th className="p-3">Held by</th>
                            <th className="p-3">Added</th>
                            <th className="p-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {leads.map((lead) => (
                            <tr key={lead.id} className="border-t align-top">
                                <td className="p-3">
                                    <LeadName lead={lead} />
                                    <div className="text-muted-foreground mt-0.5 text-xs">
                                        {[lead.contact_name, lead.phone, lead.secondary_phone, lead.email].filter(Boolean).join(' · ') || '-'}
                                    </div>
                                </td>
                                <td className="p-3">
                                    {placeLine(lead) || <span className="text-muted-foreground">-</span>}
                                    {lead.brand && <div className="text-muted-foreground text-xs">{lead.brand}</div>}
                                    <MapLink lead={lead} />
                                    <div>
                                        <ProfileLine lead={lead} />
                                    </div>
                                </td>
                                <td className="p-3">
                                    <Badge variant="secondary">{lead.status_label}</Badge>
                                    <LeadStateNotes lead={lead} />
                                </td>
                                <td className="p-3">{lead.assigned_to ?? <span className="text-muted-foreground">Unassigned</span>}</td>
                                <td className="p-3">
                                    {lead.created_at ?? '-'}
                                    {lead.created_by && <div className="text-muted-foreground text-xs">by {lead.created_by}</div>}
                                </td>
                                <td className="p-3">
                                    <div className="flex flex-wrap justify-end gap-1">
                                        <LeadActions
                                            lead={lead}
                                            canConvert={canConvert}
                                            canVerify={canVerify}
                                            onEdit={onEdit}
                                            onConvert={onConvert}
                                        />
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
            {/* ⚠⚠ The only route to a lead's own history from this list. A lead
                with no open task otherwise had a history nobody could open. */}
            <Link href={`/crm/lead/${lead.id}/timeline`} className="font-medium underline-offset-4 hover:underline">
                {lead.name ?? '(no name)'}
            </Link>
            <div className="mt-1 flex flex-wrap items-center gap-1">
                <Badge variant="outline">{lead.lead_type_label}</Badge>
                {/* The officer's A/B/C (answer 6); nothing at all while Unrated. */}
                {lead.importance && (
                    <Badge variant="secondary" title="Importance">
                        {lead.importance}
                    </Badge>
                )}
                {/* ⭐ Unverified is a normal working state (Joy's decision 1):
                    shown as a fact about the lead, in amber, never as an error. */}
                {lead.is_verified ? (
                    <Badge variant="outline" className="border-emerald-500/50 text-emerald-700 dark:text-emerald-400">
                        Verified
                    </Badge>
                ) : (
                    <Badge variant="outline" className="border-amber-500/50 text-amber-700 dark:text-amber-400">
                        Unverified
                    </Badge>
                )}
                {lead.is_archived && <Badge variant="outline">Archived</Badge>}
            </div>
        </div>
    );
}

/** ⭐ Plan D14: the way to the shop, pinned by its first visit with a location. */
function MapLink({ lead }: { lead: Lead }) {
    if (!lead.map_url) {
        return null;
    }

    return (
        <a href={lead.map_url} target="_blank" rel="noreferrer" className="flex w-fit items-center gap-1 text-xs underline underline-offset-4">
            <MapPin className="size-3" />
            Map
        </a>
    );
}

function LeadFacts({ lead }: { lead: Lead }) {
    const place = placeLine(lead);

    return (
        <div className="mt-2 grid gap-0.5">
            {lead.contact_name && <span>{lead.contact_name}</span>}
            {/* A number to TAP: the officer's next move is usually a call. */}
            <span className="flex flex-wrap gap-x-3">
                {[lead.phone, lead.secondary_phone].filter(Boolean).map((phone) => (
                    <a key={phone} href={`tel:${phone}`} className="underline underline-offset-4">
                        {phone}
                    </a>
                ))}
            </span>
            {place && <span className="text-muted-foreground">{place}</span>}
            {lead.landmark && <span className="text-muted-foreground text-xs">Near {lead.landmark}</span>}
            <MapLink lead={lead} />
            <ProfileLine lead={lead} />
            <LeadStateNotes lead={lead} />
        </div>
    );
}

/** One line of what the type profile says: the kind of shop or supplier, and its potential. */
function ProfileLine({ lead }: { lead: Lead }) {
    const bits = [
        lead.profile?.shop_type,
        lead.profile?.supplier_type,
        lead.potential ? `${lead.potential[0].toUpperCase()}${lead.potential.slice(1)} potential` : null,
        lead.profile?.wants_credit === 'yes' ? 'wants credit' : null,
    ].filter(Boolean);

    return bits.length > 0 ? <span className="text-muted-foreground text-xs">{bits.join(' · ')}</span> : null;
}

function LeadStateNotes({ lead }: { lead: Lead }) {
    return (
        <>
            {lead.is_verified && lead.verified_by && (
                <div className="text-muted-foreground mt-1 text-xs">
                    verified by {lead.verified_by}
                    {lead.verified_on ? ` on ${lead.verified_on}` : ''}
                </div>
            )}
            {lead.converted_customer && <div className="text-muted-foreground mt-1 text-xs">→ {lead.converted_customer}</div>}
            {/* Nothing was deleted; this one points at the lead to work instead. */}
            {lead.duplicate_of && <div className="text-muted-foreground mt-1 text-xs">duplicate of {lead.duplicate_of}</div>}
        </>
    );
}

function LeadActions({
    lead,
    canConvert,
    canVerify,
    onEdit,
    onConvert,
}: {
    lead: Lead;
    canConvert: boolean;
    canVerify: boolean;
    onEdit: (lead: Lead) => void;
    onConvert: (lead: Lead) => void;
}) {
    const converted = lead.status === 'converted';
    const send = (method: 'post' | 'delete', url: string) => router.visit(url, { method, preserveScroll: true });

    return (
        <>
            {/* A converted lead is history and is not editable. */}
            {!converted && (
                <Button variant="ghost" size="sm" onClick={() => onEdit(lead)}>
                    Edit
                </Button>
            )}
            {/* ⚠ Not for a LOST lead (reopen it first, or the funnel forgets it
                was ever lost), nor for a duplicate (it is not the one to work). */}
            {canConvert && !converted && lead.status !== 'lost' && !lead.duplicate_of_lead_id && !lead.is_archived && (
                <Button variant="ghost" size="sm" onClick={() => onConvert(lead)}>
                    Convert
                </Button>
            )}
            {canVerify && !lead.is_archived && !lead.is_verified && (
                <Button variant="ghost" size="sm" onClick={() => send('post', route('crm.leads.verify', lead.id))}>
                    Verify
                </Button>
            )}
            {canVerify && !lead.is_archived && lead.is_verified && (
                <ConfirmDialog
                    trigger={
                        <Button variant="ghost" size="sm">
                            Unverify
                        </Button>
                    }
                    title={`Withdraw verification of ${lead.name ?? `lead #${lead.id}`}`}
                    description="The lead stays and keeps working; it simply reads Unverified again until somebody checks it."
                    confirmLabel="Withdraw"
                    onConfirm={() => send('delete', route('crm.leads.unverify', lead.id))}
                />
            )}
            {canVerify && !converted && !lead.is_archived && (
                <ConfirmDialog
                    trigger={
                        <Button variant="ghost" size="sm">
                            Archive
                        </Button>
                    }
                    title={`Archive ${lead.name ?? `lead #${lead.id}`}`}
                    description="It leaves everybody's working list. Nothing is deleted, its history stays, and its phone number stays taken. Restore brings it back."
                    confirmLabel="Archive"
                    onConfirm={() => send('post', route('crm.leads.archive', lead.id))}
                />
            )}
            {canVerify && lead.is_archived && (
                <Button variant="ghost" size="sm" onClick={() => send('delete', route('crm.leads.restore', lead.id))}>
                    Restore
                </Button>
            )}
        </>
    );
}
