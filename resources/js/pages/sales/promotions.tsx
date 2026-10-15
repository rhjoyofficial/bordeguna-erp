import { PromotionDialog } from '@/components/sales/promotion-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Buy X Get Y free (Module 3, Slice 5). Setting a deal is a pricing decision;
 * whether a cart earned it is decided server-side at the counter. A deal is
 * paused or expires, never deleted - its history happened.
 */
interface PromotionRow {
    id: number;
    name: string;
    buy_product: string;
    buy_quantity: string;
    get_product: string;
    get_quantity: string;
    starts_on: string;
    ends_on: string;
    status: string;
}

interface PromotionsProps {
    promotions: PromotionRow[];
    products: { id: number; name: string; sku: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Promotions', href: '/sales/promotions' },
];

const qty = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

export default function Promotions({ promotions, products }: PromotionsProps) {
    // ⭐ Starting a deal is a popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). ⚠ The whole
    // page sits behind sales.prices.manage, so whoever sees it may add.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Promotions" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">Buy X, get Y free.</p>
                    <Button size="sm" onClick={() => setAdding(true)}>
                        Add promotion
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left">
                                <th className="px-3 py-2 font-medium">Deal</th>
                                <th className="px-3 py-2 font-medium">Buy</th>
                                <th className="px-3 py-2 font-medium">Get free</th>
                                <th className="px-3 py-2 font-medium">Runs</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {promotions.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-3 py-4 text-center">
                                        No promotions yet.
                                    </td>
                                </tr>
                            ) : (
                                promotions.map((promotion) => (
                                    <tr key={promotion.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{promotion.name}</td>
                                        <td className="px-3 py-2">
                                            {qty(promotion.buy_quantity)} x {promotion.buy_product}
                                        </td>
                                        <td className="px-3 py-2">
                                            {qty(promotion.get_quantity)} x {promotion.get_product}
                                        </td>
                                        <td className="px-3 py-2">
                                            {promotion.starts_on} to {promotion.ends_on}
                                        </td>
                                        <td className="px-3 py-2 capitalize">{promotion.status}</td>
                                        <td className="px-3 py-2 text-right">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.post(`/sales/promotions/${promotion.id}/toggle`, {}, { preserveScroll: true })}
                                            >
                                                {promotion.status === 'active' ? 'Pause' : 'Resume'}
                                            </Button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <PromotionDialog open={adding} onOpenChange={setAdding} products={products} />
            </div>
        </AppLayout>
    );
}
