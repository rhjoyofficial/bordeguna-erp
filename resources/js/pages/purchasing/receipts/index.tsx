import { Chip, Pill } from '@/components/assets/assets-ui';
import { Pagination } from '@/components/data-table/pagination';
import { ListFooter, SearchField } from '@/components/purchasing/purchases-ui';
import { BILL_STATE, type BillState } from '@/components/purchasing/receipt-state';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface ReceiptRow {
    id: number;
    received_on: string | null;
    supplier_challan_number: string | null;
    received_by: string | null;
    lines_count: number;
    value: string;
    bill_state: BillState;
    order: { id: number; po_number: string } | null;
    supplier: { id: number; name: string } | null;
    warehouse: string | null;
}

type Filters = {
    search: string;
    supplier_id: string;
    warehouse_id: string;
    purchase_order_id: string;
    from: string;
    to: string;
    billing: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
    { title: 'Receipts', href: '/purchasing/receipts' },
];

/**
 * ⭐ Receipt Notes (purchase redesign PR2, 18 Oct 2026; Tally's Receipt Note):
 * every delivery received against an order, newest first, filtered by
 * supplier, warehouse, order, date and whether anything is left to bill.
 * Each row opens the receipt's own page.
 */
export default function ReceiptsIndex({
    receipts,
    filters: initialFilters,
    suppliers,
    warehouses,
    can_receive,
}: {
    receipts: Paginated<ReceiptRow>;
    filters: Filters;
    suppliers: { id: number; name: string }[];
    warehouses: { id: number; name: string }[];
    can_receive: boolean;
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/purchasing/receipts', initialFilters);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('receipts.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">{t('receipts.intro')}</p>
                    {can_receive && (
                        <Button size="sm" variant="outline" asChild>
                            <Link href="/purchasing/outstanding-orders">{t('receipts.open_orders')}</Link>
                        </Button>
                    )}
                </div>

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div role="group" aria-label={t('receipts.col_bill')} className="flex flex-wrap gap-1.5">
                            <Chip active={!hasActiveFilters} onClick={reset}>
                                {t('receipts.chip_all')}
                            </Chip>
                            <Chip active={filters.billing === 'open'} onClick={() => setFilter('billing', filters.billing === 'open' ? '' : 'open')}>
                                {t('receipts.chip_open')}
                            </Chip>
                            <Chip
                                active={filters.billing === 'billed'}
                                onClick={() => setFilter('billing', filters.billing === 'billed' ? '' : 'billed')}
                            >
                                {t('receipts.chip_billed')}
                            </Chip>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
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
                            {warehouses.length > 1 && (
                                <SearchSelect
                                    value={filters.warehouse_id}
                                    onChange={(e) => setFilter('warehouse_id', e.target.value)}
                                    aria-label={t('receipts.col_warehouse')}
                                >
                                    <option value="">{t('receipts.all_warehouses')}</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            )}
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
                                placeholder={t('receipts.search')}
                                label={t('receipts.search')}
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>{t('receipts.col_receipt')}</th>
                                    <th>{t('receipts.col_date')}</th>
                                    <th>{t('receipts.col_supplier')}</th>
                                    <th>{t('receipts.col_order')}</th>
                                    <th>{t('receipts.col_warehouse')}</th>
                                    <th className="text-right!">{t('receipts.col_lines')}</th>
                                    <th className="text-right!">{t('receipts.col_value')}</th>
                                    <th>{t('receipts.col_bill')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {receipts.data.map((receipt) => (
                                    <tr key={receipt.id} className="hover:bg-muted/40">
                                        <td>
                                            <Link href={`/purchasing/receipts/${receipt.id}`} className="font-medium hover:underline">
                                                {t('receipts.receipt_no', { id: receipt.id })}
                                            </Link>
                                            {receipt.supplier_challan_number && (
                                                <div className="text-muted-foreground text-xs">challan {receipt.supplier_challan_number}</div>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {showDate(receipt.received_on) || '-'}
                                            {receipt.received_by && <div className="text-muted-foreground text-xs">{receipt.received_by}</div>}
                                        </td>
                                        <td>
                                            {receipt.supplier ? (
                                                <Link href={`/purchasing/suppliers/${receipt.supplier.id}`} className="hover:underline">
                                                    {receipt.supplier.name}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap">
                                            {receipt.order ? (
                                                <Link href={`/purchasing/purchase-orders/${receipt.order.id}`} className="hover:underline">
                                                    {receipt.order.po_number}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td>{receipt.warehouse ?? '-'}</td>
                                        <td className="text-right tabular-nums">{receipt.lines_count}</td>
                                        <td className="text-right font-medium tabular-nums">{receipt.value}</td>
                                        <td>
                                            <Pill tone={BILL_STATE[receipt.bill_state].tone}>{t(BILL_STATE[receipt.bill_state].key)}</Pill>
                                        </td>
                                    </tr>
                                ))}
                                {receipts.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                            {hasActiveFilters ? t('receipts.none_match') : t('receipts.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <ListFooter count={receipts.data.length} word="receipt">
                        {t('receipts.footer')}
                    </ListFooter>
                </section>

                <Pagination meta={receipts} />
            </div>
        </AppLayout>
    );
}
