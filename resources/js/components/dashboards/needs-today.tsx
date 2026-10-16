import { useAttentionText, type AttentionItemData } from '@/components/attention/attention-list';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, CircleCheck, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { NEEDS_ROW_WIDGETS } from './arrangement';
import type { DashboardWidget, ListPayload, SignalsPayload, Tone } from './types';
import type { FeedEntry } from './use-widget-feed';

/**
 * "Needs you today" (redesign round 3, Joy, 16 Oct 2026): the first block of
 * every role dashboard, read from payloads already on the page, never a new
 * query (workspace-design MAPPING §5, decision 3):
 *
 * - Owner, Finance, Manager: the ranked Needs attention rows (needs_attention),
 *   plus Finance's money approvals and the Manager's follow-ups.
 * - Sales and counter: my orders needing action, my follow-ups, approvals.
 * - My dashboard: my to-dos and requests (my_tasks).
 *
 * Seven rows at most, area chips filter, "See all" opens the full list. A
 * row's word badge is its only colour: Overdue red, Today amber, Waiting grey.
 * Owner and Manager also get the health check strip at the foot.
 */

const MAX_ROWS = 7;

type Badge = 'overdue' | 'today' | 'waiting';

interface NeedRow {
    id: string;
    chip: string;
    badge: Badge;
    title: string;
    sub: string;
    url: string;
}

const BADGE_CLASS: Record<Badge, string> = {
    overdue: 'bg-red-500/10 text-red-700 dark:text-red-300',
    today: 'bg-amber-500/12 text-amber-800 dark:text-amber-300',
    waiting: 'bg-muted text-muted-foreground',
};

const toneBadge = (tone: Tone): Badge => (tone === 'bad' ? 'overdue' : tone === 'warn' ? 'today' : 'waiting');

