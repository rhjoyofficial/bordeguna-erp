import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { AlertTriangle, ClipboardCheck, Package, ShoppingCart, type LucideIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

interface RecentActivityItem {
    id: number;
    description: string | null;
    causer_name: string | null;
    created_at: string;
}

interface PurchaseTrendPoint {
    month: string;
    value: number;
}

interface DashboardProps {
    stock_value: string | null;
    low_stock_count: number | null;
    open_po_count: number | null;
    pending_approval_count: number | null;
    purchase_trend: PurchaseTrendPoint[] | null;
    recent_activity: RecentActivityItem[];
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

function PurchaseTrendChart({ data }: { data: PurchaseTrendPoint[] }) {
    const max = Math.max(...data.map((point) => point.value));

    if (max <= 0) {
        return <p className="text-sm text-muted-foreground">No purchase orders in the last 6 months.</p>;
    }

    return (
        <div className="flex h-40 items-end gap-3">
            {data.map((point) => (
                <div key={point.month} className="flex h-full flex-1 flex-col items-center justify-end gap-2">
                    <div
                        className="w-full min-w-2 rounded-t-sm bg-chart-1"
                        style={{ height: `${Math.max((point.value / max) * 100, 2)}%` }}
                        title={`${point.month}: ${point.value.toLocaleString()}`}
                    />
                    <span className="text-xs text-muted-foreground">{point.month}</span>
                </div>
            ))}
        </div>
    );
}

export default function Dashboard({
    stock_value,
    low_stock_count,
    open_po_count,
    pending_approval_count,
    purchase_trend,
    recent_activity,
}: DashboardProps) {
    const hasKpis = stock_value !== null || low_stock_count !== null || open_po_count !== null || pending_approval_count !== null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {hasKpis && (
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
                    {purchase_trend !== null && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">Purchase order value — last 6 months</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <PurchaseTrendChart data={purchase_trend} />
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-sm font-medium text-muted-foreground">Recent activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_activity.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No recent activity.</p>
                            ) : (
                                <div className="space-y-3">
                                    {recent_activity.map((entry) => (
                                        <div
                                            key={entry.id}
                                            className="border-sidebar-border/70 dark:border-sidebar-border flex items-start justify-between gap-4 border-b pb-3 last:border-0 last:pb-0"
                                        >
                                            <div>
                                                <p className="text-sm">{entry.description}</p>
                                                {entry.causer_name && <p className="text-xs text-muted-foreground">by {entry.causer_name}</p>}
                                            </div>
                                            <span className="shrink-0 text-xs text-muted-foreground">{entry.created_at}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
