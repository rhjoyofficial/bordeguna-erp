import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Line {
    [key: string]: string | undefined;
    product_id: string;
    quantity: string;
    unit_cost: string;
    batch_number: string;
    expiry_date: string;
}

interface OpeningStock {
    id: number;
    as_at: string;
    note: string | null;
    warehouse: { id: number; name: string } | null;
    lines: {
        id: number;
        quantity: string;
        unit_cost: string;
        batch_number: string | null;
        expiry_date: string | null;
        product: { id: number; name: string; sku: string } | null;
    }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Opening stock', href: '/inventory/opening-stock' },
];

const emptyLine = (): Line => ({ product_id: '', quantity: '', unit_cost: '', batch_number: '', expiry_date: '' });

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function OpeningStockIndex({
    openingStocks,
    warehouses,
    products,
    countSheetWarehouses,
}: {
    openingStocks: OpeningStock[];
    warehouses: { id: number; name: string; code: string }[];
    products: { id: number; name: string; sku: string }[];
    countSheetWarehouses: { id: number; name: string; code: string }[];
}) {
    // Count-sheet picker. Deliberately NOT part of the posting form: it writes
    // nothing, and sharing the form's state would let a stray warehouse change
    // made while printing follow through into the document that posts.
    const [sheetWarehouse, setSheetWarehouse] = useState(String(countSheetWarehouses[0]?.id ?? ''));
    const [sheetDate, setSheetDate] = useState(new Date().toISOString().slice(0, 10));
    const sheetUrl = (format: 'print' | 'csv') =>
        `/inventory/opening-stock/count-sheet?warehouse=${sheetWarehouse}&as_at=${sheetDate}&format=${format}`;

    const { data, setData, post, errors, processing, reset } = useForm({
        warehouse_id: String(warehouses[0]?.id ?? ''),
        as_at: new Date().toISOString().slice(0, 10),
        note: '',
        lines: [emptyLine()] as Line[],
    });

    const setLine = (index: number, patch: Partial<Line>) => {
        const lines = [...data.lines];
        lines[index] = { ...lines[index], ...patch };
        setData('lines', lines);
    };

    const total = data.lines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0), 0);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.opening-stock.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Opening Stock" />
            <div className="flex flex-col gap-6 p-4">
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                    <p className="font-medium">What the business already held on the day the books start.</p>
                    <p className="text-muted-foreground mt-1">
                        This posts <span className="font-medium">Stock on Hand</span> against{' '}
                        <span className="font-medium">Opening Balance Equity</span>. The unit costs entered here become the cost layers FIFO consumes,
                        so they decide the profit on every sale until this stock is sold through. It can be entered{' '}
                        <span className="font-medium">once per warehouse</span>; afterwards, corrections go through an inventory adjustment.
                    </p>
                </div>

                {countSheetWarehouses.length > 0 && (
                    <div className="rounded-lg border p-4">
                        <p className="text-sm font-medium">Count sheet</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Print a blank sheet to carry around the warehouse, then type the counted numbers back into the matching CSV. Nothing is
                            pre-filled: no quantity and no cost, not even a zero. The SKUs and the warehouse code are printed for you because the
                            import matches on exactly those, and a hand-typed SKU is the mistake this sheet exists to prevent.
                        </p>
                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="sheet_warehouse">Warehouse</Label>
                                <SearchSelect fit id="sheet_warehouse" value={sheetWarehouse} onChange={(e) => setSheetWarehouse(e.target.value)}>
                                    {countSheetWarehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </SearchSelect>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="sheet_date">Count as at</Label>
                                <Input id="sheet_date" type="date" value={sheetDate} onChange={(e) => setSheetDate(e.target.value)} />
                            </div>
                            <Button asChild variant="outline">
                                <a href={sheetUrl('print')} target="_blank" rel="noopener">
                                    Print count sheet
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a href={sheetUrl('csv')}>Download CSV template</a>
                            </Button>
                        </div>
                        {/*
                            ⚠ The way in to the importer. Three screens have shipped
                            unreachable on this project; a page linked from nowhere is
                            a page nobody has.
                        */}
                        <p className="text-muted-foreground mt-3 text-sm">
                            Counted it already?{' '}
                            <Link className="underline underline-offset-2" href={route('inventory.opening-stock.import')}>
                                Upload the filled CSV
                            </Link>{' '}
                            instead of typing it in below. Same document, same rules, with the whole file previewed before anything is written.
                        </p>
                    </div>
                )}

                {warehouses.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border p-6 text-center">
                        Every active warehouse already has its opening stock recorded.
                    </div>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="warehouse_id">Warehouse</Label>
                                <SearchSelect
                                    fit
                                    id="warehouse_id"
                                    value={data.warehouse_id}
                                    onChange={(e) => setData('warehouse_id', e.target.value)}
                                >
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.warehouse_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="as_at">As at</Label>
                                <Input id="as_at" type="date" value={data.as_at} onChange={(e) => setData('as_at', e.target.value)} />
                                <InputError message={errors.as_at} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="note">Note</Label>
                                <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </div>
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Product</th>
                                        <th className="w-28 p-3">Quantity</th>
                                        <th className="w-32 p-3">Unit cost</th>
                                        <th className="w-36 p-3">Batch</th>
                                        <th className="w-40 p-3">Expiry</th>
                                        <th className="w-32 p-3 text-right">Value</th>
                                        <th className="w-16 p-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.lines.map((line, index) => (
                                        <tr key={index} className="border-t align-top">
                                            <td className="p-3">
                                                <SearchSelect
                                                    fit
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
                                            <td className="p-3">
                                                <Input value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} />
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input value={line.unit_cost} onChange={(e) => setLine(index, { unit_cost: e.target.value })} />
                                                <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input value={line.batch_number} onChange={(e) => setLine(index, { batch_number: e.target.value })} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    type="date"
                                                    value={line.expiry_date}
                                                    onChange={(e) => setLine(index, { expiry_date: e.target.value })}
                                                />
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {money((Number(line.quantity) || 0) * (Number(line.unit_cost) || 0))}
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

                        {/* C-INV-E2. The form-level refusals of the one write that
                            matters most: an empty count, a closed month, a future
                            date, a missing account mapping. */}
                        <InputError message={(errors as Record<string, string>).lines} />

                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <Button type="button" variant="outline" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                                Add line
                            </Button>
                            <div className="text-right">
                                <p className="text-muted-foreground text-sm">Total opening value</p>
                                <p className="text-2xl font-semibold tabular-nums">{money(total)}</p>
                            </div>
                        </div>

                        <div>
                            <Button type="submit" disabled={processing} size="lg">
                                Record opening stock
                            </Button>
                        </div>
                    </form>
                )}

                {openingStocks.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Already recorded</h2>
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Warehouse</th>
                                    <th className="p-3">As at</th>
                                    <th className="p-3 text-right">Lines</th>
                                    <th className="p-3">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {openingStocks.map((opening) => (
                                    <tr key={opening.id} className="border-t">
                                        <td className="p-3 font-medium">{opening.warehouse?.name ?? '-'}</td>
                                        <td className="p-3">{opening.as_at?.slice(0, 10)}</td>
                                        <td className="p-3 text-right tabular-nums">{opening.lines.length}</td>
                                        <td className="text-muted-foreground p-3">{opening.note}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
