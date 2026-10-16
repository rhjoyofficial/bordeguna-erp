import { type DuplicateMatch, type Lead, type LeadAbilities } from '@/components/crm/lead-types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/react';
import { MoreHorizontal } from 'lucide-react';
import { useEffect, useState } from 'react';

type Confirm = { title: string; description: string; label: string; method: 'post' | 'delete'; url: string };

/**
 * A lead's "More" menu (round 3, 16 Oct 2026): every action beyond Call, each
 * line shown only to somebody allowed to do it, and refused by the server in
 * the same cases it is hidden here.
 *
 *   Edit details, Add a follow-up   crm.leads.manage
 *   Make a customer (or supplier)   crm.leads.convert (+ purchasing.suppliers.manage)
 *   Verify, Remove verification,
 *   Mark as duplicate, Archive      crm.leads.verify (the ASM's, not the officer's)
 *
 * ⚠ A converted lead cannot be archived or edited, an archived one cannot be
 * verified, a lost or duplicate one is not converted: the lines are hidden in
 * exactly those cases, so nobody is offered a click that fails.
 */
export function LeadActionsMenu({
    lead,
    can,
    onEdit,
    onConvert,
    withEdit = true,
    withConvert = true,
    followUpHref,
}: {
    lead: Lead;
    can: LeadAbilities;
    onEdit: (lead: Lead) => void;
    onConvert: (lead: Lead) => void;
    /** The lead page draws Edit and Make a customer as their own buttons. */
    withEdit?: boolean;
    withConvert?: boolean;
    /** Where "Add a follow-up" goes; omitted on the lead page, where the form is. */
    followUpHref?: string;
}) {
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [duplicate, setDuplicate] = useState(false);

    const name = lead.name ?? `lead #${lead.id}`;
    const converted = lead.status === 'converted';
    const mayEdit = withEdit && can.manage && !converted;
    const mayFollowUp = !!followUpHref && can.manage && !converted;
    const mayConvert = withConvert && canConvertLead(lead, can);
    const mayVerify = can.verify && !lead.is_archived;
    const mayArchive = can.verify && !converted && !lead.is_archived;
    const mayDuplicate = can.verify && !converted && !lead.is_archived && !lead.duplicate_of_lead_id;
    const mayRestore = can.verify && lead.is_archived;

    if (!mayEdit && !mayFollowUp && !mayConvert && !mayVerify && !mayArchive && !mayRestore) {
        return null;
    }

    const send = (method: 'post' | 'delete', url: string) => router.visit(url, { method, preserveScroll: true });

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="sm" aria-label={`More actions for ${name}`}>
                        <MoreHorizontal className="size-4" />
                        More
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="min-w-52">
                    {mayEdit && <DropdownMenuItem onSelect={() => onEdit(lead)}>Edit details</DropdownMenuItem>}
                    {mayFollowUp && (
                        <DropdownMenuItem asChild>
                            <Link href={followUpHref}>Add a follow-up</Link>
                        </DropdownMenuItem>
                    )}
                    {mayConvert && (
                        <DropdownMenuItem onSelect={() => onConvert(lead)}>
                            {lead.lead_type === 'supplier' ? 'Make a supplier' : 'Make a customer'}
                        </DropdownMenuItem>
                    )}
                    {(mayVerify || mayArchive || mayRestore) && (mayEdit || mayFollowUp || mayConvert) && <DropdownMenuSeparator />}
                    {mayVerify && !lead.is_verified && (
                        <DropdownMenuItem onSelect={() => send('post', route('crm.leads.verify', lead.id))}>Mark verified</DropdownMenuItem>
                    )}
                    {mayVerify && lead.is_verified && (
                        <DropdownMenuItem
                            onSelect={() =>
                                setConfirm({
                                    title: `Remove the verification of ${name}`,
                                    description: 'The lead stays and keeps working; it simply reads Not verified again until somebody checks it.',
                                    label: 'Remove verification',
                                    method: 'delete',
                                    url: route('crm.leads.unverify', lead.id),
                                })
                            }
                        >
                            Remove verification
                        </DropdownMenuItem>
                    )}
                    {mayDuplicate && <DropdownMenuItem onSelect={() => setDuplicate(true)}>Mark as duplicate of...</DropdownMenuItem>}
                    {mayArchive && (
                        <DropdownMenuItem
                            onSelect={() =>
                                setConfirm({
                                    title: `Archive ${name}`,
                                    description:
                                        "It leaves everybody's working list. Nothing is deleted, its history stays, and its phone number stays taken. Restore brings it back.",
                                    label: 'Archive',
                                    method: 'post',
                                    url: route('crm.leads.archive', lead.id),
                                })
                            }
                        >
                            Archive
                        </DropdownMenuItem>
                    )}
                    {mayRestore && <DropdownMenuItem onSelect={() => send('delete', route('crm.leads.restore', lead.id))}>Restore</DropdownMenuItem>}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={confirm !== null} onOpenChange={(open) => !open && setConfirm(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm?.title}</DialogTitle>
                        <DialogDescription>{confirm?.description}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            onClick={() => {
                                if (confirm) send(confirm.method, confirm.url);
                                setConfirm(null);
                            }}
                        >
                            {confirm?.label}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {duplicate && <DuplicateDialog lead={lead} onClose={() => setDuplicate(false)} />}
        </>
    );
}

