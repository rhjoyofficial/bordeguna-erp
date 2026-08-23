import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    ClipboardCheck,
    Coins,
    HandCoins,
    Package,
    ReceiptText,
    ShoppingCart,
    Store,
    TrendingUp,
    Undo2,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface TradeChartPoint {
    label: string;
    sales: number | null;
    purchases: number | null;
}

interface TransactionRow {
    id: number;
    reference: string;
    party: string | null;
    date: string;
    amount: string;
    url: string;
}

interface DashboardProps {
    unpriced_sellable: { unpriced: number; sellable: number } | null;
    total_sales: string | null;
    total_purchases: string | null;
    total_sales_returns: string | null;
    total_purchase_returns: string | null;
    net_profit_month: string | null;
    invoice_due: string | null;
    total_expenses: string | null;
    customer_refunds: string | null;
    stock_value: string | null;
    low_stock_count: number | null;
    open_po_count: number | null;
    pending_approval_count: number | null;
    trade_chart: TradeChartPoint[] | null;
    trade_chart_range: string;
    recent_transactions: {
        invoices: TransactionRow[] | null;
        sales_orders: TransactionRow[] | null;
        purchase_orders: TransactionRow[] | null;
        expenses: TransactionRow[] | null;
        returns: TransactionRow[] | null;
    };

}

const CHART_RANGES = [
    { value: '1w', label: '1W' },
    { value: '1m', label: '1M' },
    { value: '3m', label: '3M' },
    { value: '6m', label: '6M' },
    { value: '1y', label: '1Y' },
];

/**
 * The Dreams POS tile: a colored icon square, a muted label and a large
 * figure. Colors come from the existing --chart-1..4 tokens rather than
 * new tile colors, so every accent preset recolors the dashboard with it.
 */
function StatTile({ icon: Icon, label, value, chartColor }: { icon: LucideIcon; label: string; value: string | number; chartColor: 1 | 2 | 3 | 4 }) {
    const iconBg = {
        1: 'bg-chart-1/15 text-chart-1',
        2: 'bg-chart-2/15 text-chart-2',
        3: 'bg-chart-3/15 text-chart-3',
        4: 'bg-chart-4/15 text-chart-4',
    }[chartColor];

    return (
        <Card>
            <CardContent className="flex items-center gap-4 p-4">
                <span className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-lg', iconBg)}>
                    <Icon className="h-5 w-5" />
                </span>
                <div className="min-w-0">
                    <p className="text-muted-foreground truncate text-sm">{label}</p>
                    <p className="truncate text-xl font-semibold">{value}</p>
                </div>
            </CardContent>
        </Card>
    );
}

function KpiTile({ icon: Icon, label, value, tone }: { icon: LucideIcon; label: string; value: string | number; tone?: 'destructive' }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">{label}</CardTitle>
                <Icon className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent className={`text-2xl font-semibold ${tone === 'destructive' ? 'text-destructive' : ''}`}>{value}</CardContent>
        </Card>
    );
}

/**
 * Grouped-bar sales vs purchases chart, hand-rolled like the purchase
 * trend it replaces - the project has no charting library and two bar
 * series do not justify adding one.
 */
