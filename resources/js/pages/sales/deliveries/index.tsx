import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { BrandFilter, type BrandOption } from '@/components/sales/brand';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Delivery {
    id: number;
    delivery_number: string;
    /** ⚠ A plain Y-m-d Dhaka date from the server; the raw cast serialises in UTC. */
    delivered_at: string;
    fully_invoiced: boolean;
    customer: { id: number; name: string } | null;
    warehouse: { id: number; name: string } | null;
    lines: { id: number; quantity: string; quantity_invoiced: string }[];
    // Through its order (Joy, 11 Oct 2026). Null when the order has none.
    brand: string | null;
}

/**
 * ⭐ Daily screen 5 of 6 in plain words and Bangla (26 Sep 2026). Digits and
 * dates never translate.
 */
export default function DeliveriesIndex({
    deliveries,
    filters: initialFilters,
    brands = [],
}: {
    deliveries: Paginated<Delivery>;
    filters: { search: string; status: string; brand: string };
    brands?: BrandOption[];
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('delivery.sales'), href: '/sales/overview' },
        { title: t('delivery.title'), href: '/sales/deliveries' },
    ];
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/deliveries', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('delivery.title')} />
            <div className="flex flex-col gap-4 p-4">
                {/* An uninvoiced challan is not an error. It is goods out that
                    nobody has billed yet, and its value is what sits in Goods
                    Delivered Not Invoiced. */}
                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder={t('delivery.search_placeholder')}
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">{t('delivery.all')}</option>
                        <option value="uninvoiced">{t('delivery.not_fully_invoiced')}</option>
                    </SearchSelect>
                    <BrandFilter value={filters.brand ?? ''} onChange={(v) => setFilter('brand', v)} brands={brands} />
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">{t('delivery.col_challan')}</th>
                                <th className="p-3">{t('delivery.col_date')}</th>
                                <th className="p-3">{t('delivery.col_customer')}</th>
                                <th className="p-3">{t('delivery.col_from')}</th>
                                <th className="p-3 text-right">{t('delivery.col_lines')}</th>
                                <th className="p-3"></th>
                                <th className="p-3 text-right">{t('delivery.col_print')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {deliveries.data.map((delivery) => (
                                <tr key={delivery.id} className="border-t">
                                    <td className="p-3 font-medium">{delivery.delivery_number}</td>
                                    <td className="p-3">{showDate(delivery.delivered_at)}</td>
                                    <td className="p-3">
                                        {delivery.customer?.name ?? '-'}
                                        {delivery.brand && (
                                            <Badge variant="outline" className="ml-2">
                                                {delivery.brand}
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{delivery.warehouse?.name ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{delivery.lines.length}</td>
                                    <td className="p-3">
                                        <Badge variant={delivery.fully_invoiced ? 'secondary' : 'outline'}>
                                            {delivery.fully_invoiced ? t('delivery.invoiced') : t('delivery.awaiting_invoice')}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right">
                                        {/* The only way to reach the challan. An engine
                                            with no door is one this project has shipped
                                            five times already. */}
                                        <Link
                                            href={`/sales/deliveries/${delivery.id}/print`}
                                            className="text-sm font-medium underline underline-offset-4"
                                        >
                                            {t('delivery.challan_link')}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {deliveries.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                        {t('delivery.none_yet')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={deliveries} />
            </div>
        </AppLayout>
    );
}
