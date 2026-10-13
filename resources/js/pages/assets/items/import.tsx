import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

/*
 * ⭐ The things sheet (asset register bulk import, Joy 13 Oct 2026). Download
 * the template, fill it (one row per thing, or per batch of identical tagged
 * things), upload, read the preview, apply. A row may say who holds the thing
 * today, and the import records that hand-over too. The preview writes nothing.
 */

interface Problem {
    line: number;
    tag: string;
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
    tags: number;
    creates: { line: number; name: string; category: string; makes: string; hands_to: string | null }[];
    updates: { line: number; tag: string; name: string; changes: Change[] }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Import', href: '/assets/items/import' },
];

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

function Problems({ items }: { items: Problem[] }) {
    return (
        <ul className="mt-2 space-y-1 text-sm">
            {items.map((p, i) => (
                <li key={i}>
                    <span className="text-muted-foreground">line {p.line}</span> <span className="font-mono text-xs">{p.tag}</span> - {p.message}
                </li>
            ))}
        </ul>
    );
}

export default function AssetItemImport({
    preview,
    limits,
    columns,
}: {
    preview: Preview | null;
    limits: { max_rows: number; max_mb: number };
    columns: { mandatory: string[]; optional: string[] };
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({ token: preview?.token ?? '', hash: preview?.hash ?? '' });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('assets.items.import.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠⚠ The token comes from the preview ON SCREEN, read at the moment of
        // Apply: useForm captured it when the page first mounted, before any
        // preview existed (the catalogue importer's lesson, 29 Sep 2026).
        apply.transform((data) => ({ ...data, token: preview?.token ?? '', hash: preview?.hash ?? '' }));
        apply.post(route('assets.items.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0;

    // ⚠ The apply step reports its refusals under `file`, and useForm types its
    // errors from the data shape, which has no `file`.
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import things" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import things</h1>
                    <ol className="text-muted-foreground mt-3 list-decimal space-y-1 pl-5 text-sm">
                        <li>
                            <a className="text-foreground underline underline-offset-2" href={route('assets.items.import.template')}>
                                Download the template
                            </a>{' '}
                            (the columns, with five example rows to copy and delete), or{' '}
                            <a className="text-foreground underline underline-offset-2" href={route('assets.items.export')}>
                                export the things already here
                            </a>{' '}
                            to correct them in Excel and bring them back.
                        </li>
                        <li>
                            One row per thing. Leave <span className="font-mono text-xs">tag</span> blank for a new thing; a filled tag corrects that
                            thing. <span className="font-mono text-xs">quantity</span> 10 in a tagged category registers 10 tags; in a counted group
                            it is the number of pieces. <span className="font-mono text-xs">holder</span> (employee code or name) or{' '}
                            <span className="font-mono text-xs">outside_party</span> records the hand-over as well.
                        </li>
                        <li>Upload it below, read what would happen, then apply. One error and nothing is written.</li>
                    </ol>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Needed: {columns.mandatory.join(', ')}. Optional: {columns.optional.join(', ')}. Up to{' '}
                        {limits.max_rows.toLocaleString('en-IN')} rows or {limits.max_mb} MB.
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
                            <Count label="rows to register" value={preview.creates.length} />
                            <Count label="tags to print" value={preview.tags} />
                            <Count label="to correct" value={preview.updates.length} />
                            <Count label="warnings" value={preview.warnings.length} tone="warn" />
                            <Count label="errors" value={preview.errors.length} tone="bad" />
                        </div>

                        {blocked && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    Nothing can be imported until these are fixed. Every row is listed, not just the first.
                                </p>
                                <Problems items={preview.errors} />
                            </div>
                        )}

                        {preview.warnings.length > 0 && (
                            <div className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4">
                                <p className="text-sm font-medium">Worth reading. These do not stop the import.</p>
                                <Problems items={preview.warnings} />
                            </div>
                        )}

                        {preview.creates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">New things</p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.creates.map((c) => (
                                                <tr key={c.line} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3 align-top">{c.line}</td>
                                                    <td className="p-3 align-top">
                                                        {c.name}
                                                        <span className="text-muted-foreground block text-xs">{c.category}</span>
                                                    </td>
                                                    <td className="text-muted-foreground p-3 align-top">
                                                        would create {c.makes}
                                                        {c.hands_to && <span className="block">{c.hands_to}</span>}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {preview.updates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    What would change on things already here
                                </p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.updates.map((u) => (
                                                <tr key={u.tag} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3 align-top">{u.line}</td>
                                                    <td className="p-3 align-top font-mono text-xs">{u.tag}</td>
                                                    <td className="p-3 align-top">{u.name}</td>
                                                    <td className="text-muted-foreground p-3 align-top">
                                                        {u.changes.length === 0 && 'nothing changes'}
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

                        <form
                            onSubmit={submitApply}
                            className="border-sidebar-border/70 dark:border-sidebar-border flex items-center gap-3 border-t pt-4"
                        >
                            <input type="hidden" value={apply.data.token} />
                            <Button disabled={apply.processing || blocked}>
                                {apply.processing ? 'Importing' : `Apply - register ${preview.tags}, correct ${preview.updates.length}`}
                            </Button>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
