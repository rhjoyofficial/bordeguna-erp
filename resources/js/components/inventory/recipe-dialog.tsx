import { DateWords } from '@/components/form/date-words';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dhakaToday } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity, plainNumber } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

/** A unit a line for this component may be written in, with a PREVIEW factor (stock units per one of it). */
export interface RecipeUnitOption {
    id: number;
    abbreviation: string;
    name: string;
    factor: string;
}

export interface RecipeProductOption {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
    stock_unit_id?: number;
    /** Phase 1 Part B: only units the server can convert into the stock unit. The stock unit is always first. */
    units?: RecipeUnitOption[];
    /** F10: false for packaging (jars, lids, labels), which is bought, never made. */
    can_be_made?: boolean;
    /** ⭐ Initial plan B5 (24 Oct 2026): its section, and its rate per STOCK unit with which figure that is. */
    is_packaging?: boolean;
    unit_rate?: string | null;
    rate_basis?: 'latest_batch' | null;
    /** Q20: a labelled hint only. */
    on_hand_average?: string | null;
}

/** The recipe being corrected, as BillOfMaterialController::index() sends it. */
export interface DialogRecipe {
    id: number;
    output_product_id: number;
    output_sku: string | null;
    output_quantity: string;
    version: number;
    name: string | null;
    effective_from: string | null;
    note: string | null;
    runs: number;
    lines: {
        component_product_id: number;
        quantity_per_output: string;
        entered_quantity?: string;
        entered_unit_id?: number | null;
        wastage_percent: string;
        note: string | null;
    }[];
}

interface FormLine {
    [key: string]: string | undefined;
    product_id: string;
    quantity_per_output: string;
    unit_id: string;
    wastage_percent: string;
    note: string;
}

const emptyLine = (): FormLine => ({ product_id: '', quantity_per_output: '', unit_id: '', wastage_percent: '', note: '' });

const blank = () => ({
    output_product_id: '',
    name: '',
    output_quantity: '1',
    // ⭐ B5 (24 Oct 2026): "In use from" is the first box and starts at today.
    effective_from: dhakaToday(),
    note: '',
    lines: [emptyLine()] as FormLine[],
});

function fromRecipe(bom: DialogRecipe): ReturnType<typeof blank> {
    return {
        output_product_id: String(bom.output_product_id),
        name: bom.name ?? '',
        // "1.0000" as stored reads "1" in the box (practice run F13).
        output_quantity: plainNumber(bom.output_quantity),
        effective_from: bom.effective_from ?? '',
        note: bom.note ?? '',
        lines: bom.lines.map((line) => ({
            product_id: String(line.component_product_id),
            // What was typed, in the unit it was typed in; the server keeps the stock figure beside it.
            quantity_per_output: plainNumber(line.entered_quantity ?? line.quantity_per_output),
            unit_id: line.entered_unit_id ? String(line.entered_unit_id) : '',
            wastage_percent: Number(line.wastage_percent) > 0 ? plainNumber(line.wastage_percent) : '',
            note: line.note ?? '',
        })),
    };
}

/** The stock-unit figure a typed quantity becomes, for display only; the server converts again on save. */
function previewInStock(quantity: string, unit: RecipeUnitOption | undefined): string | null {
    const typed = Number(quantity);

    if (!unit || !Number.isFinite(typed) || typed <= 0) {
        return null;
    }

    return formatQuantity((Math.round(typed * Number(unit.factor) * 10000) / 10000).toFixed(4));
}

