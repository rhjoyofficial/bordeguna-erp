import { Pagination } from '@/components/data-table/pagination';
import { CompactControls } from '@/components/form/compact-controls';
import { Field } from '@/components/form/field';
import InputError from '@/components/input-error';
import { AwaitingApprovalNotice } from '@/components/inventory/awaiting-approval-notice';
import { Chip, CompactTable, Hint, inventoryCrumbs, LevelBar, Pill, Toolbar } from '@/components/inventory/inventory-ui';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, formatDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { type Paginated, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, ListChecks, PackageMinus, ScanSearch, Search } from 'lucide-react';
import { FormEventHandler, useEffect, useState, type ReactNode } from 'react';

interface StockRow {
    product_id: number;
    warehouse_id: number;
    quantity: string;
    /** Of `quantity`, how much is past its expiry date: on hand, not sellable (audit #14). */
    expired_quantity: string | null;
    /** Of `quantity`, how much sits in batches ON HOLD: on hand, never picked (traceability, 17 Oct 2026). */
    held_quantity: string | null;
    product: {
        id: number;
        name: string;
        sku: string;
        low_stock_threshold: string | null;
        unit_of_measure: { id: number; abbreviation: string } | null;
    };
    warehouse: { id: number; name: string };
}

interface ProductOption {
    id: number;
    name: string;
    sku: string;
    unit: string | null;
}

interface Option {
    id: number;
    name: string;
}

/** A stock-adjustment reason, annotated by the server (AdjustmentCause). */
interface AdjustmentReason extends Option {
    type: string;
    /** Damaged, expired, lost: stock can only go down, so the direction is not asked. */
    only_reduces: boolean;
    /** ⭐ B6: "5201 - Stock Loss - Expired", where a loss for this reason posts today. */
    loss_head?: string | null;
}

interface StockContext {
    products: ProductOption[];
    warehouses: Option[];
    defaultWarehouseId: string;
    onHand: Record<string, string>;
}

const breadcrumbs = inventoryCrumbs('Stock', 'Current stock');

/** What a row's "Record expired" button pre-fills: the reason, the product, the warehouse, how many. */
interface CorrectionPreset {
    reason_id: string;
    product_id: string;
    warehouse_id: string;
    quantity: string;
}

/** ⭐ Initial plan B6 (24 Oct 2026): one batch the correction may name (StockController::batchOptions). */
interface BatchOption {
    id: number;
    batch_number: string;
    expiry_date: string | null;
    received_on: string | null;
    current_quantity: string;
    unit_cost: string;
    expired: boolean;
    on_hold: boolean;
}

/** The quantity question for stock that can only go down, by the type the server derived. */
const LOSS_QUESTION: Record<string, string> = {
    damaged: 'How many were damaged',
    expired: 'How many expired',
    lost_or_stolen: 'How many went missing',
    internal_consumption: 'How many were used',
};

/** ⭐ Initial plan B6 (24 Oct 2026): the two loss reasons added by Joy, translated. */
const LOSS_QUESTION_KEY: Record<string, TranslationKey> = {
    spoiled: 'ips.loss.q_spoiled',
    damaged_return: 'ips.loss.q_damaged_return',
};

const quantity = (value: string | number) => Number(value).toLocaleString('en-BD', { maximumFractionDigits: 4 });
const money = (value: string | number) => `৳${Number(value).toLocaleString('en-BD', { maximumFractionDigits: 2 })}`;

function ProductField({
    products,
    value,
    onChange,
    error,
}: {
    products: ProductOption[];
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <Field label="Product" htmlFor="stock-product" required error={error}>
            <SearchableSelect
                id="stock-product"
                value={value}
                onChange={onChange}
                invalid={!!error}
                options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                placeholder="Select product"
                searchPlaceholder="Search products…"
            />
        </Field>
    );
}

function WarehouseField({
    id,
    label,
    warehouses,
    value,
    onChange,
    error,
}: {
    id: string;
    label: string;
    warehouses: Option[];
    value: string;
    onChange: (v: string) => void;
    error?: string;
}) {
    return (
        <Field label={label} htmlFor={id} required error={error}>
            <SearchSelect id={id} value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">Select warehouse</option>
                {warehouses.map((w) => (
                    <option key={w.id} value={w.id}>
                        {w.name}
                    </option>
                ))}
            </SearchSelect>
        </Field>
    );
}

