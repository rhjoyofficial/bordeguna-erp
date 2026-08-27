import { Button } from '@/components/ui/button';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Plan {
    rowCount: number;
    creates: { line: number; code: string; name: string }[];
    updates: { line: number; code: string; name: string; changes: string[] }[];
    unchanged: number;
    absentFromFile: string[];
    warnings: string[];
    errors: string[];
}

/**
 * Import and export for the supplier list.
 *
 * ⚠⚠ REVIEW IS A SEPARATE STEP, not a checkbox. Uploading shows what WOULD
 * happen; a second, deliberate click writes it. The same rule the catalogue
 * importer follows, for the same reason: a tool whose safe mode is optional
 * gets used unsafely exactly once.
 */
export function SupplierImportPanel() {
    const page = usePage().props as unknown as {
        supplierImportPlan?: Plan;
        errors?: Record<string, string>;
    };
    const plan = page.supplierImportPlan;
    const [open, setOpen] = useState(false);

    const review = useForm<{ file: File | null }>({ file: null });
    const apply = useForm({});

    const submitReview: FormEventHandler = (e) => {
        e.preventDefault();
        review.post(route('purchasing.suppliers.import.review'), { preserveScroll: true, forceFormData: true });
    };

    const submitApply: FormEventHandler = (e) => {
        e.preventDefault();
        apply.post(route('purchasing.suppliers.import.apply'), { preserveScroll: true });
    };

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border">
            <div className="flex flex-wrap items-center justify-between gap-3 p-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(!open)}>
                        {open ? 'Hide' : 'Import from a spreadsheet'}
                    </Button>
                    {/* Plain links, not Inertia visits: these are file downloads. */}
                    <Button asChild variant="ghost" size="sm">
                        <a href="/purchasing/suppliers/export">Export CSV</a>
                    </Button>
                    <Button asChild variant="ghost" size="sm">
                        <a href="/purchasing/suppliers/template">Download blank template</a>
                    </Button>
                </div>
                <p className="text-muted-foreground text-xs">Export, edit in a spreadsheet, import back - the same columns both ways.</p>
            </div>

            {open && (
                <div className="border-sidebar-border/70 dark:border-sidebar-border border-t p-3">
                    <form onSubmit={submitReview} className="flex flex-wrap items-center gap-3">
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            aria-label="Supplier CSV"
                            onChange={(e) => review.setData('file', e.target.files?.[0] ?? null)}
                            className="text-sm"
                        />
                        <Button disabled={review.processing || !review.data.file}>Check the file</Button>
                        <span className="text-muted-foreground text-xs">Nothing is written until you confirm.</span>
                    </form>

                    {page.errors?.file && <p className="text-destructive mt-2 text-sm">{page.errors.file}</p>}

                    {plan && (
                        <div className="bg-muted/40 mt-4 flex flex-col gap-3 rounded-md p-3 text-sm">
                            <p className="font-medium">
                                Read {plan.rowCount} row{plan.rowCount === 1 ? '' : 's'}: {plan.creates.length} to create, {plan.updates.length} to
                                update, {plan.unchanged} unchanged.
                            </p>

                            {plan.errors.length > 0 && (
                                <div className="border-destructive/50 bg-destructive/10 rounded-md border p-2">
                                    <p className="font-medium">Nothing will be written until every one of these is fixed:</p>
                                    <ul className="text-muted-foreground list-inside list-disc">
                                        {plan.errors.map((e) => (
                                            <li key={e}>{e}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {plan.warnings.length > 0 && (
                                <div>
                                    <p className="font-medium">Warnings - these do not stop the import:</p>
                                    <ul className="text-muted-foreground list-inside list-disc">
                                        {plan.warnings.map((w) => (
                                            <li key={w}>{w}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {plan.creates.length > 0 && (
                                <div>
                                    <p className="font-medium">Would create:</p>
                                    <ul className="text-muted-foreground list-inside list-disc">
                                        {plan.creates.map((c) => (
                                            <li key={c.code}>
                                                {c.code} - {c.name}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {plan.updates.length > 0 && (
                                <div>
                                    <p className="font-medium">Would update:</p>
                                    <ul className="text-muted-foreground list-inside list-disc">
                                        {plan.updates.map((u) => (
                                            <li key={u.code}>
                                                {u.code} - {u.changes.join('; ')}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {/* ⚠ Named so the number is never a surprise. These are
                                left completely alone - a supplier with purchase
                                history must not vanish because somebody imported a
                                shorter spreadsheet. */}
                            {plan.absentFromFile.length > 0 && (
                                <p className="text-muted-foreground">
                                    In the business but not in this file, left untouched: {plan.absentFromFile.join(', ')}
                                </p>
                            )}

                            {plan.errors.length === 0 && plan.creates.length + plan.updates.length > 0 && (
                                <form onSubmit={submitApply}>
                                    <Button disabled={apply.processing}>
                                        Import {plan.creates.length + plan.updates.length} supplier
                                        {plan.creates.length + plan.updates.length === 1 ? '' : 's'}
                                    </Button>
                                </form>
                            )}
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
