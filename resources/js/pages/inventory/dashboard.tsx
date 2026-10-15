import { Chip, CompactTable, Hint, Panel, Pill, ShareRow, type Tone } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, ChevronRight, PackageMinus } from 'lucide-react';
import { type ReactNode } from 'react';

interface LowStockProduct {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
    current_quantity: number;
    low_stock_threshold: number;
}

interface ExpiringBatch {
    id: number;
    product: string | null;
    sku: string | null;
    batch_number: string | null;
    warehouse: string | null;
    quantity: number;
    expired: boolean;
    days: number;
}

interface Movement {
    id: number;
    occurred_at: string;
    product: string | null;
    sku: string | null;
    unit: string | null;
    warehouse: string | null;
    direction: string;
    reason: string;
    quantity: number;
    value: number;
}

interface Run {
    id: number;
    run_number: string;
    status: string;
    output_product: string | null;
    output_quantity: number;
    consumed_value: number | null;
    waste_value: number | null;
    yield_flagged: boolean;
    occurred_at: string | null;
}

interface Props {
    today: string;
    total_skus: number;
    in_stock_products: number;
    stocked_products: number;
    total_stock_value: string;
    low_stock_count: number;
    low_stock_products: LowStockProduct[];
    categories: { name: string; value: number; share: number }[];
    warehouses: { id: number; name: string; value: number; share: number; products: number }[];
    warehouse_id: number | null;
    trend: { label: string; in: number; out: number }[];
    suppliers: { name: string; value: number; receipts: number }[];
    recent_movements: Movement[];
    expiry: { expired: number; expiring: number };
    expiring_soon: ExpiringBatch[];
    open_items: {
        stock_requests_waiting: number;
        transfers_in_transit: number;
        counts_in_progress: number;
        draft_production_runs: number;
        expired_batches_on_shelf: number;
    };
    last_runs: Run[] | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });
const longDate = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

/** A headline tile: the number, its label with an (i), a quiet line under, the list behind it. */
function Tile({
    label,
    hint,
    value,
    note,
    tone,
    href,
}: {
    label: string;
    hint: string;
    value: ReactNode;
    note?: ReactNode;
    tone?: Tone;
    href: string;
}) {
    // The (i) is a button of its own, so the link is the number, not the card:
    // a button inside a link is not valid markup and the hint would navigate.
    return (
        <div className="bg-card text-card-foreground rounded-xl border p-4">
            <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                {label}
                <Hint text={hint} />
            </div>
            <Link href={href} className="block underline-offset-4 hover:underline">
                <div
                    className={cn(
                        'text-3xl font-semibold tracking-tight tabular-nums',
                        tone === 'warning' && 'text-amber-700 dark:text-amber-400',
                        tone === 'danger' && 'text-destructive',
                    )}
                >
                    {value}
                </div>
            </Link>
            {note && <div className="text-muted-foreground mt-1 text-xs">{note}</div>}
        </div>
    );
}

/** One line of "Needs you": a count in a soft square, what it is, where to act. */
function OpenItem({ count, tone, title, detail, href }: { count: number; tone: Tone; title: string; detail: string; href: string }) {
    const soft: Record<Tone, string> = {
        danger: 'bg-destructive/10 text-destructive',
        warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
        info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
        muted: 'bg-muted text-muted-foreground',
        ok: 'bg-primary/10 text-primary',
    };

    return (
        <Link href={href} className="hover:bg-muted/40 flex items-center gap-3 border-t px-4 py-2.5 transition-colors">
            <span
                className={cn(
                    'grid size-8 shrink-0 place-items-center rounded-lg text-[13px] font-semibold tabular-nums',
                    count > 0 ? soft[tone] : soft.muted,
                )}
            >
                {count}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block text-[13.5px] font-medium">{title}</span>
                <span className="text-muted-foreground block text-xs">{detail}</span>
            </span>
            <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
        </Link>
    );
}

/**
 * The Inventory overview (redesign, 15 Oct 2026): the four numbers the
 * controller computes, the open documents that need somebody, and the
 * panels of the old dashboard with their explanations moved into (i) hints.
 * Every number opens the list behind it; the warehouse chips narrow the page.
 *
 * Hand-rolled CSS bars like every other chart in this project; a panel with
 * nothing to show says why in one quiet line rather than drawing invented
 * numbers.
 */