/** A quantity box with the product's unit beside it, and what the book holds. */
function QuantityField({
    label,
    value,
    onChange,
    unit,
    onHand,
    error,
    hint,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    unit: string | null;
    onHand: string | null;
    error?: string;
    hint?: ReactNode;
}) {
    return (
        <Field
            label={label}
            htmlFor="stock-quantity"
            required
            error={error}
            hint={
                <>
                    {onHand !== null && (
                        <span className="block">
                            On hand here: {quantity(onHand)} {unit ?? ''}
                        </span>
                    )}
                    {hint}
                </>
            }
        >
            <div className="flex items-center gap-2">
                <Input
                    id="stock-quantity"
                    aria-required
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="any"
                    className="w-36"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
                {unit && <span className="text-muted-foreground text-sm">{unit}</span>}
            </div>
        </Field>
    );
}

/** Where a refused stock movement says why (C-INV-E1), plus any key no field above renders. */
function FormLevelErrors({ errors, keys }: { errors: Record<string, string | undefined>; keys: string[] }) {
    return (
        <>
            {keys.map((key) => (
                <InputError key={key} message={errors[key]} />
            ))}
        </>
    );
}

/*
 * ⛔ ReceiptForm and UsageForm were REMOVED on 2026-08-04 (C-FLOW-5).
 *
 * They posted to `stock.receipts.store` and `stock.usages.store`, which moved
 * stock and wrote NOTHING to the ledger - value entered and left the warehouse
 * without ever reaching the books, and the outgoing direction lost value with
 * nothing on the profit and loss.
 *
 * Stock now arrives through a goods receipt or the opening stock document, and
 * leaves through a sale or an inventory adjustment. Each of those knows its own
 * accounting consequence. See INVENTORY-ENTRY-POINTS-DESIGN.md.
 */

/**
 * "What happened?" - damage, expiry, loss, found stock, a typing mistake.
 *
 * ⚠⚠ THIS REPLACES TWO FORMS THAT COULD NOT SAVE (21 Sep 2026). The Owner's
 * "Direct adjustment" never sent the `type` the server required, and Staff's
 * "Request adjustment" posted to the stock-request endpoint, which accepts only
 * transfers. Neither refusal was rendered, so nobody could record damage, loss
 * or found stock from this screen at all. Now ONE question picks the reason,
 * the server derives the type from it, and everybody posts to the same
 * adjustment endpoint: whether it saves at once or waits for an approver is the
 * threshold's decision there, never this form's.
 */
