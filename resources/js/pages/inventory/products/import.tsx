import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Problem {
    line: number;
    sku: string;
    message: string;
}

interface Change {
    field: string;
    from: string;
    to: string;
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    rows: number;
    errors: Problem[];
    warnings: Problem[];
    creates: { line: number; sku: string; name: string }[];
    updates: { line: number; sku: string; name: string; changes: Change[] }[];
    unchanged: number;
    new_categories: string[];
    new_units: string[];
    absent_from_file: string[];
}

const breadcrumbs = inventoryCrumbs('Catalogue', 'Products', { title: 'Import', href: '/inventory/products/import' });

function Count({ label, value, tone }: { label: string; value: number; tone?: 'bad' | 'warn' }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
            <div
                className={`text-2xl font-semibold tabular-nums ${
                    tone === 'bad' && value > 0 ? 'text-destructive' : tone === 'warn' && value > 0 ? 'text-amber-600' : ''
                }`}
            >
                {value}
            </div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

export default function ProductImport({
    preview,
}: {
    preview: Preview | null;
    limits: { max_rows: number; max_mb: number };
    columns: { mandatory: string[]; optional: string[] };
}) {
    const { canImportPrices } = usePage<SharedData>().props;
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({ token: preview?.token ?? '', hash: preview?.hash ?? '' });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('inventory.products.import.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠⚠ The token comes from the preview ON SCREEN, read at the moment of
        // Apply. useForm captured it when the page first mounted, before any
        // preview existed, and the preview visit keeps that state: every Apply
        // posted an empty token and was refused (29 Sep 2026).
        apply.transform((data) => ({ ...data, token: preview?.token ?? '', hash: preview?.hash ?? '' }));
        apply.post(route('inventory.products.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0;

    // ⚠ The apply step reports its refusals under `file` - "this is not the file
    // you reviewed" is about the file, not about the token that names it - and
    // useForm types its errors from the data shape, which has no `file`.
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import products" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import products</h1>
                    <p className="mt-1 text-sm">
                        <a className="underline underline-offset-2" href={route('inventory.products.export')}>
                            Export current products
                        </a>
                    </p>
                    {/* 17 Oct 2026: this file carries no prices, and nothing said so. */}
                    <p className="text-muted-foreground mt-2 text-sm">
                        MRP and group prices are set in a second import:{' '}
                        {canImportPrices ? (
                            <Link className="text-foreground underline underline-offset-2" href={route('sales.prices.import')}>
                                Sales &gt; Sales setup &gt; Selling prices &gt; Import prices
                            </Link>
                        ) : (
                            'Sales > Sales setup > Selling prices > Import prices'
                        )}
                        .
                    </p>
                </div>

                <form onSubmit={submitFile} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="file" required>
                            CSV file
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
                    <Button disabled={upload.processing || !upload.data.file}>{upload.processing ? 'Reading' : 'Show me what it would do'}</Button>
                </form>

                {preview && (
                    <div className="flex flex-col gap-4">
                        <div className="text-muted-foreground text-sm">
                            <span className="text-foreground font-medium">{preview.file_name}</span> - {preview.rows} row(s) read.
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            <Count label="to create" value={preview.creates.length} />
                            <Count label="to update" value={preview.updates.length} />
                            <Count label="unchanged" value={preview.unchanged} />
                            <Count label="warnings" value={preview.warnings.length} tone="warn" />
                            <Count label="errors" value={preview.errors.length} tone="bad" />
                        </div>

                        {blocked && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    Nothing can be imported until these are fixed. Every row is listed, not just the first.
                                </p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {preview.errors.map((e, i) => (
                                        <li key={i}>
                                            <span className="text-muted-foreground">line {e.line}</span>{' '}
                                            <span className="font-mono text-xs">{e.sku}</span> - {e.message}
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
                                            <span className="font-mono text-xs">{w.sku}</span> - {w.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {(preview.new_categories.length > 0 || preview.new_units.length > 0) && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-4 text-sm">
                                <p className="font-medium">This file would also create:</p>
                                {preview.new_categories.length > 0 && (
                                    <p className="text-muted-foreground mt-1">
                                        {preview.new_categories.length} categor{preview.new_categories.length === 1 ? 'y' : 'ies'}:{' '}
                                        {preview.new_categories.join(', ')}
                                    </p>
                                )}
                                {preview.new_units.length > 0 && (
                                    <p className="text-muted-foreground mt-1">
                                        {preview.new_units.length} unit(s): {preview.new_units.join(', ')}
                                    </p>
                                )}
                            </div>
                        )}

                        {preview.updates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    What would change on existing products
                                </p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.updates.map((u) => (
                                                <tr key={u.sku} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="p-3 align-top font-mono text-xs">{u.sku}</td>
                                                    <td className="p-3 align-top">{u.name}</td>
                                                    <td className="text-muted-foreground p-3 align-top">
                                                        {u.changes.map((c) => (
                                                            <div key={c.field}>
                                                                {c.field}: {c.from || '(blank)'} → {c.to || '(blank)'}
                                                            </div>
                                                        ))}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {preview.creates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">New products</p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.creates.map((c) => (
                                                <tr key={c.sku} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3">{c.line}</td>
                                                    <td className="p-3 font-mono text-xs">{c.sku}</td>
                                                    <td className="p-3">{c.name}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {/*
                            C-CAT-1. Reported so the number is never a surprise, and
                            otherwise left completely alone: a file is a statement about
                            the rows it contains, not a claim to be the whole catalogue.
                        */}
                        {preview.absent_from_file.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                {preview.absent_from_file.length} product(s) already here are not mentioned in this file.
                            </p>
                        )}

                        <form
                            onSubmit={submitApply}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 border-t pt-4"
                        >
                            <input type="hidden" value={apply.data.token} />
                            <Button disabled={apply.processing || blocked}>
                                {apply.processing ? 'Importing' : `Apply - create ${preview.creates.length}, update ${preview.updates.length}`}
                            </Button>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
