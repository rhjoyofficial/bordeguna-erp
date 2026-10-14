import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Line {
    [key: string]: string | undefined;
    product_id: string;
    quantity: string;
    waste: string;
}

interface ProductOption {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
}

interface Bom {
    id: number;
    version: number;
    name: string | null;
    output_quantity: string;
    lines: { product_id: number; quantity_per_output: string; wastage_percent: string }[];
}

/**
 * ⚠⚠ C-BOM-3. A SUGGESTION, NEVER A DECISION. Everything computed here lands in
 * an editable box; the run records what the operator says was actually
 * consumed, and the server recomputes the STANDARD it is measured against from
 * the recipe itself (C-BOM-2). So this arithmetic saves typing and is never the
 * record.
 *
 * ⚠ C-BOM-4. No unit conversion. The number that comes out is in the
 * component's own stock unit, because that is the unit the recipe was written
 * in.
 */
const suggestQuantity = (line: Bom['lines'][number], bom: Bom, outputQuantity: number): string => {
    const recipeOutput = Number(bom.output_quantity) || 0;

    if (recipeOutput <= 0) {
        return '';
    }

    const perUnit = Number(line.quantity_per_output) / recipeOutput;
    const withWastage = perUnit * outputQuantity * (1 + Number(line.wastage_percent) / 100);

    // Four places, the scale a quantity column holds. Mirrors BomSuggestion.
    return String(Math.round((withWastage + Number.EPSILON) * 10000) / 10000);
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Production', href: '/inventory/production-runs' },
    { title: 'New run', href: '/inventory/production-runs/new' },
];

const emptyLine = (): Line => ({ product_id: '', quantity: '', waste: '' });

