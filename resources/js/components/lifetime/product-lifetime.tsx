import { DocumentLink, type DocumentRef } from '@/components/documents/document-link';
import { CompactTable, Panel, Stat } from '@/components/inventory/inventory-ui';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export interface ProductLifetimeData {
    totals: { key: string; reason: string; direction: string; value_only: boolean; quantity: string; value: string }[];
    write_offs: { reason: string; quantity: string; value: string }[];
    money: {
        invoiced_quantity: string;
        revenue: string;
        returned_quantity: string;
        returns_credit: string;
        cost_of_sales: string;
        returns_cost: string;
        net_revenue: string;
        net_cost: string;
        margin: string;
        margin_percent: string | null;
    } | null;
    by_supplier:
        | {
              id: number;
              name: string;
              url: string | null;
              receipts: number;
              quantity: string;
              value: string;
              average_rate: string | null;
              last_date: string | null;
          }[]
        | null;
    runs: {
        id: number;
        document: DocumentRef | null;
        date: string | null;
        status: string;
        quantity: string;
        value: string | null;
        unit_cost: string | null;
        batch_id: number | null;
        batch_number: string | null;
    }[];
    used_in: {
        runs: {
            id: number;
            document: DocumentRef | null;
            date: string | null;
            status: string | null;
            output_product_id: number | null;
            output_product: string | null;
            quantity: string;
            value: string | null;
        }[];
        recipes: {
            id: number;
            recipe_id: number;
            name: string | null;
            version: number | null;
            output_product_id: number | null;
            output_product: string | null;
            quantity_per_output: string;
        }[];
    };
    batches: {
        id: number;
        batch_number: string | null;
        received_at: string | null;
        warehouse: string | null;
        source: string | null;
        document: DocumentRef | null;
        supplier: { name: string; url: string | null } | null;
        received: string;
        unit_cost: string | null;
        cost: string;
        on_hand: string;
        sold: string;
        cost_of_sold: string;
        revenue: string | null;
        margin: string | null;
    }[];
    sees_margin: boolean;
}

function Empty({ colSpan }: { colSpan: number }) {
    const { t } = useTranslation();

    return (
        <tr>
            <td colSpan={colSpan} className="text-muted-foreground p-4 text-center">
                {t('life.none')}
            </td>
        </tr>
    );
}

function Signed({ value }: { value: string | null }) {
    if (value === null) {
        return <>-</>;
    }

    return <span className={value.startsWith('-') ? 'text-red-600 dark:text-red-400' : ''}>{formatMoney(value)}</span>;
}

/** ⭐ B23 (25 Oct 2026; R76): every movement added up by kind, the money, by supplier and write-offs. */
export function ProductLifetimeTotals({ data, unit }: { data: ProductLifetimeData; unit: string }) {
    const { t, tr } = useTranslation();

    return (
        <div className="flex flex-col gap-4">
            {data.money ? (
                <Panel title={t('life.money_title')}>
                    <div className="mt-2 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        <Stat label={t('life.revenue')} value={`Tk ${formatMoney(data.money.revenue)}`} size="sm" />
                        <Stat label={t('life.returns_credit')} value={`Tk ${formatMoney(data.money.returns_credit)}`} size="sm" />
                        <Stat label={t('life.cost_of_sales')} value={`Tk ${formatMoney(data.money.cost_of_sales)}`} size="sm" />
                        <Stat label={t('life.returns_cost')} value={`Tk ${formatMoney(data.money.returns_cost)}`} size="sm" />
                        <Stat
                            label={t('life.margin')}
                            value={`Tk ${formatMoney(data.money.margin)}`}
                            tone={data.money.margin.startsWith('-') ? 'danger' : 'ok'}
                            hint={t('life.margin_note')}
                            size="sm"
                        />
                        <Stat label="%" value={data.money.margin_percent ? `${data.money.margin_percent}%` : '-'} size="sm" />
                    </div>
                </Panel>
            ) : (
                <p className="text-muted-foreground text-xs">{t('life.no_margin')}</p>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel title={t('life.tab_lifetime')} flush>
                    <p className="text-muted-foreground px-4 pb-2 text-xs">{t('life.totals_intro')}</p>
                    <CompactTable minWidth="26rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>{t('life.col_what')}</th>
                                <th className="text-right">{t('life.col_quantity')}</th>
                                <th className="text-right">{t('life.col_value')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.totals.map((row) => (
                                <tr key={row.key}>
                                    <td>{tr(`life.k.${row.key}`, row.key.replaceAll('_', ' '))}</td>
                                    <td className="text-right whitespace-nowrap tabular-nums">
                                        {row.value_only ? '' : `${formatQuantity(row.quantity)} ${unit}`}
                                    </td>
                                    <td className="text-right tabular-nums">{formatMoney(row.value)}</td>
                                </tr>
                            ))}
                            {data.totals.length === 0 && <Empty colSpan={3} />}
                        </tbody>
                    </CompactTable>
                </Panel>

                <div className="flex flex-col gap-4">
                    {data.by_supplier !== null && (
                        <Panel title={t('life.by_supplier')} flush>
                            <CompactTable minWidth="30rem">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th>{t('life.col_supplier')}</th>
                                        <th className="text-right">{t('life.col_receipts')}</th>
                                        <th className="text-right">{t('life.col_quantity')}</th>
                                        <th className="text-right">{t('life.col_value')}</th>
                                        <th className="text-right">{t('life.col_avg_rate')}</th>
                                        <th>{t('life.col_last')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.by_supplier.map((row) => (
                                        <tr key={row.id}>
                                            <td>
                                                {row.url ? (
                                                    <Link href={row.url} className="underline-offset-2 hover:underline">
                                                        {row.name}
                                                    </Link>
                                                ) : (
                                                    row.name
                                                )}
                                            </td>
                                            <td className="text-right tabular-nums">{row.receipts}</td>
                                            <td className="text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                            <td className="text-right tabular-nums">{formatMoney(row.value)}</td>
                                            <td className="text-right tabular-nums">{row.average_rate ? formatMoney(row.average_rate) : '-'}</td>
                                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.last_date)}</td>
                                        </tr>
                                    ))}
                                    {data.by_supplier.length === 0 && <Empty colSpan={6} />}
                                </tbody>
                            </CompactTable>
                        </Panel>
                    )}

                    <Panel title={t('life.write_offs')} flush>
                        <CompactTable minWidth="22rem">
                            <thead>
                                <tr className="text-muted-foreground">
                                    <th>{t('life.col_reason')}</th>
                                    <th className="text-right">{t('life.col_quantity')}</th>
                                    <th className="text-right">{t('life.col_value')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.write_offs.map((row) => (
                                    <tr key={row.reason}>
                                        <td>{row.reason}</td>
                                        <td className="text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                        <td className="text-right tabular-nums">{formatMoney(row.value)}</td>
                                    </tr>
                                ))}
                                {data.write_offs.length === 0 && <Empty colSpan={3} />}
                            </tbody>
                        </CompactTable>
                    </Panel>
                </div>
            </div>
        </div>
    );
}

