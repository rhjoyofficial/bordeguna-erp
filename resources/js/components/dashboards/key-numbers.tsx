import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { AnimatedNumber } from './animated-number';
import { KEY_RECIPES, type KeyRecipe } from './arrangement';
import { changeAgainst, formatValue } from './format';
import { CardMaskProvider, FIGURE_SLOT, maskable, Masked, PrivacyToggle, usePrivacy, WithFigure } from './privacy';
import type { DashboardWidget, Unit, WidgetPayload } from './types';
import type { FeedEntry } from './use-widget-feed';

/**
 * Key numbers (redesign round 3, Joy, 16 Oct 2026): the four headline figures
 * of each role dashboard as PLAIN cards. It replaces the green-to-black hero
 * band: white card, hairline border, a black figure, and colour only in the
 * words that say whether it moved the right way.
 *
 * ⚠⚠ NO NEW QUERY. Every figure is read from a widget already on the page and
 * from the same live entry its card uses, so a tile can never disagree with
 * its card, refreshes with it, and shows nothing the person may not see. A
 * widget the person hid, or may not see, simply has no tile.
 *
 * ⚠ Ruling 9 holds: a widget that failed says "Could not load", never zero.
 * Privacy: a sensitive tile shows أمان until its eye or the page's is tapped.
 */

interface Figure {
    value: number | string;
    unit: Unit;
    previous?: number | null;
    caption?: ReactNode;
    progress?: number | null;
}

type T = ReturnType<typeof useTranslation>['t'];

/** The one headline number a payload stands for. `masked`: leave private figures out of the words. */
export function figureOf(payload: WidgetPayload, t: T, masked: boolean): Figure | null {
    switch (payload.kind) {
        case 'stat':
            return {
                value: payload.value,
                unit: payload.unit,
                previous: payload.previous,
                caption:
                    payload.caption ??
                    (payload.parts ?? [])
                        .filter((p) => !(masked && maskable(p.unit)))
                        .slice(0, 2)
                        .map((p) => `${p.label} ${formatValue(p.value, p.unit ?? 'text', true)}`)
                        .join(', '),
            };
        case 'series': {
            const first = payload.series[0]?.key;
            const total = payload.total ?? payload.points.reduce((sum, p) => sum + Number(first ? (p[first] ?? 0) : 0), 0);

            return {
                value: total,
                unit: payload.unit,
                previous: payload.previous_total,
                caption: payload.total_label ?? payload.series[0]?.label ?? null,
            };
        }
        case 'progress':
            return {
                value: payload.value,
                unit: payload.unit,
                progress: payload.target ? payload.value / payload.target : null,
                caption: payload.target ? (
                    <WithFigure
                        text={t('dash.colour.of_target', { target: FIGURE_SLOT })}
                        figure={<Masked unit={payload.unit}>{formatValue(payload.target, payload.unit, true)}</Masked>}
                    />
                ) : (
                    t('dash.hero.no_target')
                ),
            };
        case 'signals':
            return { value: payload.items.reduce((sum, i) => sum + i.count, 0), unit: 'count', caption: null };
        case 'attention':
            return { value: payload.total_count, unit: 'count', caption: null };
        case 'list':
        case 'lookup':
            return { value: payload.total_count, unit: 'count', caption: null };
        case 'breakdown': {
            if (payload.total !== undefined) {
                return { value: payload.total, unit: payload.unit, caption: payload.headline ?? null };
            }

            const first = payload.items[0];

            return first ? { value: first.value, unit: payload.unit, caption: payload.headline ?? null } : null;
        }
        default:
            return null;
    }
}

export function KeyNumbers({
    dashboardKey,
    widgets,
    entries,
}: {
    dashboardKey: string;
    widgets: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
}) {
    const { t } = useTranslation();
    const { sensitive, allRevealed } = usePrivacy();
    const byKey = new Map(widgets.map((w) => [w.key, w]));
    const tiles = (KEY_RECIPES[dashboardKey] ?? []).flatMap((recipe) => {
        const widget = byKey.get(recipe.key);

        return widget ? [{ recipe, widget, entry: entries[widget.key] }] : [];
    });

    if (tiles.length === 0) {
        return null;
    }

    const anyHidden = !allRevealed && tiles.some(({ widget }) => sensitive.includes(widget.key));

    return (
        <section aria-labelledby="dash-key-numbers">
            <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="dash-key-numbers" className="text-muted-foreground text-[13px] font-semibold tracking-[0.05em] uppercase">
                    {t('dash.key.title')}
                </h2>
                {anyHidden && <p className="text-muted-foreground text-xs">{t('dash.key.hidden_note')}</p>}
            </div>
            <div
                className={cn(
                    'grid grid-cols-1 gap-3 min-[420px]:grid-cols-2',
                    tiles.length >= 4 ? 'xl:grid-cols-4' : tiles.length === 3 ? 'lg:grid-cols-3' : '',
                )}
            >
                {tiles.map(({ recipe, widget, entry }) => (
                    <KeyTile key={widget.key} recipe={recipe} widget={widget} entry={entry} />
                ))}
            </div>
        </section>
    );
}

