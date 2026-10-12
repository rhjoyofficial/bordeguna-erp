import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Promotions', href: '/sales/promotions' }];

const qty = (value: string) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });

export default function Promotions({ promotions, products }: PromotionsProps) {
    const form = useForm({
        name: '',
        buy_product_id: '',
        buy_quantity: '',
        get_product_id: '',
        get_quantity: '1',
        starts_on: '',
        ends_on: '',
    });

    const options = products.map((product) => ({ value: String(product.id), label: product.name }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Promotions" />
            <div className="space-y-6 p-4">
                <h1 className="text-xl font-semibold">Promotions: Buy X Get Y free</h1>

                <form
                    className="border-border bg-card grid max-w-3xl gap-3 rounded-lg border p-4 sm:grid-cols-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/sales/promotions', { preserveScroll: true, onSuccess: () => form.reset() });
                    }}
                >
                    <div className="space-y-1 sm:col-span-2">
                        <Label required>Name of the deal</Label>
                        <Input
                            className="max-w-md"
                            aria-required
                            placeholder="e.g. Eid honey deal"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        {form.errors.name && <p className="text-destructive text-xs">{form.errors.name}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>Customer buys</Label>
                        <SearchableSelect
                            value={form.data.buy_product_id}
                            onChange={(value) => form.setData('buy_product_id', value)}
                            options={options}
                            placeholder="Product"
                            searchPlaceholder="Search products…"
                        />
                        {form.errors.buy_product_id && <p className="text-destructive text-xs">{form.errors.buy_product_id}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>This many</Label>
                        <Input
                            type="number"
                            step="0.0001"
                            min="0"
                            aria-required
                            placeholder="e.g. 3"
                            value={form.data.buy_quantity}
                            onChange={(event) => form.setData('buy_quantity', event.target.value)}
                        />
                        {form.errors.buy_quantity && <p className="text-destructive text-xs">{form.errors.buy_quantity}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>Gets free</Label>
                        <SearchableSelect
                            value={form.data.get_product_id}
                            onChange={(value) => form.setData('get_product_id', value)}
                            options={options}
                            placeholder="Product"
                            searchPlaceholder="Search products…"
                        />
                        {form.errors.get_product_id && <p className="text-destructive text-xs">{form.errors.get_product_id}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>Free quantity</Label>
                        <Input
                            type="number"
                            step="0.0001"
                            min="0"
                            aria-required
                            value={form.data.get_quantity}
                            onChange={(event) => form.setData('get_quantity', event.target.value)}
                        />
                        {form.errors.get_quantity && <p className="text-destructive text-xs">{form.errors.get_quantity}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>From</Label>
                        <Input
                            type="date"
                            aria-required
                            value={form.data.starts_on}
                            onChange={(event) => form.setData('starts_on', event.target.value)}
                        />
                        {form.errors.starts_on && <p className="text-destructive text-xs">{form.errors.starts_on}</p>}
                    </div>
                    <div className="space-y-1">
                        <Label required>To (last day)</Label>
                        <Input
                            type="date"
                            aria-required
                            value={form.data.ends_on}
                            onChange={(event) => form.setData('ends_on', event.target.value)}
                        />
                        {form.errors.ends_on && <p className="text-destructive text-xs">{form.errors.ends_on}</p>}
                    </div>
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={form.processing}>
                            Start the promotion
                        </Button>
                    </div>
                </form>

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
            </div>
        </AppLayout>
    );
}
