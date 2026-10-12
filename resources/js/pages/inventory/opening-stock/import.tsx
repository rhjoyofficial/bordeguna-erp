import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Problem {
    line: number;
    sku: string;
    message: string;
}

interface Row {
    line: number;
    sku: string;
    product: string;
    quantity: string;
    unitCost: string;
    value: string;
    batchNumber: string | null;
    expiryDate: string | null;
}

interface WarehousePlan {
    code: string;
    name: string;
    lines: number;
    value: string;
    rows: Row[];
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    as_at: string;
    rows: number;
    errors: Problem[];
    warnings: Problem[];
    not_counted: { line: number; sku: string; warehouse: string }[];
    warehouses: WarehousePlan[];
    line_count: number;
    counted_products: number;
    active_stocked_products: number;
    total_value: string;
    total_value_exact: string;
}

interface Named {
    name: string;
    code: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Opening stock', href: '/inventory/opening-stock' },
    { title: 'Import', href: '/inventory/opening-stock/import' },
];

export default function OpeningStockImport({
    preview,
    awaiting,
    alreadyRecorded,
}: {
    preview: Preview | null;
    awaiting: Named[];
    alreadyRecorded: Named[];
    stockedProducts: number;
    columns: { mandatory: string[]; optional: string[] };
    limits: { max_rows: number; max_mb: number };
}) {
    const upload = useForm<{ file: File | null; as_at: string }>({
        file: null,
        // ⚠ No default date. The opening position is defined by the day the
        // shelves were walked, and pre-filling today would let that day be
        // chosen by accident on the one document that cannot be rewritten.
        as_at: preview?.as_at ?? '',
    });

    const apply = useForm({
        token: preview?.token ?? '',
        hash: preview?.hash ?? '',
        as_at: preview?.as_at ?? '',
        confirm_total: '',
        note: '',
    });

    const [showBlanks, setShowBlanks] = useState(false);

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('inventory.opening-stock.import.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠⚠ The token comes from the preview ON SCREEN, read at the moment of
        // Apply. useForm captured it when the page first mounted, before any
        // preview existed, and the preview visit keeps that state: every Apply
        // posted an empty token and was refused (29 Sep 2026).
        apply.transform((data) => ({
            ...data,
            token: preview?.token ?? '',
            hash: preview?.hash ?? '',
            as_at: preview?.as_at ?? data.as_at,
        }));
        apply.post(route('inventory.opening-stock.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0 || (preview?.line_count ?? 0) === 0;
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;
    const uncounted = (preview?.active_stocked_products ?? 0) - (preview?.counted_products ?? 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import opening stock" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import the opening stock count</h1>
                </div>

                {/*
                    ⚠⚠ The loudest warning on any import screen in the system, and
                    deliberately so. Every other importer here can be re-run
                    tomorrow. This one cannot.
                */}
                <div className="border-destructive/50 bg-destructive/5 max-w-3xl rounded-lg border p-4 text-sm">
                    <p className="text-destructive font-medium">This document is written once per warehouse, for the life of the business.</p>
                </div>

                <div className="grid max-w-3xl gap-3 sm:grid-cols-2">
                    <div className="rounded-lg border p-3 text-sm">
                        <p className="font-medium">Still awaiting a count</p>
                        <p className="text-muted-foreground mt-1">
                            {awaiting.length === 0
                                ? 'None. Every active warehouse already has its opening stock.'
                                : awaiting.map((w) => `${w.name} (${w.code})`).join(', ')}
                        </p>
                    </div>
                    <div className="rounded-lg border p-3 text-sm">
                        <p className="font-medium">Already recorded</p>
                        <p className="text-muted-foreground mt-1">
                            {alreadyRecorded.length === 0
                                ? 'None yet.'
                                : `${alreadyRecorded.map((w) => `${w.name} (${w.code})`).join(', ')}. A file naming any of these is refused in full.`}
                        </p>
                    </div>
                </div>

                <form onSubmit={submitFile} className="flex flex-wrap items-end gap-3 border-t pt-4">
                    <div className="grid gap-2">
                        <Label htmlFor="file" required>
                            Counted CSV
                        </Label>
                        <input
                            id="file"
                            aria-required
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            className="file:border-input file:bg-background text-sm file:mr-3 file:rounded-md file:border file:px-3 file:py-2 file:text-sm"
                        />
                        <InputError message={upload.errors.file} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="as_at" required>
                            The day the shelves were counted
                        </Label>
                        <Input
                            id="as_at"
                            aria-required
                            type="date"
                            value={upload.data.as_at}
                            onChange={(e) => upload.setData('as_at', e.target.value)}
                        />
                        <InputError message={upload.errors.as_at} />
                    </div>
                    <Button disabled={upload.processing || !upload.data.file || !upload.data.as_at}>
                        {upload.processing ? 'Reading' : 'Show me what it would do'}
                    </Button>
                </form>

                {preview && (
                    <div className="flex flex-col gap-4">
                        <div className="text-muted-foreground text-sm">
                            <span className="text-foreground font-medium">{preview.file_name}</span>, {preview.rows} row(s), counted as at{' '}
                            <span className="text-foreground font-medium">{preview.as_at}</span>.
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            {[
                                ['warehouses', preview.warehouses.length, ''],
                                ['lines to write', preview.line_count, ''],
                                ['left blank', preview.not_counted.length, ''],
                                ['warnings', preview.warnings.length, 'warn'],
                                ['errors', preview.errors.length, 'bad'],
                            ].map(([label, value, tone]) => (
                                <div key={label as string} className="rounded-lg border p-3">
                                    <div
                                        className={`text-2xl font-semibold tabular-nums ${
                                            tone === 'bad' && (value as number) > 0
                                                ? 'text-destructive'
                                                : tone === 'warn' && (value as number) > 0
                                                  ? 'text-amber-600'
                                                  : ''
                                        }`}
                                    >
                                        {value as number}
                                    </div>
                                    <div className="text-muted-foreground text-xs">{label as string}</div>
                                </div>
                            ))}
                        </div>

                        {preview.errors.length > 0 && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    Nothing can be written until these are fixed, and no warehouse is written partially. Every row is listed.
                                </p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {preview.errors.map((e, i) => (
                                        <li key={i}>
                                            <span className="text-muted-foreground">line {e.line}</span>{' '}
                                            <span className="font-mono text-xs">{e.sku}</span>: {e.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {preview.warnings.length > 0 && (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4">
                                <p className="text-sm font-medium">Worth reading. These do not stop the import.</p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {preview.warnings.map((w, i) => (
                                        <li key={i}>
                                            <span className="text-muted-foreground">line {w.line}</span>{' '}
                                            <span className="font-mono text-xs">{w.sku}</span>: {w.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {/*
                            ⚠⚠ A forgotten shelf and an empty one look identical in a
                            file of blanks. The system cannot tell them apart, so it
                            says how many there are and offers the list.
                        */}
                        <div className="rounded-lg border p-4 text-sm">
                            <p className="font-medium">
                                {preview.counted_products} of {preview.active_stocked_products} active stocked product(s) are counted
                                {uncounted > 0 ? `. The other ${uncounted} start at zero stock and cannot be sold until stock arrives.` : '.'}
                            </p>
                            {preview.not_counted.length > 0 && (
                                <>
                                    <p className="text-muted-foreground mt-1">
                                        {preview.not_counted.length} row(s) were left blank, so they count nothing.
                                    </p>
                                    <button
                                        type="button"
                                        className="mt-2 text-xs underline underline-offset-2"
                                        onClick={() => setShowBlanks((v) => !v)}
                                    >
                                        {showBlanks ? 'Hide' : 'Show'} the {preview.not_counted.length} blank row(s)
                                    </button>
                                    {showBlanks && (
                                        <p className="text-muted-foreground mt-2 max-h-40 overflow-y-auto font-mono text-xs">
                                            {preview.not_counted.map((r) => `${r.sku}@${r.warehouse}`).join(', ')}
                                        </p>
                                    )}
                                </>
                            )}
                        </div>

                        {preview.warehouses.map((warehouse) => (
                            <div key={warehouse.code} className="rounded-lg border">
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b p-3">
                                    <p className="text-sm font-medium">
                                        {warehouse.name} ({warehouse.code}), {warehouse.lines} line(s)
                                    </p>
                                    <p className="text-sm tabular-nums">{warehouse.value}</p>
                                </div>
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50 sticky top-0 text-left text-xs">
                                            <tr>
                                                <th className="p-2 font-medium">line</th>
                                                <th className="p-2 font-medium">SKU</th>
                                                <th className="p-2 font-medium">product</th>
                                                <th className="p-2 text-right font-medium">quantity</th>
                                                <th className="p-2 text-right font-medium">unit cost</th>
                                                <th className="p-2 text-right font-medium">value</th>
                                                <th className="p-2 font-medium">batch / expiry</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {warehouse.rows.map((row) => (
                                                <tr key={row.line} className="border-b last:border-0">
                                                    <td className="text-muted-foreground p-2">{row.line}</td>
                                                    <td className="p-2 font-mono text-xs">{row.sku}</td>
                                                    <td className="p-2">{row.product}</td>
                                                    <td className="p-2 text-right tabular-nums">{formatQuantity(row.quantity)}</td>
                                                    <td className="p-2 text-right tabular-nums">{row.unitCost}</td>
                                                    <td className="p-2 text-right tabular-nums">{row.value}</td>
                                                    <td className="text-muted-foreground p-2 text-xs">
                                                        {[row.batchNumber, row.expiryDate].filter(Boolean).join(' · ')}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))}

                        {/*
                            ⚠⚠ THE NUMBER THE ACCOUNTANT MUST AGREE WITH. If it differs
                            from the stock line on the opening trial balance, Opening
                            Balance Equity never reaches zero, and that account
                            reaching zero is the only check that catches an opening
                            position which balances while being wrong.
                        */}
                        <div className="border-foreground/20 rounded-lg border-2 p-4">
                            <p className="text-muted-foreground text-xs tracking-wide uppercase">Total debited to Stock on Hand</p>
                            <p className="mt-1 text-3xl font-semibold tabular-nums">{preview.total_value}</p>
                        </div>

                        <form onSubmit={submitApply} className="flex flex-col gap-3 border-t pt-4">
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="confirm_total" required>
                                    Type the total back to confirm you have checked it
                                </Label>
                                <Input
                                    id="confirm_total"
                                    aria-required
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder={preview.total_value_exact}
                                    value={apply.data.confirm_total}
                                    onChange={(e) => apply.setData('confirm_total', e.target.value)}
                                    disabled={blocked}
                                />
                                <InputError message={apply.errors.confirm_total} />
                            </div>
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="note">Note stored on each document</Label>
                                <Input
                                    id="note"
                                    value={apply.data.note}
                                    onChange={(e) => apply.setData('note', e.target.value)}
                                    placeholder="Physical count, 31 August 2026"
                                    disabled={blocked}
                                />
                                <InputError message={apply.errors.note} />
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button variant="destructive" disabled={apply.processing || blocked || !apply.data.confirm_total}>
                                    {apply.processing
                                        ? 'Writing'
                                        : `Write ${preview.warehouses.length} document(s) as at ${preview.as_at}. This cannot be undone`}
                                </Button>
                            </div>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
