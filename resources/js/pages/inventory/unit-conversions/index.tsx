import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Purchase units', href: '/inventory/unit-conversions' },
];

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
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Buying in one unit, stocking in another</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Mustard oil is bought by the kilogram and sold by the litre. Record how many stock units one purchase unit becomes, and every
                        goods receipt converts it for you and works out the cost per stock unit.
                    </p>
                    <p className="text-muted-foreground mt-2 text-sm">
                        The number is <strong>frozen onto each batch</strong> as it arrives, so changing it later never rewrites what old stock cost.
                        To change it, add a new row with a later start date.
                    </p>
                    <p className="mt-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        Until a product has a conversion, every goods receipt for it is <strong>refused</strong> rather than assuming one for one.
                        That is deliberate: assuming would put 100 litres into stock for a 100 kg drum and understate the cost of a litre by about 9%,
                        and every report would still balance.
                    </p>
                </div>

                <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="product_id">Product</Label>
                        <SearchSelect id="product_id" value={data.product_id} onChange={(e) => setData('product_id', e.target.value)}>
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
                                {product.purchase_unit ? (
                                    <>
                                        , already bought in <strong>{product.purchase_unit}</strong>. A new row here changes the rate, not the unit -
                                        changing the purchase unit is a new product.
                                    </>
                                ) : (
                                    '. It has no purchase unit yet.'
                                )}
                            </p>
                        )}
                        <InputError message={errors.product_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="from_unit_id">Bought in</Label>
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
                        <Label htmlFor="effective_from">Applies from</Label>
                        <Input
                            id="effective_from"
                            type="date"
                            value={data.effective_from}
                            onChange={(e) => setData('effective_from', e.target.value)}
                        />
                        <InputError message={errors.effective_from} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="stock_units_per_purchase_unit">
                            One {purchaseUnit?.abbreviation ?? 'purchase unit'} becomes how many {product?.stock_unit ?? 'stock units'}?
                        </Label>
                        <Input
                            id="stock_units_per_purchase_unit"
                            value={data.stock_units_per_purchase_unit}
                            onChange={(e) => setData('stock_units_per_purchase_unit', e.target.value)}
                            placeholder="1.0905"
                        />
                        <InputError message={errors.stock_units_per_purchase_unit} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="purchase_units_per_stock_unit">
                            And one {product?.stock_unit ?? 'stock unit'} is how many {purchaseUnit?.abbreviation ?? 'purchase units'}?
                        </Label>
                        <Input
                            id="purchase_units_per_stock_unit"
                            value={data.purchase_units_per_stock_unit}
                            onChange={(e) => setData('purchase_units_per_stock_unit', e.target.value)}
                            placeholder="0.917"
                        />
                        <InputError message={errors.purchase_units_per_stock_unit} />
                    </div>

                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="source">Where this number comes from</Label>
                        <Input
                            id="source"
                            value={data.source}
                            onChange={(e) => setData('source', e.target.value)}
                            placeholder="e.g. Razzak Oil Mill delivery note 12 Aug"
                        />
                        <InputError message={errors.source} />
                    </div>

                    <div className="grid gap-2 lg:col-span-2">
                        <Label htmlFor="note">Note (optional)</Label>
                        <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
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

                {/*
                    ⚠⚠ Why both numbers are asked for rather than one calculated:
                    0.917 stored where 1.0905 belongs is 21% wrong, permanently,
                    with every ledger balancing. Calculating the second from the
                    first would make an inverted entry impossible to detect.
                */}
                <p className="text-muted-foreground max-w-3xl text-sm">
                    Both directions are asked for on purpose. They are not the same number upside down by accident - for mustard oil, 1 kg is 1.09 L
                    while 1 L is 0.917 kg. Typing both is what catches an entry made the wrong way round, which would otherwise be about 21% wrong for
                    ever and look perfectly correct in every report.
                </p>

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
                                    Nothing here yet. Most products are bought in the unit they are stocked in and need no conversion at all.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
