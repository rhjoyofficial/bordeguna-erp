import { Pagination } from '@/components/data-table/pagination';
import { DocumentLink, type DocumentRef, useDocumentLabel } from '@/components/documents/document-link';
import { Chip, CompactTable, inventoryCrumbs, Panel, Pill, Stat } from '@/components/inventory/inventory-ui';
import { type JoinableFamily } from '@/components/inventory/product-family-dialogs';
import { type ProductFamilyInfo, ProductFamilyStrip } from '@/components/inventory/product-family-strip';
import { type LifetimeRange, LifetimeRangeBar } from '@/components/lifetime/lifetime-ui';
import {
    ProductBatchProfit,
    type ProductLifetimeData,
    ProductLifetimeTotals,
    ProductRuns,
    ProductUsedIn,
} from '@/components/lifetime/product-lifetime';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type TranslationKey, useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface ProductInfo {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    type: string | null;
    category: string | null;
    brand: string | null;
    unit: string | null;
    is_active: boolean;
    tracks_expiry: boolean;
    low_stock_threshold: string | null;
    /** ⭐ Initial plan B4 (24 Oct 2026). */
    can_be_sold?: boolean;
    material_kind?: 'raw' | 'packaging' | null;
    made_here?: boolean;
    default_supplier?: { id: number; name: string } | null;
}

interface CardRow {
    id: number;
    date: string | null;
    reason: string;
    warehouse: string | null;
    batch_id: number | null;
    batch_number: string | null;
    in: string | null;
    out: string | null;
    rate: string | null;
    value: string;
    balance: string;
    balance_value: string;
    document: (DocumentRef & { party: string | null }) | null;
    undoes: boolean;
    note: string | null;
    by: string | null;
}

interface Card {
    opening: { quantity: string; value: string } | null;
    rows: Paginated<CardRow>;
    closing: { quantity: string; value: string };
}

interface BatchRow {
    id: number;
    batch_number: string | null;
    warehouse: string | null;
    received_at: string | null;
    expiry_date: string | null;
    received: string;
    on_hand: string;
    unit_cost: string | null;
    status: string;
    held: boolean;
}

interface PurchaseRow {
    id: number;
    date: string | null;
    document: DocumentRef | null;
    supplier: string | null;
    supplier_url: string | null;
    ordered: string;
    received: string;
    rate: string;
    discount: string | null;
    landed: string | null;
    landed_unit_cost: string | null;
}

interface SaleRow {
    id: number;
    date: string | null;
    document: DocumentRef;
    customer: string | null;
    customer_url: string | null;
    quantity: string;
    price: string;
    total: string | null;
}

interface CostRow {
    id: number;
    date: string | null;
    reason: string;
    batch_id: number | null;
    batch_number: string | null;
    warehouse: string | null;
    unit_cost: string | null;
    change: string | null;
    document: DocumentRef | null;
}

interface Recipe {
    id: number;
    version: number;
    name: string | null;
    output_quantity: string;
    lines: {
        product_id: number;
        product: string | null;
        sku: string | null;
        quantity: string;
        unit: string | null;
        wastage_percent: string | null;
    }[];
}

interface SupplierRow {
    id: number;
    name: string;
    url: string | null;
    orders: number;
    last_rate: string;
    last_date: string | null;
}

type Tab = 'card' | 'batches' | 'purchases' | 'sales' | 'costs' | 'lifetime' | 'runs' | 'used_in' | 'profit';

const LIFETIME_TABS: Tab[] = ['lifetime', 'runs', 'used_in', 'profit'];

/**
 * ⭐ The product page (PR5, 18 Oct 2026): Tally's stock item and its
 * vouchers. The header says where the stock is and what it cost; the stock
 * card lists every movement with the running quantity and value, each row
 * opening the document behind it; the other tabs are batches, purchases,
 * sales and how the cost moved. Purchases and sales show only to people who
 * may read them (null from the server).
 */