const taka = (value: number) => `৳${value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * ⭐ Initial plan B5 (24 Oct 2026): what one line costs as it is typed, for
 * the popup's PREVIEW: the typed quantity in stock units, plus wastage, times
 * the component's rate per stock unit, to the paisa. Null when the item has
 * no rate yet. The saved recipe is costed again by the server (RecipeCosting).
 */
function previewLineCost(quantity: string, wastage: string, unit: RecipeUnitOption | undefined, rate: string | null | undefined): number | null {
    const typed = Number(quantity);

    if (!unit || rate === null || rate === undefined || !Number.isFinite(typed) || typed <= 0) {
        return null;
    }

    const stock = typed * Number(unit.factor) * (1 + (Number(wastage) || 0) / 100);

    return Math.round(stock * Number(rate) * 100) / 100;
}

/**
 * Write a recipe or correct one in a wide popup over the list (Joy, 14 Oct
 * 2026: "click add button pop up will add, same when edit"). The form that sat
 * open above the recipes, and that Edit scrolled back up to, is gone.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ Whether a save edits this version or
 * writes the next one is the server's decision (a run was built from it or
 * not); the popup only says so before the button is pressed.
 *
 * ⭐ Phase 1 Part B (17 Oct 2026): each line has a unit picker beside the
 * quantity. It defaults to the component's stock unit and offers ONLY the
 * units the server can convert into it (g and kg, mL and L by the metric
 * factors; anything else through a recipe conversion recorded for that
 * product). The "= ... of stock" caption is a preview; the server converts
 * again on save and refuses what it cannot convert.
 */
export function RecipeDialog({
    open,
    onOpenChange,
    bom,
    products,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    bom: DialogRecipe | null;
    products: RecipeProductOption[];
}) {
    const editing = bom !== null;
    const form = useForm(blank());
    const { data, setData, errors, processing, reset, clearErrors, setDefaults } = form;
    const { t } = useTranslation();

    // Fresh fields every time it opens: one empty line for a new recipe, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = bom ? fromRecipe(bom) : blank();
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, bom]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const productById = new Map(products.map((p) => [String(p.id), p]));
    const outputUnit = productById.get(data.output_product_id)?.unit ?? '';

    const unitsFor = (productId: string): RecipeUnitOption[] => productById.get(productId)?.units ?? [];
    const unitFor = (line: FormLine): RecipeUnitOption | undefined => {
        const options = unitsFor(line.product_id);

        return options.find((u) => String(u.id) === line.unit_id) ?? options[0];
    };

    const setLine = (index: number, patch: Partial<FormLine>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    // ⭐ B5: the live subtotals by section; a line with a component but no rate is counted as missing.
    const costs = data.lines.reduce(
        (sum, line) => {
            const component = productById.get(line.product_id);

            if (!component) return sum;

            const cost = previewLineCost(line.quantity_per_output, line.wastage_percent, unitFor(line), component.unit_rate);

            if (cost === null) {
                return component.unit_rate ? sum : { ...sum, missing: sum.missing + 1 };
            }

            return component.is_packaging ? { ...sum, packaging: sum.packaging + cost } : { ...sum, raw: sum.raw + cost };
        },
        { raw: 0, packaging: 0, missing: 0 },
    );

    // A new component starts in its own stock unit (the first option).
    const chooseComponent = (index: number, productId: string) => {
        const stockUnit = unitsFor(productId)[0];
        setLine(index, { product_id: productId, unit_id: stockUnit ? String(stockUnit.id) : '' });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        form.transform((current) => ({
            ...current,
            name: current.name || null,
            effective_from: current.effective_from || null,
            note: current.note || null,
            lines: current.lines.map((line) => ({
                ...line,
                unit_id: line.unit_id || null,
                wastage_percent: line.wastage_percent || '0',
                note: line.note || null,
            })),
        }));

        if (bom) {
            form.put(route('inventory.bills-of-material.update', bom.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        form.post(route('inventory.bills-of-material.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl" aria-describedby={undefined} dirty={form.isDirty}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${bom.output_sku} v${bom.version}` : 'New recipe'}</DialogTitle>
                        {editing && bom.runs > 0 && (
                            <p className="text-sm text-amber-600">
                                {bom.runs} run(s) were built from this version, so saving writes v{bom.version + 1} and leaves this one alone.
                            </p>
                        )}
                    </DialogHeader>

                    <FormRow>
                        {/* ⭐ B5 (24 Oct 2026): the date box first (Joy, 19 Oct). */}
                        <Field label={t('ipc.recipe.in_use_from')} htmlFor="effective_from" error={errors.effective_from}>
                            <Input
                                id="effective_from"
                                type="date"
                                className="w-auto"
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                            <DateWords value={data.effective_from} />
                        </Field>
                        {/* ⚠ Locked on an edit: a recipe is the recipe OF one product (MFC-008), and the server refuses another. */}
                        <Field label="Product being made" htmlFor="output_product_id" error={errors.output_product_id} required>
                            <SearchSelect
                                fit
                                id="output_product_id"
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                                disabled={editing}
                                searchThreshold={1}
                            >
                                <option value="">Choose the product or in-between item</option>
                                {/* F10: never packaging; the product already chosen stays listed on an edit. */}
                                {products
                                    .filter((p) => p.can_be_made !== false || String(p.id) === data.output_product_id)
                                    .map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} ({p.sku})
                                        </option>
                                    ))}
                            </SearchSelect>
                        </Field>
                        <Field
                            label={`Makes how many ${outputUnit}`}
                            htmlFor="output_quantity"
                            className="w-44"
                            error={errors.output_quantity}
                            required
                        >
                            <Input
                                id="output_quantity"
                                aria-required
                                value={data.output_quantity}
                                onChange={(e) => setData('output_quantity', e.target.value)}
                                placeholder="1"
                            />
                        </Field>
                        <Field label="Name" htmlFor="name" size="md" error={errors.name}>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="optional" />
                        </Field>
                    </FormRow>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">
                                        Component
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-36 p-3">
                                        Quantity per {data.output_quantity || '1'} {outputUnit}
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-28 p-3">{t('units.recipe.unit')}</th>
                                    <th className="w-28 p-3">Wastage %</th>
                                    <th className="p-3 text-right whitespace-nowrap">{t('ipc.recipe.rate')}</th>
                                    <th className="p-3 text-right whitespace-nowrap">{t('ipc.recipe.line_total')}</th>
                                    <th className="p-3">Note</th>
                                    <th className="w-16 p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => {
                                    const unit = unitFor(line);
                                    const component = productById.get(line.product_id);
                                    const converted = unit && component && String(unit.id) !== String(component.stock_unit_id ?? '');
                                    const preview = converted ? previewInStock(line.quantity_per_output, unit) : null;
                                    const cost = previewLineCost(line.quantity_per_output, line.wastage_percent, unit, component?.unit_rate);

                                    return (
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <SearchSelect
                                                    className={`${selectClass} w-full`}
                                                    value={line.product_id}
                                                    onChange={(e) => chooseComponent(index, e.target.value)}
                                                    searchThreshold={1}
                                                >
                                                    <option value="">Choose a component</option>
                                                    {/* F10: a product is never a component of itself. */}
                                                    {products
                                                        .filter((p) => String(p.id) !== data.output_product_id)
                                                        .map((p) => (
                                                            <option key={p.id} value={p.id}>
                                                                {p.name} ({p.sku})
                                                            </option>
                                                        ))}
                                                </SearchSelect>
                                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-required
                                                    value={line.quantity_per_output}
                                                    onChange={(e) => setLine(index, { quantity_per_output: e.target.value })}
                                                />
                                                {preview !== null && (
                                                    <p className="text-muted-foreground mt-1 text-xs tabular-nums">
                                                        {t('units.recipe.in_stock', { quantity: preview, unit: component?.unit ?? '' })}
                                                    </p>
                                                )}
                                                <InputError message={errors[`lines.${index}.quantity_per_output` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <select
                                                    className={selectClass}
                                                    aria-label={t('units.recipe.unit')}
                                                    title={t('units.recipe.unit_hint')}
                                                    value={unit ? String(unit.id) : ''}
                                                    disabled={!line.product_id}
                                                    onChange={(e) => setLine(index, { unit_id: e.target.value })}
                                                >
                                                    {unitsFor(line.product_id).map((u) => (
                                                        <option key={u.id} value={u.id}>
                                                            {u.abbreviation}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={errors[`lines.${index}.unit_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    value={line.wastage_percent}
                                                    onChange={(e) => setLine(index, { wastage_percent: e.target.value })}
                                                    placeholder="0"
                                                />
                                                <InputError message={errors[`lines.${index}.wastage_percent` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3 text-right text-xs whitespace-nowrap tabular-nums">
                                                {component === undefined ? null : component.unit_rate ? (
                                                    <>
                                                        {taka(Number(component.unit_rate))} {t('ipc.recipe.per', { unit: component.unit ?? '' })}
                                                        {component.rate_basis && (
                                                            <span className="text-muted-foreground block">
                                                                {t(`ipc.recipe.basis.${component.rate_basis}`)}
                                                            </span>
                                                        )}
                                                        {component.on_hand_average && component.on_hand_average !== component.unit_rate && (
                                                            <span className="text-muted-foreground block">
                                                                {t('ipc.recipe.average_hint', { cost: taka(Number(component.on_hand_average)) })}
                                                            </span>
                                                        )}
                                                        {component.is_packaging && (
                                                            <span className="text-muted-foreground block">{t('ipc.packaging')}</span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground">{t('ipc.recipe.no_rate')}</span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right font-medium whitespace-nowrap tabular-nums">
                                                {cost === null ? '-' : taka(cost)}
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    value={line.note}
                                                    onChange={(e) => setLine(index, { note: e.target.value })}
                                                    placeholder="optional"
                                                />
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

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                            Add component
                        </Button>
                        <p className="text-muted-foreground max-w-2xl text-xs">{t('units.recipe.unit_hint')}</p>
                    </div>

                    {/* ⭐ B5 (24 Oct 2026; R26): the subtotals as he wrote them, live while typing. */}
                    <div className="grid w-fit gap-1 text-sm tabular-nums">
                        <p>
                            {t('ipc.recipe.subtotal', { section: t('ipc.recipe.raw') })}: {taka(costs.raw)}
                        </p>
                        <p>
                            {t('ipc.recipe.subtotal', { section: t('ipc.recipe.packaging') })}: {taka(costs.packaging)}
                        </p>
                        <p className="font-semibold">
                            {t('ipc.recipe.direct')}: {taka(costs.raw + costs.packaging)}
                        </p>
                        {Number(data.output_quantity) > 0 && Number(data.output_quantity) !== 1 && (
                            <p className="text-muted-foreground text-xs">
                                {t('ipc.recipe.per_unit', { unit: outputUnit })}:{' '}
                                {taka(Math.round(((costs.raw + costs.packaging) / Number(data.output_quantity)) * 100) / 100)}
                            </p>
                        )}
                        {costs.missing > 0 && (
                            <p className="text-xs text-amber-700 dark:text-amber-400">{t('ipc.recipe.incomplete', { count: costs.missing })}</p>
                        )}
                        <p className="text-muted-foreground max-w-2xl text-xs">{t('ipc.recipe.estimate_note')}</p>
                    </div>

                    <FormRow>
                        <Field label="Note" htmlFor="note" size="grow" error={errors.note}>
                            <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                        </Field>
                    </FormRow>

                    <InputError message={(errors as Record<string, string>).lines} />

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {editing ? (bom.runs > 0 ? `Save as v${bom.version + 1}` : 'Save changes') : 'Save recipe'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
