import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/*
 * ⭐ The weights sheet (courier hub slice 3, Joy 11 Oct 2026). A parcel's weight
 * is worked out from its products' weights, so every product needs one; this
 * fills them for the whole catalogue in one sitting. Download, fill the blanks,
 * upload, read the preview, apply. The preview writes nothing.
 */

interface Problem {
    line: number;
    sku: string;
    message: string;
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    rows: number;
    errors: Problem[];
    changes: { line: number; sku: string; name: string; from: number | null; to: number }[];
    unchanged: number;
    blank: number;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Product weights');

function Count({ label, value, tone }: { label: string; value: number; tone?: 'bad' }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
            <div className={`text-2xl font-semibold tabular-nums ${tone === 'bad' && value > 0 ? 'text-destructive' : ''}`}>{value}</div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

export default function ProductWeights({
    preview,
    summary,
    limits,
}: {
    preview: Preview | null;
    summary: { products: number; missing: number };
    limits: { max_rows: number; max_mb: number };
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({ token: preview?.token ?? '', hash: preview?.hash ?? '' });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('inventory.products.weights.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠ Read from the preview on screen at the moment of Apply, as on the
        // catalogue import: useForm captured the state before any preview existed.
        apply.transform((data) => ({ ...data, token: preview?.token ?? '', hash: preview?.hash ?? '' }));
        apply.post(route('inventory.products.weights.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0;
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Weights sheet" />
            <div className="flex flex-col gap-4 p-4">
                <div className="max-w-3xl">
                    <p className="text-muted-foreground text-sm">
                        {summary.missing} of {summary.products} active product(s) have no weight yet.{' '}
                        {summary.missing > 0 && (
                            <Link className="underline underline-offset-2" href="/inventory/products?flag=weight_missing">
                                See them
                            </Link>
                        )}
                    </p>
                    <ol className="text-muted-foreground mt-3 list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            <a className="text-foreground underline underline-offset-2" href={route('inventory.products.weights.template')}>
                                Download the sheet
                            </a>{' '}
                            (every active product, with its current weight).
                        </li>
                        <li>Fill weight_grams: one unit as it ships, packed, in whole grams. Leave a row blank to keep its weight.</li>
                        <li>Upload it below, read what would change, then apply.</li>
                    </ol>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Up to {limits.max_rows.toLocaleString('en-IN')} rows or {limits.max_mb} MB.
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

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Count label="to change" value={preview.changes.length} />
                            <Count label="already right" value={preview.unchanged} />
                            <Count label="left blank" value={preview.blank} />
                            <Count label="errors" value={preview.errors.length} tone="bad" />
                        </div>

                        {blocked && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    Nothing can be saved until these are fixed. Every row is listed, not just the first.
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

                        {preview.changes.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    What would change
                                </p>
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.changes.map((c) => (
                                                <tr key={c.sku} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3">{c.line}</td>
                                                    <td className="p-3 font-mono text-xs">{c.sku}</td>
                                                    <td className="p-3">{c.name}</td>
                                                    <td className="p-3 text-right tabular-nums">
                                                        <span className="text-muted-foreground">{c.from === null ? 'no weight' : `${c.from} g`}</span>{' '}
                                                        → {c.to} g
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        <form
                            onSubmit={submitApply}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 border-t pt-4"
                        >
                            <Button disabled={apply.processing || blocked || preview.changes.length === 0}>
                                {apply.processing ? 'Saving' : `Apply - save ${preview.changes.length} weight(s)`}
                            </Button>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
