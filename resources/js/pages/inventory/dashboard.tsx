import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface LowStockProduct {
    id: number;
    name: string;
    sku: string;
    current_quantity: string;
    low_stock_threshold: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inventory', href: '/inventory' }];

export default function InventoryDashboard({
    total_skus,
    low_stock_count,
    low_stock_products,
    total_stock_value,
}: {
    total_skus: number;
    low_stock_count: number;
    low_stock_products: LowStockProduct[];
    total_stock_value: string;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inventory" />
            <div className="flex flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Total SKUs</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">{total_skus}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Low stock</CardTitle>
                        </CardHeader>
                        <CardContent className={`text-2xl font-semibold ${low_stock_count > 0 ? 'text-destructive' : ''}`}>
                            {low_stock_count}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Total stock value</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">{total_stock_value}</CardContent>
                    </Card>
                </div>

                {low_stock_products.length > 0 && (
                    <div>
                        <h2 className="mb-2 text-sm font-medium text-muted-foreground">Products below threshold</h2>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                                    <th className="py-2">Product</th>
                                    <th className="py-2">Current qty</th>
                                    <th className="py-2">Threshold</th>
                                </tr>
                            </thead>
                            <tbody>
                                {low_stock_products.map((product) => (
                                    <tr key={product.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                        <td className="py-2">
                                            {product.name} <span className="text-muted-foreground">({product.sku})</span>
                                        </td>
                                        <td className="py-2 font-medium text-destructive">{product.current_quantity}</td>
                                        <td className="py-2 text-muted-foreground">{product.low_stock_threshold}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
