import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

export interface RecipeProductOption {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
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
        wastage_percent: string;
        note: string | null;
    }[];
}

interface FormLine {
    [key: string]: string | undefined;
    product_id: string;
    quantity_per_output: string;
    wastage_percent: string;
    note: string;
}

const emptyLine = (): FormLine => ({ product_id: '', quantity_per_output: '', wastage_percent: '', note: '' });

const blank = () => ({
    output_product_id: '',
    name: '',
    output_quantity: '1',
    effective_from: '',
    note: '',
    lines: [emptyLine()] as FormLine[],
});

function fromRecipe(bom: DialogRecipe): ReturnType<typeof blank> {
    return {
        output_product_id: String(bom.output_product_id),
        name: bom.name ?? '',
        output_quantity: bom.output_quantity,
        effective_from: bom.effective_from ?? '',
        note: bom.note ?? '',
        lines: bom.lines.map((line) => ({
            product_id: String(line.component_product_id),
            quantity_per_output: line.quantity_per_output,
            wastage_percent: line.wastage_percent,
            note: line.note ?? '',
        })),
    };
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

    const setLine = (index: number, patch: Partial<FormLine>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        form.transform((current) => ({
            ...current,
            name: current.name || null,
            effective_from: current.effective_from || null,
            note: current.note || null,
            lines: current.lines.map((line) => ({ ...line, wastage_percent: line.wastage_percent || '0', note: line.note || null })),
        }));

        if (bom) {
            form.put(route('inventory.bills-of-material.update', bom.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        form.post(route('inventory.bills-of-material.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-5xl" aria-describedby={undefined}>
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
                        {/* ⚠ Locked on an edit: a recipe is the recipe OF one product (MFC-008), and the server refuses another. */}
                        <Field label="Product being made" htmlFor="output_product_id" error={errors.output_product_id} required>
                            <SearchSelect
                                fit
                                id="output_product_id"
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                                disabled={editing}
                            >
                                <option value="">Choose the finished product</option>
                                {products.map((p) => (
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
                                    <th className="w-40 p-3">
                                        Quantity per {data.output_quantity || '1'} {outputUnit}
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
                                    <th className="w-32 p-3">Wastage %</th>
                                    <th className="p-3">Note</th>
                                    <th className="w-16 p-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => (
                                    <tr key={index} className="border-t align-top">
                                        <td className="p-3">
                                            <SearchSelect
                                                className={`${selectClass} w-full`}
                                                value={line.product_id}
                                                onChange={(e) => setLine(index, { product_id: e.target.value })}
                                            >
                                                <option value="">Choose a component</option>
                                                {products.map((p) => (
                                                    <option key={p.id} value={p.id}>
                                                        {p.name} ({p.sku})
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                            <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <div className="flex items-center gap-2">
                                                <Input
                                                    aria-required
                                                    value={line.quantity_per_output}
                                                    onChange={(e) => setLine(index, { quantity_per_output: e.target.value })}
                                                />
                                                <span className="text-muted-foreground text-xs">{productById.get(line.product_id)?.unit ?? ''}</span>
                                            </div>
                                            <InputError message={errors[`lines.${index}.quantity_per_output` as keyof typeof errors]} />
                                        </td>
                                        <td className="p-3">
                                            <Input
                                                value={line.wastage_percent}
                                                onChange={(e) => setLine(index, { wastage_percent: e.target.value })}
                                                placeholder="0"
                                            />
                                            <InputError message={errors[`lines.${index}.wastage_percent` as keyof typeof errors]} />
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
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                            Add component
                        </Button>
                    </div>

                    <FormRow>
                        <Field label="In use from" htmlFor="effective_from" error={errors.effective_from}>
                            <Input
                                id="effective_from"
                                type="date"
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                        </Field>
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
