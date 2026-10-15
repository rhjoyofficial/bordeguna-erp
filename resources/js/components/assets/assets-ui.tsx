import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The small pieces the redesigned Assets screens share (15 Oct 2026):
 * a filter chip with a count, a number with its label, a card with a
 * heading row, a status pill, a stacked meter, a hint. Tokens only, so the
 * calm base holds in dark mode and colour appears only where it means
 * something: red for overdue and missing, amber for due and renewing, the
 * primary for "this chip is on".
 *
 * ⚠ Presentation only. Every count arrives from the server.
 */

export type Tone = 'danger' | 'warning' | 'info' | 'muted' | 'ok';

const TONE_TEXT: Record<Tone, string> = {
    danger: 'text-destructive',
    warning: 'text-amber-700 dark:text-amber-400',
    info: 'text-sky-700 dark:text-sky-400',
    muted: 'text-muted-foreground',
    ok: 'text-foreground',
};

const TONE_SOFT: Record<Tone, string> = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
    muted: 'bg-muted text-muted-foreground',
    ok: 'bg-primary/10 text-primary',
};

/** A filter as a pill, with its count beside it. Only as wide as its words. */
export function Chip({
    active = false,
    count,
    tone,
    onClick,
    children,
    className,
}: {
    active?: boolean;
    count?: number | string;
    tone?: Tone;
    onClick?: () => void;
    children: ReactNode;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[12.5px] whitespace-nowrap transition-colors',
                active
                    ? 'border-foreground bg-foreground text-background'
                    : cn('bg-card hover:bg-muted', tone && !active ? TONE_TEXT[tone] : 'text-foreground'),
                className,
            )}
        >
            {children}
            {count !== undefined && <span className={cn('tabular-nums', active ? 'text-background/70' : 'text-muted-foreground')}>{count}</span>}
        </button>
    );
}

/** A number with its label under it, linked to the list behind it when there is one. */
export function Stat({
    label,
    value,
    tone = 'ok',
    href,
    hint,
    size = 'md',
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    tone?: Tone;
    href?: string;
    hint?: string;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}) {
    const body = (
        <>
            <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                {label}
                {hint && <Hint text={hint} />}
            </div>
            <div
                className={cn(
                    'font-semibold tabular-nums',
                    size === 'lg' ? 'text-3xl tracking-tight' : size === 'sm' ? 'text-lg' : 'text-xl',
                    tone !== 'ok' && TONE_TEXT[tone],
                )}
            >
                {value}
            </div>
        </>
    );

    if (href) {
        return (
            <Link href={href} className={cn('block rounded-md underline-offset-4 hover:underline', className)}>
                {body}
            </Link>
        );
    }

    return <div className={className}>{body}</div>;
}

/** A card with its heading row. `flush` lets a table or a row list run to the edges. */
export function Panel({
    title,
    aside,
    children,
    className,
    flush = false,
}: {
    title: ReactNode;
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
    flush?: boolean;
}) {
    return (
        <section className={cn('bg-card text-card-foreground rounded-xl border', flush ? 'overflow-hidden' : 'p-4', className)}>
            <div className={cn('flex flex-wrap items-center justify-between gap-2', flush && 'px-4 pt-4 pb-2')}>
                <h2 className="text-[15px] font-semibold">{title}</h2>
                {aside}
            </div>
            {children}
        </section>
    );
}

/** A short state word in a soft pill: red, amber, blue or grey by meaning. */
export function Pill({ tone = 'muted', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[11.5px] font-medium whitespace-nowrap',
                TONE_SOFT[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

/** A dashed pill saying which permission a card shows to. */
export function PermissionNote({ children, title }: { children: ReactNode; title: string }) {
    return (
        <span title={title} className="text-muted-foreground rounded-full border border-dashed px-2 py-0.5 text-[11px] whitespace-nowrap">
            {children}
        </span>
    );
}

/** A stacked bar: each segment's share of the whole, in its own colour. */
export function Meter({ segments, label, className }: { segments: { share: number; className: string }[]; label: string; className?: string }) {
    return (
        <div role="img" aria-label={label} className={cn('bg-muted flex h-2.5 gap-0.5 overflow-hidden rounded-full', className)}>
            {segments
                .filter((segment) => segment.share > 0)
                .map((segment, index) => (
                    <div key={index} style={{ width: `${segment.share}%` }} className={segment.className} />
                ))}
        </div>
    );
}

/** An "i" that explains a number on hover or focus. */
export function Hint({ text }: { text: string }) {
    return (
        <TooltipProvider delayDuration={150}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        aria-label={text}
                        className="text-muted-foreground hover:text-foreground inline-flex size-4 items-center justify-center rounded-full border"
                    >
                        <Info className="size-3" />
                    </button>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">{text}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/** A compact table's shell: horizontal scroll, soft header, hairline rows. */
export function CompactTable({ children, minWidth = '52rem', className }: { children: ReactNode; minWidth?: string; className?: string }) {
    return (
        <div className={cn('overflow-x-auto', className)}>
            <table
                style={{ minWidth }}
                className="[&_tbody_tr:hover_td]:bg-muted/40 w-full text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2 [&_tbody_td]:align-top [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap"
            >
                {children}
            </table>
        </div>
    );
}

/** A soft dot before a status word. */
export function StatusDot({ status }: { status: string }) {
    const colour: Record<string, string> = {
        in_use: 'bg-primary',
        in_store: 'bg-primary/40',
        under_repair: 'bg-amber-500',
        with_outside_party: 'bg-sky-500',
        missing: 'bg-destructive',
        disposed: 'bg-muted-foreground/50',
    };

    return (
        <span aria-hidden="true" className={cn('mr-1.5 inline-block size-1.5 rounded-full align-[1px]', colour[status] ?? 'bg-muted-foreground')} />
    );
}

/** "5 days late", "in 18 days", "today": a date's distance from today, as words. */
export function daysWord(days: number): string {
    if (days === 0) return 'today';
    if (days < 0) return `${-days} day${days === -1 ? '' : 's'} over`;

    return `in ${days} day${days === 1 ? '' : 's'}`;
}
