import { useDocumentLabel } from '@/components/documents/document-link';
import { CompactTable, Panel } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export interface LifetimeRange {
    from: string | null;
    to: string | null;
    all_time: boolean;
}

/**
 * ⭐ B23 lifetime views (25 Oct 2026): the window every lifetime page reads.
 * Empty boxes mean the whole history (the default); Show reloads the page with
 * the window, All time clears it, Excel downloads the same window.
 *
 * Owner UI rules: the date boxes come first, there is no form (Enter never
 * submits), and every control is only as wide as its content.
 */
export function LifetimeRangeBar({
    range,
    baseUrl,
    exportUrl,
    keep = {},
}: {
    range: LifetimeRange;
    baseUrl: string;
    exportUrl?: string | null;
    /** Query values the page keeps across a window change (a warehouse filter). */
    keep?: Record<string, string | number>;
}) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(range.from ?? '');
    const [to, setTo] = useState(range.to ?? '');

    const visit = (params: Record<string, string | number>) =>
        router.get(baseUrl, { ...keep, ...params }, { preserveScroll: true, preserveState: true });
    const query = new URLSearchParams(Object.entries({ from: range.from ?? '', to: range.to ?? '' }).filter(([, value]) => value !== '')).toString();

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label htmlFor="life-from" className="text-xs">
                    {t('life.from')}
                </Label>
                <Input id="life-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="life-to" className="text-xs">
                    {t('life.to')}
                </Label>
                <Input id="life-to" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
            </div>
            <Button type="button" size="sm" variant="outline" onClick={() => visit({ ...(from ? { from } : {}), ...(to ? { to } : {}) })}>
                {t('life.apply')}
            </Button>
            {!range.all_time && (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        setFrom('');
                        setTo('');
                        visit({});
                    }}
                >
                    {t('life.all_time')}
                </Button>
            )}
            {exportUrl && (
                <Button type="button" size="sm" variant="outline" asChild>
                    <a href={query ? `${exportUrl}?${query}` : exportUrl}>{t('life.export')}</a>
                </Button>
            )}
            <span className="text-muted-foreground pb-2 text-xs">
                {range.all_time
                    ? t('life.window_all')
                    : t('life.window_some', {
                          from: range.from ? showDate(range.from) : t('life.window_open'),
                          to: range.to ? showDate(range.to) : t('life.window_today'),
                      })}
            </span>
        </div>
    );
}

export interface PartyLifetimeTotal {
    type: string;
    label: string;
    side: string;
    count: number;
    amount: string | null;
}

export interface PartyTimelineLine {
    date: string;
    side: string;
    kind: string;
    label: string;
    reference: string | null;
    change: string;
    net: string;
    net_side: 'us' | 'them' | 'even';
}

export interface PartyLifetimeProps {
    range: LifetimeRange;
    combined: boolean;
    other: { side: 'supplier' | 'customer'; id: number; name: string } | null;
    totals: PartyLifetimeTotal[];
    timeline: {
        opening: { net: string; net_side: 'us' | 'them' | 'even' };
        closing: { net: string; net_side: 'us' | 'them' | 'even' };
        lines: PartyTimelineLine[];
        combined: boolean;
    } | null;
}

/** "1,200.00 we owe them", in the reader's language. */
export function NetWords({ net, side }: { net: string; side: 'us' | 'them' | 'even' }) {
    const { t } = useTranslation();

    return (
        <span className="whitespace-nowrap tabular-nums">
            {side === 'even' ? '0.00' : formatMoney(net)}{' '}
            <span className="text-muted-foreground text-xs">{t(`life.net.${side}` as TranslationKey)}</span>
        </span>
    );
}

/**
 * ⭐ B23 (25 Oct 2026; R75): everything with one supplier or customer in the
 * window: documents by kind with their totals, and one running balance (both
 * accounts and our advance when the firm is on both sides).
 */