export default function ProductionRunCreate({
    warehouses,
    products,
    stockByProduct,
    bomsByOutputProduct,
}: {
    warehouses: { id: number; name: string; code: string }[];
    products: ProductOption[];
    stockByProduct: Record<string, Record<string, string>>;
    bomsByOutputProduct: Record<string, Bom>;
}) {
    const { data, setData, post, errors, processing, transform } = useForm({
        warehouse_id: String(warehouses[0]?.id ?? ''),
        output_product_id: '',
        output_quantity: '',
        bill_of_material_id: '',
        output_expiry_date: '',
        output_mrp: '',
        occurred_at: dhakaToday(),
        expense_waste: false as boolean,
        note: '',
        confirm: true as boolean,
        lines: [emptyLine()] as Line[],
    });

    const productById = new Map(products.map((p) => [String(p.id), p]));
    const available = (productId: string) => stockByProduct[productId]?.[data.warehouse_id] ?? '0';

    const bom: Bom | undefined = bomsByOutputProduct[data.output_product_id];

    // What the recipe last wrote into the table. Used to tell "the operator has
    // not touched these" from "the operator has edited them", so re-scaling on
    // a changed output quantity never silently discards typed numbers.
    const lastSuggested = useRef<string | null>(null);
    const [recipeEdited, setRecipeEdited] = useState(false);

    const recipeLines = (forBom: Bom, quantity: number): Line[] =>
        forBom.lines.map((line) => ({
            product_id: String(line.product_id),
            quantity: suggestQuantity(line, forBom, quantity),
            waste: '',
        }));

    const applyRecipe = (forBom: Bom, quantity: number) => {
        const lines = recipeLines(forBom, quantity);
        lastSuggested.current = JSON.stringify(lines);
        setRecipeEdited(false);
        setData((current) => ({ ...current, bill_of_material_id: String(forBom.id), lines }));
    };

    // C-BOM-3. Pre-fill on choosing the output, and re-scale when the output
    // quantity changes - but ONLY while the table still holds exactly what the
    // recipe last put there.
    useEffect(() => {
        if (!bom) {
            if (data.bill_of_material_id !== '') {
                setData('bill_of_material_id', '');
            }

            return;
        }

        const quantity = Number(data.output_quantity);

        if (!Number.isFinite(quantity) || quantity <= 0) {
            return;
        }

        const blank = data.lines.every((line) => line.product_id === '' && line.quantity === '');
        const untouched = lastSuggested.current !== null && JSON.stringify(data.lines) === lastSuggested.current;

        if (blank || untouched) {
            applyRecipe(bom, quantity);
        } else {
            setRecipeEdited(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.output_product_id, data.output_quantity]);

    const setLine = (index: number, patch: Partial<Line>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((form) => ({
            ...form,
            output_expiry_date: form.output_expiry_date || null,
            output_mrp: form.output_mrp || null,
            note: form.note || null,
            bill_of_material_id: form.bill_of_material_id || null,
            lines: form.lines.map((line) => ({ ...line, waste: line.waste || '0' })),
        }));
        post(route('inventory.production-runs.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Production Run" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="warehouse_id" required>
                                Warehouse
                            </Label>
                            <SearchSelect id="warehouse_id" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)}>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name} ({w.code})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.warehouse_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="occurred_at" required>
                                Date of the work
                            </Label>
                            <Input
                                id="occurred_at"
                                aria-required
                                type="date"
                                // MFC-003 (14 Oct 2026): no day that has not come yet.
                                max={dhakaToday()}
                                value={data.occurred_at}
                                onChange={(e) => setData('occurred_at', e.target.value)}
                            />
                            <InputError message={errors.occurred_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="note">Note</Label>
                            <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                        </div>
                    </div>

                    {bom && (
                        <div className="rounded-lg border p-3 text-sm">
                            <p className="font-medium">
                                Recipe v{bom.version}
                                {bom.name ? `, ${bom.name}` : ''} is set for this product.
                            </p>
                            <p className="text-muted-foreground mt-1">
                                {recipeEdited
                                    ? 'The lines below have been edited, so they are left alone. The run records what you say was consumed; the recipe only saves typing.'
                                    : 'The lines below are filled in from it and scale with the output quantity. Change, remove or add any of them: the run records what you say was consumed.'}
                            </p>
                            {Number(data.output_quantity) > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => applyRecipe(bom, Number(data.output_quantity))}
                                >
                                    {recipeEdited ? 'Replace my lines with the recipe' : 'Re-apply the recipe'}
                                </Button>
                            )}
                        </div>
                    )}

                    <h2 className="mt-2 font-medium">Inputs consumed</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">
                                        Product
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-32 p-3">Available</th>
                                    <th className="w-36 p-3">
                                        Consumed (incl. waste)
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-32 p-3">Of which waste</th>
                                    <th className="w-16 p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => {
                                    const product = productById.get(line.product_id);

                                    return (
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <SearchSelect
                                                    className={`${selectClass} w-full`}
                                                    value={line.product_id}
                                                    onChange={(e) => setLine(index, { product_id: e.target.value })}
                                                >
                                                    <option value="">Choose a product</option>
                                                    {products.map((p) => (
                                                        <option key={p.id} value={p.id}>
                                                            {p.name} ({p.sku})
                                                        </option>
                                                    ))}
                                                </SearchSelect>
                                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="text-muted-foreground p-3 tabular-nums">
                                                {line.product_id ? `${available(line.product_id)} ${product?.unit ?? ''}` : '-'}
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-required
                                                    value={line.quantity}
                                                    onChange={(e) => setLine(index, { quantity: e.target.value })}
                                                />
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    value={line.waste}
                                                    onChange={(e) => setLine(index, { waste: e.target.value })}
                                                    placeholder="0"
                                                />
                                                <InputError message={errors[`lines.${index}.waste` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                {data.lines.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setData(
                                                                'lines',
                                                                data.lines.filter((_, i) => i !== index),
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                            Add input line
                        </Button>
                    </div>

                    <h2 className="mt-2 font-medium">Output produced</h2>
                    <div className="grid gap-4 md:grid-cols-5">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="output_product_id" required>
                                Product
                            </Label>
                            <SearchSelect
                                id="output_product_id"
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                            >
                                <option value="">Choose the finished product</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.sku})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.output_product_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_quantity" required>
                                Quantity{data.output_product_id ? ` (${productById.get(data.output_product_id)?.unit ?? ''})` : ''}
                            </Label>
                            <Input
                                id="output_quantity"
                                aria-required
                                value={data.output_quantity}
                                onChange={(e) => setData('output_quantity', e.target.value)}
                            />
                            <InputError message={errors.output_quantity} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_expiry_date">Expiry (EXP)</Label>
                            <Input
                                id="output_expiry_date"
                                type="date"
                                value={data.output_expiry_date}
                                onChange={(e) => setData('output_expiry_date', e.target.value)}
                            />
                            <InputError message={errors.output_expiry_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_mrp">MRP (label only)</Label>
                            <Input
                                id="output_mrp"
                                value={data.output_mrp}
                                onChange={(e) => setData('output_mrp', e.target.value)}
                                placeholder="optional"
                            />
                            <InputError message={errors.output_mrp} />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="expense_waste"
                            checked={data.expense_waste}
                            onCheckedChange={(checked) => setData('expense_waste', checked === true)}
                        />
                        <Label htmlFor="expense_waste" className="font-normal">
                            Expense the waste to the loss account (abnormal events only; normal waste stays in the pack cost)
                        </Label>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox id="confirm" checked={data.confirm} onCheckedChange={(checked) => setData('confirm', checked === true)} />
                        <Label htmlFor="confirm" className="font-normal">
                            Confirm immediately (uncheck to save as a draft)
                        </Label>
                    </div>

                    <InputError message={(errors as Record<string, string>).run} />
                    <InputError message={(errors as Record<string, string>).lines} />

                    <div>
                        <Button type="submit" disabled={processing} size="lg">
                            {data.confirm ? 'Record and confirm run' : 'Save draft'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
