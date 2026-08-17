import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

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
}: {
    warehouses: { id: number; name: string; code: string }[];
    products: ProductOption[];
    stockByProduct: Record<string, Record<string, string>>;
}) {
    const { data, setData, post, errors, processing, transform } = useForm({
        warehouse_id: String(warehouses[0]?.id ?? ''),
        output_product_id: '',
        output_quantity: '',
        output_expiry_date: '',
        output_mrp: '',
        occurred_at: new Date().toISOString().slice(0, 10),
        expense_waste: false as boolean,
        note: '',
        confirm: true as boolean,
        lines: [emptyLine()] as Line[],
    });

    const productById = new Map(products.map((p) => [String(p.id), p]));
    const available = (productId: string) => stockByProduct[productId]?.[data.warehouse_id] ?? '0';

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
            lines: form.lines.map((line) => ({ ...line, waste: line.waste || '0' })),
        }));
        post(route('inventory.production-runs.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Production Run" />
            <div className="flex flex-col gap-6 p-4">
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                    <p className="font-medium">Record what physically happened, after it happened.</p>
                    <p className="mt-1 text-muted-foreground">
                        Enter the quantities actually consumed and actually produced, each in its own unit; the system
                        never converts between units. The output batch will cost exactly what the run consumed (FIFO),
                        divided by the output quantity. Waste is recorded for visibility and absorbed into the cost unless
                        you explicitly expense it. One run produces <span className="font-medium">one</span> output:
                        filling two pack sizes from one drum is two runs.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="warehouse_id">Warehouse</Label>
                            <select
                                id="warehouse_id"
                                className={selectClass}
                                value={data.warehouse_id}
                                onChange={(e) => setData('warehouse_id', e.target.value)}
                            >
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name} ({w.code})
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">
                                Inputs are consumed here and the output lands here. Move packs afterwards with a transfer.
                            </p>
                            <InputError message={errors.warehouse_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="occurred_at">Date of the work</Label>
                            <Input
                                id="occurred_at"
                                type="date"
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

                    <h2 className="mt-2 font-medium">Inputs consumed</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Product</th>
                                    <th className="p-3 w-32">Available</th>
                                    <th className="p-3 w-36">Consumed (incl. waste)</th>
                                    <th className="p-3 w-32">Of which waste</th>
                                    <th className="p-3 w-16"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => {
                                    const product = productById.get(line.product_id);

                                    return (
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <select
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
                                                </select>
                                                <InputError message={errors[`lines.${index}.product_id` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3 tabular-nums text-muted-foreground">
                                                {line.product_id ? `${available(line.product_id)} ${product?.unit ?? ''}` : '-'}
                                            </td>
                                            <td className="p-3">
                                                <Input value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} />
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input value={line.waste} onChange={(e) => setLine(index, { waste: e.target.value })} placeholder="0" />
                                                <InputError message={errors[`lines.${index}.waste` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                {data.lines.length > 1 && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => setData('lines', data.lines.filter((_, i) => i !== index))}
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
                            <Label htmlFor="output_product_id">Product</Label>
                            <select
                                id="output_product_id"
                                className={selectClass}
                                value={data.output_product_id}
                                onChange={(e) => setData('output_product_id', e.target.value)}
                            >
                                <option value="">Choose the finished product</option>
                                {products.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name} ({p.sku})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.output_product_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="output_quantity">
                                Quantity{data.output_product_id ? ` (${productById.get(data.output_product_id)?.unit ?? ''})` : ''}
                            </Label>
                            <Input
                                id="output_quantity"
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
                            <p className="text-xs text-muted-foreground">Never used as the selling price.</p>
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
                            Expense the waste to the loss account (abnormal events only; normal waste stays in the pack
                            cost)
                        </Label>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="confirm"
                            checked={data.confirm}
                            onCheckedChange={(checked) => setData('confirm', checked === true)}
                        />
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
