import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Problem {
    line: number;
    reference: string;
    message: string;
}

interface PlannedVoucher {
    line: number;
    date: string;
    payee: string;
    account: string;
    description: string;
    amount: number;
    paid_from: string;
    reference: string;
}

interface Preview {
    token: string;
    hash: string;
    file_name: string;
    rows: number;
    errors: Problem[];
    warnings: Problem[];
    vouchers: PlannedVoucher[];
    total: number;
    ignored_columns: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/expenses' },
    { title: 'Expenses', href: '/purchasing/expenses' },
    { title: 'Import', href: '/purchasing/expenses/import' },
];

const taka = (n: number) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function ExpenseImport({
    preview,
}: {
    preview: Preview | null;
    columns: string[];
    accounts: { code: string; label: string }[];
    moneyAccounts: string[];
    limits: { max_rows: number; max_mb: number };
}) {
    const upload = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({ token: preview?.token ?? '', hash: preview?.hash ?? '' });

    const submitFile: FormEventHandler = (e) => {
        e.preventDefault();
        upload.post(route('purchasing.expenses.import.preview'), { forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        // ⚠⚠ The token comes from the preview ON SCREEN, read at the moment of
        // Apply. useForm captured it when the page first mounted, before any
        // preview existed, and the preview visit keeps that state: every Apply
        // posted an empty token and was refused (29 Sep 2026).
        apply.transform((data) => ({ ...data, token: preview?.token ?? '', hash: preview?.hash ?? '' }));
        apply.post(route('purchasing.expenses.import.store'));
    };

    const blocked = (preview?.errors.length ?? 0) > 0;
    const applyError = (apply.errors as Record<string, string | undefined>).file ?? apply.errors.token;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Import expenses" />
            <div className="flex flex-col gap-6 p-4">
                <div className="max-w-3xl">
                    <h1 className="text-lg font-semibold">Import expenses</h1>
                    {/*
                        ⚠⚠ Said BEFORE the upload, not after it. An expense voucher in
                        this ERP is paid the moment it is recorded, so an import is a
                        payment run, not a data load.
                    */}
                    <p className="mt-3 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        Every row here is money already spent. Recording them posts the payments straight away, so import a file only once the money
                        has actually gone.
                    </p>

                    <p className="mt-2 text-sm">
                        <a className="underline underline-offset-2" href={route('purchasing.expenses.import.template')}>
                            Download the template
                        </a>{' '}
                        or{' '}
                        <Link className="underline underline-offset-2" href={route('purchasing.expenses.index')}>
                            go back to expenses
                        </Link>
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
                            accept=".csv,text/csv"
                            required
                            aria-required
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
                            {[
                                ['expenses to record', preview.vouchers.length.toString(), ''],
                                ['total', taka(preview.total), ''],
                                ['warnings', preview.warnings.length.toString(), 'warn'],
                                ['errors', preview.errors.length.toString(), 'bad'],
                            ].map(([label, value, tone]) => (
                                <div key={label} className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-3">
                                    <div
                                        className={`text-2xl font-semibold tabular-nums ${
                                            tone === 'bad' && preview.errors.length > 0
                                                ? 'text-destructive'
                                                : tone === 'warn' && preview.warnings.length > 0
                                                  ? 'text-amber-600'
                                                  : ''
                                        }`}
                                    >
                                        {value}
                                    </div>
                                    <div className="text-muted-foreground text-xs">{label}</div>
                                </div>
                            ))}
                        </div>

                        {blocked && (
                            <div className="border-destructive/40 bg-destructive/5 rounded-lg border p-4">
                                <p className="text-destructive text-sm font-medium">
                                    Nothing can be recorded until these are fixed. A file is either fit to import or it is not.
                                </p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {preview.errors.map((e, i) => (
                                        <li key={i}>
                                            <span className="text-muted-foreground">line {e.line}</span>{' '}
                                            <span className="font-mono text-xs">{e.reference}</span> - {e.message}
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
                                            <span className="font-mono text-xs">{w.reference}</span> - {w.message}
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

                        {preview.vouchers.length > 0 && (
                            <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border">
                                <p className="border-sidebar-border/70 dark:border-sidebar-border border-b p-3 text-sm font-medium">
                                    What would be recorded, in file order
                                </p>
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-xs">
                                            <tr>
                                                <th className="p-3 font-medium">Date</th>
                                                <th className="p-3 font-medium">Paid to</th>
                                                <th className="p-3 font-medium">Charged to</th>
                                                <th className="p-3 font-medium">What for</th>
                                                <th className="p-3 font-medium">Reference</th>
                                                <th className="p-3 font-medium">Paid from</th>
                                                <th className="p-3 text-right font-medium">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {preview.vouchers.map((v) => (
                                                <tr key={v.line} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                                    <td className="p-3 tabular-nums">{v.date}</td>
                                                    <td className="p-3">{v.payee || '-'}</td>
                                                    <td className="text-muted-foreground p-3">{v.account}</td>
                                                    <td className="text-muted-foreground p-3">{v.description || '-'}</td>
                                                    <td className="p-3 font-mono text-xs">{v.reference || '-'}</td>
                                                    <td className="text-muted-foreground p-3">{v.paid_from}</td>
                                                    <td className="p-3 text-right tabular-nums">{taka(v.amount)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="font-medium">
                                                <td className="p-3" colSpan={6}>
                                                    Total
                                                </td>
                                                <td className="p-3 text-right tabular-nums">{taka(preview.total)}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        )}

                        <form onSubmit={submitApply} className="flex flex-wrap items-center gap-3">
                            <Button disabled={blocked || apply.processing || preview.vouchers.length === 0}>
                                {apply.processing ? 'Recording' : `Record ${preview.vouchers.length} expense(s), ${taka(preview.total)}`}
                            </Button>
                            <InputError message={applyError} />
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
