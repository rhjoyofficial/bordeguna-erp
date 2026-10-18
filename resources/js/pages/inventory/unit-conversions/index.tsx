import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo } from 'react';

type Dimension = 'mass' | 'volume' | 'count' | null;
type Purpose = 'purchase' | 'recipe' | 'sale';

interface Conversion {
    id: number;
    sku: string;
    product: string;
    purpose: Purpose;
    from_unit: string;
    from_dimension: Dimension;
    to_unit: string;
    to_dimension: Dimension;
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
    stock_unit_dimension: Dimension;
    purchase_unit: string | null;
}

interface UnitOption {
    id: number;
    name: string;
    abbreviation: string;
    dimension: Dimension;
    factor_to_base: string | null;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Purchase units');

/** g and kg, mL and L: the pairs the ERP converts by itself and refuses a typed ratio for. */
function convertsBySelf(a: UnitOption | undefined, b: UnitOption | undefined): boolean {
    return (
        !!a &&
        !!b &&
        a.id !== b.id &&
        a.dimension !== null &&
        a.dimension !== 'count' &&
        a.dimension === b.dimension &&
        a.factor_to_base !== null &&
        b.factor_to_base !== null
    );
}

export default function UnitConversions({
    conversions,
    products,
    units,
}: {
    conversions: Conversion[];
    products: ProductOption[];
    units: UnitOption[];
}) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, reset } = useForm({
        product_id: '',
        purpose: 'purchase' as Purpose,
        from_unit_id: '',
        stock_units_per_purchase_unit: '',
        purchase_units_per_stock_unit: '',
        effective_from: '',
        source: '',
        note: '',
    });

    const product = useMemo(() => products.find((p) => String(p.id) === data.product_id), [products, data.product_id]);
    const fromUnit = useMemo(() => units.find((u) => String(u.id) === data.from_unit_id), [units, data.from_unit_id]);
    const stockUnit = useMemo(() => units.find((u) => product && u.id === product.stock_unit_id), [units, product]);
    const isRecipe = data.purpose === 'recipe';

    // Shown, never used to fill the other box in. The two numbers must be typed
    // independently - that disagreement is the only thing standing between an
    // inverted factor and a permanently wrong stock cost.
    const product1 = Number(data.stock_units_per_purchase_unit) * Number(data.purchase_units_per_stock_unit);
    const bothTyped = data.stock_units_per_purchase_unit !== '' && data.purchase_units_per_stock_unit !== '';
    const agrees = bothTyped && Math.abs(product1 - 1) < 0.0001;

    const dimensionLabel = (dimension: Dimension) => (dimension === null ? t('units.dimension.none') : t(`units.dimension.${dimension}`));

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
                <p className="text-muted-foreground max-w-3xl text-sm">{t('units.purpose.hint')}</p>

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
                                Stocked in <strong>{product.stock_unit}</strong> ({dimensionLabel(product.stock_unit_dimension)})
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
                        <Label htmlFor="purpose" required>
                            {t('units.purpose')}
                        </Label>
                        <select
                            id="purpose"
                            className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                            value={data.purpose}
                            onChange={(e) => setData('purpose', e.target.value as Purpose)}
                        >
                            <option value="purchase">{t('units.purpose.purchase')}</option>
                            <option value="recipe">{t('units.purpose.recipe')}</option>
                        </select>
                        <InputError message={errors.purpose} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="from_unit_id" required>
                            {isRecipe ? 'Recipe lines written in' : 'Bought in'}
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
                        {convertsBySelf(fromUnit, stockUnit) && <p className="text-destructive text-xs">{t('units.conversion.same_dimension')}</p>}
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
                            {isRecipe
                                ? t('units.conversion.recipe_direction', {
                                      from: fromUnit?.abbreviation ?? 'unit',
                                      to: product?.stock_unit ?? 'stock unit',
                                  })
                                : `One ${fromUnit?.abbreviation ?? 'purchase unit'} becomes how many ${product?.stock_unit ?? 'stock units'}?`}
                        </Label>
                        <Input
                            id="stock_units_per_purchase_unit"
                            aria-required
                            value={data.stock_units_per_purchase_unit}
                            onChange={(e) => setData('stock_units_per_purchase_unit', e.target.value)}
                            placeholder={isRecipe ? '1.1' : '1.0905'}
                        />
                        <InputError message={errors.stock_units_per_purchase_unit} />
                    </div>

                    {/* ⚠ A recipe row is ONE direction, stated above. No reciprocal is asked for: the
                        real mustard-oil pair (1.1 and 0.9) is not reciprocal and must not be "checked". */}
                    {!isRecipe && (
                        <div className="grid gap-2">
                            <Label htmlFor="purchase_units_per_stock_unit" required>
                                And one {product?.stock_unit ?? 'stock unit'} is how many {fromUnit?.abbreviation ?? 'purchase units'}?
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
                    )}

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
                        {!isRecipe && bothTyped && (
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
                            <th className="py-2">{t('units.purpose')}</th>
                            <th className="py-2">From</th>
                            <th className="py-2">Stocked in</th>
                            <th className="py-2 text-right">One unit becomes</th>
                            <th className="py-2">Applies from</th>
                            <th className="py-2">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        {conversions.map((c) => (
                            <tr key={c.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">
                                    <span className="font-mono text-xs">{c.sku}</span> {c.product}
                                </td>
                                <td className="py-2">{t(`units.purpose.${c.purpose}`)}</td>
                                <td className="py-2">
                                    {c.from_unit} <span className="text-muted-foreground text-xs">({dimensionLabel(c.from_dimension)})</span>
                                </td>
                                <td className="py-2">
                                    {c.to_unit} <span className="text-muted-foreground text-xs">({dimensionLabel(c.to_dimension)})</span>
                                </td>
                                <td className="py-2 text-right tabular-nums">
                                    1 {c.from_unit} = {c.stock_units_per_purchase_unit} {c.to_unit}
                                </td>
                                <td className="py-2">{showDate(c.effective_from)}</td>
                                <td className="text-muted-foreground py-2">{c.source}</td>
                            </tr>
                        ))}
                        {conversions.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
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