/** The production runs that made this product. */
export function ProductRuns({ data }: { data: ProductLifetimeData }) {
    const { t, tr } = useTranslation();

    return (
        <Panel title={t('life.tab_runs')} flush>
            <p className="text-muted-foreground px-4 pb-2 text-xs">{t('life.runs_intro')}</p>
            <CompactTable minWidth="44rem">
                <thead>
                    <tr className="text-muted-foreground">
                        <th>{t('life.col_date')}</th>
                        <th>{t('life.col_run')}</th>
                        <th>{t('life.col_status')}</th>
                        <th className="text-right">{t('life.col_quantity')}</th>
                        <th className="text-right">{t('life.col_value')}</th>
                        <th className="text-right">{t('life.col_unit_cost')}</th>
                        <th>{t('life.col_batch')}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.runs.map((row) => (
                        <tr key={row.id}>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                            <td>{row.document ? <DocumentLink doc={row.document} /> : '-'}</td>
                            <td>{tr(`production.status.${row.status}`, row.status)}</td>
                            <td className="text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                            <td className="text-right tabular-nums">{row.value ? formatMoney(row.value) : '-'}</td>
                            <td className="text-right tabular-nums">{row.unit_cost ? formatMoney(row.unit_cost) : '-'}</td>
                            <td className="font-mono text-xs">
                                {row.batch_id ? (
                                    <Link href={`/inventory/batches/${row.batch_id}`} className="underline-offset-2 hover:underline">
                                        {row.batch_number ?? `#${row.batch_id}`}
                                    </Link>
                                ) : (
                                    '-'
                                )}
                            </td>
                        </tr>
                    ))}
                    {data.runs.length === 0 && <Empty colSpan={7} />}
                </tbody>
            </CompactTable>
        </Panel>
    );
}

