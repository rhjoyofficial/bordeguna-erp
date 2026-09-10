import { Field, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
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

const quantity = (value: string | null) => (value === null ? '' : Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 }));

/**
 * One line of the entry table. Counted quantity (and, when the count exceeds
 * the book, a unit cost with the newest batch cost suggested) saves per row -
 * a person walking the floor with a phone records shelf by shelf.
 */
function LineRow({ line, countId, editable }: { line: Line; countId: number; editable: boolean }) {
    const [counted, setCounted] = useState(line.counted_quantity ?? '');
    const [cost, setCost] = useState(line.unit_cost ?? '');
    const [dirty, setDirty] = useState(false);

    const live = line.live_quantity ?? '0';
    const drifted = line.counted_quantity !== null && Number(live) !== Number(line.expected_quantity);
    const over = counted !== '' && Number(counted) > Number(live);

    const save = () =>
        router.patch(
            route('inventory.stock-counts.lines.update', [countId, line.id]),
            {
                counted_quantity: counted === '' ? null : counted,
                unit_cost: cost === '' ? (over && line.suggested_unit_cost !== null ? line.suggested_unit_cost : null) : cost,
            },
            { preserveScroll: true, onSuccess: () => setDirty(false) },
        );

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
                {quantity(live)}
                {drifted && (
                    <p className="text-xs font-medium text-amber-600 dark:text-amber-500" title="Stock moved while this count was open. Recount this line before completing.">
                        moved since the sheet was printed
                    </p>
                )}
            </td>
            {editable ? (
                <>
                    <td className="p-2 text-right">
                        <Input
                            type="number"
                            inputMode="decimal"
                            className="ml-auto w-28 text-right"
                            value={counted}
                            onChange={(e) => {
                                setCounted(e.target.value);
                                setDirty(true);
                            }}
                            placeholder="not counted"
                        />
                    </td>
                    <td className="p-2 text-right">
                        {over && (
                            <Input
                                type="number"
                                inputMode="decimal"
                                className="ml-auto w-28 text-right"
                                value={cost}
                                onChange={(e) => {
                                    setCost(e.target.value);
                                    setDirty(true);
                                }}
                                placeholder={line.suggested_unit_cost ?? 'cost each'}
                                title="Counted above the book quantity: the found stock needs a unit cost. The newest batch cost is suggested."
                            />
                        )}
                    </td>
                    <td className="p-2 text-right">
                        <Button size="sm" variant={dirty ? 'default' : 'ghost'} onClick={save} disabled={!dirty}>
                            Save
                        </Button>
                    </td>
                </>
            ) : (
                <>
                    <td className="p-3 text-right">{line.counted_quantity === null ? <span className="text-muted-foreground">not counted</span> : quantity(line.counted_quantity)}</td>
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
}: {
    count: Count;
    lines: Line[];
    reasons: Option[];
    addableProducts: Option[];
    canWrite: boolean;
}) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const editable = count.status === 'draft' && canWrite;

    const completeForm = useForm({ reason_id: '' });
    const addForm = useForm({ product_id: '' });

    const complete: FormEventHandler = (e) => {
        e.preventDefault();
        completeForm.post(route('inventory.stock-counts.complete', count.id), { preserveScroll: true });
    };

    const addProduct: FormEventHandler = (e) => {
        e.preventDefault();
        addForm.post(route('inventory.stock-counts.lines.store', count.id), {
            preserveScroll: true,
            onSuccess: () => addForm.reset(),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Inventory', href: '/inventory' },
        { title: 'Stock counts', href: '/inventory/stock-counts' },
        { title: count.count_number, href: `/inventory/stock-counts/${count.id}` },
    ];

    const skipped = lines.filter((line) => line.counted_quantity === null).length;

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
                        <Badge variant={count.status === 'completed' ? 'default' : count.status === 'cancelled' ? 'secondary' : 'outline'}>{count.status}</Badge>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/inventory/stock-counts/${count.id}/sheet`}>Print blind sheet</Link>
                        </Button>
                        {editable && (
                            <Button variant="ghost" size="sm" onClick={() => router.post(route('inventory.stock-counts.cancel', count.id), {}, { preserveScroll: true })}>
                                Cancel count
                            </Button>
                        )}
                    </div>
                </div>

                {errors.count && <div className="rounded-md border border-red-500/50 bg-red-500/10 p-3 text-sm">{errors.count}</div>}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Product</th>
                                <th className="p-3 text-right">Book at start</th>
                                <th className="p-3 text-right">Book now</th>
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
                                <LineRow key={line.id} line={line} countId={count.id} editable={editable} />
                            ))}
                            {lines.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-4 text-center">
                                        Nothing in scope: the book says this warehouse holds no stock matching the filter.
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
                                        <SearchSelect id="product_id" value={addForm.data.product_id} onChange={(e) => addForm.setData('product_id', e.target.value)}>
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

                        <form onSubmit={complete} className="border-sidebar-border/70 dark:border-sidebar-border bg-card rounded-xl border p-3">
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
                                    <SearchSelect id="reason_id" value={completeForm.data.reason_id} onChange={(e) => completeForm.setData('reason_id', e.target.value)}>
                                        <option value="">Choose a reason</option>
                                        {reasons.map((reason) => (
                                            <option key={reason.id} value={reason.id}>
                                                {reason.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                                <Button disabled={completeForm.processing} className="self-end">
                                    Complete the count
                                </Button>
                            </FormRow>
                        </form>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