export default function InventoryDashboard({
    today,
    total_skus,
    in_stock_products,
    stocked_products,
    total_stock_value,
    low_stock_count,
    low_stock_products,
    categories,
    warehouses,
    warehouse_id,
    trend,
    suppliers,
    recent_movements,
    expiry,
    expiring_soon,
    open_items,
    last_runs,
}: Props) {
    const abilities = useInventoryAbilities();
    const { canViewOperatingReports, canViewAccounting } = usePage<SharedData>().props;
    const trendMax = Math.max(...trend.map((point) => Math.max(point.in, point.out)), 0);
    const thisMonth = trend[trend.length - 1];
    const picked = warehouses.find((warehouse) => warehouse.id === warehouse_id) ?? null;
    const canMove = abilities.adjustStock || abilities.requestStock;
    const stockValueHref = canViewAccounting || canViewOperatingReports ? '/accounting/inventory-valuation' : '/inventory/stock';
    const openCount = Object.values(open_items).reduce((sum, count) => sum + count, 0);

    const pickWarehouse = (id: number | null) =>
        router.get('/inventory', id === null ? {} : { warehouse_id: id }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <p className="text-muted-foreground text-sm">
                        {longDate(today)} · {warehouses.length} warehouse{warehouses.length === 1 ? '' : 's'} · {total_skus} active product
                        {total_skus === 1 ? '' : 's'}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {warehouses.length > 1 && (
                            <>
                                <Chip active={warehouse_id === null} onClick={() => pickWarehouse(null)}>
                                    All warehouses
                                </Chip>
                                {warehouses.map((warehouse) => (
                                    <Chip key={warehouse.id} active={warehouse.id === warehouse_id} onClick={() => pickWarehouse(warehouse.id)}>
                                        {warehouse.name}
                                    </Chip>
                                ))}
                            </>
                        )}
                        {canMove && (
                            <>
                                <Button asChild variant="outline">
                                    <Link href="/inventory/stock">
                                        <PackageMinus className="size-4" />
                                        Record damage or loss
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href="/inventory/stock">
                                        <ArrowLeftRight className="size-4" />
                                        Move stock
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Tile
                        label="Stock value, taka at cost"
                        hint="Sum of every active batch: quantity times its unit cost. Opens Inventory valuation."
                        value={total_stock_value}
                        note={
                            picked
                                ? `${picked.share}% of all stock value`
                                : warehouses.length > 1
                                  ? warehouses.map((w) => `${w.name} ${w.share}%`).join(', ')
                                  : undefined
                        }
                        href={stockValueHref}
                    />
                    {/* No "total units" tile on purpose: packs, litres and kilograms do
                        not sum. Products with stock is the honest quantity-flavoured fact. */}
                    <Tile
                        label="Products in stock"
                        hint="How many stocked products hold any quantity right now. Units are not summed: packs, litres and kilograms do not add up."
                        value={
                            <>
                                {in_stock_products} <span className="text-muted-foreground text-base font-medium">of {stocked_products}</span>
                            </>
                        }
                        note={
                            stocked_products - in_stock_products > 0
                                ? `${stocked_products - in_stock_products} hold nothing today`
                                : 'Every stocked product holds something'
                        }
                        href="/inventory/stock"
                    />
                    <Tile
                        label="Low stock"
                        hint="Products at or below their reorder level, counted across all warehouses. Set the level on the product (More: reorder when stock falls below)."
                        value={low_stock_count}
                        tone={low_stock_count > 0 ? 'warning' : 'ok'}
                        note={low_stock_count === 0 ? 'Nothing is at or below its level' : 'Opens the Low stock list'}
                        href="/inventory/low-stocks"
                    />
                    <Tile
                        label="Expired / expiring"
                        hint="Batches past their date, and batches inside their product's warning window (90 days unless the product sets its own)."
                        value={
                            <>
                                <span className={expiry.expired > 0 ? 'text-destructive' : ''}>{expiry.expired}</span>
                                <span className="text-muted-foreground font-normal"> / </span>
                                {expiry.expiring}
                            </>
                        }
                        note={
                            expiring_soon.length > 0
                                ? `Nearest: ${expiring_soon[0].product ?? '-'}${expiring_soon[0].batch_number ? `, batch ${expiring_soon[0].batch_number}` : ''}, ${expiring_soon[0].expired ? `expired ${expiring_soon[0].days} d ago` : `${expiring_soon[0].days} days`}`
                                : 'No dated batch is near its date'
                        }
                        href="/inventory/expiry"
                    />
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    <Panel
                        title="Needs you"
                        aside={
                            <span className="text-muted-foreground text-xs">
                                {openCount} open item{openCount === 1 ? '' : 's'}
                            </span>
                        }
                        flush
                    >
                        {abilities.approveStock && (
                            <OpenItem
                                count={open_items.stock_requests_waiting}
                                tone="warning"
                                title="Stock requests waiting for approval"
                                detail="Moves staff asked for; stock does not change until someone approves"
                                href="/inventory/stock-requests?status=pending"
                            />
                        )}
                        <OpenItem
                            count={open_items.transfers_in_transit}
                            tone="info"
                            title="Transfers in transit"
                            detail="Not counted anywhere until marked received"
                            href="/inventory/stock/transfers?status=in_transit"
                        />
                        <OpenItem
                            count={open_items.counts_in_progress}
                            tone="ok"
                            title={open_items.counts_in_progress === 1 ? 'Stock count in progress' : 'Stock counts in progress'}
                            detail="Started and not yet completed; variances post when the count completes"
                            href="/inventory/stock-counts"
                        />
                        {abilities.manageProduction && (
                            <OpenItem
                                count={open_items.draft_production_runs}
                                tone="muted"
                                title={open_items.draft_production_runs === 1 ? 'Production run still a draft' : 'Production runs still drafts'}
                                detail="A draft consumes nothing until confirmed"
                                href="/inventory/production-runs?status=draft"
                            />
                        )}
                        <OpenItem
                            count={open_items.expired_batches_on_shelf}
                            tone="danger"
                            title="Expired batches still on the shelf"
                            detail="Record them as expired so the book stops counting them"
                            href="/inventory/expiry"
                        />
                    </Panel>

                    <Panel
                        title="Stock value by category"
                        aside={<span className="text-muted-foreground text-xs">share of {total_stock_value}</span>}
                    >
                        {categories.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">No stock is held yet, so there is nothing to distribute.</p>
                        ) : (
                            <div className="mt-3 flex flex-col gap-3">
                                {categories.map((category) => (
                                    <ShareRow
                                        key={category.name}
                                        label={category.name}
                                        share={category.share}
                                        aside={<span className="mr-1.5">{category.share}% ·</span>}
                                        value={money(category.value)}
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        title={`Stock movement, last ${trend.length} months`}
                        aside={
                            <div className="text-muted-foreground flex items-center gap-3 text-xs">
                                <span className="flex items-center gap-1.5">
                                    <span className="bg-chart-2 size-2 rounded-sm" /> Value in
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="bg-chart-4 size-2 rounded-sm" /> Value out
                                </span>
                            </div>
                        }
                    >
                        {trendMax <= 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">
                                No stock has moved in this window. The chart fills in as receipts, production and sales flow.
                            </p>
                        ) : (
                            <div>
                                {thisMonth && (
                                    <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1">
                                        <span>
                                            <span className="text-xl font-semibold tabular-nums">{money(thisMonth.in)}</span>{' '}
                                            <span className="text-muted-foreground text-xs">in, {thisMonth.label} so far</span>
                                        </span>
                                        <span>
                                            <span className="text-xl font-semibold tabular-nums">{money(thisMonth.out)}</span>{' '}
                                            <span className="text-muted-foreground text-xs">out</span>
                                        </span>
                                    </div>
                                )}
                                <div className="mt-3 flex h-36 items-end gap-2 border-b pb-1">
                                    {trend.map((point) => (
                                        <div
                                            key={point.label}
                                            className="flex h-full flex-1 items-end justify-center gap-1"
                                            title={`${point.label}: in ${money(point.in)}, out ${money(point.out)}`}
                                        >
                                            <div
                                                className="bg-chart-2 w-full max-w-4 rounded-t-sm"
                                                style={{ height: `${Math.max((point.in / trendMax) * 100, 1)}%` }}
                                            />
                                            <div
                                                className="bg-chart-4 w-full max-w-4 rounded-t-sm"
                                                style={{ height: `${Math.max((point.out / trendMax) * 100, 1)}%` }}
                                            />
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-1.5 flex gap-2">
                                    {trend.map((point) => (
                                        <div key={point.label} className="text-muted-foreground flex-1 text-center text-[11px]">
                                            {point.label}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </Panel>

                    <Panel
                        title={
                            <span className="flex items-center gap-1.5">
                                Warehouses
                                <Hint text="Each active warehouse's share of stock value. Not capacity: no warehouse has declared one." />
                            </span>
                        }
                        aside={
                            abilities.manageCatalog ? (
                                <Link href="/inventory/warehouses" className="text-primary text-xs hover:underline">
                                    Manage
                                </Link>
                            ) : undefined
                        }
                    >
                        {/* Share of stock value, NOT capacity: no warehouse here has ever
                            declared a capacity, so a ring would be a made-up percentage. */}
                        {warehouses.length === 0 ? (
                            <p className="text-muted-foreground mt-2 text-sm">No active warehouses.</p>
                        ) : (
                            <div className="mt-3 flex flex-col gap-3">
                                {warehouses.map((warehouse) => (
                                    <ShareRow
                                        key={warehouse.id}
                                        label={warehouse.name}
                                        share={warehouse.share}
                                        aside={
                                            <span className="mr-1.5">
                                                {warehouse.products} product{warehouse.products === 1 ? '' : 's'} ·
                                            </span>
                                        }
                                        value={money(warehouse.value)}
                                    />
                                ))}
                            </div>
                        )}
                        <div className="mt-4 border-t pt-3">
                            <p className="text-muted-foreground mb-1.5 text-xs">Top suppliers by goods received</p>
                            {suppliers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No purchases yet. This fills in once goods receipts start landing.</p>
                            ) : (
                                <table className="w-full text-[13px]">
                                    <tbody>
                                        {suppliers.map((supplier) => (
                                            <tr key={supplier.name} className="[&_td]:py-1">
                                                <td>{supplier.name}</td>
                                                <td className="text-muted-foreground text-xs">
                                                    {supplier.receipts} receipt{supplier.receipts === 1 ? '' : 's'}
                                                </td>
                                                <td className="text-right tabular-nums">{money(supplier.value)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                    </Panel>

                    <Panel
                        title="Below reorder level"
                        flush
                        aside={
                            <Link href="/inventory/low-stocks" className="text-primary text-xs hover:underline">
                                All {low_stock_count}
                            </Link>
                        }
                    >
                        {low_stock_products.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing is at or below its reorder level.</p>
                        ) : (
                            <CompactTable minWidth="24rem">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th>Product</th>
                                        <th className="text-right">On hand</th>
                                        <th className="text-right">Reorder at</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {low_stock_products.map((product) => (
                                        <tr key={product.id}>
                                            <td>
                                                {product.name} <span className="text-muted-foreground text-xs">{product.sku}</span>
                                            </td>
                                            <td className="text-right font-semibold text-amber-700 tabular-nums dark:text-amber-400">
                                                {qty(product.current_quantity)} {product.unit ?? ''}
                                            </td>
                                            <td className="text-muted-foreground text-right tabular-nums">{qty(product.low_stock_threshold)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Panel>

                    <Panel
                        title="Expiring soon"
                        flush
                        aside={
                            <Link href="/inventory/expiry" className="text-primary text-xs hover:underline">
                                All {expiry.expired + expiry.expiring}
                            </Link>
                        }
                    >
                        {expiring_soon.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">No dated batch is expired or inside its warning window.</p>
                        ) : (
                            <CompactTable minWidth="28rem">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th>Product · batch</th>
                                        <th>Warehouse</th>
                                        <th className="text-right">Held</th>
                                        <th className="text-right">Days left</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {expiring_soon.map((batch) => (
                                        <tr key={batch.id}>
                                            <td>
                                                {batch.product}{' '}
                                                <span className="text-muted-foreground font-mono text-xs">{batch.batch_number ?? ''}</span>
                                            </td>
                                            <td className="text-muted-foreground">{batch.warehouse}</td>
                                            <td className="text-right tabular-nums">{qty(batch.quantity)}</td>
                                            <td className="text-right">
                                                {batch.expired ? (
                                                    <Pill tone="danger">expired {batch.days} d</Pill>
                                                ) : (
                                                    <Pill tone={batch.days <= 7 ? 'danger' : 'warning'}>{batch.days} d</Pill>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Panel>

                    <Panel
                        title="Recent stock movements"
                        flush
                        className="lg:col-span-2"
                        aside={
                            <Link href="/inventory/stock" className="text-primary text-xs hover:underline">
                                Stock book
                            </Link>
                        }
                    >
                        {recent_movements.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">
                                Nothing has moved yet. The first receipt or sale starts this list.
                            </p>
                        ) : (
                            <CompactTable minWidth="44rem">
                                <thead>
                                    <tr className="text-muted-foreground">
                                        <th>When</th>
                                        <th>Product</th>
                                        <th>Warehouse</th>
                                        <th>What</th>
                                        <th className="text-right">Quantity</th>
                                        <th className="text-right">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recent_movements.map((movement) => (
                                        <tr key={movement.id}>
                                            <td className="text-muted-foreground whitespace-nowrap">{movement.occurred_at}</td>
                                            <td className="max-w-56 truncate">{movement.product}</td>
                                            <td className="text-muted-foreground">{movement.warehouse}</td>
                                            <td>
                                                <Pill tone={movement.direction === 'in' ? 'ok' : 'muted'}>{movement.reason}</Pill>
                                            </td>
                                            <td
                                                className={cn(
                                                    'text-right tabular-nums',
                                                    movement.direction === 'in' ? 'text-primary' : 'text-destructive',
                                                )}
                                            >
                                                {movement.direction === 'in' ? '+' : '-'}
                                                {qty(movement.quantity)} {movement.unit ?? ''}
                                            </td>
                                            <td className="text-right tabular-nums">{money(movement.value)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </CompactTable>
                        )}
                    </Panel>

                    {last_runs !== null && (
                        <Panel
                            title="Last production runs"
                            flush
                            className="lg:col-span-2"
                            aside={
                                <span className="flex items-center gap-3 text-xs">
                                    <Link href="/inventory/production-runs" className="text-primary hover:underline">
                                        All runs
                                    </Link>
                                    <Link href="/inventory/production-yield" className="text-primary hover:underline">
                                        Yield report
                                    </Link>
                                </span>
                            }
                        >
                            {last_runs.length === 0 ? (
                                <p className="text-muted-foreground px-4 pb-4 text-sm">No production runs yet.</p>
                            ) : (
                                <CompactTable minWidth="44rem">
                                    <thead>
                                        <tr className="text-muted-foreground">
                                            <th>Run</th>
                                            <th>Date</th>
                                            <th>Output</th>
                                            <th className="text-right">Quantity</th>
                                            <th className="text-right">Consumed value</th>
                                            <th className="text-right">Waste value</th>
                                            <th>Yield</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {last_runs.map((run) => (
                                            <tr key={run.id}>
                                                <td>
                                                    <Link
                                                        href={`/inventory/production-runs/${run.id}`}
                                                        className="text-primary font-medium hover:underline"
                                                    >
                                                        {run.run_number}
                                                    </Link>
                                                </td>
                                                <td className="text-muted-foreground whitespace-nowrap">{run.occurred_at}</td>
                                                <td>{run.output_product}</td>
                                                <td className="text-right tabular-nums">{qty(run.output_quantity)}</td>
                                                <td className="text-right tabular-nums">
                                                    {run.consumed_value === null ? '-' : money(run.consumed_value)}
                                                </td>
                                                <td className="text-right tabular-nums">{run.waste_value === null ? '-' : money(run.waste_value)}</td>
                                                <td>
                                                    {run.yield_flagged ? (
                                                        <Pill tone="warning">deviation</Pill>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </td>
                                                <td>
                                                    <Pill tone={run.status === 'confirmed' ? 'ok' : run.status === 'reversed' ? 'danger' : 'muted'}>
                                                        {run.status}
                                                    </Pill>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </CompactTable>
                            )}
                        </Panel>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