function RecordCorrectionDialog({
    context,
    reasons,
    lastCosts,
    canAdjustDirectly,
    canSetThreshold,
    threshold,
    open,
    onOpenChange,
    preset,
}: {
    context: StockContext;
    reasons: AdjustmentReason[];
    lastCosts: Record<string, string>;
    canAdjustDirectly: boolean;
    canSetThreshold: boolean;
    threshold: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** ⭐ Redesign (15 Oct 2026): a row's "Record expired" opens this already filled in. */
    preset: CorrectionPreset | null;
}) {
    const { canManageReasons } = usePage<SharedData>().props;
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        // ⭐ Corrections wave 1 (20 Oct 2026): the day it happened, FIRST (Joy,
        // 19 Oct). The server always took it; the dialog never asked, so every
        // correction was dated the day it was typed.
        effective_date: dhakaToday(),
        reason_id: '',
        product_id: '',
        warehouse_id: context.defaultWarehouseId,
        direction: '',
        quantity: '',
        unit_cost: '',
        note: '',
        // ⭐ B6 (Joy, 24 Oct 2026; R71): the exact batch that was lost; blank = oldest first.
        stock_batch_id: '',
    });
    // ⭐ B6: the batches of this product in this warehouse; null = not asked yet.
    const [batches, setBatches] = useState<BatchOption[] | null>(null);
    const [loadingBatches, setLoadingBatches] = useState(false);

    // Opened from a row: the expired reason, that product, that warehouse, the
    // expired quantity; opened from the toolbar: a blank form, as before.
    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData({
            effective_date: dhakaToday(),
            reason_id: preset?.reason_id ?? '',
            product_id: preset?.product_id ?? '',
            warehouse_id: preset?.warehouse_id ?? context.defaultWarehouseId,
            direction: preset ? 'decrease' : '',
            quantity: preset?.quantity ?? '',
            unit_cost: '',
            note: '',
            stock_batch_id: '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, preset]);

    // ⭐ B6 (24 Oct 2026): ask for the batches whenever stock is going down
    // and the product and warehouse are known. A GET, so no token to send.
    useEffect(() => {
        if (!open || data.direction !== 'decrease' || !data.product_id || !data.warehouse_id) {
            setBatches(null);
            return;
        }

        let cancelled = false;
        setLoadingBatches(true);
        const query = new URLSearchParams({ product_id: data.product_id, warehouse_id: data.warehouse_id });
        fetch(`${route('inventory.stock.batch-options')}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : { batches: [] }))
            .then((body: { batches: BatchOption[] }) => {
                if (!cancelled) setBatches(body.batches);
            })
            .catch(() => {
                if (!cancelled) setBatches([]);
            })
            .finally(() => {
                if (!cancelled) setLoadingBatches(false);
            });

        return () => {
            cancelled = true;
        };
    }, [open, data.direction, data.product_id, data.warehouse_id]);

    const reason = reasons.find((r) => String(r.id) === data.reason_id);
    const product = context.products.find((p) => String(p.id) === data.product_id);
    const onHand = product && data.warehouse_id ? (context.onHand[`${product.id}-${data.warehouse_id}`] ?? '0') : null;
    const lastCost = product ? lastCosts[String(product.id)] : undefined;
    const increasing = data.direction === 'increase';
    const overdraw = !increasing && onHand !== null && data.quantity !== '' && Number(data.quantity) > Number(onHand);
    const chosenBatch = batches?.find((batch) => String(batch.id) === data.stock_batch_id) ?? null;
    const overBatch = chosenBatch !== null && data.quantity !== '' && Number(data.quantity) > Number(chosenBatch.current_quantity);
    const quantityQuestion = reason?.only_reduces
        ? (LOSS_QUESTION[reason.type] ?? (LOSS_QUESTION_KEY[reason.type] ? t(LOSS_QUESTION_KEY[reason.type]) : 'How many'))
        : increasing
          ? 'How many more'
          : data.direction === 'decrease'
            ? 'How many fewer'
            : 'How many';

    const chooseReason = (value: string) => {
        const picked = reasons.find((r) => String(r.id) === value);
        // Damage, expiry and loss only ever take stock away; everything else
        // has to say which way the book is wrong.
        setData((current) => ({
            ...current,
            reason_id: value,
            direction: picked?.only_reduces ? 'decrease' : '',
            unit_cost: picked?.only_reduces ? '' : current.unit_cost,
            stock_batch_id: picked?.only_reduces ? current.stock_batch_id : '',
        }));
        // The old answer's complaints no longer apply to the new one.
        clearErrors('reason_id', 'direction', 'unit_cost');
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.stock.adjustments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);
                if (!next) {
                    clearErrors();
                }
            }}
        >
            <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{preset ? 'Record the expired stock' : 'What happened to the stock?'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <Field label={t('corr.stock.adjust.when')} htmlFor="stock-effective-date" required error={errors.effective_date}>
                        <Input
                            id="stock-effective-date"
                            aria-required
                            type="date"
                            className="w-auto"
                            max={dhakaToday()}
                            value={data.effective_date}
                            onChange={(e) => setData('effective_date', e.target.value)}
                        />
                    </Field>
                    <Field label="What happened?" htmlFor="stock-reason" required error={errors.reason_id}>
                        <SearchSelect id="stock-reason" value={data.reason_id} onChange={(e) => chooseReason(e.target.value)}>
                            <option value="">Choose what happened</option>
                            {reasons.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {reason?.only_reduces && reason.loss_head && (
                            <p className="text-muted-foreground text-xs">{t('ips.loss.head', { head: reason.loss_head })}</p>
                        )}
                    </Field>
                    {reasons.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            This business has no reasons for stock corrections yet.{' '}
                            {canManageReasons ? (
                                <Link href="/settings/reasons" className="underline underline-offset-2">
                                    Add them in Settings
                                </Link>
                            ) : (
                                'Ask a manager to add them in Settings.'
                            )}
                        </p>
                    )}

                    <ProductField
                        products={context.products}
                        value={data.product_id}
                        onChange={(v) => setData((current) => ({ ...current, product_id: v, stock_batch_id: '' }))}
                        error={errors.product_id}
                    />
                    <WarehouseField
                        id="stock-warehouse"
                        label="Warehouse"
                        warehouses={context.warehouses}
                        value={data.warehouse_id}
                        onChange={(v) => setData((current) => ({ ...current, warehouse_id: v, stock_batch_id: '' }))}
                        error={errors.warehouse_id}
                    />

                    {reason && !reason.only_reduces && (
                        <fieldset className="grid gap-2">
                            <legend className="text-sm font-medium">
                                Compared with the system, there is
                                <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                    *
                                </span>
                            </legend>
                            <div className="flex flex-wrap gap-2" role="radiogroup" aria-required>
                                {[
                                    { value: 'decrease', label: 'Less (stock goes down)' },
                                    { value: 'increase', label: 'More (found stock, goes up)' },
                                ].map((option) => (
                                    <label
                                        key={option.value}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm',
                                            data.direction === option.value && 'border-primary bg-primary/5',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="direction"
                                            value={option.value}
                                            checked={data.direction === option.value}
                                            onChange={() => setData((current) => ({ ...current, direction: option.value, stock_batch_id: '' }))}
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.direction} />
                        </fieldset>
                    )}

                    <QuantityField
                        label={quantityQuestion}
                        value={data.quantity}
                        onChange={(v) => setData('quantity', v)}
                        unit={product?.unit ?? null}
                        onHand={onHand}
                        error={errors.quantity}
                        hint={
                            overdraw ? (
                                <span className="block text-amber-600 dark:text-amber-500">That is more than the system holds here.</span>
                            ) : undefined
                        }
                    />

                    {/* ⭐ Initial plan B6 (Joy, 24 Oct 2026; R71): the exact batch that was
                        damaged, spoiled or lost, written off at its own cost. Blank keeps
                        the old way (oldest first). The loss head is unchanged. */}
                    {data.direction === 'decrease' && data.product_id && data.warehouse_id && (
                        <Field
                            label={t('ipc.batch.label')}
                            htmlFor="stock-batch"
                            error={errors.stock_batch_id}
                            hint={
                                <>
                                    <span className="block">{t('ipc.batch.hint')}</span>
                                    {chosenBatch && Number(data.quantity) > 0 && !overBatch && (
                                        <span className="block font-medium">
                                            {t('ipc.batch.loss_at', {
                                                cost: money(chosenBatch.unit_cost),
                                                total: money(Math.round(Number(chosenBatch.unit_cost) * Number(data.quantity) * 100) / 100),
                                            })}
                                        </span>
                                    )}
                                    {overBatch && chosenBatch && (
                                        <span className="block text-amber-600 dark:text-amber-500">
                                            {t('ipc.batch.too_much', { quantity: quantity(chosenBatch.current_quantity) })}
                                        </span>
                                    )}
                                </>
                            }
                        >
                            {loadingBatches && batches === null ? (
                                <p className="text-muted-foreground text-sm">{t('ipc.batch.loading')}</p>
                            ) : batches !== null && batches.length === 0 ? (
                                <p className="text-muted-foreground text-sm">{t('ipc.batch.none')}</p>
                            ) : (
                                <SearchSelect
                                    id="stock-batch"
                                    fit
                                    value={data.stock_batch_id}
                                    onChange={(e) => setData('stock_batch_id', e.target.value)}
                                >
                                    <option value="">{t('ipc.batch.auto')}</option>
                                    {(batches ?? []).map((batch) => (
                                        <option key={batch.id} value={batch.id}>
                                            {[
                                                batch.batch_number,
                                                t('ipc.batch.left', { quantity: quantity(batch.current_quantity), unit: product?.unit ?? '' }),
                                                t('ipc.batch.each', { cost: money(batch.unit_cost) }),
                                                batch.expiry_date ? t('ipc.batch.expires', { date: formatDate(batch.expiry_date) }) : null,
                                                batch.expired ? t('ipc.batch.expired') : null,
                                                batch.on_hold ? t('ipc.batch.on_hold') : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </option>
                                    ))}
                                </SearchSelect>
                            )}
                        </Field>
                    )}

                    {increasing && (
                        <Field
                            label="Cost per unit (৳)"
                            htmlFor="stock-unit-cost"
                            required
                            error={errors.unit_cost}
                            hint={
                                lastCost !== undefined ? (
                                    <>
                                        Last cost {money(lastCost)}.{' '}
                                        <button type="button" className="underline underline-offset-2" onClick={() => setData('unit_cost', lastCost)}>
                                            Use it
                                        </button>
                                    </>
                                ) : undefined
                            }
                        >
                            <Input
                                id="stock-unit-cost"
                                aria-required
                                type="number"
                                inputMode="decimal"
                                min="0"
                                step="any"
                                className="w-36"
                                value={data.unit_cost}
                                onChange={(e) => setData('unit_cost', e.target.value)}
                                placeholder="incl. tax"
                            />
                        </Field>
                    )}

                    <Field label="Note" htmlFor="stock-note" error={errors.note}>
                        <Input id="stock-note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="Any extra detail" />
                    </Field>

                    {/* C-INV-E1. A refused correction says why: a closed month, a service product, a bad type. */}
                    <FormLevelErrors errors={errors as Record<string, string | undefined>} keys={['stock', 'type']} />

                    <p className="text-muted-foreground text-sm">
                        {!canAdjustDirectly ? (
                            'A manager approves this before the stock changes.'
                        ) : threshold === null ? (
                            <>
                                No approval amount is set, so every correction, yours included, waits in Approvals until someone approves it.{' '}
                                {canSetThreshold && (
                                    <Link href="/inventory/settings" className="underline underline-offset-2">
                                        Set an amount
                                    </Link>
                                )}
                            </>
                        ) : (
                            `Corrections worth up to ${money(threshold)} are saved at once; bigger ones wait in Approvals.`
                        )}
                    </p>

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {canAdjustDirectly ? 'Record it' : 'Send for approval'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Moving stock between warehouses. The Owner and Manager dispatch it
 * (it lands once somebody marks it received); Staff ASK, and a manager's
 * approval dispatches it.
 */
function MoveStockDialog({ context, reasons, direct }: { context: StockContext; reasons: Option[]; direct: boolean }) {
    const { canManageReasons } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        // Only the stock-request endpoint reads it; a stock request is only
        // ever a transfer since slice 3.
        type: 'transfer',
        product_id: '',
        warehouse_id: context.defaultWarehouseId,
        target_warehouse_id: '',
        quantity: '',
        reason_id: '',
        note: '',
    });

    const product = context.products.find((p) => String(p.id) === data.product_id);
    const onHand = product && data.warehouse_id ? (context.onHand[`${product.id}-${data.warehouse_id}`] ?? '0') : null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route(direct ? 'inventory.stock.transfers.store' : 'inventory.stock-requests.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">
                    <ArrowLeftRight className="size-4" />
                    {direct ? 'Move stock' : 'Ask to move stock'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{direct ? 'Move stock to another warehouse' : 'Ask to move stock'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <ProductField
                        products={context.products}
                        value={data.product_id}
                        onChange={(v) => setData('product_id', v)}
                        error={errors.product_id}
                    />
                    <div className="flex flex-wrap gap-4">
                        <WarehouseField
                            id="move-from"
                            label="From"
                            warehouses={context.warehouses}
                            value={data.warehouse_id}
                            onChange={(v) => setData('warehouse_id', v)}
                            error={errors.warehouse_id}
                        />
                        <WarehouseField
                            id="move-to"
                            label="To"
                            warehouses={context.warehouses.filter((w) => String(w.id) !== data.warehouse_id)}
                            value={data.target_warehouse_id}
                            onChange={(v) => setData('target_warehouse_id', v)}
                            error={errors.target_warehouse_id}
                        />
                    </div>
                    <QuantityField
                        label="How many"
                        value={data.quantity}
                        onChange={(v) => setData('quantity', v)}
                        unit={product?.unit ?? null}
                        onHand={onHand}
                        error={errors.quantity}
                    />
                    <Field label="Why" htmlFor="move-reason" required error={errors.reason_id}>
                        <SearchSelect id="move-reason" value={data.reason_id} onChange={(e) => setData('reason_id', e.target.value)}>
                            <option value="">Choose a reason</option>
                            {reasons.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    {reasons.length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            This business has no reasons for moving stock yet.{' '}
                            {canManageReasons ? (
                                <Link href="/settings/reasons" className="underline underline-offset-2">
                                    Add them in Settings
                                </Link>
                            ) : (
                                'Ask a manager to add them in Settings.'
                            )}
                        </p>
                    )}
                    <Field label="Note" htmlFor="move-note" error={errors.note}>
                        <Input id="move-note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="Any extra detail" />
                    </Field>

                    {/* C-INV-E1. Not enough stock, a closed month: said here, not a blank page. */}
                    <FormLevelErrors errors={errors as Record<string, string | undefined>} keys={['stock', 'type']} />

                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {direct ? 'Send it' : 'Send for approval'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * What to do when there is nothing to show yet. ⚠ Each line says what is
 * missing AND where to go, and a link is only offered to somebody who may use
 * it - a link into a 403 reads as a broken system.
 */
function SetupNotice({ children }: { children: ReactNode }) {
    return <div className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">{children}</div>;
}

export default function StockIndex({
    stock,
    products,
    warehouses,
    default_warehouse_id,
    on_hand,
    last_costs,
    adjustment_reasons,
    transfer_reasons,
    can_adjust_directly,
    can_request,
    can_set_threshold,
    approval_threshold,
    awaiting_approval,
    filters: initialFilters,
}: {
    stock: Paginated<StockRow>;
    products: ProductOption[];
    warehouses: Option[];
    default_warehouse_id: number | null;
    on_hand: Record<string, string>;
    last_costs: Record<string, string>;
    adjustment_reasons: AdjustmentReason[];
    transfer_reasons: Option[];
    can_adjust_directly: boolean;
    can_request: boolean;
    can_set_threshold: boolean;
    approval_threshold: string | null;
    awaiting_approval: string | null;
    in_transit_count: number;
    filters: { search: string; warehouse_id: string; low: string; expired: string };
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock', initialFilters);
    const abilities = useInventoryAbilities();
    const { canViewPurchasing } = usePage<SharedData>().props;
    const [correction, setCorrection] = useState<{ open: boolean; preset: CorrectionPreset | null }>({ open: false, preset: null });

    const context: StockContext = {
        products,
        warehouses,
        defaultWarehouseId: default_warehouse_id ? String(default_warehouse_id) : '',
        onHand: on_hand,
    };

    const canRaise = can_adjust_directly || can_request;
    const ready = warehouses.length > 0 && products.length > 0;
    // The reason that means "expired", as the server annotates it: a row's
    // "Record expired" button exists only when the business has one.
    const expiredReason = adjustment_reasons.find((reason) => reason.type === 'expired') ?? null;

    // ⚠ Low is the product's TOTAL against its threshold (the Low stock
    // screen's rule), summed here from the unfiltered on-hand map, so a shop
    // counter holding 14 of a product with 200 in the main store is not low.
    const totalOf = (productId: number) =>
        Object.entries(on_hand)
            .filter(([key]) => key.startsWith(`${productId}-`))
            .reduce((sum, [, quantity]) => sum + Number(quantity), 0);

    const recordExpired = (row: StockRow) =>
        setCorrection({
            open: true,
            preset: {
                reason_id: String(expiredReason?.id ?? ''),
                product_id: String(row.product_id),
                warehouse_id: String(row.warehouse_id),
                quantity: String(Number(row.expired_quantity ?? 0)),
            },
        });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock" />
            <div className="flex flex-col gap-4 p-4">
                {/* ⚠ The actions sit ABOVE the list (21 Sep 2026). They were two forms
                    under a twenty-row table, so on a phone nobody found them. */}
                <Toolbar
                    note={`${stock.total} row${stock.total === 1 ? '' : 's'}: one per product and warehouse. The Overview adds them up.`}
                    actions={
                        <>
                            {/* ⭐ Traceability (17 Oct 2026): from a batch number to its whole trail. */}
                            <Button variant="outline" asChild>
                                <Link href="/inventory/batches">
                                    <ScanSearch className="size-4" />
                                    Find a batch
                                </Link>
                            </Button>
                            {/* ⭐ Corrections wave 1 (20 Oct 2026): where a correction is edited, withdrawn or reversed. */}
                            <Button variant="outline" asChild>
                                <Link href="/inventory/stock/adjustments">
                                    <ListChecks className="size-4" />
                                    {t('corr.stock.adjust.list_link')}
                                </Link>
                            </Button>
                            {canRaise && ready && warehouses.length > 1 && (
                                <MoveStockDialog context={context} reasons={transfer_reasons} direct={can_adjust_directly} />
                            )}
                            {canRaise && ready && (
                                <Button onClick={() => setCorrection({ open: true, preset: null })}>
                                    <PackageMinus className="size-4" />
                                    Record damage or loss
                                </Button>
                            )}
                        </>
                    }
                />

                <AwaitingApprovalNotice message={awaiting_approval} />

                {warehouses.length === 0 && (
                    <SetupNotice>
                        No warehouse yet. Stock is always kept in a warehouse, so that comes first.{' '}
                        {abilities.manageCatalog ? (
                            <Link href="/inventory/warehouses" className="text-foreground underline underline-offset-2">
                                Add a warehouse
                            </Link>
                        ) : (
                            'Ask a manager to add one.'
                        )}
                    </SetupNotice>
                )}
                {warehouses.length > 0 && products.length === 0 && (
                    <SetupNotice>
                        No products that hold stock yet.{' '}
                        {abilities.manageCatalog ? (
                            <Link href="/inventory/products" className="text-foreground underline underline-offset-2">
                                Add products
                            </Link>
                        ) : (
                            'Ask a manager to add them.'
                        )}
                    </SetupNotice>
                )}

                <CompactControls>
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="border-input bg-background flex h-9 w-72 max-w-full items-center gap-2 rounded-md border px-2.5">
                            <Search className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
                            <Input
                                aria-label="Search product SKU or name"
                                value={filters.search}
                                onChange={(e) => setFilter('search', e.target.value)}
                                placeholder="Search product SKU or name"
                                className="h-7 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                            />
                        </label>
                        {warehouses.length > 1 && (
                            <>
                                <Chip active={filters.warehouse_id === ''} onClick={() => setFilter('warehouse_id', '')}>
                                    All warehouses
                                </Chip>
                                {warehouses.map((w) => (
                                    <Chip
                                        key={w.id}
                                        active={filters.warehouse_id === String(w.id)}
                                        onClick={() => setFilter('warehouse_id', String(w.id))}
                                    >
                                        {w.name}
                                    </Chip>
                                ))}
                                <span className="bg-border mx-1 hidden h-6 w-px sm:block" aria-hidden="true" />
                            </>
                        )}
                        <Chip tone="warning" active={filters.low === '1'} onClick={() => setFilter('low', filters.low === '1' ? '' : '1')}>
                            Only low stock
                        </Chip>
                        <Chip tone="danger" active={filters.expired === '1'} onClick={() => setFilter('expired', filters.expired === '1' ? '' : '1')}>
                            Only with expired
                        </Chip>
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2"
                            >
                                Clear
                            </button>
                        )}
                        {canRaise && (
                            <span className="text-muted-foreground ml-auto inline-flex items-center gap-1.5 text-xs">
                                {!can_adjust_directly
                                    ? 'A manager approves every correction before stock changes'
                                    : approval_threshold === null
                                      ? 'No approval amount is set, so every correction waits in Approvals'
                                      : `Corrections up to ${money(approval_threshold)} save at once; bigger ones wait in Approvals`}
                                <Hint
                                    text={
                                        can_set_threshold
                                            ? 'The approval limit lives in Setup, Settings. Only an approver can change it.'
                                            : 'The approval limit is set by an approver in Setup, Settings.'
                                    }
                                />
                            </span>
                        )}
                    </div>
                </CompactControls>

                <div className="bg-card text-card-foreground rounded-xl border">
                    <CompactTable minWidth="52rem">
                        <thead>
                            <tr className="text-muted-foreground">
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th className="text-right">On hand</th>
                                <th>
                                    <span className="inline-flex items-center gap-1">
                                        Reorder level
                                        <Hint text="Set per product, on its total across warehouses. The bar shows that total against the level." />
                                    </span>
                                </th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {stock.data.map((row) => {
                                const threshold = row.product.low_stock_threshold === null ? null : Number(row.product.low_stock_threshold);
                                const total = totalOf(row.product_id);
                                const expired = Number(row.expired_quantity ?? 0);
                                const held = Number(row.held_quantity ?? 0);
                                const low = threshold !== null && total > 0 && total <= threshold;
                                const unit = row.product.unit_of_measure?.abbreviation ?? '';

                                return (
                                    <tr key={`${row.product_id}-${row.warehouse_id}`}>
                                        <td>
                                            <Link
                                                href={`/inventory/products/${row.product_id}`}
                                                className="font-medium underline-offset-2 hover:underline"
                                            >
                                                {row.product.name}
                                            </Link>{' '}
                                            <span className="text-muted-foreground font-mono text-xs">{row.product.sku}</span>
                                        </td>
                                        <td className="text-muted-foreground">{row.warehouse.name}</td>
                                        <td className={cn('text-right tabular-nums', low && 'font-semibold text-amber-700 dark:text-amber-400')}>
                                            {quantity(row.quantity)}
                                            {unit && <span className="text-muted-foreground font-normal"> {unit}</span>}
                                            {expired > 0 && (
                                                <div className="text-destructive text-xs font-normal">
                                                    {t('stock.expired_of_which', { quantity: quantity(expired) })}
                                                </div>
                                            )}
                                            {held > 0 && (
                                                <div className="text-xs font-normal">
                                                    <Link
                                                        href={`/inventory/batches?search=${encodeURIComponent(row.product.sku)}`}
                                                        className="text-amber-700 underline-offset-2 hover:underline dark:text-amber-400"
                                                    >
                                                        {quantity(held)} on hold
                                                    </Link>
                                                </div>
                                            )}
                                        </td>
                                        <td>
                                            <span className="inline-flex items-center gap-2">
                                                {expired > 0 ? (
                                                    <Pill tone="danger">expired</Pill>
                                                ) : threshold === null ? (
                                                    <span className="text-muted-foreground text-xs">not set</span>
                                                ) : low ? (
                                                    <Pill tone="warning">low</Pill>
                                                ) : (
                                                    <Pill tone="ok">ok</Pill>
                                                )}
                                                <LevelBar quantity={total} threshold={threshold} />
                                            </span>
                                        </td>
                                        <td className="text-right whitespace-nowrap">
                                            {expired > 0 && canRaise && expiredReason && (
                                                <Button variant="ghost" size="sm" className="text-destructive" onClick={() => recordExpired(row)}>
                                                    Record expired
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {stock.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                        {hasActiveFilters ? (
                                            'No stock matches these filters.'
                                        ) : (
                                            <>
                                                No stock recorded yet. Stock arrives through{' '}
                                                {abilities.adjustStock ? (
                                                    <Link href="/inventory/opening-stock" className="text-foreground underline underline-offset-2">
                                                        opening stock
                                                    </Link>
                                                ) : (
                                                    'opening stock'
                                                )}{' '}
                                                (what each warehouse held on the day the books start) or by receiving a{' '}
                                                {canViewPurchasing ? (
                                                    <Link href="/purchasing/purchase-orders" className="text-foreground underline underline-offset-2">
                                                        purchase order
                                                    </Link>
                                                ) : (
                                                    'purchase order'
                                                )}
                                                .
                                            </>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </CompactTable>
                </div>

                <Pagination meta={stock} />

                {canRaise && ready && (
                    <RecordCorrectionDialog
                        context={context}
                        reasons={adjustment_reasons}
                        lastCosts={last_costs}
                        canAdjustDirectly={can_adjust_directly}
                        canSetThreshold={can_set_threshold}
                        threshold={approval_threshold}
                        open={correction.open}
                        onOpenChange={(open) => setCorrection((current) => ({ ...current, open }))}
                        preset={correction.preset}
                    />
                )}
            </div>
        </AppLayout>
    );
}
