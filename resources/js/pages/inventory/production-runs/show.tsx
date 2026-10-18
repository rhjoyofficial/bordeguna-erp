import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';

interface LineRow {
    id: number;
    product: string | null;
    sku: string | null;
    unit: string | null;
    quantity_consumed: string;
    standard_quantity: string | null;
    waste_quantity: string;
    consumed_value: string | null;
    waste_value: string | null;
}

interface MovementRow {
    id: number;
    product: string | null;
    direction: string;
    quantity: string;
    unit_cost: string;
    batch_number: string | null;
    batch_id: number | null;
    reverses_movement_id: number | null;
    occurred_at: string | null;
}

interface YieldCheck {
    checked: boolean;
    reason?: string;
    content_unit?: string | null;
    expected_content?: string;
    net_consumed?: string;
    converted_from?: string[];
    deviation_percent?: number;
    tolerance_percent?: number;
    flagged?: boolean;
}

interface Run {
    id: number;
    run_number: string;
    status: string;
    warehouse: string | null;
    output_product: string | null;
    output_sku: string | null;
    output_unit: string | null;
    output_quantity: string;
    bill_of_material: { id: number; version: number; name: string | null } | null;
    output_expiry_date: string | null;
    output_mrp: string | null;
    expense_waste: boolean;
    consumed_value: string | null;
    waste_value: string | null;
    yield_check: YieldCheck | null;
    occurred_at: string | null;
    note: string | null;
    created_by: string | null;
    confirmed_by: string | null;
    confirmed_at: string | null;
    reversed_by: string | null;
    reversed_at: string | null;
    output_batch: {
        id: number;
        held: boolean;
        batch_number: string | null;
        unit_cost: string;
        manufactured_date: string | null;
        expiry_date: string | null;
        mrp: string | null;
        current_quantity: string;
    } | null;
    lines: LineRow[];
    movements: MovementRow[];
}

const money = (value: string | null) => formatMoney(value);

const percent = (waste: string, consumed: string) => {
    const w = Number(waste);
    const c = Number(consumed);

    return c > 0 ? `${((w / c) * 100).toFixed(1)}%` : '-';
};

