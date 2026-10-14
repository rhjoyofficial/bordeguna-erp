import { ReportHeading } from '@/components/report/report-shell';
import { DiscountDialog, type DiscountProduct } from '@/components/sales/discount-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Discount = {
    id: number;
    product: string | null;
    sku: string | null;
    label: string | null;
    group: string;
    min_quantity: number;
    unit_price: number;
    effective_from: string | null;
    effective_to: string | null;
    is_winning: boolean;
};

type PageProps = {
    on: string;
    discounts: Discount[];
    products: DiscountProduct[];
    groups: { id: number; name: string; code: string }[];
};

export default function Discounts() {
    const { on, discounts, products, groups } = usePage<PageProps>().props;

    // ⭐ Setting a discount is a popup over the list (14 Oct 2026, Joy: "add
    // edit will from the pop up, not like current above the list"). ⚠ The
    // whole page sits behind sales.prices.manage, so whoever sees it may add.
    const [adding, setAdding] = useState(false);

    return (
        <AppLayout>
            <Head title="Discounts" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Discounts" actions={<Button onClick={() => setAdding(true)}>Add discount</Button>} />

                <div>
                    <h3 className="mb-3 text-sm font-medium">Discounts set</h3>
                    <div className="border-border overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Product</th>
                                    <th className="p-3 font-medium">Name</th>
                                    <th className="p-3 font-medium">Who</th>
                                    <th className="p-3 text-right font-medium">From qty</th>
                                    <th className="p-3 text-right font-medium">Price</th>
                                    <th className="p-3 font-medium">Period</th>
                                    <th className="p-3 font-medium">Applying today</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {discounts.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                            No discounts set. Everything sells at its list price.
                                        </td>
                                    </tr>
                                )}
                                {discounts.map((d) => (
                                    <tr key={d.id} className="border-border border-t">
                                        <td className="p-3">
                                            <div className="font-medium">{d.product}</div>
                                            <div className="text-muted-foreground text-xs">{d.sku}</div>
                                        </td>
                                        <td className="p-3">{d.label ?? <span className="text-muted-foreground">-</span>}</td>
                                        <td className="p-3">{d.group}</td>
                                        <td className="p-3 text-right">{d.min_quantity}</td>
                                        <td className="p-3 text-right font-medium">{d.unit_price.toFixed(2)}</td>
                                        <td className="p-3 whitespace-nowrap">
                                            {d.effective_from}
                                            {d.effective_to ? ` to ${d.effective_to}` : ' onwards'}
                                        </td>
                                        <td className="p-3">
                                            {d.is_winning ? (
                                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">
                                                    Yes
                                                </span>
                                            ) : (
                                                <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs">No</span>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => router.post(`/sales/discounts/${d.id}/close`, {}, { preserveScroll: true })}
                                            >
                                                End it
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <DiscountDialog open={adding} onOpenChange={setAdding} on={on} products={products} groups={groups} />
            </div>
        </AppLayout>
    );
}
