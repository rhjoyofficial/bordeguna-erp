import { Button } from '@/components/ui/button';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, ArrowRight, Info } from 'lucide-react';

export type Severity = 'critical' | 'high' | 'normal';

export interface AttentionAction {
    label: string;
    label_key: string;
    url: string;
    method: 'post' | 'delete';
    confirm?: string;
}

/** One item of Needs attention (App\Support\Attention\AttentionItem::toArray). */
export interface AttentionItemData {
    key: string;
    source: string;
    area: string;
    severity: Severity;
    reason: string;
    reason_key: string;
    params: Record<string, string | number>;
    url: string;
    since: string | null;
    age_days: number | null;
    owner_role: string | null;
    owner_user_id: number | null;
    count: number | null;
    brand_id: number | null;
    actions: AttentionAction[];
    /** True for a per-parcel item: it belongs to its order's brand. */
    brand_aware?: boolean;
    /** The brand's name, when the item belongs to one. */
    brand?: string | null;
    mine?: boolean;
}

const SEVERITY_STYLE: Record<Severity, { row: string; badge: string; Icon: typeof Info }> = {
    critical: { row: 'border-l-red-500', badge: 'bg-red-500/15 text-red-700 dark:text-red-300', Icon: AlertOctagon },
    high: { row: 'border-l-amber-500', badge: 'bg-amber-500/15 text-amber-700 dark:text-amber-300', Icon: AlertTriangle },
    normal: { row: 'border-l-sky-500', badge: 'bg-sky-500/15 text-sky-700 dark:text-sky-300', Icon: Info },
};

/**
 * The reason in the reader's language: the dictionary sentence with the
 * server's figures dropped in (digits, money and dates are never translated),
 * else the server's own English. A courier status inside it is said in Bangla
 * too when the dictionary knows it.
 */
export function useAttentionText() {
    const { t, tr } = useTranslation();

    const reason = (item: AttentionItemData): string => {
        const params: Record<string, string | number> = { ...item.params };

        if (typeof params.status_value === 'string' && params.status_value !== '') {
            params.status = tr(`courier_status.${params.status_value}`, String(params.status ?? params.status_value));
        }

        return tr(item.reason_key, item.reason, params);
    };

    const owner = (item: AttentionItemData, userId: number | null): string => {
        if (item.owner_user_id !== null && item.owner_user_id === userId) {
            return t('attention.owner.you');
        }

        if (item.owner_role) {
            return tr(`attention.role.${item.owner_role.toLowerCase()}`, item.owner_role);
        }

        return t('attention.owner.anyone');
    };

    const age = (item: AttentionItemData): string => {
        if (item.age_days === null) {
            return t('attention.age.unknown');
        }

        return item.age_days === 0 ? t('attention.age.today') : t('attention.age.days', { days: item.age_days });
    };

    return { reason, owner, age, t, tr };
}

export function SeverityBadge({ severity }: { severity: Severity }) {
    const { t } = useTranslation();
    const style = SEVERITY_STYLE[severity] ?? SEVERITY_STYLE.normal;

    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', style.badge)}>
            <style.Icon className="size-3" aria-hidden />
            {t(`attention.severity.${severity}` as TranslationKey)}
        </span>
    );
}

/**
 * The ranked rows, shared by /attention and the dashboard widget so the two
 * look and read the same. `compact` drops the owner column for the widget.
 */
export function AttentionList({
    items,
    userId,
    compact = false,
    brandFiltered = false,
}: {
    items: AttentionItemData[];
    userId: number | null;
    compact?: boolean;
    /** A brand filter is on: say which lines are about the whole business. */
    brandFiltered?: boolean;
}) {
    const { reason, owner, age, t, tr } = useAttentionText();

    const run = (action: AttentionAction) => {
        if (action.confirm && !window.confirm(tr(action.confirm, action.confirm))) {
            return;
        }

        const options = { preserveScroll: true };

        if (action.method === 'delete') {
            router.delete(action.url, options);
        } else {
            router.post(action.url, {}, options);
        }
    };

    return (
        <ul className="divide-y rounded-lg border">
            {items.map((item) => {
                const style = SEVERITY_STYLE[item.severity] ?? SEVERITY_STYLE.normal;

                return (
                    <li
                        key={item.key}
                        className={cn(
                            'animate-in fade-in slide-in-from-top-1 flex flex-col gap-2 border-l-4 p-3 duration-300 motion-reduce:animate-none sm:flex-row sm:items-center',
                            style.row,
                        )}
                    >
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <SeverityBadge severity={item.severity} />
                                <span className="text-muted-foreground text-xs">{tr(`attention.area.${item.area}`, item.area)}</span>
                                {item.brand ? (
                                    <span className="rounded border px-1.5 text-xs">{item.brand}</span>
                                ) : (
                                    brandFiltered &&
                                    !item.brand_aware && <span className="text-muted-foreground text-xs">{t('orders.all_brands')}</span>
                                )}
                                {!compact && (
                                    <span className="text-muted-foreground text-xs">
                                        {t('attention.col.owner')}: <span className="text-foreground">{owner(item, userId)}</span>
                                    </span>
                                )}
                            </div>
                            <p className="text-sm leading-snug break-words">{reason(item)}</p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:justify-end">
                            <span className="text-muted-foreground w-16 text-right text-xs tabular-nums" title={item.since ?? undefined}>
                                {age(item)}
                            </span>
                            {item.actions.map((action) => (
                                <Button key={action.url + action.method} size="sm" variant="outline" onClick={() => run(action)}>
                                    {tr(action.label_key, action.label)}
                                </Button>
                            ))}
                            <Button asChild size="sm" variant="secondary">
                                <Link href={item.url} prefetch>
                                    {t('attention.open')}
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Link>
                            </Button>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
