import { LeadCorrections, type CrmReasons } from '@/components/crm/crm-corrections';
import { canConvertLead, LeadActionsMenu } from '@/components/crm/lead-actions-menu';
import { leadUpdatePayload } from '@/components/crm/lead-form';
import { dayMonth, whatsappUrl } from '@/components/crm/lead-format';
import { type Lead, type LeadAbilities, type Option, type ValueLabel } from '@/components/crm/lead-types';
import { type ActionSetProps } from '@/components/purchasing/correction-actions';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { MapPin, MessageCircle, Phone } from 'lucide-react';
import { useState, type ReactNode } from 'react';

export interface LeadHeadFacts {
    source: string | null;
    campaign: string | null;
    lost_reason: string | null;
}

/**
 * The head of a lead's page (round 3, 16 Oct 2026): who and where, the way to
 * reach them, and the facts an officer checks before he calls.
 *
 * ⚠ Status changes here through the SAME update route and payload as the edit
 * popup (leadUpdatePayload); Lost still asks for the reason, and Converted is
 * not offered (it is set only by Make a customer).
 *
 * ⭐ WhatsApp opens WhatsApp and sets "Log what happened" to WhatsApp (decision
 * 4): logging is one tap, nothing is logged by itself.
 */
export function LeadHead({
    lead,
    facts,
    can,
    selectableStatuses,
    lostReasons,
    onEdit,
    onConvert,
    onWhatsApp,
    actions,
    reopen,
    reasons,
}: {
    lead: Lead;
    facts: LeadHeadFacts;
    can: LeadAbilities;
    selectableStatuses: ValueLabel[];
    lostReasons: Option[];
    onEdit: () => void;
    onConvert: () => void;
    onWhatsApp: () => void;
    actions?: ActionSetProps;
    reopen?: { blocker: string | null } | null;
    reasons?: CrmReasons;
}) {
    const converted = lead.status === 'converted';
    const whatsapp = whatsappUrl(lead.phone);
    const place = [lead.address, lead.market, lead.area, lead.thana, lead.district].filter(Boolean).join(', ');
    const isShop = lead.lead_type !== 'customer';

    return (
        <section className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <span className="text-foreground font-medium">{lead.lead_type_label}</span>
                {lead.importance && <span>Importance {lead.importance.toUpperCase()}</span>}
                {isShop &&
                    (lead.is_verified ? (
                        <span className="text-emerald-700 dark:text-emerald-400">
                            Verified{lead.verified_by ? ` by ${lead.verified_by}` : ''}
                            {lead.verified_on ? `, ${dayMonth(lead.verified_on)}` : ''}
                        </span>
                    ) : (
                        <span className="text-amber-700 dark:text-amber-400">Not verified</span>
                    ))}
                {lead.brand && <span>{lead.brand}</span>}
                {lead.is_archived && <span>Archived</span>}
                {lead.duplicate_of && <span>Duplicate of {lead.duplicate_of}</span>}
            </div>

            <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-tight">{lead.name ?? `Lead #${lead.id}`}</h1>
                    {lead.contact_name && (
                        <p className="text-sm">
                            {lead.contact_name}
                            <span className="text-muted-foreground">{isShop ? ', proprietor' : ''}</span>
                        </p>
                    )}
                    {place && <p className="text-muted-foreground mt-1 text-sm">{place}</p>}
                    {lead.landmark && <p className="text-muted-foreground text-xs">Near {lead.landmark}</p>}
                    {lead.map_url && (
                        <a
                            href={lead.map_url}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-1 inline-flex items-center gap-1 text-xs underline underline-offset-4"
                        >
                            <MapPin className="size-3" />
                            Map, pinned by the first visit with a location
                        </a>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    {lead.phone && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={`tel:${lead.phone}`} aria-label={`Call ${lead.name ?? 'this lead'}`}>
                                <Phone className="size-3.5" />
                                <span className="tabular-nums">{lead.phone}</span>
                            </a>
                        </Button>
                    )}
                    {whatsapp && (
                        <Button variant="outline" size="sm" asChild>
                            <a href={whatsapp} target="_blank" rel="noreferrer" onClick={onWhatsApp}>
                                <MessageCircle className="size-3.5" />
                                WhatsApp
                            </a>
                        </Button>
                    )}
                    {can.manage && !converted && (
                        <Button variant="outline" size="sm" onClick={onEdit}>
                            Edit details
                        </Button>
                    )}
                    {canConvertLead(lead, can) && (
                        <Button size="sm" onClick={onConvert}>
                            {lead.lead_type === 'supplier' ? 'Make a supplier' : 'Make a customer'}
                        </Button>
                    )}
                    {/* ⭐ Corrections wave 3: Reopen, Reverse conversion or Delete. */}
                    <LeadCorrections
                        leadId={lead.id}
                        name={lead.name ?? `Lead #${lead.id}`}
                        actions={actions}
                        reopen={reopen}
                        reasons={reasons ?? {}}
                    />
                    <LeadActionsMenu lead={lead} can={can} onEdit={onEdit} onConvert={onConvert} withEdit={false} withConvert={false} />
                </div>
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 border-t pt-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                <Fact label="Status">
                    {can.manage && !converted && !lead.is_archived && lead.status !== 'lost' ? (
                        <StatusSelect lead={lead} statuses={selectableStatuses} lostReasons={lostReasons} />
                    ) : (
                        <span className={lead.status === 'lost' ? 'text-destructive font-medium' : 'font-medium'}>{lead.status_label}</span>
                    )}
                    {lead.status === 'lost' && facts.lost_reason && <span className="text-muted-foreground block text-xs">{facts.lost_reason}</span>}
                    {lead.converted_customer && <span className="text-muted-foreground block text-xs">now {lead.converted_customer}</span>}
                </Fact>
                <Fact label="Held by">{lead.assigned_to ?? <span className="text-muted-foreground">Unassigned</span>}</Fact>
                <Fact label="Potential">
                    {lead.potential ? lead.potential[0].toUpperCase() + lead.potential.slice(1) : <span className="text-muted-foreground">-</span>}
                </Fact>
                <Fact label="Came from">
                    {facts.source ?? <span className="text-muted-foreground">Not recorded</span>}
                    {facts.campaign && <span className="text-muted-foreground block text-xs">{facts.campaign}</span>}
                </Fact>
                <Fact label="Added">
                    {lead.created_at ? dayMonth(lead.created_at) : '-'}
                    {lead.created_by && <span className="text-muted-foreground block text-xs">by {lead.created_by}</span>}
                </Fact>
                <Fact label="Last contact">
                    {lead.last_contact ? (
                        <span className={lead.is_cold ? 'text-amber-700 dark:text-amber-400' : ''}>
                            {dayMonth(lead.last_contact.at)}
                            {lead.last_contact.label && `, ${lead.last_contact.label.toLowerCase()}`}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">Not yet</span>
                    )}
                    {lead.is_cold && <span className="block text-xs text-amber-700 dark:text-amber-400">{lead.quiet_days} days quiet</span>}
                </Fact>
            </dl>
        </section>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-0.5">{children}</dd>
        </div>
    );
}

function StatusSelect({ lead, statuses, lostReasons }: { lead: Lead; statuses: ValueLabel[]; lostReasons: Option[] }) {
    const [lostReason, setLostReason] = useState(lead.lost_reason_id ? String(lead.lost_reason_id) : '');
    const [choosingLost, setChoosingLost] = useState(false);
    const [saving, setSaving] = useState(false);

    const save = (status: string, lostReasonId: string) => {
        setSaving(true);
        router.put(route('crm.leads.update', lead.id), leadUpdatePayload(lead, { status, lost_reason_id: lostReasonId }), {
            preserveScroll: true,
            onFinish: () => setSaving(false),
            onSuccess: () => setChoosingLost(false),
        });
    };

    return (
        <div className="flex flex-col gap-1.5">
            <SearchSelect
                fit
                aria-label="Status"
                disabled={saving}
                value={choosingLost ? 'lost' : lead.status}
                onChange={(e) => {
                    if (e.target.value === 'lost' && lead.status !== 'lost') {
                        setChoosingLost(true);
                        return;
                    }
                    setChoosingLost(false);
                    if (e.target.value !== lead.status) save(e.target.value, e.target.value === 'lost' ? lostReason : '');
                }}
            >
                {statuses.map((status) => (
                    <option key={status.value} value={status.value}>
                        {status.label}
                    </option>
                ))}
            </SearchSelect>
            {choosingLost && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <SearchSelect fit aria-label="Why it was lost" value={lostReason} onChange={(e) => setLostReason(e.target.value)}>
                        <option value="">Why was it lost?</option>
                        {lostReasons.map((reason) => (
                            <option key={reason.id} value={reason.id}>
                                {reason.name}
                            </option>
                        ))}
                    </SearchSelect>
                    <Button size="sm" disabled={saving} onClick={() => save('lost', lostReason)}>
                        Mark lost
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setChoosingLost(false)}>
                        Cancel
                    </Button>
                </div>
            )}
        </div>
    );
}