export default function ProductionRunShow({
    run,
    journal,
    canReverse,
}: {
    run: Run;
    journal: { id: number; journal_number: string } | null;
    canReverse: boolean;
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const { t } = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        ...inventoryCrumbs('Production', 'Runs'),
        { title: run.run_number, href: `/inventory/production-runs/${run.id}` },
    ];

    const wastePercentByValue =
        run.consumed_value && Number(run.consumed_value) > 0 && run.waste_value !== null
            ? `${((Number(run.waste_value) / Number(run.consumed_value)) * 100).toFixed(2)}%`
            : '-';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={run.run_number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-3 text-xl font-semibold">
                            {run.run_number}
                            <Badge variant={run.status === 'confirmed' ? 'default' : run.status === 'draft' ? 'secondary' : 'destructive'}>
                                {run.status}
                            </Badge>
                            {run.yield_check?.flagged && <Badge variant="destructive">yield deviation</Badge>}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {run.warehouse} · {showDate(run.occurred_at)} · entered by {run.created_by}
                            {run.confirmed_by && ` · confirmed by ${run.confirmed_by}`}
                            {run.reversed_by && ` · reversed by ${run.reversed_by} at ${showDate(run.reversed_at)}`}
                        </p>
                        {run.note && <p className="mt-1 text-sm">{run.note}</p>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {/* ⭐ Traceability M1 (17 Oct 2026): the jar label with batch no. MFG EXP MRP. */}
                        {run.status === 'confirmed' && run.output_batch && (
                            <Button variant="outline" asChild>
                                <a href={`/inventory/production-runs/${run.id}/labels`} target="_blank" rel="noopener noreferrer">
                                    <Printer className="size-4" />
                                    Print batch labels
                                </a>
                            </Button>
                        )}
                        {run.status === 'draft' && (
                            <>
                                <Button onClick={() => router.post(`/inventory/production-runs/${run.id}/confirm`)}>Confirm run</Button>
                                {/* MFC-009 (14 Oct 2026): asked first, never one click. */}
                                <ConfirmDialog
                                    trigger={<Button variant="destructive">Delete draft</Button>}
                                    title={t('production.delete_draft.title', { number: run.run_number })}
                                    description={t('production.delete_draft.description')}
                                    confirmLabel={t('production.delete_draft.confirm')}
                                    cancelLabel={t('production.cancel')}
                                    destructive
                                    onConfirm={() => router.delete(`/inventory/production-runs/${run.id}`)}
                                />
                            </>
                        )}
                        {/* MFC-009 (14 Oct 2026): a reversal writes reversing movements and
                            a journal dated today, so it is confirmed in a dialog first. */}
                        {run.status === 'confirmed' && (
                            <ConfirmDialog
                                trigger={
                                    <Button
                                        variant="destructive"
                                        disabled={!canReverse}
                                        title={
                                            canReverse
                                                ? 'Restores every consumed portion and consumes the produced batch back.'
                                                : 'The output batch has already been partly sold or consumed.'
                                        }
                                    >
                                        Reverse run
                                    </Button>
                                }
                                title={t('production.reverse.title', { number: run.run_number })}
                                description={t('production.reverse.description')}
                                confirmLabel={t('production.reverse.confirm')}
                                cancelLabel={t('production.cancel')}
                                destructive
                                onConfirm={() => router.post(`/inventory/production-runs/${run.id}/reverse`)}
                            />
                        )}
                    </div>
                </div>

                <InputError message={errors.run} />

                <div className="grid gap-4 md:grid-cols-4">
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Output</p>
                        <p className="font-medium">
                            {formatQuantity(run.output_quantity)} {run.output_unit} · {run.output_product}
                        </p>
                        <p className="text-muted-foreground text-xs">{run.output_sku}</p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Consumed value (FIFO)</p>
                        <p className="font-medium tabular-nums">{money(run.consumed_value)}</p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Waste value · share of consumed</p>
                        <p className="font-medium tabular-nums">
                            {money(run.waste_value)} · {wastePercentByValue}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {run.expense_waste ? 'Expensed to the loss account' : 'Absorbed into the output cost'}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Output batch cost</p>
                        <p className="font-medium tabular-nums">{run.output_batch ? money(run.output_batch.unit_cost) : '-'}</p>
                        <p className="text-muted-foreground text-xs">per {run.output_unit ?? 'unit'}</p>
                    </div>
                </div>

                {run.output_batch && (
                    <div className="rounded-lg border p-4">
                        <h2 className="flex flex-wrap items-center gap-2 font-medium">
                            Output batch
                            {run.output_batch.held && <Badge variant="destructive">on hold</Badge>}
                            <Link
                                href={`/inventory/batches/${run.output_batch.id}`}
                                className="text-muted-foreground text-sm font-normal underline underline-offset-2"
                            >
                                Trace this batch
                            </Link>
                        </h2>
                        <div className="mt-2 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <span className="text-muted-foreground">Batch no.</span> {run.output_batch.batch_number}
                            </div>
                            <div>
                                <span className="text-muted-foreground">MFG</span> {formatDate(run.output_batch.manufactured_date)}
                            </div>
                            <div>
                                <span className="text-muted-foreground">EXP</span> {formatDate(run.output_batch.expiry_date)}
                            </div>
                            <div>
                                <span className="text-muted-foreground">MRP</span> {run.output_batch.mrp ? money(run.output_batch.mrp) : '-'}
                            </div>
                            <div>
                                <span className="text-muted-foreground">On hand</span> {formatQuantity(run.output_batch.current_quantity)}
                            </div>
                        </div>
                    </div>
                )}

                {run.yield_check && (
                    <div className={`rounded-lg border p-4 ${run.yield_check.flagged ? 'border-destructive/50 bg-destructive/5' : ''}`}>
                        <h2 className="font-medium">Yield check</h2>
                        {run.yield_check.checked ? (
                            <p className="text-muted-foreground mt-1 text-sm">
                                Expected content {formatQuantity(run.yield_check.expected_content)} {run.yield_check.content_unit} · consumed net of
                                waste {formatQuantity(run.yield_check.net_consumed)} {run.yield_check.content_unit} · deviation{' '}
                                <span className={run.yield_check.flagged ? 'text-destructive font-medium' : ''}>
                                    {formatQuantity(run.yield_check.deviation_percent)}%
                                </span>{' '}
                                against a tolerance of {formatQuantity(run.yield_check.tolerance_percent)}%.{' '}
                                {run.yield_check.flagged ? 'Outside tolerance.' : 'Within tolerance.'}
                                {run.yield_check.converted_from && run.yield_check.converted_from.length > 0 && (
                                    <>
                                        {' '}
                                        Inputs in {run.yield_check.converted_from.join(', ')} were converted to {run.yield_check.content_unit}.
                                    </>
                                )}
                            </p>
                        ) : (
                            <p className="text-muted-foreground mt-1 text-sm">
                                Not checked:{' '}
                                {run.yield_check.reason === 'no_declared_content'
                                    ? 'the output product declares no net content.'
                                    : 'no input line is stocked in the declared content unit or one that converts to it (g and kg, mL and L).'}
                            </p>
                        )}
                    </div>
                )}

                <div className="rounded-lg border">
                    <h2 className="border-b p-3 font-medium">
                        Inputs consumed
                        {run.bill_of_material && (
                            <span className="text-muted-foreground ml-2 text-sm font-normal">
                                {/*
                                    ⚠ C-BOM-2. Provenance. The recipe was COPIED at
                                    the moment this run was drafted; it may well
                                    have changed since, and this run still says what
                                    it consumed.
                                */}
                                built from recipe v{run.bill_of_material.version}
                                {run.bill_of_material.name ? ` (${run.bill_of_material.name})` : ''}, as it stood that day
                            </span>
                        )}
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Product</th>
                                    <th className="p-3 text-right">Consumed</th>
                                    {/*
                                        C-BOM-3. The whole point of having a
                                        standard: where the operator departed
                                        from it, and by how much.
                                    */}
                                    <th className="p-3 text-right">Recipe said</th>
                                    <th className="p-3 text-right">Variance</th>
                                    <th className="p-3 text-right">Waste</th>
                                    <th className="p-3 text-right">Waste %</th>
                                    <th className="p-3 text-right">Consumed value</th>
                                    <th className="p-3 text-right">Waste value</th>
                                </tr>
                            </thead>
                            <tbody>
                                {run.lines.map((line) => (
                                    <tr key={line.id} className="border-t">
                                        <td className="p-3">
                                            {line.product} <span className="text-muted-foreground text-xs">{line.sku}</span>
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {formatQuantity(line.quantity_consumed)} {line.unit}
                                        </td>
                                        <td className="text-muted-foreground p-3 text-right tabular-nums">
                                            {line.standard_quantity === null ? '-' : `${formatQuantity(line.standard_quantity)} ${line.unit ?? ''}`}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {(() => {
                                                if (line.standard_quantity === null) {
                                                    // Not "zero variance": the recipe never
                                                    // mentioned this component, and a line
                                                    // added by hand is the interesting one.
                                                    return <span className="text-muted-foreground text-xs">added by hand</span>;
                                                }

                                                const variance = Number(line.quantity_consumed) - Number(line.standard_quantity);

                                                if (Math.abs(variance) < 0.00005) {
                                                    return <span className="text-muted-foreground">0</span>;
                                                }

                                                return (
                                                    <span className={variance > 0 ? 'text-amber-600' : ''}>
                                                        {variance > 0 ? '+' : ''}
                                                        {formatQuantity(Math.round(variance * 10000) / 10000)} {line.unit}
                                                    </span>
                                                );
                                            })()}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {formatQuantity(line.waste_quantity)} {line.unit}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{percent(line.waste_quantity, line.quantity_consumed)}</td>
                                        <td className="p-3 text-right tabular-nums">{money(line.consumed_value)}</td>
                                        <td className="p-3 text-right tabular-nums">{money(line.waste_value)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {run.movements.length > 0 && (
                    <div className="rounded-lg border">
                        <h2 className="border-b p-3 font-medium">Stock movements (batch traceability)</h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Product</th>
                                        <th className="p-3">Direction</th>
                                        <th className="p-3 text-right">Quantity</th>
                                        <th className="p-3 text-right">Batch cost</th>
                                        <th className="p-3">Batch</th>
                                        <th className="p-3">Undoes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {run.movements.map((movement) => (
                                        <tr key={movement.id} className="border-t">
                                            <td className="p-3">{movement.product}</td>
                                            <td className="p-3">{movement.direction}</td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(movement.quantity)}</td>
                                            <td className="p-3 text-right tabular-nums">{money(movement.unit_cost)}</td>
                                            <td className="p-3">
                                                {movement.batch_id ? (
                                                    <Link
                                                        href={`/inventory/batches/${movement.batch_id}`}
                                                        className="underline-offset-2 hover:underline"
                                                    >
                                                        {movement.batch_number ?? 'No number'}
                                                    </Link>
                                                ) : (
                                                    (movement.batch_number ?? '-')
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3">
                                                {movement.reverses_movement_id ? `#${movement.reverses_movement_id}` : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                <div className="rounded-lg border p-4 text-sm">
                    <h2 className="font-medium">Accounting</h2>
                    {journal ? (
                        <p className="text-muted-foreground mt-1">
                            Posted journal <span className="font-medium">{journal.journal_number}</span>, in Accounting → Journals.
                        </p>
                    ) : (
                        <p className="text-muted-foreground mt-1">
                            {run.status === 'draft' ? 'Nothing posts until the run is confirmed.' : 'No journal posted.'}
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
