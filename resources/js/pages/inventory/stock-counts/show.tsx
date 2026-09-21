import { ConfirmDialog } from '@/components/confirm-dialog';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { AwaitingApprovalNotice } from '@/components/inventory/awaiting-approval-notice';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Adjustment {
    id: number;
    direction: string;
    quantity: string;
    status: string | null;
}

interface Line {
    id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    expected_quantity: string;
    counted_quantity: string | null;
    live_quantity: string | null;
    unit_cost: string | null;
    suggested_unit_cost: string | null;
    adjustment: Adjustment | null;
}

interface Count {
    id: number;
    count_number: string;
    status: string;
    warehouse: string | null;
    category: string | null;
    brand: string | null;
    reason: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    completed_by: string | null;
    completed_at: string | null;
}

interface Option {
    id: number;
    name: string;
    sku?: string;
}

/** What has been typed into a row, saved or not. */
interface Draft {
    counted: string;
    cost: string;
}

const quantity = (value: string | null) => (value === null ? '' : Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 }));

const draftFrom = (line: Line): Draft => ({ counted: line.counted_quantity ?? '', cost: line.unit_cost ?? '' });

/**
 * What a row sends. ⚠ When the count is above the book and no cost was typed,
 * the newest batch cost is sent as the SUGGESTION it is shown as (C-CNT-4 via
 * C-SC-1) - unchanged from the per-row save this replaces.
 */
const payloadFor = (line: Line, draft: Draft) => {
    const over = draft.counted !== '' && Number(draft.counted) > Number(line.live_quantity ?? '0');

    return {
        counted_quantity: draft.counted === '' ? null : draft.counted,
        unit_cost: draft.cost === '' ? (over && line.suggested_unit_cost !== null ? line.suggested_unit_cost : null) : draft.cost,
    };
};

/**
 * One line of the entry table. Counted quantity (and, when the count exceeds
 * the book, a unit cost with the newest batch cost suggested). A person
 * walking the floor with a phone still saves shelf by shelf; the page-level
 * "Save all" catches every row they did not.
 */
function LineRow({
    line,
    draft,
    dirty,
    editable,
    errors,
    onChange,
    onSave,
    saving,
}: {
    line: Line;
    draft: Draft;
    dirty: boolean;
    editable: boolean;
    errors: Record<string, string>;
    onChange: (draft: Draft) => void;
    onSave: () => void;
    saving: boolean;
}) {
    const live = line.live_quantity ?? '0';
    const drifted = line.counted_quantity !== null && Number(live) !== Number(line.expected_quantity);
    const over = draft.counted !== '' && Number(draft.counted) > Number(live);
    const countedError = errors[`lines.${line.id}.counted_quantity`];
    const costError = errors[`lines.${line.id}.unit_cost`];

    return (
        <tr className="border-b align-top">
            <td className="p-3">
                <p>{line.product}</p>
                {line.sku && <p className="text-muted-foreground text-xs">{line.sku}</p>}
            </td>
            <td className="p-3 text-right">
                {quantity(line.expected_quantity)}
                {line.unit && <span className="text-muted-foreground"> {line.unit}</span>}
            </td>
            <td className="p-3 text-right">
                {/* A completed count stores the live figure only for lines it counted;
                    an uncounted one was never measured, which is not zero. */}
                {!editable && line.live_quantity === null ? <span className="text-muted-foreground">-</span> : quantity(live)}
                {drifted && (
                    <p
                        className="text-xs font-medium text-amber-600 dark:text-amber-500"
                        title="Stock moved while this count was open. Recount this line before completing."
                    >
                        moved since the sheet was printed
                    </p>
                )}
            </td>
            {editable ? (
                <>
                    <td className="p-2 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                            <Input
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="any"
                                aria-invalid={!!countedError}
                                // ⚠ "Not saved" is the box's colour, not a line under it: a line
                                // grew the row and moved every row below it under the thumb.
                                className={cn('w-28 text-right', dirty && !countedError && 'border-amber-500 ring-1 ring-amber-500/40')}
                                title={dirty ? 'Typed, not saved yet' : undefined}
                                value={draft.counted}
                                onChange={(e) => onChange({ ...draft, counted: e.target.value })}
                                placeholder="not counted"
                            />
                            {line.unit && <span className="text-muted-foreground text-xs">{line.unit}</span>}
                        </div>
                        {/* ⚠ Line errors were never rendered (21 Sep 2026 audit): a refused
                            number simply stayed in the box looking saved. */}
                        <InputError message={countedError} className="text-xs" />
                    </td>
                    <td className="p-2 text-right">
                        {over && (
                            <Input
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="any"
                                aria-invalid={!!costError}
                                className="ml-auto w-28 text-right"
                                value={draft.cost}
                                onChange={(e) => onChange({ ...draft, cost: e.target.value })}
                                placeholder={line.suggested_unit_cost ?? 'cost each'}
                                title="Counted above the book quantity: the found stock needs a unit cost. The newest batch cost is suggested."
                            />
                        )}
                        <InputError message={costError} className="text-xs" />
                    </td>
                    <td className="p-2 text-right">
                        <Button size="sm" variant={dirty ? 'default' : 'ghost'} onClick={onSave} disabled={!dirty || saving}>
                            Save
                        </Button>
                    </td>
                </>
            ) : (
                <>
                    <td className="p-3 text-right">
                        {line.counted_quantity === null ? (
                            <span className="text-muted-foreground">not counted</span>
                        ) : (
                            quantity(line.counted_quantity)
                        )}
                    </td>
                    <td className="p-3 text-right" colSpan={2}>
                        {line.adjustment === null ? (
                            line.counted_quantity === null ? (
                                <span className="text-muted-foreground">skipped</span>
                            ) : (
                                <span className="text-muted-foreground">matched</span>
                            )
                        ) : (
                            <span>
                                {line.adjustment.direction === 'increase' ? '+' : '-'}
                                {quantity(line.adjustment.quantity)}
                                <Badge variant="outline" className="ml-2">
                                    {line.adjustment.status ?? 'recorded'}
                                </Badge>
                            </span>
                        )}
                    </td>
                </>
            )}
        </tr>
    );
}

