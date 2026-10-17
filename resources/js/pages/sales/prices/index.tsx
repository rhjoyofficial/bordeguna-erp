import { Pagination } from '@/components/data-table/pagination';
import { PriceDialog } from '@/components/sales/price-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Price {
    id: number;
    unit_price: string;
    min_quantity: string;
    /** ⚠ Plain Y-m-d Dhaka dates from the server; the raw casts serialise in UTC. */
    effective_from: string;
    effective_to: string | null;
    note: string | null;
    product: { id: number; name: string; sku: string } | null;
    customer: { id: number; name: string } | null;
    price_group: { id: number; code: string; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Prices', href: '/sales/prices' },
];

export default function PricesIndex({
    prices,
    products,
    customers,
    priceGroups,
}: {
    prices: Paginated<Price>;
    products: { id: number; name: string; sku: string }[];
    customers: { id: number; name: string }[];
    priceGroups: { id: number; code: string; name: string }[];
}) {
    const { canImportPrices, canManagePrices } = usePage<SharedData>().props;
    // ⭐ Setting a price is a popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). ⚠ The list is
    // open to whoever reads sales; the button only to who may set a price.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Selling Prices" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Import prices was a sidebar row until 29 Sep 2026. */}
                        {canImportPrices && (
                            <Button asChild variant="outline">
                                <Link href="/sales/prices/import">Import prices</Link>
                            </Button>
                        )}
                        {canManagePrices && <Button onClick={() => setAdding(true)}>Add price</Button>}
                    </div>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3">Applies to</th>
                                <th className="p-3 text-right">From qty</th>
                                <th className="p-3 text-right">Price</th>
                                <th className="p-3">In force</th>
                            </tr>
                        </thead>
                        <tbody>
                            {prices.data.map((price) => (
                                <tr key={price.id} className="border-t">
                                    <td className="p-3">{price.product?.name ?? '-'}</td>
                                    <td className="p-3">
                                        {price.customer ? (
                                            <Badge variant="secondary">{price.customer.name}</Badge>
                                        ) : price.price_group ? (
                                            <Badge variant="outline">{price.price_group.code}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground">Standard (consumers only)</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{formatQuantity(price.min_quantity)}</td>
                                    <td className="p-3 text-right tabular-nums">{formatMoney(price.unit_price)}</td>
                                    <td className="text-muted-foreground p-3">
                                        {formatDate(price.effective_from)}
                                        {price.effective_to ? ` to ${formatDate(price.effective_to)}` : ' onwards'}
                                    </td>
                                </tr>
                            ))}
                            {prices.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-6 text-center">
                                        No selling prices set yet. Nothing can be sold until there is one.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={prices} />

                {canManagePrices && (
                    <PriceDialog open={adding} onOpenChange={setAdding} products={products} customers={customers} priceGroups={priceGroups} />
                )}
            </div>
        </AppLayout>
    );
}