export function PartyLifetimePanel({ lifetime, baseUrl, exportUrl }: { lifetime: PartyLifetimeProps; baseUrl: string; exportUrl: string }) {
    const { t, tr } = useTranslation();
    const labelOf = useDocumentLabel();
    const sideLabel = (side: string) => tr(`life.side.${side}`, side);
    const otherUrl = lifetime.other
        ? lifetime.other.side === 'customer'
            ? `/sales/customers/${lifetime.other.id}`
            : `/purchasing/suppliers/${lifetime.other.id}`
        : null;

    return (
        <Panel title={t('life.party_title')}>
            <p className="text-muted-foreground mt-1 text-xs">{t('life.party_intro')}</p>
            <div className="mt-3">
                <LifetimeRangeBar range={lifetime.range} baseUrl={baseUrl} exportUrl={exportUrl} />
            </div>
            {lifetime.other && otherUrl && (
                <p className="mt-3 text-sm">
                    {t('life.party_combined', { side: t(`life.side_word.${lifetime.other.side}` as TranslationKey) })}{' '}
                    <Link href={otherUrl} className="font-medium underline-offset-2 hover:underline">
                        {lifetime.other.name}
                    </Link>
                    . {t('life.party_combined_tail')}
                </p>
            )}

            <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,22rem)_1fr]">
                <div>
                    <h3 className="mb-2 text-sm font-semibold">{t('life.totals')}</h3>
                    <CompactTable minWidth="18rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>{t('life.col_kind')}</th>
                                <th className="text-right">{t('life.col_count')}</th>
                                <th className="text-right">{t('life.col_amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lifetime.totals.map((row) => (
                                <tr key={`${row.side}-${row.type}`}>
                                    <td>
                                        {labelOf(row)}
                                        {lifetime.combined && <span className="text-muted-foreground block text-xs">{sideLabel(row.side)}</span>}
                                    </td>
                                    <td className="text-right tabular-nums">{row.count}</td>
                                    <td className="text-right tabular-nums">{row.amount ? formatMoney(row.amount) : ''}</td>
                                </tr>
                            ))}
                            {lifetime.totals.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="text-muted-foreground text-center">
                                        {t('life.none')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                </div>

                {lifetime.timeline && (
                    <div>
                        <h3 className="mb-1 text-sm font-semibold">{t('life.timeline')}</h3>
                        <p className="text-muted-foreground mb-2 text-xs">{t('life.change_hint')}</p>
                        <CompactTable minWidth="44rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('life.col_date')}</th>
                                    {lifetime.timeline.combined && <th>{t('life.col_side')}</th>}
                                    <th>{t('life.col_what')}</th>
                                    <th>{t('life.col_reference')}</th>
                                    <th className="text-right">{t('life.col_change')}</th>
                                    <th className="text-right">{t('life.col_net')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colSpan={lifetime.timeline.combined ? 5 : 4} className="text-muted-foreground italic">
                                        {t('life.opening')}
                                    </td>
                                    <td className="text-right">
                                        <NetWords net={lifetime.timeline.opening.net} side={lifetime.timeline.opening.net_side} />
                                    </td>
                                </tr>
                                {lifetime.timeline.lines.map((line, index) => (
                                    <tr key={index}>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(line.date)}</td>
                                        {lifetime.timeline?.combined && <td className="text-xs">{sideLabel(line.side)}</td>}
                                        <td>{line.label}</td>
                                        <td className="text-muted-foreground text-xs">{line.reference ?? ''}</td>
                                        <td className="text-right tabular-nums">{formatMoney(line.change)}</td>
                                        <td className="text-right">
                                            <NetWords net={line.net} side={line.net_side} />
                                        </td>
                                    </tr>
                                ))}
                                <tr className="font-medium">
                                    <td colSpan={lifetime.timeline.combined ? 5 : 4}>{t('life.closing')}</td>
                                    <td className="text-right">
                                        <NetWords net={lifetime.timeline.closing.net} side={lifetime.timeline.closing.net_side} />
                                    </td>
                                </tr>
                            </tbody>
                        </CompactTable>
                    </div>
                )}
            </div>
        </Panel>
    );
}
