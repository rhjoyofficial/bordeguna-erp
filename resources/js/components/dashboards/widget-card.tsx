import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, EyeOff, Loader2, RefreshCw } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { secondsAgo } from './format';
import { iconFor } from './palette';
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
 * the eye reads them as separate things, fired once on page load) and one
 * brand glow when a live refresh CHANGES the figure (feedback that something
 * happened). The card itself never moves under the pointer (Joy, 11 Oct:
 * "no needs on hover movements full card"). Under reduced motion they simply
 * appear.
 *
 * Colour (fourth pass, "simplify overall colors"): a plain card with a static
 * hairline border and a neutral icon tile. Only a card whose figure needs
 * someone (something waiting, overdue or failed) colours its icon tile, amber
 * or red, so colour on the page always means something.
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
    const Icon = iconFor(widget.key);
    // The icon tile is neutral unless the figure itself says something is wrong or waiting.
    const data = result.data;
    const state: 'bad' | 'warn' | null =
        data === null
            ? 'bad'
            : data.kind === 'attention'
              ? data.counts.critical > 0
                  ? 'bad'
                  : data.total_count > 0
                    ? 'warn'
                    : null
              : data.kind === 'signals'
                ? data.items.some((item) => item.count > 0)
                    ? 'warn'
                    : null
                : 'tone' in data && (data.tone === 'bad' || data.tone === 'warn')
                  ? data.tone
                  : null;

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
            transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1], delay: reduce ? 0 : Math.min(index, 12) * 0.045 }}
            className={cn(
                'dash-acc-brand',
                'bg-card text-card-foreground @container relative isolate flex flex-col overflow-hidden rounded-2xl border p-4 sm:p-5',
                'shadow-[0_1px_2px_rgba(20,40,25,0.04)] dark:shadow-none',
                SPAN[widget.size],
            )}
        >
            {pulse > 0 && <span key={pulse} aria-hidden className="dash-pulse pointer-events-none absolute inset-0 rounded-[inherit]" />}

            <header className="mb-4 flex items-start gap-3">
                <span
                    className={cn(
                        'flex size-9 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset',
                        state === 'bad'
                            ? 'bg-red-500/10 text-red-700 ring-red-600/15 dark:text-red-300 dark:ring-red-400/25'
                            : state === 'warn'
                              ? 'bg-amber-500/12 text-amber-800 ring-amber-600/20 dark:text-amber-300 dark:ring-amber-400/25'
                              : 'bg-muted text-foreground/70 ring-border/60',
                    )}
                >
                    <Icon className="size-[18px]" aria-hidden />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 id={`widget-${widget.key}`} className="line-clamp-2 text-sm leading-snug font-semibold" title={description ?? undefined}>
                        {title}
                    </h2>
                    <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px]">
                        {result.data?.basis && <span>{BASIS[result.data.basis] ? t(BASIS[result.data.basis]) : result.data.basis}</span>}
                        {widget.date_basis && <span>{t('dash.by_basis', { basis: widget.date_basis })}</span>}
                        {brandName && <span>{widget.brand_aware ? brandName : t('orders.all_brands')}</span>}
                        <span
                            className={cn('flex items-center gap-1', (entry.stale || tooOld) && 'text-amber-700 dark:text-amber-400')}
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
                            className="text-muted-foreground hover:bg-accent rounded-md p-1 hover:text-[var(--acc-ink)]"
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
                    <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-gradient-to-br from-amber-500/12 to-orange-500/8 p-3 text-sm">
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-amber-500/20 text-amber-700 dark:text-amber-300">
                            <AlertTriangle className="size-4" aria-hidden />
                        </span>
                        <div>
                            <p>
                                {result.message === null
                                    ? t('dash.load_failed')
                                    : result.message === SERVER_FAILED
                                      ? t('dash.load_failed_retry')
                                      : result.message}
                            </p>
                            <button
                                type="button"
                                onClick={onRefresh}
                                className="mt-1 text-xs font-medium text-amber-800 hover:underline dark:text-amber-300"
                            >
                                {t('dash.try_again')}
                            </button>
                        </div>
                    </div>
                ) : (
                    <WidgetBody payload={result.data} emptyText={emptyText} widgetKey={widget.key} />
                )}
            </div>
        </motion.section>
    );
}