export function NeedsToday({
    dashboardKey,
    widgets,
    entries,
}: {
    dashboardKey: string;
    widgets: DashboardWidget[];
    entries: Record<string, FeedEntry | undefined>;
}) {
    const { t, tr } = useTranslation();
    const text = useAttentionText();
    const [chip, setChip] = useState('all');
    const byKey = new Map(widgets.map((w) => [w.key, w]));
    const data = (key: string) => {
        const widget = byKey.get(key);

        return widget ? (entries[key]?.result ?? widget.result).data : undefined;
    };

    const rows: NeedRow[] = [];
    let failed = false;

    // The ranked Needs attention rows.
    const attention = data('needs_attention');
    if (attention === null) {
        failed = true;
    } else if (attention?.kind === 'attention') {
        attention.items.forEach((item: AttentionItemData) => {
            rows.push({
                id: item.key,
                chip: item.area,
                badge: item.severity === 'critical' ? 'overdue' : item.severity === 'high' ? 'today' : 'waiting',
                title: text.reason(item),
                sub: `${tr(`attention.area.${item.area}`, item.area)} · ${text.age(item)}`,
                url: item.url,
            });
        });
    }

    // Rows of this role's own list widgets.
    (NEEDS_ROW_WIDGETS[dashboardKey] ?? []).forEach(({ key, chip: rowChip }) => {
        const payload = data(key);
        const widget = byKey.get(key);

        if (payload === null) {
            failed = true;

            return;
        }

        if (!widget || !payload || (payload.kind !== 'list' && payload.kind !== 'lookup')) {
            return;
        }

        const list = payload as ListPayload;
        const [first, ...rest] = list.columns;

        list.rows.forEach((row) => {
            rows.push({
                id: `${key}:${row.id}`,
                chip: rowChip,
                badge: toneBadge(row.tone),
                title: String(first ? (row.cells[first.key] ?? '') : ''),
                sub: [
                    tr(`widget.${key}.title`, widget.title),
                    ...rest.map((column) => row.cells[column.key]).filter((cell) => cell !== null && cell !== ''),
                ].join(' · '),
                url: row.url ?? widget.drill_down ?? '/work',
            });
        });
    });

    const hasBlock = byKey.has('needs_attention') || (NEEDS_ROW_WIDGETS[dashboardKey] ?? []).some(({ key }) => byKey.has(key));

    if (!hasBlock) {
        return null;
    }

    const chips = [...new Set(rows.map((row) => row.chip))];
    const shown = rows.filter((row) => chip === 'all' || row.chip === chip);
    const employee = dashboardKey === 'employee';
    const seeAll = employee ? '/work' : '/attention';
    const health = data('business_health');

    return (
        <section aria-labelledby="dash-needs" className="bg-card text-card-foreground overflow-hidden rounded-xl border">
            <header className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-4 pb-3">
                <div className="min-w-0 flex-1">
                    <h2 id="dash-needs" className="text-base font-semibold">
                        {employee ? t('dash.need.title_self') : t('dash.need.title')}
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        {rows.length === 0 ? t('dash.need.none') : t('dash.need.count', { count: rows.length })}
                    </p>
                </div>
                <Link href={seeAll} className="text-primary inline-flex shrink-0 items-center gap-0.5 text-sm font-medium hover:underline">
                    {employee ? t('dash.need.see_my_work') : t('dash.need.see_all')}
                    <ChevronRight className="size-4" aria-hidden />
                </Link>
            </header>

            {chips.length > 1 && (
                <div className="flex flex-wrap gap-1.5 px-4 pb-3" role="group" aria-label={t('dash.need.filter')}>
                    {['all', ...chips].map((key) => {
                        const count = key === 'all' ? rows.length : rows.filter((row) => row.chip === key).length;

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setChip(key)}
                                aria-pressed={chip === key}
                                className={cn(
                                    'min-h-7 rounded-full border px-2.5 py-0.5 text-xs transition-colors',
                                    chip === key ? 'bg-foreground text-background border-foreground' : 'bg-card hover:bg-muted',
                                )}
                            >
                                {key === 'all' ? t('dash.need.chip.all') : chipLabel(key, t, tr)} <span className="tabular-nums">{count}</span>
                            </button>
                        );
                    })}
                </div>
            )}

            {failed && (
                <p className="flex items-center gap-1.5 border-t px-4 py-2.5 text-xs text-amber-800 dark:text-amber-300">
                    <TriangleAlert className="size-3.5 shrink-0" aria-hidden /> {t('dash.need.part_failed')}
                </p>
            )}

            {shown.length === 0 ? (
                <p className="text-muted-foreground flex items-center gap-2 border-t px-4 py-3 text-sm">
                    <CircleCheck className="size-4 shrink-0 text-[var(--dash-good)]" aria-hidden /> {t('dash.need.all_clear')}
                </p>
            ) : (
                <ul>
                    {shown.slice(0, MAX_ROWS).map((row) => (
                        <li key={row.id} className="hover:bg-muted/40 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t px-4 py-2.5">
                            <span
                                className={cn(
                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap',
                                    BADGE_CLASS[row.badge],
                                )}
                            >
                                {t(`dash.need.badge.${row.badge}` as TranslationKey)}
                            </span>
                            <div className="min-w-0 flex-1 basis-48">
                                <p className="text-sm leading-snug">{row.title}</p>
                                <p className="text-muted-foreground truncate text-xs">{row.sub}</p>
                            </div>
                            <Link
                                href={row.url}
                                className="hover:bg-muted shrink-0 rounded-md border px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                            >
                                {t('attention.open')}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {shown.length > MAX_ROWS && (
                <p className="text-muted-foreground border-t px-4 py-2 text-xs">{t('dash.need.more', { count: shown.length - MAX_ROWS })}</p>
            )}

            {health?.kind === 'signals' && <HealthStrip payload={health} />}
        </section>
    );
}

function chipLabel(key: string, t: ReturnType<typeof useTranslation>['t'], tr: ReturnType<typeof useTranslation>['tr']): string {
    switch (key) {
        case 'orders':
            return t('dash.need.chip.orders');
        case 'followups':
            return t('dash.need.chip.followups');
        case 'approvals':
            return t('dash.need.chip.approvals');
        case 'todos':
            return t('dash.need.chip.todos');
        default:
            return tr(`attention.area.${key}`, key);
    }
}

/** "5 of 7 checks clear", the ones that are not named, and the way to Business health. */
function HealthStrip({ payload }: { payload: SignalsPayload }) {
    const { t } = useTranslation();
    const open = payload.items.filter((item) => item.count > 0);
    const clear = payload.items.length - open.length;

    return (
        <div className="bg-muted/30 flex flex-wrap items-center gap-x-3 gap-y-1 border-t px-4 py-2.5 text-xs">
            <span className="font-medium">{t('dash.need.health')}</span>
            <span className="text-muted-foreground tabular-nums">{t('dash.need.health_clear', { clear, total: payload.items.length })}</span>
            {open.length > 0 && (
                <span className="min-w-0 flex-1 text-red-700 dark:text-red-400">{open.map((item) => `${item.label} ${item.count}`).join(', ')}</span>
            )}
            <Link href="/health" className="text-primary ml-auto inline-flex items-center gap-0.5 font-medium hover:underline">
                {t('ws.business_health')}
                <ChevronRight className="size-3.5" aria-hidden />
            </Link>
        </div>
    );
}
