import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, EyeOff, Loader2, RefreshCw } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { agoText, secondsAgo } from './format';
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
 * the eye reads them as separate things, fired once on page load), and nothing
 * else moves the card itself. Under reduced motion they simply appear.
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

    return (
        <motion.section
            aria-labelledby={`widget-${widget.key}`}
            initial={reduce ? false : { opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1], delay: reduce ? 0 : Math.min(index, 12) * 0.045 }}
            className={cn('bg-card text-card-foreground flex flex-col rounded-xl border p-4 shadow-xs', SPAN[widget.size])}
        >
            <header className="mb-3 flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <h2 id={`widget-${widget.key}`} className="truncate text-sm font-medium" title={widget.description ?? undefined}>
                        {widget.title}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px]">
                        {result.data?.basis && <span>{BASIS[result.data.basis] ?? result.data.basis}</span>}
                        {widget.date_basis && <span>by {widget.date_basis}</span>}
                        {brandName && <span>{widget.brand_aware ? brandName : 'All brands'}</span>}
                        <span
                            className={cn('flex items-center gap-1', (entry.stale || tooOld) && 'text-amber-600 dark:text-amber-400')}
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
                            className="text-muted-foreground hover:text-primary hover:bg-accent rounded p-1"
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
                    <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        <div>
                            <p>{result.message ?? 'This figure could not be loaded.'}</p>
                            <button type="button" onClick={onRefresh} className="text-primary mt-1 text-xs hover:underline">
                                Try again
                            </button>
                        </div>
                    </div>
                ) : (
                    <WidgetBody payload={result.data} emptyText={widget.empty_text} />
                )}
            </div>
        </motion.section>
    );
}
