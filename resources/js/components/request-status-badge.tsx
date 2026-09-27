import { cn } from '@/lib/utils';

/**
 * ⭐ 27 Sep 2026 (Joy): a request's state, in its own colour, so an employee
 * reading their own leave, movement, expense or correction sees at a glance
 * what was approved, what was refused, what was sent back, and what is still
 * waiting. Before this, waiting and rejected wore the same grey chip and could
 * not be told apart.
 *
 * The status comes through as free text (the approval workflow's
 * pending/approved/rejected/returned, or an expense claim's own wording), so
 * this matches on meaning rather than an exact string, and falls back to a
 * neutral chip for anything it does not recognise.
 */
type Tone = 'waiting' | 'approved' | 'rejected' | 'returned' | 'neutral';

const TONE_CLASSES: Record<Tone, string> = {
    waiting: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    returned: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
    neutral: 'bg-muted text-muted-foreground',
};

const DEFAULT_LABEL: Record<Tone, string> = {
    waiting: 'Waiting',
    approved: 'Approved',
    rejected: 'Rejected',
    returned: 'Sent back',
    neutral: 'Draft',
};

function toneOf(status: string): Tone {
    const s = status.toLowerCase();

    if (s.includes('sent back') || s.includes('sent_back') || s.includes('return')) {
        return 'returned';
    }
    if (s.includes('reject') || s.includes('declin')) {
        return 'rejected';
    }
    if (s.includes('approve') || s.includes('settled') || s.includes('applied') || s.includes('accept')) {
        return 'approved';
    }
    if (s.includes('pending') || s.includes('waiting') || s.includes('submitt') || s.includes('review') || s.includes('for approval')) {
        return 'waiting';
    }

    return 'neutral';
}

/**
 * @param status the raw status text from the server.
 * @param label override the shown text; otherwise the status is title-cased,
 *   or a plain word is used for a recognised state.
 */
export function RequestStatusBadge({ status, label, className }: { status: string; label?: string; className?: string }) {
    const tone = toneOf(status);
    const text = label ?? (tone === 'neutral' ? titleCase(status) : DEFAULT_LABEL[tone]);

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                TONE_CLASSES[tone],
                className,
            )}
        >
            {text}
        </span>
    );
}

function titleCase(value: string): string {
    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}