function KeyTile({ recipe, widget, entry }: { recipe: KeyRecipe; widget: DashboardWidget; entry: FeedEntry | undefined }) {
    const { t, tr } = useTranslation();
    const cardMasked = usePrivacy().isMasked(widget.key);
    const payload = (entry?.result ?? widget.result).data;
    const figure = payload ? figureOf(payload, t, cardMasked) : null;
    const label = recipe.label ? t(recipe.label) : tr(`widget.${widget.key}.title`, widget.title);
    const masked = cardMasked && figure !== null && maskable(figure.unit);
    const numeric = figure && typeof figure.value === 'number' ? figure.value : null;
    const change = numeric !== null && !masked ? changeAgainst(numeric, figure?.previous) : null;
    const good = change ? (recipe.better === 'up') === change.up : null;
    const fullText = masked ? t('dash.privacy.hidden_figure') : figure ? formatValue(figure.value, figure.unit) : '';

    const body = (
        <>
            <p className={cn('text-muted-foreground truncate text-xs', widget.sensitive && 'pr-7')}>{label}</p>
            {figure === null ? (
                payload === null ? (
                    <p className="mt-2 flex items-center gap-1.5 text-sm font-medium text-amber-800 dark:text-amber-300">
                        <TriangleAlert className="size-4 shrink-0" aria-hidden /> {t('dash.hero.could_not_load')}
                    </p>
                ) : (
                    <p className="text-muted-foreground mt-2 text-sm">{(payload as { message?: string }).message ?? t('dash.hero.nothing_yet')}</p>
                )
            ) : (
                <>
                    <p
                        className="mt-1.5 text-[1.625rem] leading-tight font-semibold tracking-tight tabular-nums"
                        title={masked ? undefined : fullText}
                    >
                        <Masked unit={figure.unit}>
                            {numeric !== null ? <AnimatedNumber value={numeric} unit={figure.unit} compact flash={false} /> : figure.value}
                        </Masked>
                    </p>
                    {figure.progress !== undefined && figure.progress !== null && (
                        <div
                            className="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--dash-track)]"
                            role="progressbar"
                            aria-valuenow={Math.round(Math.min(1, Math.max(0, figure.progress)) * 100)}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div
                                className="h-full rounded-full bg-[var(--dash-good)]"
                                style={{ width: `${Math.min(1, Math.max(0, figure.progress)) * 100}%` }}
                            />
                        </div>
                    )}
                    <p className="text-muted-foreground mt-1.5 line-clamp-2 text-xs">
                        {change && (
                            <span
                                className={cn(
                                    'mr-1.5 font-medium tabular-nums',
                                    good ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400',
                                )}
                            >
                                {change.up ? '▲' : '▼'} {change.text}
                                <span className="sr-only">, {good ? t('dash.hero.better') : t('dash.hero.worse')}</span>
                            </span>
                        )}
                        {change && <span className="mr-1.5">{t('dash.key.vs_previous')}</span>}
                        {figure.caption}
                    </p>
                </>
            )}
        </>
    );

    const tileClass =
        'bg-card text-card-foreground block h-full rounded-xl border p-4 transition-colors hover:bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-2';

    return (
        <div className="relative h-full">
            <CardMaskProvider masked={cardMasked}>
                {widget.drill_down ? (
                    <Link href={widget.drill_down} className={tileClass} aria-label={t('dash.hero.open', { label, value: fullText })}>
                        {body}
                    </Link>
                ) : (
                    <div className={tileClass}>{body}</div>
                )}
            </CardMaskProvider>
            {/* Beside the link, never inside it: a button in a link is two controls in one. */}
            {widget.sensitive && (
                <PrivacyToggle
                    widgetKey={widget.key}
                    title={label}
                    className={cn(
                        'absolute top-3 right-3 rounded-md p-1 transition-colors',
                        cardMasked ? 'text-muted-foreground hover:bg-accent hover:text-foreground' : 'bg-accent text-foreground',
                    )}
                />
            )}
        </div>
    );
}