export default function StockCountShow({
    count,
    lines,
    reasons,
    addableProducts,
    canWrite,
    awaitingApproval,
}: {
    count: Count;
    lines: Line[];
    reasons: Option[];
    addableProducts: Option[];
    canWrite: boolean;
    awaitingApproval?: string | null;
}) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const editable = count.status === 'draft' && canWrite;

    // ⚠⚠ THE TYPED NUMBERS LIVE HERE, NOT IN EACH ROW (21 Sep 2026). Complete
    // reads only what was SAVED, so a row typed and not saved was silently
    // "skipped", and each row kept its "unsaved" state to itself where nothing
    // could see it. Held at page level, the page can count what is unsaved,
    // save it all at once, and never complete past it without saying so.
    const [drafts, setDrafts] = useState<Record<number, Draft>>({});
    const [dirtyIds, setDirtyIds] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    const completeForm = useForm({ reason_id: '' });
    const addForm = useForm({ product_id: '' });

    const draftOf = (line: Line) => drafts[line.id] ?? draftFrom(line);

    const change = (line: Line, draft: Draft) => {
        setDrafts((current) => ({ ...current, [line.id]: draft }));
        setDirtyIds((current) => (current.includes(line.id) ? current : [...current, line.id]));
    };

    /**
     * Saves the given rows in ONE request. ⚠ preserveState stays true (Inertia's
     * own default for a PATCH, written out so nobody "tidies" it): a remount
     * would throw away what the rows NOT being saved still hold.
     */
    const save = (ids: number[], after?: () => void) => {
        const payload = Object.fromEntries(lines.filter((line) => ids.includes(line.id)).map((line) => [line.id, payloadFor(line, draftOf(line))]));

        setSaving(true);
        router.patch(
            route('inventory.stock-counts.lines.update-many', count.id),
            { lines: payload },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setDirtyIds((current) => current.filter((id) => !ids.includes(id)));
                    after?.();
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const complete = () => {
        const post = () => completeForm.post(route('inventory.stock-counts.complete', count.id), { preserveScroll: true });

        // Unsaved rows go in first, or they would be skipped as "nobody looked".
        if (dirtyIds.length > 0) {
            save(dirtyIds, post);
        } else {
            post();
        }
    };

    const addProduct: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('inventory.stock-counts.lines.store', count.id), {
            preserveScroll: true,
            // ⚠ Keeps the unsaved numbers in the other rows (as the PATCH above).
            preserveState: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inventory', href: '/inventory' },
        { title: 'Stock counts', href: '/inventory/stock-counts' },
        { title: count.count_number, href: `/inventory/stock-counts/${count.id}` },
    ];

    const skipped = lines.filter((line) => draftOf(line).counted === '').length;
    const unsaved = dirtyIds.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={count.count_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <ReportHeading
                        title={count.count_number}
                        description={`${count.warehouse ?? ''}${count.category ? `, ${count.category}` : ''}${count.brand ? `, ${count.brand}` : ''} · started ${count.created_at ?? ''} by ${count.created_by ?? ''}${
                            count.status === 'completed' ? ` · completed ${count.completed_at ?? ''} by ${count.completed_by ?? ''}` : ''
                        }`}
                    />
                    <div className="flex items-center gap-2">
                        <Badge variant={count.status === 'completed' ? 'default' : count.status === 'cancelled' ? 'secondary' : 'outline'}>
                            {count.status}
                        </Badge>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/inventory/stock-counts/${count.id}/sheet`}>Print blind sheet</Link>
                        </Button>
                        {editable && (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        Cancel count
                                    </Button>
                                }
                                title={`Cancel ${count.count_number}?`}
                                description="Nothing is corrected and the numbers typed so far are kept only as a record. Start a new count to count again."
                                confirmLabel="Cancel the count"
                                cancelLabel="Keep counting"
                                destructive
                                onConfirm={() => router.post(route('inventory.stock-counts.cancel', count.id), {}, { preserveScroll: true })}
                            />
                        )}
                    </div>
                </div>

                <AwaitingApprovalNotice message={awaitingApproval} />

                {errors.count && <div className="rounded-md border border-red-500/50 bg-red-500/10 p-3 text-sm">{errors.count}</div>}

                {/* ⚠ Always there while counting, and the same height either way: when it
                    appeared only once something was typed, the table jumped down under the
                    counter's thumb and the next tap landed on the wrong row (seen in the
                    21 Sep walk). */}
                {editable && (
                    <div
                        className={cn(
                            'flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm',
                            unsaved > 0 ? 'border-amber-500/40 bg-amber-500/5' : 'text-muted-foreground',
                        )}
                    >
                        <span>
                            {unsaved > 0 ? `${unsaved} ${unsaved === 1 ? 'row is' : 'rows are'} typed but not saved.` : 'Every typed count is saved.'}
                        </span>
                        <Button size="sm" onClick={() => save(dirtyIds)} disabled={saving || unsaved === 0}>
                            Save all
                        </Button>
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">In system at start</th>
                                <th className="p-3 text-right">In system now</th>
                                <th className="p-3 text-right">Counted</th>
                                {editable ? (
                                    <>
                                        <th className="p-3 text-right">Cost (found stock)</th>
                                        <th className="p-3 text-right"></th>
                                    </>
                                ) : (
                                    <th className="p-3 text-right" colSpan={2}>
                                        Result
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((line) => (
                                <LineRow
                                    key={line.id}
                                    line={line}
                                    draft={draftOf(line)}
                                    dirty={dirtyIds.includes(line.id)}
                                    editable={editable}
                                    errors={errors}
                                    onChange={(draft) => change(line, draft)}
                                    onSave={() => save([line.id])}
                                    saving={saving}
                                />
                            ))}
                            {lines.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                        Nothing in scope: the system says this warehouse holds no stock matching the filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {editable && (
                    <div className="flex flex-col gap-4">
                        {addableProducts.length > 0 && (
                            <form onSubmit={addProduct} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                                <FormRow className="gap-3">
                                    <Field
                                        label="Found something the book does not list here?"
                                        htmlFor="product_id"
                                        required
                                        size="lg"
                                        error={addForm.errors.product_id}
                                        hint="Adds a line with a book quantity of whatever the system holds (usually zero), so the find becomes a counted variance."
                                    >
                                        <SearchSelect
                                            id="product_id"
                                            value={addForm.data.product_id}
                                            onChange={(e) => addForm.setData('product_id', e.target.value)}
                                        >
                                            <option value="">Choose the product found</option>
                                            {addableProducts.map((product) => (
                                                <option key={product.id} value={product.id}>
                                                    {product.name} {product.sku ? `(${product.sku})` : ''}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </Field>
                                    <Button disabled={addForm.processing} className="self-end">
                                        Add line
                                    </Button>
                                </FormRow>
                            </form>
                        )}

                        <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
                            <FormRow className="gap-3">
                                <Field
                                    label="Reason for the corrections"
                                    htmlFor="reason_id"
                                    required
                                    size="md"
                                    error={completeForm.errors.reason_id}
                                    hint={
                                        skipped > 0
                                            ? `${skipped} uncounted line${skipped === 1 ? ' is' : 's are'} skipped, not written off: a blank means nobody looked.`
                                            : 'Every line is counted.'
                                    }
                                >
                                    <SearchSelect
                                        id="reason_id"
                                        value={completeForm.data.reason_id}
                                        onChange={(e) => completeForm.setData('reason_id', e.target.value)}
                                    >
                                        <option value="">Choose a reason</option>
                                        {reasons.map((reason) => (
                                            <option key={reason.id} value={reason.id}>
                                                {reason.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                                {/* ⚠ A confirmation, because Complete turns every difference into a
                                    stock correction and cannot be undone from this page. */}
                                <ConfirmDialog
                                    trigger={
                                        <Button type="button" disabled={completeForm.processing || saving} className="self-end">
                                            Complete the count
                                        </Button>
                                    }
                                    title={`Complete ${count.count_number}?`}
                                    description={[
                                        unsaved > 0
                                            ? `${unsaved} typed ${unsaved === 1 ? 'row is' : 'rows are'} not saved yet and will be saved first.`
                                            : null,
                                        skipped > 0
                                            ? `${skipped} line${skipped === 1 ? ' has' : 's have'} no count and will be skipped, not written off.`
                                            : null,
                                        'Every difference between the count and the system becomes a stock correction.',
                                    ]
                                        .filter(Boolean)
                                        .join(' ')}
                                    confirmLabel="Complete the count"
                                    cancelLabel="Keep counting"
                                    onConfirm={complete}
                                />
                            </FormRow>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