/** Where this product goes in: the runs that consumed it and the recipes that call for it. */
export function ProductUsedIn({ data, unit }: { data: ProductLifetimeData; unit: string }) {
    const { t, tr } = useTranslation();

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Panel title={t('life.used_runs')} flush>
                <CompactTable minWidth="36rem">
                    <thead>
                        <tr className="text-muted-foreground">
                            <th>{t('life.col_date')}</th>
                            <th>{t('life.col_run')}</th>
                            <th>{t('life.col_makes')}</th>
                            <th className="text-right">{t('life.col_quantity')}</th>
                            <th className="text-right">{t('life.col_value')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.used_in.runs.map((row) => (
                            <tr key={row.id}>
                                <td className="text-muted-foreground whitespace-nowrap">{showDate(row.date)}</td>
                                <td>
                                    {row.document ? <DocumentLink doc={row.document} /> : '-'}
                                    {row.status && row.status !== 'confirmed' && (
                                        <span className="text-muted-foreground block text-xs">
                                            {tr(`production.status.${row.status}`, row.status)}
                                        </span>
                                    )}
                                </td>
                                <td>
                                    {row.output_product_id ? (
                                        <Link href={`/inventory/products/${row.output_product_id}`} className="underline-offset-2 hover:underline">
                                            {row.output_product}
                                        </Link>
                                    ) : (
                                        row.output_product
                                    )}
                                </td>
                                <td className="text-right whitespace-nowrap tabular-nums">
                                    {formatQuantity(row.quantity)} {unit}
                                </td>
                                <td className="text-right tabular-nums">{row.value ? formatMoney(row.value) : '-'}</td>
                            </tr>
                        ))}
                        {data.used_in.runs.length === 0 && <Empty colSpan={5} />}
                    </tbody>
                </CompactTable>
            </Panel>
            <Panel title={t('life.used_recipes')} flush>
                <CompactTable minWidth="26rem">
                    <thead>
                        <tr className="text-muted-foreground">
                            <th>{t('life.col_makes')}</th>
                            <th>{t('life.col_recipe')}</th>
                            <th className="text-right">{t('life.col_per_output')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.used_in.recipes.map((row) => (
                            <tr key={row.id}>
                                <td>
                                    {row.output_product_id ? (
                                        <Link href={`/inventory/products/${row.output_product_id}`} className="underline-offset-2 hover:underline">
                                            {row.output_product}
                                        </Link>
                                    ) : (
                                        row.output_product
                                    )}
                                </td>
                                <td className="text-muted-foreground text-xs">
                                    v{row.version}
                                    {row.name && ` (${row.name})`}
                                </td>
                                <td className="text-right whitespace-nowrap tabular-nums">
                                    {formatQuantity(row.quantity_per_output)} {unit}
                                </td>
                            </tr>
                        ))}
                        {data.used_in.recipes.length === 0 && <Empty colSpan={3} />}
                    </tbody>
                </CompactTable>
            </Panel>
        </div>
    );
}

/** ⭐ R13: profit or loss per batch. */
export function ProductBatchProfit({ data }: { data: ProductLifetimeData }) {
    const { t, tr } = useTranslation();

    return (
        <Panel title={t('life.tab_profit')} flush>
            <p className="text-muted-foreground px-4 pb-2 text-xs">{t('life.profit_intro')}</p>
            {!data.sees_margin && <p className="text-muted-foreground px-4 pb-2 text-xs">{t('life.no_margin')}</p>}
            <CompactTable minWidth="70rem">
                <thead>
                    <tr className="text-muted-foreground">
                        <th>{t('life.col_batch')}</th>
                        <th>{t('life.col_received')}</th>
                        <th>{t('life.col_source')}</th>
                        <th className="text-right">{t('life.col_quantity')}</th>
                        <th className="text-right">{t('life.col_unit_cost')}</th>
                        <th className="text-right">{t('life.col_cost')}</th>
                        <th className="text-right">{t('life.col_sold')}</th>
                        <th className="text-right">{t('life.col_cost_sold')}</th>
                        {data.sees_margin && <th className="text-right">{t('life.col_revenue')}</th>}
                        {data.sees_margin && <th className="text-right">{t('life.margin')}</th>}
                        <th className="text-right">{t('life.col_on_hand')}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.batches.map((row) => (
                        <tr key={row.id}>
                            <td className="font-mono text-xs">
                                <Link href={`/inventory/batches/${row.id}`} className="underline-offset-2 hover:underline">
                                    {row.batch_number ?? `#${row.id}`}
                                </Link>
                                {row.warehouse && <span className="text-muted-foreground block font-sans">{row.warehouse}</span>}
                            </td>
                            <td className="text-muted-foreground whitespace-nowrap">{showDate(row.received_at)}</td>
                            <td>
                                {row.source ? tr(`life.source.${row.source}`, row.source) : '-'}
                                {row.document && (
                                    <span className="block text-xs">
                                        <DocumentLink doc={row.document} />
                                    </span>
                                )}
                                {row.supplier &&
                                    (row.supplier.url ? (
                                        <Link
                                            href={row.supplier.url}
                                            className="text-muted-foreground block text-xs underline-offset-2 hover:underline"
                                        >
                                            {row.supplier.name}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground block text-xs">{row.supplier.name}</span>
                                    ))}
                            </td>
                            <td className="text-right tabular-nums">{formatQuantity(row.received)}</td>
                            <td className="text-right tabular-nums">{row.unit_cost ? formatMoney(row.unit_cost) : '-'}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.cost)}</td>
                            <td className="text-right tabular-nums">{formatQuantity(row.sold)}</td>
                            <td className="text-right tabular-nums">{formatMoney(row.cost_of_sold)}</td>
                            {data.sees_margin && <td className="text-right tabular-nums">{row.revenue ? formatMoney(row.revenue) : '-'}</td>}
                            {data.sees_margin && (
                                <td className="text-right font-medium tabular-nums">
                                    <Signed value={row.margin} />
                                </td>
                            )}
                            <td className="text-right tabular-nums">{formatQuantity(row.on_hand)}</td>
                        </tr>
                    ))}
                    {data.batches.length === 0 && <Empty colSpan={data.sees_margin ? 11 : 9} />}
                </tbody>
            </CompactTable>
        </Panel>
    );
}
