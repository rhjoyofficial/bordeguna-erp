import { DateWords } from '@/components/form/date-words';
import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { recipeStep } from '@/lib/recipe-fill';
import { decimalInput, formatQuantity } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
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
    tracks_expiry: boolean;
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

const breadcrumbs = inventoryCrumbs('Production', 'Runs', { title: 'New run', href: '/inventory/production-runs/new' });

const emptyLine = (): Line => ({ product_id: '', quantity: '', waste: '' });

export default function ProductionRunCreate({
    warehouses,
    defaultWarehouseId,
    products,
    stockByProduct,
    bomsByOutputProduct,
}: {
    warehouses: { id: number; name: string; code: string }[];
    defaultWarehouseId: number | null;
    products: ProductOption[];
    stockByProduct: Record<string, Record<string, string>>;
    bomsByOutputProduct: Record<string, Bom>;
}) {
    const { data, setData, post, errors, processing, transform } = useForm({
        warehouse_id: String(defaultWarehouseId ?? warehouses[0]?.id ?? ''),
        output_product_id: '',
        output_quantity: '',
        bill_of_material_id: '',
        output_expiry_date: '',
        output_mrp: '',
        occurred_at: dhakaToday(),
        expense_waste: false as boolean,
        note: '',
        confirm: true as boolean,
        record_again: false as boolean,
        lines: [emptyLine()] as Line[],
    });
    const { t } = useTranslation();

    const productById = new Map(products.map((p) => [String(p.id), p]));
    const available = (productId: string) => stockByProduct[productId]?.[data.warehouse_id] ?? '0';

    // 17 Oct 2026: the same rule the server refuses by (RecordProductionRun).
    const expiryRequired = productById.get(data.output_product_id)?.tracks_expiry ?? false;

    const bom: Bom | undefined = bomsByOutputProduct[data.output_product_id];

    // P9 (18 Oct 2026): has the operator edited a line by hand? Until then the
    // recipe owns the table (lib/recipe-fill.ts), so re-scaling on a changed
    // output quantity never silently discards typed numbers.
    const linesTouched = useRef(false);
    const [recipeEdited, setRecipeEdited] = useState(false);

    const recipeLines = (forBom: Bom, quantity: number): Line[] =>
        forBom.lines.map((line) => ({
            product_id: String(line.product_id),
            quantity: suggestQuantity(line, forBom, quantity),
            waste: '',
        }));

    // A null quantity lists the recipe's products and leaves the amounts blank.
    const applyRecipe = (forBom: Bom, quantity: number | null) => {
        const lines =
            quantity === null
                ? forBom.lines.map((line) => ({ product_id: String(line.product_id), quantity: '', waste: '' }))
                : recipeLines(forBom, quantity);
        linesTouched.current = false;
        setRecipeEdited(false);
        setData((current) => ({ ...current, bill_of_material_id: String(forBom.id), lines: lines.length > 0 ? lines : [emptyLine()] }));
    };

    // C-BOM-3. Pre-fill on choosing the output, and re-scale when the output
    // quantity changes - but ONLY while no line has been edited by hand.
    useEffect(() => {
        if (!bom) {
            if (data.bill_of_material_id !== '') {
                // The lines came from another product's recipe and nobody edited
                // them: they belong to that product, not this one.
                if (!linesTouched.current) {
                    setData((current) => ({ ...current, bill_of_material_id: '', lines: [emptyLine()] }));
                } else {
                    setData('bill_of_material_id', '');
                }
            }

            return;
        }

        const step = recipeStep({ hasRecipe: true, quantity: data.output_quantity, touched: linesTouched.current });

        if (step === 'fill') {
            applyRecipe(bom, Number(data.output_quantity));
        } else if (step === 'products-only') {
            applyRecipe(bom, null);
        } else {
            setRecipeEdited(true);

            // ⚠ Bug hunt 16 Oct 2026: after the output changed, the typed lines
            // were kept but the OLD output's recipe id was still sent, and the
            // server refused it ("That recipe makes a different product"). The
            // lines are not this recipe's, so no recipe is claimed for them.
            if (data.bill_of_material_id !== '' && data.bill_of_material_id !== String(bom.id)) {
                setData('bill_of_material_id', '');
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.output_product_id, data.output_quantity]);

    // F23 (practice run, 17 Oct 2026): the form starts on the default
    // warehouse, but the raw materials may sit elsewhere (Savar). Which inputs
    // does a warehouse not hold enough of, and which warehouse holds them all?
    const warehouseTouched = useRef(false);
    const inputs = data.lines.filter((line) => line.product_id !== '');
    const shortIn = (warehouseId: string) =>
        inputs.filter((line) => {
            const have = Number(stockByProduct[line.product_id]?.[warehouseId] ?? '0');
            const need = Number(line.quantity);

            return Number.isFinite(need) && need > 0 ? have < need : have <= 0;
        });
    const shortHere = shortIn(data.warehouse_id);
    const holdsAll =
        inputs.length === 0 ? undefined : warehouses.find((w) => String(w.id) !== data.warehouse_id && shortIn(String(w.id)).length === 0);
    const [movedTo, setMovedTo] = useState<string | null>(null);

    // Not yet chosen by hand: move to the warehouse that holds every input.
    useEffect(() => {
        if (!warehouseTouched.current && shortHere.length > 0 && holdsAll) {
            setData('warehouse_id', String(holdsAll.id));
            setMovedTo(holdsAll.name);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [JSON.stringify(inputs), data.warehouse_id]);

    const touchLines = () => {
        linesTouched.current = true;
        if (bom) setRecipeEdited(true);
    };

    const setLine = (index: number, patch: Partial<Line>) => {
        touchLines();
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    const send = (recordAgain: boolean) => {
        transform((form) => ({
            ...form,
            // P8 (18 Oct 2026): true only from the "same run a moment ago" warning.
            record_again: recordAgain,
            output_expiry_date: form.output_expiry_date || null,
            output_mrp: form.output_mrp || null,
            note: form.note || null,
            bill_of_material_id: form.bill_of_material_id || null,
            lines: form.lines.map((line) => ({ ...line, waste: line.waste || '0' })),
        }));
        post(route('inventory.production-runs.store'));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send(false);
    };

    const formErrors = errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New production run" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="warehouse_id" required>
                                Warehouse
                            </Label>
                            <SearchSelect
                                id="warehouse_id"
                                value={data.warehouse_id}
                                onChange={(e) => {
                                    warehouseTouched.current = true;
                                    setMovedTo(null);
                                    setData('warehouse_id', e.target.value);
                                }}
                            >
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name} ({w.code})
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.warehouse_id} />
                            {movedTo !== null && shortHere.length === 0 && (
                                <p className="text-muted-foreground text-xs">Chosen because {movedTo} holds every input.</p>
                            )}
                            {shortHere.length > 0 && (
                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                    Not enough here: {shortHere.map((line) => productById.get(line.product_id)?.name ?? 'item').join(', ')}.
                                    {holdsAll ? (
                                        <>
                                            {' '}
                                            {holdsAll.name} holds them all.{' '}
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={() => {
                                                    warehouseTouched.current = true;
                                                    setData('warehouse_id', String(holdsAll.id));
                                                }}
                                            >
                                                Use {holdsAll.name}
                                            </button>
                                        </>
                                    ) : (
                                        ' No single warehouse holds them all: move stock first, or change the quantities.'
                                    )}
                                </p>
                            )}
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
                            <DateWords value={data.occurred_at} />
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
                            {!(Number(data.output_quantity) > 0) && !recipeEdited && (
                                <p className="text-muted-foreground mt-1">{t('production.recipe.waiting')}</p>
                            )}
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
                    {/* ⭐ Traceability (17 Oct 2026): the shelf and the ledger must take the same tin. */}
                    <p className="text-muted-foreground -mt-2 text-sm">Use the oldest-expiry tin first: the ERP records the batch it picks (FEFO).</p>
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
                                                {line.product_id ? `${formatQuantity(available(line.product_id))} ${product?.unit ?? ''}` : '-'}
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-required
                                                    inputMode="decimal"
                                                    value={line.quantity}
                                                    onChange={(e) => setLine(index, { quantity: decimalInput(e.target.value) })}
                                                />
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    inputMode="decimal"
                                                    value={line.waste}
                                                    onChange={(e) => setLine(index, { waste: decimalInput(e.target.value) })}
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
                                                        onClick={() => {
                                                            touchLines();
                                                            setData(
                                                                'lines',
                                                                data.lines.filter((_, i) => i !== index),
                                                            );
                                                        }}
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                touchLines();
                                setData('lines', [...data.lines, emptyLine()]);
                            }}
                        >
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
                                <option value="">Choose the product or in-between item</option>
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
                                inputMode="decimal"
                                value={data.output_quantity}
                                onChange={(e) => setData('output_quantity', decimalInput(e.target.value))}
                            />
                            <InputError message={errors.output_quantity} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_expiry_date" required={expiryRequired}>
                                Expiry (EXP)
                            </Label>
                            <Input
                                id="output_expiry_date"
                                aria-required={expiryRequired}
                                type="date"
                                value={data.output_expiry_date}
                                onChange={(e) => setData('output_expiry_date', e.target.value)}
                            />
                            <DateWords value={data.output_expiry_date} />
                            <InputError message={errors.output_expiry_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_mrp">MRP (label only)</Label>
                            <Input
                                id="output_mrp"
                                inputMode="decimal"
                                value={data.output_mrp}
                                onChange={(e) => setData('output_mrp', decimalInput(e.target.value))}
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

                    <InputError message={formErrors.run} />
                    <InputError message={formErrors.lines} />
                    {formErrors.repeat && (
                        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 p-3 text-sm dark:border-amber-700">
                            <p className="text-amber-800 dark:text-amber-300">{formErrors.repeat}</p>
                            {formErrors.repeat_run_id && (
                                <Link className="underline" href={route('inventory.production-runs.show', formErrors.repeat_run_id)}>
                                    {t('production.repeat.open')}
                                </Link>
                            )}
                            <Button type="button" variant="outline" size="sm" disabled={processing} onClick={() => send(true)}>
                                {t('production.repeat.again')}
                            </Button>
                        </div>
                    )}

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
