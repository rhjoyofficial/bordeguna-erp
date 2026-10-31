import { Chip, Pill } from '@/components/assets/assets-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Row {
    id: number;
    purchase_order_id: number;
    po_number: string;
    status: string | null;
    approved: boolean;
    supplier: { id: number; name: string } | null;
    warehouse: string | null;
    product: { id: number; name: string; sku: string } | null;
    unit: string | null;
    ordered: string;
    received: string;
    pending: string;
    unit_cost: string;
    pending_value: string;
    order_date: string | null;
    expected_date: string | null;
    overdue_days: number;
}

interface Group {
    key: number;
    name: string;
    sku: string | null;
    pending: string | null;
    unit: string | null;
    lines: number;
    orders: number;
    pending_value: string;
    overdue_value: string;
    overdue_lines: number;
}

type Filters = { supplier_id: string; product_id: string; warehouse_id: string; overdue: string; view: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
    { title: 'Outstanding orders', href: '/purchasing/outstanding-orders' },
];

const tableClass =
    'w-full text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap';

/**
 * ⭐ Outstanding orders (purchase redesign PR2, 18 Oct 2026; Tally's Purchase
 * Order Outstanding): every order line still waiting for goods, latest due
 * first, by line, supplier or product. A line links to its order and, when
 * the order is approved, straight to the Receive step.
 */