function TradeChart({ data }: { data: TradeChartPoint[] }) {
    const max = Math.max(...data.map((point) => Math.max(point.sales ?? 0, point.purchases ?? 0)));
    const hasSales = data.some((point) => point.sales !== null);
    const hasPurchases = data.some((point) => point.purchases !== null);

    if (max <= 0) {
        return <p className="text-sm text-muted-foreground">No sales or purchases in this window.</p>;
    }

    return (
        <div>
            <div className="flex h-48 items-end gap-2">
                {data.map((point) => (
                    <div key={point.label} className="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
                        <div className="flex h-full w-full items-end justify-center gap-0.5">
                            {hasSales && (
                                <div
                                    className="bg-chart-1 w-full max-w-3 rounded-t-sm"
                                    style={{ height: `${Math.max(((point.sales ?? 0) / max) * 100, 1)}%` }}
                                    title={`${point.label} sales: ${(point.sales ?? 0).toLocaleString()}`}
                                />
                            )}
                            {hasPurchases && (
                                <div
                                    className="bg-chart-4 w-full max-w-3 rounded-t-sm"
                                    style={{ height: `${Math.max(((point.purchases ?? 0) / max) * 100, 1)}%` }}
                                    title={`${point.label} purchases: ${(point.purchases ?? 0).toLocaleString()}`}
                                />
                            )}
                        </div>
                        <span className="text-muted-foreground text-[10px] whitespace-nowrap">{point.label}</span>
                    </div>
                ))}
            </div>
            <div className="text-muted-foreground mt-3 flex items-center gap-4 text-xs">
                {hasSales && (
                    <span className="flex items-center gap-1.5">
                        <span className="bg-chart-1 h-2.5 w-2.5 rounded-sm" /> Sales
                    </span>
                )}
                {hasPurchases && (
                    <span className="flex items-center gap-1.5">
                        <span className="bg-chart-4 h-2.5 w-2.5 rounded-sm" /> Purchases
                    </span>
                )}
            </div>
        </div>
    );
}

const TRANSACTION_TABS: { key: keyof DashboardProps['recent_transactions']; label: string; amountHeading: string }[] = [
    { key: 'invoices', label: 'Invoices', amountHeading: 'Total' },
    { key: 'sales_orders', label: 'Sales orders', amountHeading: 'Status' },
    { key: 'purchase_orders', label: 'Purchase orders', amountHeading: 'Status' },
    { key: 'expenses', label: 'Expenses', amountHeading: 'Total' },
    { key: 'returns', label: 'Returns', amountHeading: 'Credit' },
];

