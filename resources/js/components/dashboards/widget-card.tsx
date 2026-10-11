import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, EyeOff, Loader2, RefreshCw } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { agoText, secondsAgo } from './format';
import { accentFor, iconFor } from './palette';
import type { DashboardWidget } from './types';
import type { FeedEntry } from './use-widget-feed';
import { WidgetBody } from './widget-body';

const SPAN: Record<DashboardWidget['size'], string> = {
    sm: '',
    md: 'md:col-span-2',
    lg: 'md:col-span-2 xl:col-span-4',
};

const BASIS: Record<string, string> = {
    ledger: 'Ledger',
    operational: 'Operational',
    estimate: 'Estimate',
};

/**
 * One widget on the page: title, what it is based on, how fresh it is, the
 * figure, and the way to the screen behind it.
 *
 * ui-motion: Offset and Delay on entry (the cards arrive one after another, so
 * the eye reads them as separate things, fired once on page load), a small
 * lift under the pointer (it is a thing you can act on), and one accent glow
 * when a live refresh CHANGES the figure (feedback that something happened).
 * Under reduced motion they simply appear and stay put.
 *
 * Colour (Joy, 11 Oct 2026): each domain wears its own accent, set by the
 * .dash-acc-* class (app.css) so light and dark are each chosen, not flipped.
 */
export function WidgetCard({
    widget,
    entry,
    index,
    now,
    onHide,
    onRefresh,
    brandName = null,
}: {
    widget: DashboardWidget;
    entry: FeedEntry;
    index: number;
    now: number;
    /** The brand chosen on the dashboard, or null for all brands. */
    brandName?: string | null;
    onHide: () => void;
    onRefresh: () => void;
}) {
    const reduce = useReducedMotion();
    const result = entry.result;
    const ago = agoText(secondsAgo(result.generated_at, now));
    const tooOld = (secondsAgo(result.generated_at, now) ?? 0) > Math.max(widget.refresh_seconds * 3, 180);
    const Icon = iconFor(widget.key);

    // A changed figure from the live feed glows once. The first answer never does.
    const fingerprint = JSON.stringify(result.data);
    const seen = useRef(fingerprint);
    const [pulse, setPulse] = useState(0);

    useEffect(() => {
        if (seen.current !== fingerprint) {
            seen.current = fingerprint;
            setPulse((n) => n + 1);
        }
    }, [fingerprint]);

    return (
        <motion.section
            aria-labelledby={`widget-${widget.key}`}
            initial={reduce ? false : { opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            whileHover={reduce ? undefined : { y: -3 }}
            transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1], delay: reduce ? 0 : Math.min(index, 12) * 0.045 }}
            className={cn(
                `dash-acc-${accentFor(widget)}`,
                'bg-card text-card-foreground @container relative isolate flex flex-col overflow-hidden rounded-2xl border p-4 sm:p-5',
                'shadow-[0_1px_2px_rgba(20,40,25,0.05),0_10px_28px_-18px_rgba(20,50,30,0.22)] transition-shadow duration-300',
                'hover:shadow-[0_1px_2px_rgba(20,40,25,0.06),0_22px_40px_-20px_var(--acc)] dark:shadow-[0_1px_2px_rgba(0,0,0,0.4),0_12px_30px_-18px_rgba(0,0,0,0.7)]',
                SPAN[widget.size],
            )}
            style={{ backgroundImage: 'linear-gradient(180deg, var(--acc-wash), transparent 9rem)' }}
        >
            {/* The domain's colour along the top edge. */}
            <span aria-hidden className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[var(--acc)] to-[var(--acc-2)]" />
            {pulse > 0 && <span key={pulse} aria-hidden className="dash-pulse pointer-events-none absolute inset-0 rounded-[inherit]" />}

            <header className="mb-4 flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[var(--acc)] to-[var(--acc-2)] text-white shadow-[0_6px_14px_-6px_var(--acc)]">
                    <Icon className="size-[18px]" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                    <h2
                        id={`widget-${widget.key}`}
                        className="line-clamp-2 text-sm leading-snug font-semibold"
                        title={widget.description ?? undefined}
                    >
                        {widget.title}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px]">
                        {result.data?.basis && <span>{BASIS[result.data.basis] ?? result.data.basis}</span>}
                        {widget.date_basis && <span>by {widget.date_basis}</span>}
                        {brandName && <span>{widget.brand_aware ? brandName : 'All brands'}</span>}
                        <span
                            className={cn('flex items-center gap-1', (entry.stale || tooOld) && 'text-amber-700 dark:text-amber-400')}
                            aria-live="polite"
                        >
                            {entry.loading ? <Loader2 className="size-3 animate-spin" aria-hidden /> : null}
                            {entry.stale ? `Not refreshed, showing ${ago}` : `Updated ${ago}`}
                        </span>
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-0.5">
                    <button
                        type="button"
                        onClick={onRefresh}
                        className="text-muted-foreground hover:text-foreground hover:bg-accent rounded p-1"
                        aria-label={`Refresh ${widget.title}`}
                        title="Refresh now"
                    >
                        <RefreshCw className="size-3.5" />
                    </button>
                    <button
                        type="button"
                        onClick={onHide}
                        className="text-muted-foreground hover:text-foreground hover:bg-accent rounded p-1"
                        aria-label={`Hide ${widget.title}`}
                        title="Hide from my dashboard"
                    >
                        <EyeOff className="size-3.5" />
                    </button>
                    {widget.drill_down && (
                        <Link
                            href={widget.drill_down}
                            className="text-muted-foreground hover:bg-accent rounded-md p-1 hover:text-[var(--acc-ink)]"
                            aria-label={`Open the screen behind ${widget.title}`}
                            title="Open the full screen"
                        >
                            <ArrowUpRight className="size-3.5" />
                        </Link>
                    )}
                </div>
            </header>

            <div className="flex-1">
                {result.data === null ? (
                    // ⚠ Ruling 9: a failure is said out loud, never drawn as zero.
                    <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-gradient-to-br from-amber-500/12 to-orange-500/8 p-3 text-sm">
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-300">
                            <AlertTriangle className="size-4" aria-hidden />
                        </span>
                        <div>
                            <p>{result.message ?? 'This figure could not be loaded.'}</p>
                            <button
                                type="button"
                                onClick={onRefresh}
                                className="mt-1 text-xs font-medium text-amber-800 hover:underline dark:text-amber-300"
                            >
                                Try again
                            </button>
                        </div>
                    </div>
                ) : (
                    <WidgetBody payload={result.data} emptyText={widget.empty_text} widgetKey={widget.key} />
                )}
            </div>
        </motion.section>
    );
}
