import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { BlockedNote, type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
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
    // ⭐ Corrections wave 1 (20 Oct 2026).
    number: string;
    total: string;
    status: 'live' | 'corrected';
    corrected_by: string | null;
    correction_of: string | null;
    actions: StockAvailability[];
}

const breadcrumbs = inventoryCrumbs('Setup', 'Opening stock');

const emptyLine = (): Line => ({ product_id: '', quantity: '', unit_cost: '', batch_number: '', expiry_date: '' });

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function OpeningStockIndex({
    openingStocks,
    warehouses,
    defaultWarehouseId,
    hasWarehouses,
    canManageWarehouses,
    products,
    countSheetWarehouses,
    countSheetDefaultId,
    showCorrections,
}: {
    showCorrections: boolean;
    openingStocks: OpeningStock[];
    warehouses: { id: number; name: string; code: string }[];
    defaultWarehouseId: number | null;
    hasWarehouses: boolean;
    canManageWarehouses: boolean;
    products: { id: number; name: string; sku: string; unit: string | null }[];
    countSheetWarehouses: { id: number; name: string; code: string }[];
    countSheetDefaultId: number | null;
}) {
    // Count-sheet picker. Deliberately NOT part of the posting form: it writes
    // nothing, and sharing the form's state would let a stray warehouse change
    // made while printing follow through into the document that posts.
    const [sheetWarehouse, setSheetWarehouse] = useState(String(countSheetDefaultId ?? countSheetWarehouses[0]?.id ?? ''));
    const [sheetDate, setSheetDate] = useState(dhakaToday());
    const { t } = useTranslation();
    const sheetUrl = (format: 'print' | 'csv') =>
        `/inventory/opening-stock/count-sheet?warehouse=${sheetWarehouse}&as_at=${sheetDate}&format=${format}`;

    const { data, setData, post, errors, processing, reset } = useForm({
        // The default warehouse when it is still waiting, not whichever sorts first.
        warehouse_id: String(defaultWarehouseId ?? warehouses[0]?.id ?? ''),
        as_at: dhakaToday(),
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
            <Head title="Opening stock" />
            <div className="flex flex-col gap-6 p-4">
                {countSheetWarehouses.length > 0 && (
                    <div className="rounded-lg border p-4">
                        <p className="text-sm font-medium">Count sheet</p>
                        <div className="mt-2 flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="sheet_warehouse" required>
                                    Warehouse
                                </Label>
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
                            </Link>
                        </p>
                    </div>
                )}

                {/* ⚠⚠ With NO warehouses this used to say every warehouse was done
                    (21 Sep 2026): zero left to do read as all finished. */}
                {!hasWarehouses ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-6 text-center">
                        No warehouse yet. Opening stock is recorded per warehouse, so add one first.{' '}
                        {canManageWarehouses ? (
                            <Link href="/inventory/warehouses" className="text-foreground underline underline-offset-2">
                                Add a warehouse
                            </Link>
                        ) : (
                            'Ask a manager to add one.'
                        )}
                    </div>
                ) : warehouses.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border p-6 text-center">
                        Every active warehouse already has its opening stock recorded. Later corrections go through{' '}
                        <Link href="/inventory/stock" className="text-foreground underline underline-offset-2">
                            Record damage or loss
                        </Link>{' '}
                        on the Stock screen.
                    </div>
                ) : products.length === 0 ? (
                    <div className="text-muted-foreground rounded-lg border border-dashed p-6 text-center">
                        No products that hold stock yet, so there is nothing to count in.{' '}
                        <Link href="/inventory/products" className="text-foreground underline underline-offset-2">
                            Add products
                        </Link>{' '}
                        first.
                    </div>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="warehouse_id" required>
                                    Warehouse
                                </Label>
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
                                <Label htmlFor="as_at" required>
                                    As at
                                </Label>
                                <Input id="as_at" aria-required type="date" value={data.as_at} onChange={(e) => setData('as_at', e.target.value)} />
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
                                        <th className="p-3">
                                            Product
                                            <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                *
                                            </span>
                                        </th>
                                        <th className="w-32 p-3">
                                            Quantity
                                            <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                *
                                            </span>
                                        </th>
                                        <th className="w-32 p-3">
                                            Unit cost
                                            <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                *
                                            </span>
                                        </th>
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
                                                {/* The unit beside the box: "40" is kilograms or sacks. */}
                                                <div className="flex items-center gap-1.5">
                                                    <Input
                                                        aria-required
                                                        value={line.quantity}
                                                        onChange={(e) => setLine(index, { quantity: e.target.value })}
                                                    />
                                                    <span className="text-muted-foreground text-xs">
                                                        {products.find((p) => String(p.id) === line.product_id)?.unit ?? ''}
                                                    </span>
                                                </div>
                                                <InputError message={errors[`lines.${index}.quantity` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    aria-required
                                                    value={line.unit_cost}
                                                    onChange={(e) => setLine(index, { unit_cost: e.target.value })}
                                                />
                                                <InputError message={errors[`lines.${index}.unit_cost` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    value={line.batch_number}
                                                    placeholder="Auto if blank"
                                                    onChange={(e) => setLine(index, { batch_number: e.target.value })}
                                                />
                                                {/* ⚠ Validated and refused on the server, and never shown (21 Sep 2026 audit). */}
                                                <InputError message={errors[`lines.${index}.batch_number` as keyof typeof errors]} />
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    type="date"
                                                    value={line.expiry_date}
                                                    onChange={(e) => setLine(index, { expiry_date: e.target.value })}
                                                />
                                                <InputError message={errors[`lines.${index}.expiry_date` as keyof typeof errors]} />
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

                {(openingStocks.length > 0 || showCorrections) && (
                    <div className="overflow-x-auto rounded-lg border">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b p-3">
                            <h2 className="font-medium">Already recorded</h2>
                            {/* ⭐ Corrections wave 1 (20 Oct 2026): the live one per warehouse by default. */}
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={showCorrections}
                                    onChange={(e) =>
                                        router.get('/inventory/opening-stock', e.target.checked ? { corrections: 1 } : {}, { preserveScroll: true })
                                    }
                                />
                                {t('corr.stock.opening.show_corrections')}
                            </label>
                        </div>
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Warehouse</th>
                                    <th className="p-3">As at</th>
                                    <th className="p-3 text-right">Lines</th>
                                    <th className="p-3 text-right">{t('corr.stock.opening.value')}</th>
                                    <th className="p-3">Note</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {openingStocks.map((opening) => {
                                    const correct = opening.actions.find((a) => a.action === 'correct');

                                    return (
                                        <tr key={opening.id} className="border-t align-top">
                                            <td className="p-3 font-medium">
                                                {opening.warehouse?.name ?? '-'}
                                                <span className="text-muted-foreground block text-xs font-normal">{opening.number}</span>
                                                {opening.correction_of && (
                                                    <span className="text-muted-foreground block text-xs font-normal">
                                                        {t('corr.stock.correction_of', { number: opening.correction_of })}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3">{formatDate(opening.as_at)}</td>
                                            <td className="p-3 text-right tabular-nums">{opening.lines.length}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(opening.total)}</td>
                                            <td className="text-muted-foreground p-3">
                                                {opening.status === 'corrected' && (
                                                    <Badge variant="outline" className="mr-2">
                                                        {t('corr.stock.opening.corrected', { number: opening.corrected_by ?? '' })}
                                                    </Badge>
                                                )}
                                                {opening.note}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap">
                                                {correct &&
                                                    (correct.allowed || correct.requestable ? (
                                                        <Button size="sm" variant="outline" asChild>
                                                            <Link href={`/inventory/opening-stock/${opening.id}/correct`}>
                                                                {t('corr.stock.correct')}
                                                            </Link>
                                                        </Button>
                                                    ) : (
                                                        <Button size="sm" variant="outline" disabled>
                                                            {t('corr.stock.correct')}
                                                        </Button>
                                                    ))}
                                                <BlockedNote availability={correct?.requestable ? undefined : correct} />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