export default function OutstandingOrders({
    rows,
    groups,
    truncated,
    totals,
    filters: initialFilters,
    suppliers,
    warehouses,
    products,
    can_receive,
}: {
    rows: Row[];
    groups: Group[];
    truncated: boolean;
    totals: { lines: number; orders: number; overdue_lines: number; pending_value: string; overdue_value: string };
    filters: Filters;
    today: string;
    suppliers: { id: number; name: string }[];
    warehouses: { id: number; name: string }[];
    products: { id: number; name: string; sku: string }[];
    can_receive: boolean;
}) {
    const { t } = useTranslation();
    // ⚠ "line" is the server's default view; sent only when it is not.
    const { filters, setFilter, setMany } = useFilters('/purchasing/outstanding-orders', {
        ...initialFilters,
        view: initialFilters.view === 'line' ? '' : initialFilters.view,
    });
    const view = filters.view || 'line';
    const narrowed = Object.entries(filters).some(([key, value]) => key !== 'view' && value !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('outstanding.title')} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap gap-3">
                    <div className="bg-card rounded-xl border px-4 py-3">
                        <p className="text-muted-foreground text-xs">
                            {t('outstanding.total_lines', { lines: totals.lines, orders: totals.orders })}
                        </p>
                        <p className="text-lg font-semibold tabular-nums">{totals.pending_value}</p>
                        <p className="text-muted-foreground text-xs">{t('outstanding.total_value')}</p>
                    </div>
                    <div className="bg-card rounded-xl border px-4 py-3">
                        <p className="text-muted-foreground text-xs">
                            {t('outstanding.total_late')}: {totals.overdue_lines} {t('outstanding.col_lines')}
                        </p>
                        <p className={`text-lg font-semibold tabular-nums ${totals.overdue_lines > 0 ? 'text-destructive' : ''}`}>
                            {totals.overdue_value}
                        </p>
                        <p className="text-muted-foreground text-xs">{t('outstanding.col_late_value')}</p>
                    </div>
                </div>

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div role="group" aria-label={t('outstanding.title')} className="flex flex-wrap gap-1.5">
                            <Chip active={view === 'line'} onClick={() => setFilter('view', '')}>
                                {t('outstanding.view_line')}
                            </Chip>
                            <Chip active={view === 'supplier'} onClick={() => setFilter('view', 'supplier')}>
                                {t('outstanding.view_supplier')}
                            </Chip>
                            <Chip active={view === 'product'} onClick={() => setFilter('view', 'product')}>
                                {t('outstanding.view_product')}
                            </Chip>
                            <Chip
                                active={filters.overdue === '1'}
                                tone="danger"
                                onClick={() => setFilter('overdue', filters.overdue === '1' ? '' : '1')}
                            >
                                {t('outstanding.overdue_only')}
                            </Chip>
                        </div>
                        <div className="flex flex-wrap gap-2">
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
                            <SearchSelect
                                value={filters.product_id}
                                onChange={(e) => setFilter('product_id', e.target.value)}
                                aria-label={t('receipts.col_product')}
                            >
                                <option value="">{t('outstanding.all_products')}</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.sku})
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
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        {view === 'line' ? (
                            <table className={`${tableClass} min-w-[60rem]`}>
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th>{t('receipts.col_order')}</th>
                                        <th>{t('receipts.col_supplier')}</th>
                                        <th>{t('receipts.col_product')}</th>
                                        <th className="text-right!">{t('outstanding.col_ordered')}</th>
                                        <th className="text-right!">{t('outstanding.col_received')}</th>
                                        <th className="text-right!">{t('outstanding.col_pending')}</th>
                                        <th className="text-right!">{t('outstanding.col_value')}</th>
                                        <th>{t('outstanding.col_expected')}</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="hover:bg-muted/40">
                                            <td className="whitespace-nowrap">
                                                <Link
                                                    href={`/purchasing/purchase-orders/${row.purchase_order_id}`}
                                                    className="font-medium hover:underline"
                                                >
                                                    {row.po_number}
                                                </Link>
                                                {row.warehouse && <div className="text-muted-foreground text-xs">{row.warehouse}</div>}
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
                                            <td>
                                                {row.product?.name ?? '-'}
                                                {row.product && <div className="text-muted-foreground text-xs">{row.product.sku}</div>}
                                            </td>
                                            <td className="text-right tabular-nums">{formatQuantity(row.ordered)}</td>
                                            <td className="text-right tabular-nums">{formatQuantity(row.received)}</td>
                                            <td className="text-right font-medium whitespace-nowrap tabular-nums">
                                                {formatQuantity(row.pending)} {row.unit ?? ''}
                                            </td>
                                            <td className="text-right tabular-nums">{row.pending_value}</td>
                                            <td className="whitespace-nowrap">
                                                {row.expected_date ? formatDate(row.expected_date) : t('outstanding.no_date')}
                                                {row.overdue_days > 0 && (
                                                    <div className="text-destructive text-xs">
                                                        {t('outstanding.late_days', { days: row.overdue_days })}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="text-right whitespace-nowrap">
                                                {!row.approved ? (
                                                    <Pill tone="warning">{t('outstanding.waiting_approval')}</Pill>
                                                ) : (
                                                    can_receive && (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={`/purchasing/purchase-orders/${row.purchase_order_id}?receive=1`}>
                                                                {t('outstanding.receive')}
                                                            </Link>
                                                        </Button>
                                                    )
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {rows.length === 0 && (
                                        <tr>
                                            <td colSpan={9} className="text-muted-foreground p-6 text-center">
                                                {narrowed ? t('outstanding.none_match') : t('outstanding.none')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            <table className={`${tableClass} min-w-[44rem]`}>
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th>{view === 'supplier' ? t('receipts.col_supplier') : t('receipts.col_product')}</th>
                                        {view === 'product' && <th className="text-right!">{t('outstanding.col_pending')}</th>}
                                        <th className="text-right!">{t('outstanding.col_lines')}</th>
                                        <th className="text-right!">{t('outstanding.col_orders')}</th>
                                        <th className="text-right!">{t('outstanding.col_value')}</th>
                                        <th className="text-right!">{t('outstanding.col_late_value')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {groups.map((group) => (
                                        <tr key={group.key} className="hover:bg-muted/40">
                                            <td>
                                                <button
                                                    type="button"
                                                    className="font-medium hover:underline"
                                                    onClick={() =>
                                                        setMany(
                                                            view === 'supplier'
                                                                ? { supplier_id: String(group.key), view: '' }
                                                                : { product_id: String(group.key), view: '' },
                                                        )
                                                    }
                                                >
                                                    {group.name}
                                                </button>
                                                {group.sku && <div className="text-muted-foreground text-xs">{group.sku}</div>}
                                            </td>
                                            {view === 'product' && (
                                                <td className="text-right whitespace-nowrap tabular-nums">
                                                    {group.pending === null ? '-' : `${formatQuantity(group.pending)} ${group.unit ?? ''}`}
                                                </td>
                                            )}
                                            <td className="text-right tabular-nums">{group.lines}</td>
                                            <td className="text-right tabular-nums">{group.orders}</td>
                                            <td className="text-right font-medium tabular-nums">{group.pending_value}</td>
                                            <td className={`text-right tabular-nums ${group.overdue_lines > 0 ? 'text-destructive' : ''}`}>
                                                {group.overdue_value}
                                            </td>
                                        </tr>
                                    ))}
                                    {groups.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                                {narrowed ? t('outstanding.none_match') : t('outstanding.none')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>
                    {truncated && <p className="text-muted-foreground border-t px-4 py-2.5 text-xs">{t('outstanding.truncated')}</p>}
                </section>
            </div>
        </AppLayout>
    );
}