export default function ProductShow({
    product,
    summary,
    by_warehouse,
    last_purchase,
    selling,
    recipe,
    suppliers,
    card,
    warehouse,
    warehouses,
    batches,
    purchases,
    sales,
    costs,
    family = null,
    families = [],
    can,
    range,
    lifetime,
}: {
    product: ProductInfo;
    summary: { on_hand: string; available?: string; reserved?: string; average_cost: string | null; stock_value: string };
    by_warehouse: { warehouse_id: number; warehouse: string | null; quantity: string; value: string }[];
    last_purchase: { rate: string; date: string | null; supplier: (DocumentRef & { name: string }) | null; document: DocumentRef | null } | null;
    selling: { mrp: string | null; rules: { group: string | null; min_quantity: string; price: string | null; label: string | null }[] };
    recipe: Recipe | null;
    suppliers: SupplierRow[] | null;
    card: Card;
    warehouse: number | null;
    warehouses: { id: number; name: string }[];
    batches: BatchRow[];
    purchases: PurchaseRow[] | null;
    sales: SaleRow[] | null;
    costs: CostRow[];
    /** ⭐ Initial plan B22 (24 Oct 2026): the family this product is a size of. */
    family?: ProductFamilyInfo | null;
    /** For a single product: the families it may join (same kind only). */
    families?: JoinableFamily[];
    can: { manage: boolean; see_recipes: boolean };
    /** ⭐ B23 (25 Oct 2026): the lifetime window (all time by default) and what is in it. */
    range: LifetimeRange;
    lifetime: ProductLifetimeData;
}) {
    const { t, tr } = useTranslation();
    const labelOf = useDocumentLabel();
    // A window change reloads the page; it comes back on the lifetime tab.
    const [tab, setTab] = useState<Tab>(range.all_time ? 'card' : 'lifetime');
    const canGiveSamples = !!usePage<SharedData>().props.canManageSamples;
    const base = `/inventory/products/${product.id}`;
    const unit = product.unit ?? '';
    const reason = (value: string) => tr(`product.reason.${value}` as TranslationKey, value.replaceAll('_', ' '));

    const visitCard = (params: Record<string, string | number>) =>
        router.get(base, params, { preserveState: true, preserveScroll: true, only: ['card', 'warehouse'] });

    const tabs: { key: Tab; label: string; show: boolean }[] = [
        { key: 'card', label: t('product.tab_card'), show: true },
        { key: 'batches', label: t('product.tab_batches'), show: true },
        { key: 'purchases', label: t('product.tab_purchases'), show: purchases !== null },
        { key: 'sales', label: t('product.tab_sales'), show: sales !== null },
        { key: 'costs', label: t('product.tab_costs'), show: true },
        { key: 'lifetime', label: t('life.tab_lifetime'), show: true },
        { key: 'runs', label: t('life.tab_runs'), show: lifetime.runs.length > 0 },
        { key: 'used_in', label: t('life.tab_used_in'), show: lifetime.used_in.runs.length > 0 || lifetime.used_in.recipes.length > 0 },
        { key: 'profit', label: t('life.tab_profit'), show: true },
    ];

    const breadcrumbs = inventoryCrumbs('Catalogue', product.type === 'raw_material' ? 'Raw materials' : 'Products', {
        title: product.name,
        href: base,
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={product.name} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            {product.name}
                            {!product.is_active && <Pill tone="muted">Inactive</Pill>}
                            {/* ⭐ B4 (24 Oct 2026; R7, R17, R18). */}
                            {product.type === 'finished_good' && (
                                <Pill tone={product.made_here ? 'ok' : 'muted'}>{product.made_here ? t('ipc.made_here') : t('ipc.bought_in')}</Pill>
                            )}
                            {product.material_kind === 'packaging' && <Pill tone="info">{t('ipc.packaging')}</Pill>}
                            {product.can_be_sold === false && <Pill tone="muted">{t('ipc.not_for_sale')}</Pill>}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            <span className="font-mono text-xs">{product.sku}</span>
                            {product.category && ` · ${product.category}`}
                            {product.brand && ` · ${product.brand}`}
                            {product.barcode && <span className="font-mono text-xs"> · {product.barcode}</span>}
                            {product.default_supplier && (
                                <>
                                    {' · '}
                                    {t('ipc.supplier.label')}:{' '}
                                    <Link
                                        href={`/purchasing/suppliers/${product.default_supplier.id}`}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {product.default_supplier.name}
                                    </Link>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* ⭐ Samples (Joy, 25 Oct 2026): give this product free, in the Give sample popup. */}
                        {canGiveSamples && product.type !== 'service' && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/sales/samples?give=${product.id}`}>{t('smp.give_as_sample')}</Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/inventory/products?search=${encodeURIComponent(product.sku ?? product.name)}`}>
                                {t('product.edit_in_list')}
                            </Link>
                        </Button>
                    </div>
                </div>

                <ProductFamilyStrip product={product} family={family} families={families} canManage={can.manage} />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Stat
                        label={t('product.on_hand')}
                        value={`${formatQuantity(summary.on_hand)} ${unit}`}
                        hint={
                            summary.available === undefined
                                ? undefined
                                : `${t('rsv.available')}: ${formatQuantity(summary.available)}` +
                                  (Number(summary.reserved ?? 0) > 0
                                      ? ` (${t('rsv.line_reserved', { quantity: formatQuantity(summary.reserved) })})`
                                      : '')
                        }
                    />
                    <Stat label={t('product.average_cost')} value={summary.average_cost ? `Tk ${formatMoney(summary.average_cost)}` : '-'} />
                    <Stat label={t('product.stock_value')} value={`Tk ${formatMoney(summary.stock_value)}`} />
                    <Stat
                        label={t('product.last_purchase')}
                        value={last_purchase ? `Tk ${formatMoney(last_purchase.rate)}` : '-'}
                        hint={last_purchase ? `${showDate(last_purchase.date)} ${last_purchase.supplier?.name ?? ''}` : undefined}
                    />
                    <Stat label={t('product.selling_price')} value={selling.mrp ? `Tk ${formatMoney(selling.mrp)}` : '-'} hint="MRP" />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel title={t('product.by_warehouse')} flush>
                        <CompactTable minWidth="18rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('product.warehouse')}</th>
                                    <th className="text-right">{t('product.quantity')}</th>
                                    <th className="text-right">{t('product.value')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {by_warehouse.map((row) => (
                                    <tr key={row.warehouse_id}>
                                        <td>
                                            <button
                                                type="button"
                                                className="underline-offset-2 hover:underline"
                                                onClick={() => {
                                                    setTab('card');
                                                    visitCard({ warehouse: row.warehouse_id });
                                                }}
                                            >
                                                {row.warehouse}
                                            </button>
                                        </td>
                                        <td className="text-right tabular-nums">
                                            {formatQuantity(row.quantity)} {unit}
                                        </td>
                                        <td className="text-right tabular-nums">{formatMoney(row.value)}</td>
                                    </tr>
                                ))}
                                {by_warehouse.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="text-muted-foreground text-center">
                                            {t('product.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                    </Panel>

                    <Panel title={t('product.recipe')}>
                        {recipe ? (
                            <div className="mt-2 text-sm">
                                <p className="text-muted-foreground text-xs">
                                    v{recipe.version}
                                    {recipe.name && ` (${recipe.name})`} · {formatQuantity(recipe.output_quantity)} {unit}
                                </p>
                                <ul className="mt-2 space-y-1">
                                    {recipe.lines.map((line) => (
                                        <li key={line.product_id} className="flex justify-between gap-3">
                                            <Link href={`/inventory/products/${line.product_id}`} className="underline-offset-2 hover:underline">
                                                {line.product}
                                            </Link>
                                            <span className="tabular-nums">
                                                {formatQuantity(line.quantity)} {line.unit}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            <p className="text-muted-foreground mt-2 text-sm">{t('product.no_recipe')}</p>
                        )}
                        {selling.rules.length > 0 && (
                            <div className="mt-3 border-t pt-2 text-sm">
                                <p className="text-muted-foreground text-xs">{t('product.selling_price')}</p>
                                <ul className="mt-1 space-y-0.5">
                                    {selling.rules.map((rule, i) => (
                                        <li key={i} className="flex justify-between gap-3">
                                            <span>
                                                {rule.group ?? 'All'}
                                                {Number(rule.min_quantity) > 1 && ` (${formatQuantity(rule.min_quantity)}+)`}
                                                {rule.label && ` · ${rule.label}`}
                                            </span>
                                            <span className="tabular-nums">{rule.price ? formatMoney(rule.price) : '-'}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </Panel>

                    {suppliers !== null && (
                        <Panel title={t('product.suppliers')}>
                            {suppliers.length === 0 ? (
                                <p className="text-muted-foreground mt-2 text-sm">{t('product.no_suppliers')}</p>
                            ) : (
                                <ul className="mt-2 space-y-1 text-sm">
                                    {suppliers.map((s) => (
                                        <li key={s.id} className="flex justify-between gap-3">
                                            {s.url ? (
                                                <Link href={s.url} className="underline-offset-2 hover:underline">
                                                    {s.name}
                                                </Link>
                                            ) : (
                                                <span>{s.name}</span>
                                            )}
                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                Tk {formatMoney(s.last_rate)} · {showDate(s.last_date)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}
                </div>

                <div className="flex flex-wrap gap-1.5">
                    {tabs
                        .filter((entry) => entry.show)
                        .map((entry) => (
                            <Chip key={entry.key} active={tab === entry.key} onClick={() => setTab(entry.key)}>
                                {entry.label}
                            </Chip>
                        ))}
                </div>

                {LIFETIME_TABS.includes(tab) && (
                    <LifetimeRangeBar range={range} baseUrl={base} exportUrl={tab === 'profit' ? `${base}/lifetime-export` : null} />
                )}
                {tab === 'lifetime' && <ProductLifetimeTotals data={lifetime} unit={unit} />}
                {tab === 'runs' && <ProductRuns data={lifetime} />}
                {tab === 'used_in' && <ProductUsedIn data={lifetime} unit={unit} />}
                {tab === 'profit' && <ProductBatchProfit data={lifetime} />}

                {tab === 'card' && (
                    <Panel
                        title={t('product.tab_card')}
                        flush
                        aside={
                            <select
                                className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                value={warehouse ?? ''}
                                onChange={(event) => visitCard(event.target.value ? { warehouse: event.target.value } : {})}
                            >
                                <option value="">{t('product.all_warehouses')}</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                        }
                    >
                        <p className="text-muted-foreground px-4 pb-2 text-xs">{t('product.card_intro')}</p>
                        <CompactTable minWidth="64rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('docs.col_date')}</th>
                                    <th>{t('product.col_movement')}</th>
                                    <th>{t('docs.col_document')}</th>
                                    <th>{t('product.col_batch')}</th>
                                    <th className="text-right">{t('product.col_in')}</th>
                                    <th className="text-right">{t('product.col_out')}</th>
                                    <th className="text-right">{t('product.col_rate')}</th>
                                    <th className="text-right">{t('product.col_value')}</th>
                                    <th className="text-right">{t('product.col_balance')}</th>
                                    <th className="text-right">{t('product.value')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {card.opening && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground italic">
                                            {t('product.opening_row')}
                                        </td>
                                        <td className="text-right tabular-nums">{formatQuantity(card.opening.quantity)}</td>
                                        <td className="text-right tabular-nums">{formatMoney(card.opening.value)}</td>
                                    </tr>
                                )}
                                {card.rows.data.map((row) => (
                                    <tr key={row.id}>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                                        <td className="whitespace-nowrap">
                                            {reason(row.reason)}
                                            {row.warehouse && <span className="text-muted-foreground text-xs"> · {row.warehouse}</span>}
                                            {row.undoes && <span className="text-muted-foreground block text-xs">{t('product.undoes')}</span>}
                                        </td>
                                        <td>
                                            {row.document ? (
                                                <>
                                                    <span className="text-muted-foreground text-xs">{labelOf(row.document)} </span>
                                                    <DocumentLink doc={row.document} />
                                                    {row.document.party && (
                                                        <span className="text-muted-foreground block text-xs">{row.document.party}</span>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground text-xs">{row.note ?? '-'}</span>
                                            )}
                                        </td>
                                        <td className="font-mono text-xs">
                                            {row.batch_id ? (
                                                <Link href={`/inventory/batches/${row.batch_id}`} className="underline-offset-2 hover:underline">
                                                    {row.batch_number ?? 'No number'}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{row.in ? formatQuantity(row.in) : ''}</td>
                                        <td className="text-right tabular-nums">{row.out ? formatQuantity(row.out) : ''}</td>
                                        <td className="text-right tabular-nums">{row.rate ? formatMoney(row.rate) : ''}</td>
                                        <td className="text-right tabular-nums">{formatMoney(row.value)}</td>
                                        <td className="text-right font-medium tabular-nums">{formatQuantity(row.balance)}</td>
                                        <td className="text-right tabular-nums">{formatMoney(row.balance_value)}</td>
                                    </tr>
                                ))}
                                {card.rows.data.length === 0 && (
                                    <tr>
                                        <td colSpan={10} className="text-muted-foreground p-4 text-center">
                                            {t('product.no_movements')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                        <div className="grid gap-2 border-t px-4 py-2">
                            <span className="text-muted-foreground text-xs">
                                {t('product.col_balance')}: {formatQuantity(card.closing.quantity)} {unit} · Tk {formatMoney(card.closing.value)}
                            </span>
                            <Pagination
                                meta={card.rows}
                                onChange={(page, perPage) => visitCard({ ...(warehouse ? { warehouse } : {}), card_page: page, per_page: perPage })}
                            />
                        </div>
                    </Panel>
                )}

                {tab === 'batches' && (
                    <Panel title={t('product.tab_batches')} flush>
                        <CompactTable minWidth="52rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('product.col_batch')}</th>
                                    <th>{t('product.warehouse')}</th>
                                    <th>{t('product.col_received')}</th>
                                    <th>{t('product.col_expiry')}</th>
                                    <th className="text-right">{t('product.col_received')}</th>
                                    <th className="text-right">{t('product.on_hand')}</th>
                                    <th className="text-right">{t('product.col_cost')}</th>
                                    <th>{t('product.col_status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {batches.map((batch) => (
                                    <tr key={batch.id}>
                                        <td className="font-mono text-xs">
                                            <Link href={`/inventory/batches/${batch.id}`} className="underline-offset-2 hover:underline">
                                                {batch.batch_number ?? 'No number'}
                                            </Link>
                                        </td>
                                        <td>{batch.warehouse}</td>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(batch.received_at)}</td>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(batch.expiry_date)}</td>
                                        <td className="text-right tabular-nums">{formatQuantity(batch.received)}</td>
                                        <td className="text-right tabular-nums">{formatQuantity(batch.on_hand)}</td>
                                        <td className="text-right tabular-nums">{batch.unit_cost ? formatMoney(batch.unit_cost) : '-'}</td>
                                        <td>{batch.held ? <Pill tone="warning">On hold</Pill> : <Pill tone="muted">{batch.status}</Pill>}</td>
                                    </tr>
                                ))}
                                {batches.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-4 text-center">
                                            {t('product.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}

                {tab === 'purchases' && purchases !== null && (
                    <Panel title={t('product.tab_purchases')} flush>
                        <CompactTable minWidth="60rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('docs.col_date')}</th>
                                    <th>{t('docs.col_document')}</th>
                                    <th>{t('product.col_supplier')}</th>
                                    <th className="text-right">{t('product.quantity')}</th>
                                    <th className="text-right">{t('product.col_rate')}</th>
                                    <th className="text-right">{t('product.col_discount')}</th>
                                    <th className="text-right">{t('product.col_landed')}</th>
                                    <th className="text-right">{t('product.col_cost')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {purchases.map((row) => (
                                    <tr key={row.id}>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                                        <td>{row.document && <DocumentLink doc={row.document} />}</td>
                                        <td>
                                            {row.supplier_url ? (
                                                <Link href={row.supplier_url} className="underline-offset-2 hover:underline">
                                                    {row.supplier}
                                                </Link>
                                            ) : (
                                                row.supplier
                                            )}
                                        </td>
                                        <td className="text-right whitespace-nowrap tabular-nums">
                                            {formatQuantity(row.received)} / {formatQuantity(row.ordered)}
                                        </td>
                                        <td className="text-right tabular-nums">{formatMoney(row.rate)}</td>
                                        <td className="text-right tabular-nums">{row.discount ? formatMoney(row.discount) : ''}</td>
                                        <td className="text-right tabular-nums">{row.landed ? formatMoney(row.landed) : ''}</td>
                                        <td className="text-right font-medium tabular-nums">
                                            {row.landed_unit_cost ? formatMoney(row.landed_unit_cost) : '-'}
                                        </td>
                                    </tr>
                                ))}
                                {purchases.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-4 text-center">
                                            {t('product.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}

                {tab === 'sales' && sales !== null && (
                    <Panel title={t('product.tab_sales')} flush>
                        <CompactTable minWidth="48rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('docs.col_date')}</th>
                                    <th>{t('docs.col_document')}</th>
                                    <th>{t('product.col_customer')}</th>
                                    <th className="text-right">{t('product.quantity')}</th>
                                    <th className="text-right">{t('product.col_price')}</th>
                                    <th className="text-right">{t('docs.col_amount')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {sales.map((row) => (
                                    <tr key={row.id}>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                                        <td>
                                            <DocumentLink doc={row.document} />
                                        </td>
                                        <td>
                                            {row.customer_url ? (
                                                <Link href={row.customer_url} className="underline-offset-2 hover:underline">
                                                    {row.customer}
                                                </Link>
                                            ) : (
                                                row.customer
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                        <td className="text-right tabular-nums">{formatMoney(row.price)}</td>
                                        <td className="text-right tabular-nums">{row.total ? formatMoney(row.total) : ''}</td>
                                    </tr>
                                ))}
                                {sales.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                            {t('product.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}

                {tab === 'costs' && (
                    <Panel title={t('product.tab_costs')} flush>
                        <CompactTable minWidth="52rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('docs.col_date')}</th>
                                    <th>{t('product.col_source')}</th>
                                    <th>{t('docs.col_document')}</th>
                                    <th>{t('product.col_batch')}</th>
                                    <th className="text-right">{t('product.col_cost')}</th>
                                    <th className="text-right">{t('product.col_value')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {costs.map((row) => (
                                    <tr key={row.id}>
                                        <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                                        <td>{reason(row.reason)}</td>
                                        <td>{row.document ? <DocumentLink doc={row.document} /> : '-'}</td>
                                        <td className="font-mono text-xs">
                                            {row.batch_id ? (
                                                <Link href={`/inventory/batches/${row.batch_id}`} className="underline-offset-2 hover:underline">
                                                    {row.batch_number ?? 'No number'}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="text-right tabular-nums">{row.unit_cost ? formatMoney(row.unit_cost) : ''}</td>
                                        <td className="text-right tabular-nums">{row.change ? formatMoney(row.change) : ''}</td>
                                    </tr>
                                ))}
                                {costs.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                            {t('product.none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </CompactTable>
                    </Panel>
                )}
            </div>
        </AppLayout>
    );
}
