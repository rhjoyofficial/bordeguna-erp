import { Pagination } from '@/components/data-table/pagination';
import { MarketPriceDialog, plainPrice, type MarketProduct } from '@/components/sales/market-price-dialog';
import { PriceDialog } from '@/components/sales/price-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Price {
    id: number;
    unit_price: string;
    min_quantity: string;
    /** ⚠ Plain Y-m-d Dhaka dates from the server; the raw casts serialise in UTC. */
    effective_from: string;
    effective_to: string | null;
    note: string | null;
    product: { id: number; name: string; sku: string } | null;
    customer: { id: number; name: string } | null;
    price_group: { id: number; code: string; name: string } | null;
}

/** Today's price of one market product (Phase 2 / 1B, 17 Oct 2026). */
interface MarketRow {
    product_id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    unit_price: string;
    cost_snapshot: string | null;
    margin_percent: string | null;
    since: string;
    since_date: string;
    confirmed_by: string | null;
}

interface HistoryRow {
    id: number;
    product: string | null;
    source: 'receipt' | 'manual' | string;
    cost_snapshot: string | null;
    prior_price: string | null;
    new_price: string;
    /** 18 Oct 2026 (D-E): true = a markdown below the batch cost. */
    is_override: boolean;
    override_reason: string | null;
    confirmed_by: string | null;
    confirmed_at: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Prices', href: '/sales/prices' },
];

