import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Price {
    id: number;
    unit_price: string;
    min_quantity: string;
    effective_from: string;
    effective_to: string | null;
    note: string | null;
    product: { id: number; name: string; sku: string } | null;
    customer: { id: number; name: string } | null;
    price_group: { id: number; code: string; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
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
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        unit_price: '',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
        customer_id: '',
        // ⚠ Retail by default (21 Sep 2026): a price for nobody in particular is
        // no longer accepted, and Retail is who "everyone" always meant.
        price_group_id: String(priceGroups.find((g) => g.code === 'RETAIL')?.id ?? ''),
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
                        <Label htmlFor="product_id" required>
                            Product
                        </Label>
                        <SearchSelect id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)}>
                            <option value="">Choose a product</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.sku})
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.product_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="unit_price" required>
                            Selling price
                        </Label>
                        <Input id="unit_price" value={data.unit_price} onChange={(e) => setData('unit_price', e.target.value)} />
                        <InputError message={errors.unit_price} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="effective_from" required>
                            In force from
                        </Label>
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
                        <Input id="effective_to" type="date" value={data.effective_to} onChange={(e) => setData('effective_to', e.target.value)} />
                        <InputError message={errors.effective_to} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="customer_id">For one customer</Label>
                        <SearchSelect id="customer_id" value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)}>
                            <option value="">No particular customer</option>
                            {customers.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price_group_id">Or a price group</Label>
                        {/* ⚠ Rows, never typed: the free-text version of this field
                            is how a distributor gets silently charged retail. */}
                        <SearchSelect id="price_group_id" value={data.price_group_id} onChange={(e) => setData('price_group_id', e.target.value)}>
                            <option value="">Choose a group</option>
                            {priceGroups.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.code}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.price_group_id} />
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
                                            <Badge variant="outline">{price.price_group.code}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground">Standard (consumers only)</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{price.min_quantity}</td>
                                    <td className="p-3 text-right tabular-nums">{price.unit_price}</td>
                                    <td className="text-muted-foreground p-3">
                                        {price.effective_from?.slice(0, 10)}
                                        {price.effective_to ? ` to ${price.effective_to.slice(0, 10)}` : ' onwards'}
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
            </div>
        </AppLayout>
    );
}
