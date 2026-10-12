import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Problem {
    line: number;
    sku: string;
    message: string;
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    effective_from: string;
    rows: number;
    errors: Problem[];
    warnings: Problem[];
    creates: { line: number; sku: string; product: string; group: string; price: string; minQuantity: string }[];
    supersedes: { sku: string; group: string; from: string; was: string; becomes: string; closesOn: string }[];
    unchanged: number;
    mrp_changes?: number;
    ignored_columns: string[];
    still_unpriced: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Selling prices', href: '/sales/prices' },
    { title: 'Import', href: '/sales/prices/import' },
];

export default function PriceImport({ preview, groups }: { preview: Preview | null; groups: { code: string; name: string }[] }) {
    const upload = useForm<{ file: File | null; effective_from: string }>({
        file: null,
        effective_from: preview?.effective_from ?? '',
    });

    const apply = useForm({
        token: preview?.token ?? '',
        hash: preview?.hash ?? '',
        effective_from: preview?.effective_from ?? '',
    });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('sales.prices.import.preview'), { forceFormData: true });
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
            effective_from: preview?.effective_from ?? data.effective_from,
        }));
        apply.post(route('sales.prices.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0;
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import prices" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import selling prices</h1>
                    <p className="text-muted-foreground mt-2 text-sm">
                        Price groups here: {groups.map((g) => g.code).join(', ')}. Start from{' '}
                        <a className="underline underline-offset-2" href={route('sales.prices.export')}>
                            an export of what is in force
                        </a>
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
                            type="file"
                            aria-required
                            accept=".csv,text/csv"
                            onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                            className="file:border-input file:bg-background text-sm file:mr-3 file:rounded-md file:border file:px-3 file:py-2 file:text-sm"
                        />
                        <InputError message={upload.errors.file} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="effective_from" required>
                            These prices start on
                        </Label>
                        <Input
                            id="effective_from"
                            type="date"
                            aria-required
                            value={upload.data.effective_from}
                            onChange={(e) => upload.setData('effective_from', e.target.value)}
                        />
                        <InputError message={upload.errors.effective_from} />
                    </div>
                    <Button disabled={upload.processing || !upload.data.file || !upload.data.effective_from}>
                        {upload.processing ? 'Reading' : 'Show me what it would do'}
                    </Button>
                </form>

                {preview && (
                    <div className="flex flex-col gap-4">
                        <div className="text-muted-foreground text-sm">
                            <span className="text-foreground font-medium">{preview.file_name}</span> - {preview.rows} row(s), taking effect{' '}
                            <span className="text-foreground font-medium">{preview.effective_from}</span>.
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
                            {[
                                ['new prices', preview.creates.length, ''],
                                ['MRP set', preview.mrp_changes ?? 0, ''],
                                ['replaced', preview.supersedes.length, ''],
                                ['unchanged', preview.unchanged, ''],
                                ['warnings', preview.warnings.length, 'warn'],
                                ['errors', preview.errors.length, 'bad'],
                            ].map(([label, value, tone]) => (
                                <div key={label as string} className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
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

                        {blocked && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    No prices can be changed until these are fixed. Every row is listed.
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

                        {preview.ignored_columns.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                Columns in the file this importer does not read: {preview.ignored_columns.join(', ')}.
                            </p>
                        )}

                        {preview.supersedes.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    Prices this would replace - the old rule is closed the day before, never edited or deleted
                                </p>
                                <div className="max-h-72 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.supersedes.map((s, i) => (
                                                <tr key={i} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="p-3 font-mono text-xs">{s.sku}</td>
                                                    <td className="p-3">{s.group}</td>
                                                    <td className="text-muted-foreground p-3 tabular-nums">
                                                        {s.was} → {s.becomes}
                                                    </td>
                                                    <td className="text-muted-foreground p-3 text-xs">closes {s.closesOn}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {preview.creates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    New price rules
                                </p>
                                <div className="max-h-72 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.creates.map((c, i) => (
                                                <tr key={i} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3">{c.line}</td>
                                                    <td className="p-3 font-mono text-xs">{c.sku}</td>
                                                    <td className="p-3">{c.product}</td>
                                                    <td className="p-3">{c.group}</td>
                                                    <td className="p-3 text-right tabular-nums">{formatMoney(c.price)}</td>
                                                    <td className="text-muted-foreground p-3 text-xs">
                                                        {Number(c.minQuantity) > 1 ? `from ${c.minQuantity}` : ''}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {preview.still_unpriced.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-4 text-sm">
                                <p className="font-medium">
                                    {preview.still_unpriced.length} product(s) would still have no price, so they still cannot be sold.
                                </p>
                                <p className="text-muted-foreground mt-1 font-mono text-xs">
                                    {preview.still_unpriced.slice(0, 40).join(', ')}
                                    {preview.still_unpriced.length > 40 ? ` and ${preview.still_unpriced.length - 40} more` : ''}
                                </p>
                            </div>
                        )}

                        <form
                            onSubmit={submitApply}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 border-t pt-4"
                        >
                            <Button disabled={apply.processing || blocked}>
                                {apply.processing
                                    ? 'Importing'
                                    : `Apply from ${preview.effective_from} - ${preview.creates.length} new, ${preview.supersedes.length} replaced, ${preview.mrp_changes ?? 0} MRP`}
                            </Button>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