export default function PricesIndex({
    prices,
    products,
    customers,
    priceGroups,
    marketPrices = [],
    marketHistory = [],
    marketProducts = [],
    marketUnpriced = [],
    canMarkDown = false,
}: {
    prices: Paginated<Price>;
    products: { id: number; name: string; sku: string }[];
    customers: { id: number; name: string }[];
    priceGroups: { id: number; code: string; name: string }[];
    marketPrices?: MarketRow[];
    marketHistory?: HistoryRow[];
    marketProducts?: MarketProduct[];
    /** ⭐ 18 Oct 2026 (D-E): market products with no confirmed price: not on sale today. */
    marketUnpriced?: string[];
    canMarkDown?: boolean;
}) {
    const { canImportPrices, canManagePrices } = usePage<SharedData>().props;
    const { t } = useTranslation();
    // ⭐ Setting a price is a popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). ⚠ The list is
    // open to whoever reads sales; the button only to who may set a price.
    const [adding, setAdding] = useState(false);
    // ⭐ Phase 2 / 1B: today's price of a market product, in its own popup.
    const [repricing, setRepricing] = useState(false);
    const [showHistory, setShowHistory] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Selling Prices" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Import prices was a sidebar row until 29 Sep 2026. */}
                        {canImportPrices && (
                            <Button asChild variant="outline">
                                <Link href="/sales/prices/import">Import prices</Link>
                            </Button>
                        )}
                        {canManagePrices && marketProducts.length > 0 && (
                            <Button variant="outline" onClick={() => setRepricing(true)}>
                                {t('market.set_todays_price')}
                            </Button>
                        )}
                        {canManagePrices && <Button onClick={() => setAdding(true)}>Add price</Button>}
                    </div>
                </div>

                {/* ⭐ Phase 2 / 1B (17 Oct 2026): today's market prices, above the price
                    list. The receipt proposes, the receiver confirms; this is the result. */}
                {marketProducts.length > 0 && (
                    <section className="bg-card rounded-lg border">
                        <div className="flex flex-wrap items-start justify-between gap-2 border-b px-4 py-3">
                            <div>
                                <p className="text-[15px] font-semibold">{t('market.section_title')}</p>
                                <p className="text-muted-foreground text-xs">{t('market.section_intro')}</p>
                            </div>
                            <Button variant="ghost" size="sm" onClick={() => setShowHistory((open) => !open)} aria-expanded={showHistory}>
                                {showHistory ? t('market.history_hide') : t('market.history')}
                            </Button>
                        </div>
                        {marketUnpriced.length > 0 && (
                            <p className="border-b px-4 py-2 text-xs text-amber-700 dark:text-amber-400">
                                {t('market.hidden_unpriced', { names: marketUnpriced.join(', ') })}
                            </p>
                        )}
                        {marketPrices.length === 0 ? (
                            <p className="text-muted-foreground px-4 py-4 text-sm">{t('market.none_yet')}</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3">{t('market.product')}</th>
                                            <th className="p-3 text-right">{t('market.cost')}</th>
                                            <th className="p-3 text-right">{t('market.price')}</th>
                                            <th className="p-3 text-right">{t('market.margin')}</th>
                                            <th className="p-3">{t('market.since')}</th>
                                            <th className="p-3">{t('market.by')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {marketPrices.map((row) => (
                                            <tr key={row.product_id} className="border-t">
                                                <td className="p-3">
                                                    {row.product}
                                                    {row.unit && <span className="text-muted-foreground text-xs"> / {row.unit}</span>}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {row.cost_snapshot === null ? '-' : formatMoney(row.cost_snapshot)}
                                                </td>
                                                <td className="p-3 text-right font-medium tabular-nums">{formatMoney(row.unit_price)}</td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {row.margin_percent === null ? '-' : `${row.margin_percent}%`}
                                                </td>
                                                <td className="p-3">
                                                    <span className="inline-block rounded-full bg-sky-100 px-1.5 py-px text-[11px] font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                                        {t('market.todays_price_since', { time: row.since })}
                                                    </span>
                                                    <span className="text-muted-foreground ml-1 text-xs">{formatDate(row.since_date)}</span>
                                                </td>
                                                <td className="text-muted-foreground p-3">{row.confirmed_by ?? '-'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        {showHistory && (
                            <div className="border-t">
                                {marketHistory.length === 0 ? (
                                    <p className="text-muted-foreground px-4 py-3 text-sm">{t('market.history_none')}</p>
                                ) : (
                                    <ul className="divide-y">
                                        {marketHistory.map((row) => (
                                            <li key={row.id} className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-4 py-2 text-[13px]">
                                                <span className="text-muted-foreground w-28 shrink-0 text-xs tabular-nums">
                                                    {showDate(row.confirmed_at)}
                                                </span>
                                                <span className="font-medium">{row.product}</span>
                                                <span className="tabular-nums">
                                                    {row.prior_price !== null && (
                                                        <span className="text-muted-foreground">
                                                            {t('market.prior')} {plainPrice(row.prior_price)} {'->'}{' '}
                                                        </span>
                                                    )}
                                                    Tk {plainPrice(row.new_price)}
                                                </span>
                                                <span className="text-muted-foreground text-xs">
                                                    {t('market.cost')} {plainPrice(row.cost_snapshot)} ·{' '}
                                                    {row.source === 'manual' ? t('market.source.manual') : t('market.source.receipt')} ·{' '}
                                                    {row.confirmed_by}
                                                    {row.is_override && (
                                                        <span className="ml-1 text-amber-700 dark:text-amber-400">
                                                            {t('market.markdown')}
                                                            {row.override_reason ? `: ${row.override_reason}` : ''}
                                                        </span>
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </section>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3">Applies to</th>
                                <th className="p-3 text-right">From qty</th>
                                <th className="p-3 text-right">Price</th>
                                <th className="p-3">In force</th>
                            </tr>
                        </thead>
                        <tbody>
                            {prices.data.map((price) => (
                                <tr key={price.id} className="border-t">
                                    <td className="p-3">{price.product?.name ?? '-'}</td>
                                    <td className="p-3">
                                        {price.customer ? (
                                            <Badge variant="secondary">{price.customer.name}</Badge>
                                        ) : price.price_group ? (
                                            <Badge variant="outline">{price.price_group.code}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground">Standard (consumers only)</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(price.min_quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(price.unit_price)}</td>
                                    <td className="text-muted-foreground p-3">
                                        {formatDate(price.effective_from)}
                                        {price.effective_to ? ` to ${formatDate(price.effective_to)}` : ' onwards'}
                                    </td>
                                </tr>
                            ))}
                            {prices.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        No selling prices set yet. Nothing can be sold until there is one.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={prices} />

                {canManagePrices && (
                    <PriceDialog open={adding} onOpenChange={setAdding} products={products} customers={customers} priceGroups={priceGroups} />
                )}
                {canManagePrices && (
                    <MarketPriceDialog open={repricing} onOpenChange={setRepricing} products={marketProducts} canMarkDown={canMarkDown} />
                )}
            </div>
        </AppLayout>
    );
}
