import { Pagination } from '@/components/data-table/pagination';
import { ListFooter, SearchField } from '@/components/purchasing/purchases-ui';
import { SearchSelect } from '@/components/search-select';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface ReturnRow {
    id: number;
    returned_on: string | null;
    returned_by: string | null;
    note: string | null;
    lines_count: number;
    value: string;
    credited: string;
    order: { id: number; po_number: string } | null;
    supplier: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
    { title: 'Returns', href: '/purchasing/returns' },
];

/**
 * ⭐ Purchase returns (purchase redesign PR2, 18 Oct 2026; Tally's Rejection
 * Out): goods sent back to a supplier, newest first, with their value and how
 * much of it a credit note has settled. Each row opens the return's page.
 */
export default function ReturnsIndex({
    returns,
    filters: initialFilters,
    suppliers,
}: {
    returns: Paginated<ReturnRow>;
    filters: { search: string; supplier_id: string; from: string; to: string };
    suppliers: { id: number; name: string }[];
}) {
    const { t } = useTranslation();
    const { filters, setFilter, hasActiveFilters } = useFilters('/purchasing/returns', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('returns.title')} />
            <div className="flex flex-col gap-4 p-4">
                <p className="text-muted-foreground text-sm">{t('returns.intro')}</p>

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-end gap-2 px-4 py-3">
                        <SearchSelect
                            value={filters.supplier_id}
                            onChange={(e) => setFilter('supplier_id', e.target.value)}
                            aria-label={t('receipts.col_supplier')}
                        >
                            <option value="">{t('receipts.all_suppliers')}</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <label className="flex items-center gap-1.5 text-xs">
                            <span className="text-muted-foreground">{t('receipts.from')}</span>
                            <Input type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} className="h-8 w-36" />
                        </label>
                        <label className="flex items-center gap-1.5 text-xs">
                            <span className="text-muted-foreground">{t('receipts.to')}</span>
                            <Input type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} className="h-8 w-36" />
                        </label>
                        <SearchField
                            value={filters.search}
                            onChange={(v) => setFilter('search', v)}
                            placeholder={t('returns.search')}
                            label={t('returns.search')}
                        />
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>{t('returns.col_return')}</th>
                                    <th>{t('returns.col_date')}</th>
                                    <th>{t('receipts.col_supplier')}</th>
                                    <th>{t('receipts.col_order')}</th>
                                    <th className="text-right!">{t('receipts.col_lines')}</th>
                                    <th className="text-right!">{t('receipts.col_value')}</th>
                                    <th className="text-right!">{t('returns.col_credited')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {returns.data.map((row) => (
                                    <tr key={row.id} className="hover:bg-muted/40">
                                        <td>
                                            <Link href={`/purchasing/returns/${row.id}`} className="font-medium hover:underline">
                                                {t('returns.return_no', { id: row.id })}
                                            </Link>
                                            {row.note && <div className="text-muted-foreground max-w-xs truncate text-xs">{row.note}</div>}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {showDate(row.returned_on) || '-'}
                                            {row.returned_by && <div className="text-muted-foreground text-xs">{row.returned_by}</div>}
                                        </td>
                                        <td>
                                            {row.supplier ? (
                                                <Link href={`/purchasing/suppliers/${row.supplier.id}`} className="hover:underline">
                                                    {row.supplier.name}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {row.order ? (
                                                <Link href={`/purchasing/purchase-orders/${row.order.id}`} className="hover:underline">
                                                    {row.order.po_number}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{row.lines_count}</td>
                                        <td className="text-right font-medium tabular-nums">{row.value}</td>
                                        <td className="text-right tabular-nums">{row.credited}</td>
                                    </tr>
                                ))}
                                {returns.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-muted-foreground p-6 text-center">
                                            {hasActiveFilters ? t('returns.none_match') : t('returns.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <ListFooter count={returns.data.length} word="return">
                        {t('returns.footer')}
                    </ListFooter>
                </section>

                <Pagination meta={returns} />
            </div>
        </AppLayout>
    );
}
