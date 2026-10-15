import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo } from 'react';

interface Conversion {
    id: number;
    sku: string;
    product: string;
    from_unit: string;
    to_unit: string;
    stock_units_per_purchase_unit: string;
    effective_from: string;
    source: string;
    note: string | null;
}

interface ProductOption {
    id: number;
    sku: string;
    name: string;
    stock_unit: string | null;
    stock_unit_id: number;
    purchase_unit: string | null;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Purchase units');

export default function UnitConversions({
    conversions,
    products,
    units,
}: {
    conversions: Conversion[];
    products: ProductOption[];
    units: { id: number; name: string; abbreviation: string }[];
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        from_unit_id: '',
        stock_units_per_purchase_unit: '',
        purchase_units_per_stock_unit: '',
        effective_from: '',
        source: '',
        note: '',
    });

    const product = useMemo(() => products.find((p) => String(p.id) === data.product_id), [products, data.product_id]);
    const purchaseUnit = useMemo(() => units.find((u) => String(u.id) === data.from_unit_id), [units, data.from_unit_id]);

    // Shown, never used to fill the other box in. The two numbers must be typed
    // independently - that disagreement is the only thing standing between an
    // inverted factor and a permanently wrong stock cost.
    const product1 = Number(data.stock_units_per_purchase_unit) * Number(data.purchase_units_per_stock_unit);
    const bothTyped = data.stock_units_per_purchase_unit !== '' && data.purchase_units_per_stock_unit !== '';
    const agrees = bothTyped && Math.abs(product1 - 1) < 0.0001;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.unit-conversions.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase units" />
            <div className="flex flex-col gap-4 p-4">
                <p className="text-muted-foreground max-w-3xl text-sm">
                    Buying in one unit, stocking in another: a sack of 50 kg bought, kilograms stocked. Every future receipt converts with it.
                </p>

                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="product_id" required>
                            Product
                        </Label>
                        <SearchSelect fit id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)}>
                            <option value="">Choose a product</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.sku} - {p.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {product && (
                            <p className="text-muted-foreground text-xs">
                                Stocked in <strong>{product.stock_unit}</strong>
                                {product.purchase_unit && (
                                    <>
                                        , bought in <strong>{product.purchase_unit}</strong>
                                    </>
                                )}
                            </p>
                        )}
                        <InputError message={errors.product_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="from_unit_id" required>
                            Bought in
                        </Label>
                        <SearchSelect id="from_unit_id" value={data.from_unit_id} onChange={(e) => setData('from_unit_id', e.target.value)}>
                            <option value="">Choose a unit</option>
                            {units
                                .filter((u) => !product || u.id !== product.stock_unit_id)
                                .map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} ({u.abbreviation})
                                    </option>
                                ))}
                        </SearchSelect>
                        <InputError message={errors.from_unit_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="effective_from" required>
                            Applies from
                        </Label>
                        <Input
                            id="effective_from"
                            aria-required
                            type="date"
                            value={data.effective_from}
                            onChange={(e) => setData('effective_from', e.target.value)}
                        />
                        <InputError message={errors.effective_from} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="stock_units_per_purchase_unit" required>
                            One {purchaseUnit?.abbreviation ?? 'purchase unit'} becomes how many {product?.stock_unit ?? 'stock units'}?
                        </Label>
                        <Input
                            id="stock_units_per_purchase_unit"
                            aria-required
                            value={data.stock_units_per_purchase_unit}
                            onChange={(e) => setData('stock_units_per_purchase_unit', e.target.value)}
                            placeholder="1.0905"
                        />
                        <InputError message={errors.stock_units_per_purchase_unit} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="purchase_units_per_stock_unit" required>
                            And one {product?.stock_unit ?? 'stock unit'} is how many {purchaseUnit?.abbreviation ?? 'purchase units'}?
                        </Label>
                        <Input
                            id="purchase_units_per_stock_unit"
                            aria-required
                            value={data.purchase_units_per_stock_unit}
                            onChange={(e) => setData('purchase_units_per_stock_unit', e.target.value)}
                            placeholder="0.917"
                        />
                        <InputError message={errors.purchase_units_per_stock_unit} />
                    </div>

                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="source" required>
                            Where this number comes from
                        </Label>
                        <Input
                            id="source"
                            aria-required
                            className="max-w-md"
                            value={data.source}
                            onChange={(e) => setData('source', e.target.value)}
                            placeholder="e.g. Razzak Oil Mill delivery note 12 Aug"
                        />
                        <InputError message={errors.source} />
                    </div>

                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="note">Note (optional)</Label>
                        <Input id="note" className="max-w-md" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>

                    <div className="flex items-center gap-3 lg:col-span-4">
                        <Button disabled={processing}>Record this conversion</Button>
                        {bothTyped && (
                            <span className={`text-xs ${agrees ? 'text-muted-foreground' : 'text-destructive'}`}>
                                {agrees
                                    ? 'The two numbers agree.'
                                    : `Those two multiply to ${product1.toFixed(4)}, not 1. One of them is the wrong way round.`}
                            </span>
                        )}
                    </div>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Product</th>
                            <th className="py-2">Bought in</th>
                            <th className="py-2">Stocked in</th>
                            <th className="py-2 text-right">One purchase unit becomes</th>
                            <th className="py-2">From</th>
                            <th className="py-2">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        {conversions.map((c) => (
                            <tr key={c.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <span className="font-mono text-xs">{c.sku}</span> {c.product}
                                </td>
                                <td className="py-2">{c.from_unit}</td>
                                <td className="py-2">{c.to_unit}</td>
                                <td className="py-2 text-right tabular-nums">
                                    {c.stock_units_per_purchase_unit} {c.to_unit}
                                </td>
                                <td className="py-2">{c.effective_from}</td>
                                <td className="text-muted-foreground py-2">{c.source}</td>
                            </tr>
                        ))}
                        {conversions.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-muted-foreground py-4 text-center">
                                    No conversions yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
