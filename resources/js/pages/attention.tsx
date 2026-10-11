import { AttentionList, type AttentionItemData, type Severity } from '@/components/attention/attention-list';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { echo, ensureEcho } from '@/lib/echo';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

interface Filters {
    severity: Severity | null;
    area: string | null;
    /** A brand id, 'none' (orders with no brand), or null for all. */
    brand: string | null;
    mine: boolean;
}

interface Props {
    items: AttentionItemData[];
    total: number;
    counts: Record<Severity, number>;
    areas: string[];
    failed: { key: string; area: string }[];
    brands: { id: number; name: string }[] | null;
    filters: Filters;
    generated_at: string;
}

const SEVERITIES: Severity[] = ['critical', 'high', 'normal'];
const RELOAD = ['items', 'total', 'counts', 'areas', 'failed', 'generated_at', 'attentionCount'];

/**
 * Re-read the list when a dashboard event arrives (a parcel moved, a booking
 * failed, a statement was imported). Record changes are covered by the page's
 * `live` kinds through the app shell; this adds the courier signals.
 *
 * ⚠ Only this listener is removed on the way out, never the channel: the app
 * shell and the dashboards listen on it too.
 */
function useDashboardSignals(): void {
    const { realtime } = usePage<SharedData>().props;
    const channel = realtime?.business_channel ?? null;
    const enabled = Boolean(realtime?.enabled);

    useEffect(() => {
        if (!enabled || !channel || !ensureEcho()) {
            return;
        }

        let timer: number | null = null;
        const handle = () => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }
            timer = window.setTimeout(() => router.reload({ only: RELOAD }), 800);
        };

        const subscription = echo().private(channel);
        subscription.listen('.dashboard.event', handle);

        return () => {
            if (timer !== null) {
                window.clearTimeout(timer);
            }
            subscription.stopListening('.dashboard.event', handle);
        };
    }, [enabled, channel]);
}

export default function Attention({ items, total, counts, areas, failed, brands, filters }: Props) {
    const { t, tr } = useTranslation();
    const { auth } = usePage<SharedData>().props;
    const userId = (auth?.user?.id as number | undefined) ?? null;
    const breadcrumbs: BreadcrumbItem[] = [{ title: t('attention.title'), href: '/attention' }];

    useDashboardSignals();

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string | number> = {};

        if (merged.severity) query.severity = merged.severity;
        if (merged.area) query.area = merged.area;
        if (merged.brand) query.brand = merged.brand;
        if (merged.mine) query.mine = 1;

        router.get('/attention', query, { preserveScroll: true, preserveState: true, replace: true });
    };

    const filtered = Boolean(filters.severity || filters.area || filters.brand || filters.mine);
    const failedAreas = [...new Set(failed.map((f) => tr(`attention.area.${f.area}`, f.area)))].join(', ');
    const selectClass = 'border-input bg-background h-9 rounded-md border px-2 text-sm';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('attention.title')} />
            <div className="space-y-5 p-4">
                <div>
                    <h1 className="text-xl font-semibold">{t('attention.title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('attention.subtitle')}</p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {SEVERITIES.map((severity) => (
                        <button
                            key={severity}
                            type="button"
                            onClick={() => apply({ severity: filters.severity === severity ? null : severity })}
                            className={cn(
                                'rounded-lg border px-3 py-2 text-left transition',
                                filters.severity === severity ? 'border-foreground' : 'hover:border-foreground/40',
                                severity === 'critical' && counts.critical > 0 && 'border-red-500/50 bg-red-500/5',
                                severity === 'high' && counts.high > 0 && 'border-amber-500/50 bg-amber-500/5',
                            )}
                            aria-pressed={filters.severity === severity}
                        >
                            <span className="block text-2xl font-bold tabular-nums">{counts[severity]}</span>
                            <span className="text-muted-foreground text-xs">{t(`attention.severity.${severity}` as TranslationKey)}</span>
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <label className="flex flex-col gap-1 text-xs">
                        <span className="text-muted-foreground">{t('attention.filter.severity')}</span>
                        <select
                            className={selectClass}
                            value={filters.severity ?? ''}
                            onChange={(e) => apply({ severity: (e.target.value || null) as Severity | null })}
                        >
                            <option value="">{t('attention.filter.all_severities')}</option>
                            {SEVERITIES.map((severity) => (
                                <option key={severity} value={severity}>
                                    {t(`attention.severity.${severity}` as TranslationKey)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="flex flex-col gap-1 text-xs">
                        <span className="text-muted-foreground">{t('attention.filter.area')}</span>
                        <select className={selectClass} value={filters.area ?? ''} onChange={(e) => apply({ area: e.target.value || null })}>
                            <option value="">{t('attention.filter.all_areas')}</option>
                            {areas.map((area) => (
                                <option key={area} value={area}>
                                    {tr(`attention.area.${area}`, area)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {brands !== null && (
                        <label className="flex flex-col gap-1 text-xs">
                            <span className="text-muted-foreground">{t('attention.filter.brand')}</span>
                            <select className={selectClass} value={filters.brand ?? ''} onChange={(e) => apply({ brand: e.target.value || null })}>
                                <option value="">{t('attention.filter.all_brands')}</option>
                                {brands.map((brand) => (
                                    <option key={brand.id} value={String(brand.id)}>
                                        {brand.name}
                                    </option>
                                ))}
                                <option value="none">{t('orders.no_brand')}</option>
                            </select>
                        </label>
                    )}
                    <label className="flex h-9 items-center gap-2 text-sm">
                        <input type="checkbox" checked={filters.mine} onChange={(e) => apply({ mine: e.target.checked })} />
                        {t('attention.filter.mine')}
                    </label>
                    {filtered && (
                        <Button variant="ghost" size="sm" onClick={() => router.get('/attention', {}, { preserveScroll: true, replace: true })}>
                            {t('attention.clear_filters')}
                        </Button>
                    )}
                </div>

                {failed.length > 0 && (
                    <div
                        role="status"
                        className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-800 dark:text-amber-200"
                    >
                        {t('attention.failed_note', { areas: failedAreas })}
                    </div>
                )}

                {items.length === 0 ? (
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-6 text-center">
                        {total === 0 ? (
                            <>
                                <p className="text-lg font-semibold text-emerald-700 dark:text-emerald-300">{t('attention.all_clear')}</p>
                                <p className="text-muted-foreground text-sm">{t('attention.all_clear_hint')}</p>
                            </>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('attention.none_match')}</p>
                        )}
                    </div>
                ) : (
                    <>
                        <p className="text-muted-foreground text-xs">{t('attention.showing', { shown: items.length, total })}</p>
                        <AttentionList items={items} userId={userId} brandFiltered={filters.brand !== null} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
