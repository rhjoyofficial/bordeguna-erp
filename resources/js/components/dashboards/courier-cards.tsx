import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, Loader2, RefreshCw, TriangleAlert } from 'lucide-react';
import { COURIER_CARDS } from './arrangement';
import { CardMaskProvider, EyeIcon, usePrivacy } from './privacy';
import type { DashboardWidget } from './types';
import type { FeedEntry } from './use-widget-feed';
import { WidgetBody } from './widget-body';

/**
 * The courier widgets as THREE cards (redesign round 3, Joy, 16 Oct 2026):
 * Parcels, Cash on delivery, Courier connections. Each card draws its widgets'
 * payloads unchanged, one under the other, so every figure, gate and refresh
 * is the widget's own: Courier balances still reaches only accounting.view,
 * and a Manager sees the Cash on delivery card without it.
 */
/** The server's default wording (WidgetRegistry / Widget::emptyText), said in the reader's language. */
const SERVER_FAILED = 'This figure could not be loaded. It will try again on its own.';
const SERVER_EMPTY = 'Nothing to show yet.';

export function CourierCards({
    widgets,
    entries,
    onRefresh,
}: {
    widgets: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
    onRefresh: (keys: string[]) => void;
}) {
    const byKey = new Map(widgets.map((w) => [w.key, w]));
    const cards = COURIER_CARDS.map((card) => ({
        ...card,
        members: card.widgets.flatMap((key) => (byKey.has(key) ? [byKey.get(key)!] : [])),
    })).filter((card) => card.members.length > 0);

    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="grid grid-cols-1 gap-3 lg:grid-cols-2 xl:grid-cols-3">
            {cards.map((card) => (
                <CourierCard key={card.key} title={card.title} members={card.members} entries={entries} onRefresh={onRefresh} />
            ))}
        </div>
    );
}

function CourierCard({
    title,
    members,
    entries,
    onRefresh,
}: {
    title: TranslationKey;
    members: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
    onRefresh: (keys: string[]) => void;
}) {
    const { t, tr } = useTranslation();
    const privacy = usePrivacy();
    const sensitive = members.filter((w) => w.sensitive);
    const masked = sensitive.some((w) => privacy.isMasked(w.key));
    const loading = members.some((w) => entries[w.key]?.loading);
    const link = members.find((w) => w.drill_down)?.drill_down ?? null;
    const name = t(title);

    return (
        <section aria-label={name} className="bg-card text-card-foreground flex flex-col rounded-xl border p-4">
            <header className="mb-3 flex items-center gap-2">
                <h3 className="min-w-0 flex-1 text-sm font-semibold">{name}</h3>
                {loading && <Loader2 className="text-muted-foreground size-3.5 animate-spin" aria-hidden />}
                <button
                    type="button"
                    onClick={() => onRefresh(members.map((w) => w.key))}
                    className="text-muted-foreground hover:text-foreground hover:bg-accent rounded p-1"
                    aria-label={t('dash.refresh_name', { name })}
                    title={t('dash.refresh_now')}
                >
                    <RefreshCw className="size-3.5" />
                </button>
                {sensitive.length > 0 && (
                    <button
                        type="button"
                        onClick={() =>
                            sensitive.forEach((w) => {
                                // One eye for the card: every private figure in it shows or hides together.
                                if (privacy.isMasked(w.key) === masked) {
                                    privacy.toggle(w.key);
                                }
                            })
                        }
                        aria-pressed={!masked}
                        aria-label={masked ? t('dash.privacy.show_all') : t('dash.privacy.hide_all')}
                        className={cn('hover:bg-accent rounded p-1', masked ? 'text-muted-foreground' : 'text-foreground')}
                    >
                        <EyeIcon open={!masked} />
                    </button>
                )}
                {link && (
                    <Link
                        href={link}
                        className="text-muted-foreground hover:bg-accent hover:text-foreground rounded-md p-1"
                        aria-label={t('dash.open_name', { name })}
                        title={t('dash.open_full')}
                    >
                        <ArrowUpRight className="size-3.5" />
                    </Link>
                )}
            </header>
            <div className="flex flex-1 flex-col gap-4">
                {members.map((widget) => {
                    const result = entries[widget.key]?.result ?? widget.result;
                    const widgetTitle = tr(`widget.${widget.key}.title`, widget.title);
                    const emptyText =
                        widget.empty_text === SERVER_EMPTY ? t('dash.empty_default') : tr(`widget.${widget.key}.empty`, widget.empty_text);

                    return (
                        <div key={widget.key} id={`widget-card-${widget.key}`}>
                            {members.length > 1 && <p className="text-muted-foreground mb-1.5 text-xs font-medium">{widgetTitle}</p>}
                            {result.data === null ? (
                                <p className="flex items-start gap-1.5 text-sm text-amber-800 dark:text-amber-300">
                                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
                                    {result.message === null || result.message === SERVER_FAILED ? t('dash.load_failed_retry') : result.message}
                                </p>
                            ) : (
                                <CardMaskProvider masked={privacy.isMasked(widget.key)}>
                                    <WidgetBody payload={result.data} emptyText={emptyText} widgetKey={widget.key} />
                                </CardMaskProvider>
                            )}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