function RecentTransactions({ transactions }: { transactions: DashboardProps['recent_transactions'] }) {
    const visibleTabs = TRANSACTION_TABS.filter((tab) => transactions[tab.key] !== null);
    const [active, setActive] = useState(visibleTabs[0]?.key);

    if (visibleTabs.length === 0) {
        return null;
    }

    const activeTab = visibleTabs.find((tab) => tab.key === active) ?? visibleTabs[0];
    const rows = transactions[activeTab.key] ?? [];

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Recent transactions</CardTitle>
                <div className="flex flex-wrap gap-1 pt-1">
                    {visibleTabs.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => setActive(tab.key)}
                            className={cn(
                                'rounded-md px-2.5 py-1 text-xs transition-colors',
                                tab.key === activeTab.key
                                    ? 'bg-primary text-primary-foreground font-medium'
                                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Nothing recorded yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs">
                                    <th className="pb-2 font-medium">Reference</th>
                                    <th className="pb-2 font-medium">Party</th>
                                    <th className="pb-2 font-medium">Date</th>
                                    <th className="pb-2 text-right font-medium">{activeTab.amountHeading}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id} className="border-b last:border-0">
                                        <td className="py-2">
                                            <Link href={row.url} className="text-primary hover:underline">
                                                {row.reference}
                                            </Link>
                                        </td>
                                        <td className="text-muted-foreground py-2">{row.party ?? '-'}</td>
                                        <td className="text-muted-foreground py-2 whitespace-nowrap">{row.date}</td>
                                        <td className="py-2 text-right whitespace-nowrap">{row.amount}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({
    unpriced_sellable,
    total_sales,
    total_purchases,
    total_sales_returns,
    total_purchase_returns,
    net_profit_month,
    invoice_due,
    total_expenses,
    customer_refunds,
    stock_value,
    low_stock_count,
    open_po_count,
    pending_approval_count,
    trade_chart,
    trade_chart_range,
    recent_transactions,

}: DashboardProps) {
    const [bannerDismissed, setBannerDismissed] = useState(false);

    const hasTradeTiles = total_sales !== null || total_purchases !== null || total_sales_returns !== null || total_purchase_returns !== null;
    const hasMoneyTiles = net_profit_month !== null || invoice_due !== null || total_expenses !== null || customer_refunds !== null;
    const hasOpsTiles = stock_value !== null || low_stock_count !== null || open_po_count !== null || pending_approval_count !== null;

    const setRange = (range: string) => {
        router.get('/dashboard', { range }, { preserveState: true, preserveScroll: true, only: ['trade_chart', 'trade_chart_range'] });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {unpriced_sellable !== null && unpriced_sellable.unpriced > 0 && !bannerDismissed && (
                    <div className="border-destructive/30 bg-destructive/10 text-foreground flex items-center gap-3 rounded-lg border px-4 py-2.5 text-sm">
                        <AlertTriangle className="text-destructive h-4 w-4 shrink-0" />
                        <p className="flex-1">
                            {unpriced_sellable.unpriced} of {unpriced_sellable.sellable} sellable products have no selling price in force, so each
                            refuses its first sale.{' '}
                            <Link href="/sales/prices" className="font-medium underline underline-offset-2">
                                Set prices
                            </Link>
                        </p>
                        <button
                            type="button"
                            onClick={() => setBannerDismissed(true)}
                            aria-label="Dismiss"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                )}

                {hasTradeTiles && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {total_sales !== null && <StatTile icon={Store} label="Total sales" value={total_sales} chartColor={1} />}
                        {total_purchases !== null && <StatTile icon={ShoppingCart} label="Total purchases" value={total_purchases} chartColor={4} />}
                        {total_sales_returns !== null && (
                            <StatTile icon={Undo2} label="Sales returns" value={total_sales_returns} chartColor={3} />
                        )}
                        {total_purchase_returns !== null && (
                            <StatTile icon={ReceiptText} label="Purchase return credits" value={total_purchase_returns} chartColor={2} />
                        )}
                    </div>
                )}

                {hasMoneyTiles && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {net_profit_month !== null && <StatTile icon={TrendingUp} label="Profit this month" value={net_profit_month} chartColor={1} />}
                        {invoice_due !== null && <StatTile icon={HandCoins} label="Invoice due" value={invoice_due} chartColor={3} />}
                        {total_expenses !== null && <StatTile icon={Coins} label="Total expenses" value={total_expenses} chartColor={4} />}
                        {customer_refunds !== null && <StatTile icon={Banknote} label="Customer refunds" value={customer_refunds} chartColor={2} />}
                    </div>
                )}

                {hasOpsTiles && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {stock_value !== null && <KpiTile icon={Package} label="Stock value" value={stock_value} />}
                        {low_stock_count !== null && (
                            <KpiTile
                                icon={AlertTriangle}
                                label="Low stock"
                                value={low_stock_count}
                                tone={low_stock_count > 0 ? 'destructive' : undefined}
                            />
                        )}
                        {open_po_count !== null && <KpiTile icon={ShoppingCart} label="Open purchase orders" value={open_po_count} />}
                        {pending_approval_count !== null && (
                            <KpiTile
                                icon={ClipboardCheck}
                                label="Pending approvals"
                                value={pending_approval_count}
                                tone={pending_approval_count > 0 ? 'destructive' : undefined}
                            />
                        )}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {trade_chart !== null && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Sales & purchases</CardTitle>
                                <div className="flex gap-1">
                                    {CHART_RANGES.map((option) => (
                                        <Button
                                            key={option.value}
                                            size="sm"
                                            variant={option.value === trade_chart_range ? 'default' : 'ghost'}
                                            className="h-7 px-2 text-xs"
                                            onClick={() => setRange(option.value)}
                                        >
                                            {option.label}
                                        </Button>
                                    ))}
                                </div>
                            </CardHeader>
                            <CardContent>
                                <TradeChart data={trade_chart} />
                            </CardContent>
                        </Card>
                    )}

                    {/* Recent activity REMOVED on Joy's instruction, 23 Aug 2026
                        ("delete the recent activities"). The activity log table
                        itself stays - it is the audit trail - only this feed of
                        it on the dashboard is gone. */}
                </div>

                <RecentTransactions transactions={recent_transactions} />
            </div>
        </AppLayout>
    );
}
