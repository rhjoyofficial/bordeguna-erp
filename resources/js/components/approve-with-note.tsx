import { BusyButton } from '@/components/busy-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Approve and Reject for one row, with the note a self-approval needs.
 *
 * ⚠ Bug hunt 16 Oct 2026: the payroll inputs and increments pages posted
 * Approve with no note, so approving your own overtime, bonus, adjustment or
 * increment was refused (Rule #22) with no box to write the reason in. Same
 * rule as the payroll page: the note is optional, and required only when it
 * is your own money.
 */
export function ApproveWithNote({ approveUrl, rejectUrl }: { approveUrl: string; rejectUrl: string }) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);

    const approve = () =>
        router.post(
            approveUrl,
            { note: note.trim() || null },
            {
                preserveScroll: true,
                onStart: () => setError(null),
                onError: (errors) => setError(errors.self_approval_reason ?? errors.note ?? null),
            },
        );

    return (
        <div className="flex flex-col items-end gap-1">
            <div className="flex justify-end gap-2">
                <Input
                    aria-label="Approval note"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    placeholder="Note (needed if it is your own)"
                    className="h-8 w-56"
                />
                <Button size="sm" variant="ghost" onClick={() => router.post(rejectUrl, {}, { preserveScroll: true })}>
                    Reject
                </Button>
                <BusyButton size="sm" busyLabel="Approving…" onClick={approve}>
                    Approve
                </BusyButton>
            </div>
            {error && <p className="text-destructive max-w-xs text-right text-xs">{error}</p>}
        </div>
    );
}
