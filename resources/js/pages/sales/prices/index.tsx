import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Price {
    id: number;
    unit_price: string;
    min_quantity: string;
    price_group: string | null;
    effective_from: string;
    effective_to: string | null;
    note: string | null;
    product: { id: number; name: string; sku: string } | null;
    customer: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Prices', href: '/sales/prices' },
];

export default function PricesIndex({
    prices,
    products,
    customers,
}: {
    prices: Paginated<Price>;
    products: { id: number; name: string; sku: string }[];
    customers: { id: number; name: string }[];
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        unit_price: '',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
        customer_id: '',
        price_group: '',
        min_quantity: '0',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.prices.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Selling Prices" />
            <div className="flex flex-col gap-6 p-4">
                {/* A price is never edited, only superseded: set a new one from a
                    date, and the old row stays so an old invoice can still be
                    explained. */}
                <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="product_id">Product</Label>
                        <select
                            id="product_id"
                            className={selectClass}
                            value={data.product_id}
                            onChange={(e) => setData('product_id', e.target.value)}
                        >
                            <option value="">Choose a product</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.sku})
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.product_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="unit_price">Selling price</Label>
                        <Input id="unit_price" value={data.unit_price} onChange={(e) => setData('unit_price', e.target.value)} />
                        <InputError message={errors.unit_price} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="effective_from">In force from</Label>
                        <Input
                            id="effective_from"
                            type="date"
                            value={data.effective_from}
                            onChange={(e) => setData('effective_from', e.target.value)}
                        />
                        <InputError message={errors.effective_from} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="effective_to">Until (optional)</Label>
                        <Input
                            id="effective_to"
                            type="date"
                            value={data.effective_to}
                            onChange={(e) => setData('effective_to', e.target.value)}
                        />
                        <InputError message={errors.effective_to} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="customer_id">For one customer</Label>
                        <select
                            id="customer_id"
                            className={selectClass}
                            value={data.customer_id}
                            onChange={(e) => setData('customer_id', e.target.value)}
                        >
                            <option value="">Everyone</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price_group">Or a price group</Label>
                        <Input
                            id="price_group"
                            value={data.price_group}
                            onChange={(e) => setData('price_group', e.target.value)}
                            placeholder="dealer"
                        />
                        <InputError message={errors.price_group} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="min_quantity">From quantity</Label>
                        <Input id="min_quantity" value={data.min_quantity} onChange={(e) => setData('min_quantity', e.target.value)} />
                    </div>
                    <div className="flex items-end">
                        <Button type="submit" disabled={processing}>
                            Set price
                        </Button>
                    </div>
                </form>

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
                                            <Badge variant="outline">{price.price_group}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground">Everyone</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{price.min_quantity}</td>
                                    <td className="p-3 text-right tabular-nums">{price.unit_price}</td>
                                    <td className="p-3 text-muted-foreground">
                                        {price.effective_from?.slice(0, 10)}
                                        {price.effective_to ? ` to ${price.effective_to.slice(0, 10)}` : ' onwards'}
                                    </td>
                                </tr>
                            ))}
                            {prices.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        No selling prices set yet. Nothing can be sold until there is one.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={prices} />
            </div>
        </AppLayout>
    );
}
