import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';

/**
 * "Submitted for approval", with the way to it.
 *
 * ⚠ The toast alone said "submitted for approval" and vanished, and nothing
 * said where the correction had gone or that stock had NOT changed yet - so the
 * natural next move was to type it in again. This stays on the page and links
 * to /approvals, where the approver acts on it.
 *
 * ⚠ Rendered from a PAGE prop the controller reads out of the flashed session
 * (`awaitingApproval`), not from the shared flash: a flashed key is not an
 * Inertia prop until something passes it (HandleInertiaRequests, dead engine #9).
 */
export function AwaitingApprovalNotice({ message }: { message?: string | null }) {
    if (!message) {
        return null;
    }

    return (
        <div role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
            <Clock className="size-4 shrink-0 text-amber-600 dark:text-amber-500" aria-hidden="true" />
            <span className="flex-1">{message}</span>
            <Link href="/approvals" className="font-medium underline underline-offset-2">
                Open Approvals
            </Link>
        </div>
    );
}
