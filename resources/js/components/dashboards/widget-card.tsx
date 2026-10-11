import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, EyeOff, Loader2, RefreshCw } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { secondsAgo } from './format';
import type { DashboardWidget } from './types';
import type { FeedEntry } from './use-widget-feed';
import { WidgetBody } from './widget-body';

const SPAN: Record<DashboardWidget['size'], string> = {
    sm: '',
    md: 'md:col-span-2',
    lg: 'md:col-span-2 xl:col-span-4',
};

const BASIS: Record<string, TranslationKey> = {
    ledger: 'dash.basis.ledger',
    operational: 'dash.basis.operational',
    estimate: 'dash.basis.estimate',
};

/** The server's own wording for a widget that failed (WidgetRegistry::resolve). */
const SERVER_FAILED = 'This figure could not be loaded. It will try again on its own.';

/** The default empty text every widget inherits (Widget::emptyText). */
const SERVER_EMPTY = 'Nothing to show yet.';

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
    const { t, tr } = useTranslation();
    const result = entry.result;
    const seconds = secondsAgo(result.generated_at, now);
    // Same steps as format.ts agoText, in the reader's language; the digits stay digits.
    const ago =
        seconds === null
            ? ''
            : seconds < 5
              ? t('dash.ago.now')
              : seconds < 60
                ? t('dash.ago.seconds', { n: seconds })
                : seconds < 3600
                  ? t('dash.ago.minutes', { n: Math.floor(seconds / 60) })
                  : t('dash.ago.hours', { n: Math.floor(seconds / 3600) });
    const title = tr(`widget.${widget.key}.title`, widget.title);
    const description = widget.description === null ? null : tr(`widget.${widget.key}.description`, widget.description);
    const emptyText = widget.empty_text === SERVER_EMPTY ? t('dash.empty_default') : tr(`widget.${widget.key}.empty`, widget.empty_text);
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
                    <h2 id={`widget-${widget.key}`} className="truncate text-sm font-medium" title={description ?? undefined}>
                        {title}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px]">
                        {result.data?.basis && <span>{BASIS[result.data.basis] ? t(BASIS[result.data.basis]) : result.data.basis}</span>}
                        {widget.date_basis && <span>{t('dash.by_basis', { basis: widget.date_basis })}</span>}
                        {brandName && <span>{widget.brand_aware ? brandName : t('orders.all_brands')}</span>}
                        <span
                            className={cn('flex items-center gap-1', (entry.stale || tooOld) && 'text-amber-600 dark:text-amber-400')}
                            aria-live="polite"
                        >
                            {entry.loading ? <Loader2 className="size-3 animate-spin" aria-hidden /> : null}
                            {entry.stale ? t('dash.not_refreshed', { ago }) : t('dash.updated', { ago })}
                        </span>
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-0.5">
                    <button
                        type="button"
                        onClick={onRefresh}
                        className="text-muted-foreground hover:text-foreground hover:bg-accent rounded p-1"
                        aria-label={t('dash.refresh_name', { name: title })}
                        title={t('dash.refresh_now')}
                    >
                        <RefreshCw className="size-3.5" />
                    </button>
                    <button
                        type="button"
                        onClick={onHide}
                        className="text-muted-foreground hover:text-foreground hover:bg-accent rounded p-1"
                        aria-label={t('dash.hide_name', { name: title })}
                        title={t('dash.hide_title')}
                    >
                        <EyeOff className="size-3.5" />
                    </button>
                    {widget.drill_down && (
                        <Link
                            href={widget.drill_down}
                            className="text-muted-foreground hover:text-primary hover:bg-accent rounded p-1"
                            aria-label={t('dash.open_name', { name: title })}
                            title={t('dash.open_full')}
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
                            <p>
                                {result.message === null
                                    ? t('dash.load_failed')
                                    : result.message === SERVER_FAILED
                                      ? t('dash.load_failed_retry')
                                      : result.message}
                            </p>
                            <button type="button" onClick={onRefresh} className="text-primary mt-1 text-xs hover:underline">
                                {t('dash.try_again')}
                            </button>
                        </div>
                    </div>
                ) : (
                    <WidgetBody payload={result.data} emptyText={emptyText} />
                )}
            </div>
        </motion.section>
    );
}
