import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface LowStockProduct {
    id: number;
    name: string;
    sku: string;
    current_quantity: number;
    low_stock_threshold: number;
}

interface Props {
    total_skus: number;
    in_stock_products: number;
    stocked_products: number;
    total_stock_value: string;
    low_stock_count: number;
    low_stock_products: LowStockProduct[];
    categories: { name: string; value: number; share: number }[];
    warehouses: { name: string; value: number; share: number; products: number }[];
    trend: { label: string; in: number; out: number }[];
    suppliers: { name: string; value: number; receipts: number }[];
    recent_movements: {
        id: number;
        occurred_at: string;
        product: string | null;
        sku: string | null;
        warehouse: string | null;
        direction: string;
        reason: string;
        quantity: number;
        value: number;
    }[];
    expiry: { expired: number; expiring: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (value: number) => value.toLocaleString('en-BD', { maximumFractionDigits: 4 });

const BAR_COLORS = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4', 'bg-chart-5'];

function Tile({ label, value, viewAll, tone }: { label: string; value: string | number; viewAll?: string; tone?: 'destructive' }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-baseline justify-between space-y-0 pb-2">
                <CardTitle className="text-muted-foreground text-sm font-medium">{label}</CardTitle>
                {viewAll && (
                    <Link href={viewAll} className="text-primary text-xs whitespace-nowrap hover:underline">
                        View all
                    </Link>
                )}
            </CardHeader>
            <CardContent className={`text-2xl font-semibold ${tone === 'destructive' ? 'text-destructive' : ''}`}>{value}</CardContent>
        </Card>
    );
}

/**
 * The DreamsERP inventory-dashboard anatomy over real engine data. Hand-rolled
 * CSS bars like every other chart in this project; a panel with nothing to
 * show says why in one quiet line rather than drawing invented numbers.
 */
export default function InventoryDashboard({
    total_skus,
    in_stock_products,
    stocked_products,
    total_stock_value,
    low_stock_count,
    low_stock_products,
    categories,
    warehouses,
    trend,
    suppliers,
    recent_movements,
    expiry,
}: Props) {
    const trendMax = Math.max(...trend.map((point) => Math.max(point.in, point.out)), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex flex-col gap-4 p-4">
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {/* No "total units" tile on purpose: packs, litres and
                        kilograms do not sum. Products with stock is the honest
                        quantity-flavoured fact. */}
                    <Tile label="Products in stock" value={`${in_stock_products} of ${stocked_products}`} viewAll="/inventory/stock" />
                    <Tile label="Stock value" value={total_stock_value} viewAll="/accounting/inventory-valuation" />
                    <Tile
                        label="Low stock"
                        value={low_stock_count}
                        viewAll="/inventory/low-stocks"
                        tone={low_stock_count > 0 ? 'destructive' : undefined}
                    />
                    <Tile
                        label="Expired / expiring"
                        value={`${expiry.expired} / ${expiry.expiring}`}
                        viewAll="/inventory/expiry"
                        tone={expiry.expired > 0 ? 'destructive' : undefined}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Stock value by category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {categories.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No stock is held yet, so there is nothing to distribute.</p>
                            ) : (
                                <div className="flex flex-col gap-2">
                                    {categories.map((category, index) => (
                                        <div key={category.name} className="flex items-center gap-2">
                                            <span className="text-muted-foreground w-32 truncate text-xs">{category.name}</span>
                                            <div className="bg-muted h-3 flex-1 overflow-hidden rounded-sm">
                                                <div
                                                    className={`h-full rounded-sm ${BAR_COLORS[index % BAR_COLORS.length]}`}
                                                    style={{ width: `${Math.max(category.share, 2)}%` }}
                                                    title={`${category.name}: ${money(category.value)}`}
                                                />
                                            </div>
                                            <span className="w-24 text-right text-xs tabular-nums">{money(category.value)}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Stock movement, last {trend.length} months</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trendMax <= 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No stock has moved in this window. The chart fills in as receipts, production and sales flow.
                                </p>
                            ) : (
                                <div>
                                    <div className="flex h-36 items-end gap-2">
                                        {trend.map((point) => (
                                            <div key={point.label} className="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                                                <div className="flex h-full w-full items-end justify-center gap-0.5">
                                                    <div
                                                        className="bg-chart-2 w-full max-w-3 rounded-t-sm"
                                                        style={{ height: `${Math.max((point.in / trendMax) * 100, 1)}%` }}
                                                        title={`${point.label} in: ${money(point.in)}`}
                                                    />
                                                    <div
                                                        className="bg-chart-4 w-full max-w-3 rounded-t-sm"
                                                        style={{ height: `${Math.max((point.out / trendMax) * 100, 1)}%` }}
                                                        title={`${point.label} out: ${money(point.out)}`}
                                                    />
                                                </div>
                                                <span className="text-muted-foreground text-[10px] whitespace-nowrap">{point.label}</span>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="text-muted-foreground mt-3 flex items-center gap-4 text-xs">
                                        <span className="flex items-center gap-1.5">
                                            <span className="bg-chart-2 h-2.5 w-2.5 rounded-sm" /> Value in
                                        </span>
                                        <span className="flex items-center gap-1.5">
                                            <span className="bg-chart-4 h-2.5 w-2.5 rounded-sm" /> Value out
                                        </span>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Warehouses</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* Share of stock value, NOT capacity: no warehouse
                                here has ever declared a capacity, so a ring
                                would be a made-up percentage. */}
                            <div className="flex flex-col gap-2">
                                {warehouses.map((warehouse, index) => (
                                    <div key={warehouse.name} className="flex items-center gap-2">
                                        <span className="w-28 truncate text-xs">{warehouse.name}</span>
                                        <div className="bg-muted h-3 flex-1 overflow-hidden rounded-sm">
                                            <div
                                                className={`h-full rounded-sm ${BAR_COLORS[index % BAR_COLORS.length]}`}
                                                style={{ width: `${Math.max(warehouse.share, warehouse.value > 0 ? 2 : 0)}%` }}
                                                title={`${warehouse.name}: ${money(warehouse.value)} (${warehouse.share}%)`}
                                            />
                                        </div>
                                        <span className="text-muted-foreground w-16 text-right text-xs">{warehouse.products} prod.</span>
                                        <span className="w-24 text-right text-xs tabular-nums">{money(warehouse.value)}</span>
                                    </div>
                                ))}
                                {warehouses.length === 0 && <p className="text-muted-foreground text-sm">No active warehouses.</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Top suppliers by goods received</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {suppliers.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No purchases yet. This fills in once goods receipts start landing.</p>
                            ) : (
                                <table className="w-full text-sm">
                                    <tbody>
                                        {suppliers.map((supplier) => (
                                            <tr key={supplier.name} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="py-1.5">{supplier.name}</td>
                                                <td className="text-muted-foreground py-1.5 text-xs">
                                                    {supplier.receipts} receipt{supplier.receipts === 1 ? '' : 's'}
                                                </td>
                                                <td className="py-1.5 text-right tabular-nums">{money(supplier.value)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="flex flex-row items-baseline justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Recent stock movements</CardTitle>
                            <Link href="/inventory/stock" className="text-primary text-xs hover:underline">
                                View all
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {recent_movements.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nothing has moved yet. The first receipt or sale starts this list.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[32rem] text-sm">
                                        <tbody>
                                            {recent_movements.map((movement) => (
                                                <tr key={movement.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground py-1.5 text-xs whitespace-nowrap">{movement.occurred_at}</td>
                                                    <td className="max-w-48 truncate py-1.5">{movement.product}</td>
                                                    <td className="text-muted-foreground py-1.5 text-xs">{movement.warehouse}</td>
                                                    <td className={`py-1.5 text-xs ${movement.direction === 'in' ? '' : 'text-destructive'}`}>
                                                        {movement.direction === 'in' ? '+' : '-'}
                                                        {qty(movement.quantity)} ({movement.reason})
                                                    </td>
                                                    <td className="py-1.5 text-right text-xs tabular-nums">{money(movement.value)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-baseline justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Below threshold</CardTitle>
                            <Link href="/inventory/low-stocks" className="text-primary text-xs hover:underline">
                                View all
                            </Link>
                        </CardHeader>
                        <CardContent>
                            {low_stock_products.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nothing is at or below its threshold.</p>
                            ) : (
                                <table className="w-full text-sm">
                                    <tbody>
                                        {low_stock_products.map((product) => (
                                            <tr key={product.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                <td className="py-1.5">
                                                    {product.name} <span className="text-muted-foreground text-xs">({product.sku})</span>
                                                </td>
                                                <td className="text-destructive py-1.5 text-right font-medium tabular-nums">
                                                    {qty(product.current_quantity)}
                                                </td>
                                                <td className="text-muted-foreground py-1.5 text-right text-xs tabular-nums">
                                                    of {qty(product.low_stock_threshold)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
