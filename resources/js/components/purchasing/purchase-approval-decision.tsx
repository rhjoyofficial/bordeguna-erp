import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export interface PurchaseApprovalRow {
    id: number;
    number: string;
    kind: string;
    kind_label: string;
    summary: string;
    supplier: string | null;
    amount: string;
    document_date: string | null;
    reference: string | null;
    reasons: string[];
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
    created_by: string | null;
    created_at: string | null;
    decided_by: string | null;
    decided_at: string | null;
    document_url: string | null;
    can_decide: boolean;
    cannot_decide: string | null;
    approve_url: string;
    reject_url: string;
}

/** The pending badge with its reasons, the same on the list and the document. */
export function PendingApprovalBadge({ status }: { status: PurchaseApprovalRow['status'] }) {
    const styles = {
        pending: 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
        approved: 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
        rejected: 'border-red-300 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
        withdrawn: 'border-slate-300 bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-300',
    } as const;
    const words = { pending: 'Waiting approval', approved: 'Approved and posted', rejected: 'Rejected', withdrawn: 'Withdrawn' } as const;

    return <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${styles[status]}`}>{words[status]}</span>;
}

/**
 * ⭐ Purchasing approvals (ruling P7, Joy 18 Oct 2026): Approve posts the
 * document as it was typed; Reject asks why, in a popup. Shown only to an
 * approver who did not enter it (the server refuses anyone else anyway).
 */
export function PurchaseApprovalDecision({ approval }: { approval: PurchaseApprovalRow }) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    if (approval.status !== 'pending') {
        return null;
    }

    if (!approval.can_decide) {
        return approval.cannot_decide ? <p className="text-muted-foreground text-sm">{approval.cannot_decide}</p> : null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                disabled={busy}
                onClick={() =>
                    router.post(approval.approve_url, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })
                }
            >
                Approve and post
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button size="sm" variant="outline">
                        Reject
                    </Button>
                </DialogTrigger>
                <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>Reject {approval.number}</DialogTitle>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor={`reject_${approval.id}`} required>
                            Why
                        </Label>
                        <Input
                            id={`reject_${approval.id}`}
                            aria-required
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            placeholder="e.g. price not agreed with the supplier"
                        />
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline">Cancel</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={reason.trim() === ''}
                            onClick={() =>
                                router.post(approval.reject_url, { review_note: reason }, { preserveScroll: true, onSuccess: () => setOpen(false) })
                            }
                        >
                            Reject
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** "2 bills are waiting for approval", on the list they would appear in once posted. */
export function PendingApprovalsNotice({ count, what }: { count?: number; what: string }) {
    if (!count) {
        return null;
    }

    return (
        <Link
            href="/purchasing/approvals"
            className="w-fit rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 hover:underline dark:bg-amber-950 dark:text-amber-100"
        >
            {count} {what} waiting for a second person&apos;s approval. Not posted yet.
        </Link>
    );
}

export interface ApprovedVia {
    id: number;
    number: string;
    decided_by: string | null;
    decided_at: string | null;
    reasons: string[];
}

/** On a posted document that went through approval: who approved it and why it needed one. */
export function ApprovedViaNotice({ approval }: { approval?: ApprovedVia | null }) {
    if (!approval) {
        return null;
    }

    return (
        <div className="w-fit rounded-lg border px-3 py-2 text-sm">
            <span className="font-medium">Approved</span> by {approval.decided_by ?? 'an approver'} (
            <Link href={`/purchasing/approvals/${approval.id}`} className="hover:underline">
                {approval.number}
            </Link>
            ) because: {approval.reasons.join('; ')}.
        </div>
    );
}
