import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface ProductOption {
    id: number;
    sku: string;
    name: string;
    unit: string | null;
}

interface BomLine {
    component_product_id: number;
    sku: string | null;
    component: string | null;
    unit: string | null;
    component_active: boolean;
    quantity_per_output: string;
    wastage_percent: string;
    note: string | null;
}

interface Bom {
    id: number;
    output_product_id: number;
    output_sku: string | null;
    output_product: string | null;
    output_unit: string | null;
    output_quantity: string;
    version: number;
    name: string | null;
    is_active: boolean;
    effective_from: string | null;
    note: string | null;
    runs: number;
    lines: BomLine[];
}

interface FormLine {
    [key: string]: string | undefined;
    product_id: string;
    quantity_per_output: string;
    wastage_percent: string;
    note: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Recipes (BOM)', href: '/inventory/bills-of-material' },
];

const emptyLine = (): FormLine => ({ product_id: '', quantity_per_output: '', wastage_percent: '', note: '' });

export default function BillsOfMaterialIndex({ boms, products }: { boms: Bom[]; products: ProductOption[] }) {
    const [editing, setEditing] = useState<Bom | null>(null);

    const form = useForm({
        output_product_id: '',
        name: '',
        output_quantity: '1',
        effective_from: '',
        note: '',
        lines: [emptyLine()] as FormLine[],
    });

    const { data, setData, errors, processing, reset, clearErrors } = form;

    const productById = new Map(products.map((p) => [String(p.id), p]));
    const outputUnit = productById.get(data.output_product_id)?.unit ?? '';

    const setLine = (index: number, patch: Partial<FormLine>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    const startNew = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    const startEdit = (bom: Bom) => {
        setEditing(bom);
        clearErrors();
        setData({
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
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
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

        if (editing) {
            form.put(route('inventory.bills-of-material.update', editing.id), {
                onSuccess: () => {
                    setEditing(null);
                    reset();
                },
            });

            return;
        }

        form.post(route('inventory.bills-of-material.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recipes" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Recipes</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        What goes into each packed product, and how much. A recipe is a template: it saves typing on a production run and does nothing
                        else.
                    </p>
                </div>

                {/*
                    ⚠⚠ C-BOM-1. The single most important thing to understand about
                    this screen, said on the screen. Nothing here moves stock or
                    posts anything.
                */}
                <div className="max-w-3xl rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                    <p className="font-medium">Saving a recipe moves no stock and posts nothing.</p>
                    <p className="text-muted-foreground mt-1">
                        It only decides what a production run <span className="font-medium">suggests</span> consuming. Every suggested line stays
                        editable on the run, and the run records what was actually used. Quantities are in each component's own unit and nothing is
                        ever converted. If a unit looks wrong, fix it on the product.
                    </p>
                    {/*
                        ⚠⚠ THE CONSUMABLES RULE. Anything in a recipe can stop a
                        production run, because the run refuses on any short
                        component. This is the sentence that keeps a two-taka
                        sticker from stopping a shipment.
                    */}
                    <p className="text-muted-foreground mt-2">
                        <span className="text-foreground font-medium">Only put something here if running out of it should stop the run.</span> Bulk
                        product, jars, bottles, caps and boxes: yes. Shrink poly, inner poly, pouch film and labels: no, those are expensed when
                        bought, never stocked, and a missing sticker must never hold up a shipment.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4 rounded-lg border p-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-medium">
                            {editing ? `Editing ${editing.output_sku} v${editing.version}` : 'New recipe'}
                            {editing && editing.runs > 0 && (
                                <span className="ml-2 text-sm font-normal text-amber-600">
                                    {editing.runs} run(s) were built from this version, so saving writes v{editing.version + 1} and leaves this one
                                    alone.
                                </span>
                            )}
                        </h2>
                        {editing && (
                            <Button type="button" variant="ghost" size="sm" onClick={startNew}>
                                Cancel and start a new one
                            </Button>
                        )}
                    </div>

                    <div className="grid gap-4 md:grid-cols-4">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="output_product_id">Product being made</Label>
                            <SearchSelect
                                id="output_product_id"
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                                disabled={editing !== null}
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
                            <Label htmlFor="output_quantity">Makes how many {outputUnit}</Label>
                            <Input id="output_quantity" value={data.output_quantity} onChange={(e) => setData('output_quantity', e.target.value)} />
                            <p className="text-muted-foreground text-xs">Usually 1. Say 20 if the recipe was written over a 20 kg drum.</p>
                            <InputError message={errors.output_quantity} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="optional" />
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Component</th>
                                    <th className="w-40 p-3">
                                        Quantity per {data.output_quantity || '1'} {outputUnit}
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

                    <p className="text-muted-foreground text-xs">
                        Wastage inflates the suggested quantity and nothing else. It reaches no ledger and no stock movement: what actually left the
                        warehouse is what the run says it did.
                    </p>

                    <div className="flex flex-wrap items-end gap-3">
                        <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                            Add component
                        </Button>
                        <div className="grid gap-2">
                            <Label htmlFor="effective_from">In use from</Label>
                            <Input
                                id="effective_from"
                                type="date"
                                value={data.effective_from}
                                onChange={(e) => setData('effective_from', e.target.value)}
                            />
                        </div>
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor="note">Note</Label>
                            <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                        </div>
                    </div>

                    <InputError message={(errors as Record<string, string>).lines} />

                    <div>
                        <Button type="submit" disabled={processing}>
                            {editing ? (editing.runs > 0 ? `Save as v${editing.version + 1}` : 'Save changes') : 'Save recipe'}
                        </Button>
                    </div>
                </form>

                <div className="flex flex-col gap-3">
                    {boms.length === 0 && (
                        <div className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                            No recipes yet. The first one worth writing is the pickle packing run.
                        </div>
                    )}

                    {boms.map((bom) => (
                        <div key={bom.id} className={`rounded-lg border ${bom.is_active ? '' : 'opacity-60'}`}>
                            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b p-3">
                                <div>
                                    <p className="font-medium">
                                        {bom.output_product} <span className="text-muted-foreground font-mono text-xs">{bom.output_sku}</span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        v{bom.version}
                                        {bom.name ? ` · ${bom.name}` : ''} · makes {bom.output_quantity} {bom.output_unit}
                                        {bom.effective_from ? ` · from ${bom.effective_from}` : ''}
                                        {bom.runs > 0 ? ` · ${bom.runs} run(s) built from it` : ''}
                                        {bom.is_active ? '' : ' · switched off'}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" onClick={() => startEdit(bom)}>
                                        Edit
                                    </Button>
                                    {bom.is_active ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('inventory.bills-of-material.deactivate', bom.id))}
                                        >
                                            Switch off
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('inventory.bills-of-material.activate', bom.id))}
                                        >
                                            Use this one
                                        </Button>
                                    )}
                                    {/*
                                        ⚠ Offered only for a version no run was built
                                        from. A used version is the answer to "what
                                        recipe was that run built from"; the server
                                        refuses it too.
                                    */}
                                    {bom.runs === 0 && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.delete(route('inventory.bills-of-material.destroy', bom.id))}
                                        >
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <table className="w-full text-sm">
                                <tbody>
                                    {bom.lines.map((line) => (
                                        <tr key={line.component_product_id} className="border-b last:border-0">
                                            <td className="p-3 font-mono text-xs">{line.sku}</td>
                                            <td className="p-3">
                                                {line.component}
                                                {!line.component_active && (
                                                    <span className="ml-2 text-xs text-amber-600">this component is inactive</span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {line.quantity_per_output} {line.unit}
                                            </td>
                                            <td className="text-muted-foreground w-28 p-3 text-right text-xs">
                                                {Number(line.wastage_percent) > 0 ? `+${line.wastage_percent}% wastage` : ''}
                                            </td>
                                            <td className="text-muted-foreground p-3 text-xs">{line.note}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
