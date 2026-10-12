import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * Register emphasis (Joy, 12 Oct 2026): "on each register / journal page, the
 * necessary things will be bold and coloured so I can easily identify them."
 *
 * One meaning, one look, on every register: dates, voucher numbers, ledger
 * heads, parties and amounts. Colour is carried by the text, never by a
 * background, so the calm theme stays calm. Narration stays muted on purpose
 * so these stand out against it.
 *
 * Tones (light / dark), all AA on the card surface:
 *   debit   emerald-700 / emerald-400
 *   credit  orange-700  / orange-400
 *   balance foreground bold, red-700 / red-400 when negative
 *   doc no  the theme's primary (brand green), mono semibold
 *
 * Presentation only: values arrive already formatted from the server and are
 * shown as they come.
 */

export const LEDGER_TONE = {
    date: 'text-foreground font-semibold whitespace-nowrap tabular-nums',
    docNo: 'text-primary font-mono font-semibold',
    headCode: 'text-muted-foreground font-mono text-xs font-normal',
    headName: 'text-foreground font-semibold',
    party: 'text-foreground font-medium',
    debit: 'text-emerald-700 dark:text-emerald-400 font-semibold tabular-nums',
    credit: 'text-orange-700 dark:text-orange-400 font-semibold tabular-nums',
    balance: 'text-foreground font-bold tabular-nums',
    negative: 'text-red-700 dark:text-red-400 font-bold tabular-nums',
    total: 'text-foreground text-[0.95rem] font-bold tabular-nums',
    amount: 'text-foreground font-semibold tabular-nums',
} as const;

/** A row's date, shown exactly as the page already formats it. */
export function DateCell({ children, className }: { children: ReactNode; className?: string }) {
    return <span className={cn(LEDGER_TONE.date, className)}>{children}</span>;
}

/** A voucher, journal or document number. A link when it has somewhere to go. */
export function DocNo({ href, children, className }: { href?: string | null; children: ReactNode; className?: string }) {
    if (href) {
        return (
            <Link href={href} className={cn(LEDGER_TONE.docNo, 'underline-offset-4 hover:underline', className)}>
                {children}
            </Link>
        );
    }

    return <span className={cn(LEDGER_TONE.docNo, className)}>{children}</span>;
}

/**
 * Splits an account label as the server writes it ("1110 - Cash in Hand")
 * into its code and name. A label with no code comes back whole as the name.
 */
export function splitAccountLabel(label: string): { code: string | null; name: string } {
    const match = /^(\S+) - (.+)$/s.exec(label);

    return match ? { code: match[1], name: match[2] } : { code: null, name: label };
}

/**
 * A ledger head: the code in muted mono, the name in semibold. Give `code`
 * and `name`, or a server `label` ("1110 - Cash in Hand") to split. Pass
 * `href` for a link, or `onClick` for a button (the general ledger's drill-down).
 */
export function LedgerHead({
    code: givenCode,
    name: givenName,
    label,
    href,
    onClick,
    className,
}: {
    code?: string | null;
    name?: ReactNode;
    label?: string | null;
    href?: string | null;
    onClick?: () => void;
    className?: string;
}) {
    const parsed = label ? splitAccountLabel(label) : null;
    const code = givenCode ?? parsed?.code ?? null;
    const name = givenName ?? parsed?.name ?? null;

    if (name === null && code === null) {
        return null;
    }

    const body = (
        <>
            {code ? <span className={cn(LEDGER_TONE.headCode, 'mr-1.5')}>{code}</span> : null}
            <span className={LEDGER_TONE.headName}>{name}</span>
        </>
    );

    if (href) {
        return (
            <Link href={href} className={cn('underline-offset-4 hover:underline', className)}>
                {body}
            </Link>
        );
    }

    if (onClick) {
        return (
            <button type="button" onClick={onClick} className={cn('text-left underline-offset-4 hover:underline', className)}>
                {body}
            </button>
        );
    }

    return <span className={className}>{body}</span>;
}

/** A customer, supplier, employee or payee name. */
export function PartyText({ children, className }: { children: ReactNode; className?: string }) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return <span className={cn(LEDGER_TONE.party, className)}>{children}</span>;
}

export type AmountTone = 'debit' | 'credit' | 'balance' | 'total' | 'amount';

/** True for a formatted figure that reads as below zero: "-1,200.00" or "(1,200.00)". */
export function isNegativeFigure(value: ReactNode): boolean {
    if (typeof value !== 'string' && typeof value !== 'number') {
        return false;
    }
    const text = String(value).trim();

    return text.startsWith('-') || text.startsWith('(') || text.startsWith('−');
}

/**
 * A money figure in its meaning's colour. Empty values render nothing, so a
 * blank debit or credit cell stays blank. Balances and amounts turn red when
 * negative; totals stay bold foreground.
 */
export function Amount({
    value,
    tone = 'amount',
    suffix,
    negative: forceNegative,
    className,
}: {
    value: ReactNode;
    tone?: AmountTone;
    suffix?: ReactNode;
    /** Mark the figure as the bad side (an overdrawn bank, a debtor in credit) even without a minus sign. */
    negative?: boolean;
    className?: string;
}) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const negative = forceNegative ?? ((tone === 'balance' || tone === 'amount') && isNegativeFigure(value));

    return (
        <span className={cn(negative ? LEDGER_TONE.negative : LEDGER_TONE[tone], 'whitespace-nowrap', className)}>
            {value}
            {suffix ? <> {suffix}</> : null}
        </span>
    );
}

type StatusTone = 'posted' | 'draft' | 'waiting' | 'reversed' | 'rejected' | 'neutral';

const STATUS_CLASSES: Record<StatusTone, string> = {
    posted: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    draft: 'bg-muted text-muted-foreground',
    waiting: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300',
    reversed: 'bg-red-100 text-red-800 line-through decoration-red-800/40 dark:bg-red-500/15 dark:text-red-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
    neutral: 'bg-muted text-muted-foreground',
};

function statusToneOf(status: string): StatusTone {
    const s = status.toLowerCase();

    if (s.includes('revers') || s.includes('void') || s.includes('cancel')) {
        return 'reversed';
    }
    if (s.includes('reject') || s.includes('declin')) {
        return 'rejected';
    }
    if (s.includes('await') || s.includes('pending') || s.includes('approval') || s.includes('submitt') || s.includes('waiting')) {
        return 'waiting';
    }
    if (s.includes('post') || s.includes('approved') || s.includes('final') || s.includes('done') || s.includes('complete')) {
        return 'posted';
    }
    if (s.includes('draft')) {
        return 'draft';
    }

    return 'neutral';
}

/** A voucher or journal state: posted green, draft grey, awaiting approval amber, reversed red. */
export function StatusPill({ status, label, className }: { status: string; label?: ReactNode; className?: string }) {
    const tone = statusToneOf(status);
    const text =
        label ??
        status
            .replace(/[_-]+/g, ' ')
            .toLowerCase()
            .replace(/^\w/, (c) => c.toUpperCase());

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                STATUS_CLASSES[tone],
                className,
            )}
        >
            {text}
        </span>
    );
}
