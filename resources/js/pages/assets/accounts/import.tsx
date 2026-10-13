import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Problem {
    line: number;
    name: string;
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
    creates: { line: number; name: string; kind: string; users: number }[];
    updates: { line: number; name: string; kind: string; changes: Change[]; new_users: string[] }[];
    unchanged: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounts hub', href: '/assets/accounts' },
    { title: 'Import', href: '/assets/accounts/import' },
];

function Count({ label, value, tone }: { label: string; value: number; tone?: 'bad' | 'warn' }) {
    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
            <div
                className={`text-2xl font-semibold tabular-nums ${tone === 'bad' && value > 0 ? 'text-destructive' : tone === 'warn' && value > 0 ? 'text-amber-600' : ''}`}
            >
                {value}
            </div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    );
}

/**
 * The accounts importer (slice 4): template, upload, preview, apply. The
 * same three steps as the product import.
 *
 * ⛔ No password column, by design: logins are typed on the account's page.
 */
export default function CompanyAccountImport({
    preview,
    limits,
    columns,
    kinds,
}: {
    preview: Preview | null;
    limits: { max_rows: number; max_mb: number };
    columns: { mandatory: string[]; optional: string[] };
    kinds: string[];
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({ token: preview?.token ?? '', hash: preview?.hash ?? '' });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post('/assets/accounts/import/preview', { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠⚠ The token is read from the preview ON SCREEN at the moment of
        // Apply: useForm captured its state before any preview existed.
        apply.transform((data) => ({ ...data, token: preview?.token ?? '', hash: preview?.hash ?? '' }));
        apply.post('/assets/accounts/import');
    };

    const blocked = (preview?.errors.length ?? 0) > 0;
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import accounts" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import accounts</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Step 1:{' '}
                        <a className="underline underline-offset-2" href="/assets/accounts/import/template">
                            download the template
                        </a>{' '}
                        (or{' '}
                        <a className="underline underline-offset-2" href="/assets/accounts/export">
                            export what is here
                        </a>
                        ), fill it in Excel, save as CSV. Step 2: upload it and read the preview. Step 3: apply. Nothing is written until step 3, and
                        one error anywhere means nothing is written at all.
                    </p>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Columns: <span className="font-mono">{columns.mandatory.join(', ')}</span> (required),{' '}
                        <span className="font-mono">{columns.optional.join(', ')}</span>. <span className="font-mono">kind</span> is one of{' '}
                        <span className="font-mono">{kinds.join(', ')}</span>. <span className="font-mono">owner</span>,{' '}
                        <span className="font-mono">users</span> (separated by ;) and <span className="font-mono">two_factor_holder</span> are
                        employee codes or exact full names. An account is matched on kind + identifier, or kind + name when there is no identifier. Up
                        to {limits.max_rows} rows, {limits.max_mb} MB. No passwords in the file: add logins on each account's page.
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
                    <Button type="button" variant="ghost" asChild>
                        <Link href="/assets/accounts">Back to the hub</Link>
                    </Button>
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
                                    Nothing can be imported until these are fixed. Every row is listed.
                                </p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {preview.errors.map((e, i) => (
                                        <li key={i}>
                                            <span className="text-muted-foreground">line {e.line}</span> <span className="font-medium">{e.name}</span>{' '}
                                            - {e.message}
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
                                            <span className="text-muted-foreground">line {w.line}</span> <span className="font-medium">{w.name}</span>{' '}
                                            - {w.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {preview.updates.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    What would change on existing accounts
                                </p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.updates.map((u) => (
                                                <tr key={u.line} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3 align-top">{u.line}</td>
                                                    <td className="p-3 align-top">
                                                        {u.name} <span className="text-muted-foreground text-xs">{u.kind}</span>
                                                    </td>
                                                    <td className="text-muted-foreground p-3 align-top">
                                                        {u.changes.map((c) => (
                                                            <div key={c.field}>
                                                                {c.field}: {c.from || '(blank)'} → {c.to || '(blank)'}
                                                            </div>
                                                        ))}
                                                        {u.new_users.length > 0 && <div>access for: {u.new_users.join(', ')}</div>}
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
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">New accounts</p>
                                <div className="max-h-80 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {preview.creates.map((c) => (
                                                <tr key={c.line} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="text-muted-foreground w-16 p-3">{c.line}</td>
                                                    <td className="p-3">{c.name}</td>
                                                    <td className="text-muted-foreground p-3">{c.kind}</td>
                                                    <td className="text-muted-foreground p-3">{c.users > 0 && `${c.users} with access`}</td>
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