/** Whether "Make a customer" (or a supplier, plan D12) applies to this lead for this reader. */
export function canConvertLead(lead: Lead, can: LeadAbilities): boolean {
    return (
        (lead.lead_type === 'supplier' ? can.convertSupplier : can.convert) &&
        lead.status !== 'converted' &&
        // Not a LOST lead (reopen it first, or the funnel forgets it was lost),
        // nor a duplicate (it is not the one to work), nor an archived one.
        lead.status !== 'lost' &&
        !lead.duplicate_of_lead_id &&
        !lead.is_archived
    );
}

/**
 * Two leads are one person: THIS lead becomes the duplicate, the one picked
 * survives (LeadController::linkDuplicate). The candidates are the leads that
 * share its number, read from the same duplicate check the form uses.
 */
function DuplicateDialog({ lead, onClose }: { lead: Lead; onClose: () => void }) {
    const [matches, setMatches] = useState<DuplicateMatch['leads'] | null>(null);
    const [sending, setSending] = useState(false);

    useEffect(() => {
        const phones = [lead.phone, lead.secondary_phone].filter((phone): phone is string => !!phone);

        if (phones.length === 0) {
            setMatches([]);
            return;
        }

        Promise.all(
            phones.map((phone) =>
                fetch(`/crm/leads/duplicates?${new URLSearchParams({ phone, ignore_lead_id: String(lead.id) })}`, {
                    headers: { Accept: 'application/json' },
                }).then((response) => (response.ok ? (response.json() as Promise<DuplicateMatch>) : null)),
            ),
        ).then((answers) => {
            const seen = new Map<number, DuplicateMatch['leads'][number]>();
            answers.forEach((answer) => answer?.leads.forEach((match) => seen.set(match.id, match)));
            setMatches([...seen.values()]);
        });
    }, [lead]);

    const keep = (survivorId: number) => {
        setSending(true);
        router.post(
            route('crm.leads.duplicate-of', lead.id),
            { survivor_id: survivorId },
            { preserveScroll: true, onFinish: () => setSending(false), onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Mark {lead.name ?? `lead #${lead.id}`} as a duplicate</DialogTitle>
                    <DialogDescription>
                        Pick the lead to keep working. This one leaves the working list; nothing is deleted and its history stays.
                    </DialogDescription>
                </DialogHeader>
                {matches === null ? (
                    <p className="text-muted-foreground text-sm">Looking for leads with the same number...</p>
                ) : matches.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No other lead you can see shares this number. Open Edit details to check it.</p>
                ) : (
                    <ul className="divide-y rounded-md border">
                        {matches.map((match) => (
                            <li key={match.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span>
                                    <span className="font-medium">{match.name ?? `Lead #${match.id}`}</span>
                                    <span className="text-muted-foreground block text-xs">
                                        {match.phone} · {match.status}
                                    </span>
                                </span>
                                <Button size="sm" variant="outline" disabled={sending} onClick={() => keep(match.id)}>
                                    Keep this one
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
