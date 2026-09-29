import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Field } from '@/components/form/field';
import InputError from '@/components/input-error';
import { AwaitingApprovalNotice } from '@/components/inventory/awaiting-approval-notice';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, PackageMinus } from 'lucide-react';
import { FormEventHandler, useState, type ReactNode } from 'react';

interface StockRow {
    product_id: number;
    warehouse_id: number;
    quantity: string;
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
}

interface StockContext {
    products: ProductOption[];
    warehouses: Option[];
    defaultWarehouseId: string;
    onHand: Record<string, string>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Stock', href: '/inventory/stock' },
];

/** The quantity question for stock that can only go down, by the type the server derived. */
const LOSS_QUESTION: Record<string, string> = {
    damaged: 'How many were damaged',
    expired: 'How many expired',
    lost_or_stolen: 'How many went missing',
    internal_consumption: 'How many were used',
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
}: {
    context: StockContext;
    reasons: AdjustmentReason[];
    lastCosts: Record<string, string>;
    canAdjustDirectly: boolean;
    canSetThreshold: boolean;
    threshold: string | null;
}) {
    const { canManageReasons } = usePage<SharedData>().props;
    const [open, setOpen] = useState(false);
    const { data, setData, post, errors, processing, reset, clearErrors } = useForm({
        reason_id: '',
        product_id: '',
        warehouse_id: context.defaultWarehouseId,
        direction: '',
        quantity: '',
        unit_cost: '',
        note: '',
    });

    const reason = reasons.find((r) => String(r.id) === data.reason_id);
    const product = context.products.find((p) => String(p.id) === data.product_id);
    const onHand = product && data.warehouse_id ? (context.onHand[`${product.id}-${data.warehouse_id}`] ?? '0') : null;
    const lastCost = product ? lastCosts[String(product.id)] : undefined;
    const increasing = data.direction === 'increase';
    const overdraw = !increasing && onHand !== null && data.quantity !== '' && Number(data.quantity) > Number(onHand);
    const quantityQuestion = reason?.only_reduces
        ? (LOSS_QUESTION[reason.type] ?? 'How many')
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
                <Button>
                    <PackageMinus className="size-4" />
                    Record damage or loss
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>What happened to the stock?</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid max-h-[65vh] gap-4 overflow-y-auto pr-1">
                    <Field label="What happened?" htmlFor="stock-reason" required error={errors.reason_id}>
                        <SearchSelect id="stock-reason" value={data.reason_id} onChange={(e) => chooseReason(e.target.value)}>
                            <option value="">Choose what happened</option>
                            {reasons.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </SearchSelect>
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
                        onChange={(v) => setData('product_id', v)}
                        error={errors.product_id}
                    />
                    <WarehouseField
                        id="stock-warehouse"
                        label="Warehouse"
                        warehouses={context.warehouses}
                        value={data.warehouse_id}
                        onChange={(v) => setData('warehouse_id', v)}
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
                            <div className="flex flex-wrap gap-2" role="radiogroup">
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
                                            onChange={() => setData('direction', option.value)}
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
    in_transit_count,
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
    filters: { search: string; warehouse_id: string };
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/inventory/stock', initialFilters);
    const abilities = useInventoryAbilities();
    const { canViewPurchasing } = usePage<SharedData>().props;

    const context: StockContext = {
        products,
        warehouses,
        defaultWarehouseId: default_warehouse_id ? String(default_warehouse_id) : '',
        onHand: on_hand,
    };

    const canRaise = can_adjust_directly || can_request;
    const ready = warehouses.length > 0 && products.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock" />
            <div className="flex flex-col gap-6 p-4">
                {/* ⚠ The actions sit ABOVE the list (21 Sep 2026). They were two forms
                    under a twenty-row table, so on a phone nobody found them. */}
                <ReportHeading
                    title="Current stock"
                    actions={
                        <>
                            {canRaise && ready && (
                                <RecordCorrectionDialog
                                    context={context}
                                    reasons={adjustment_reasons}
                                    lastCosts={last_costs}
                                    canAdjustDirectly={can_adjust_directly}
                                    canSetThreshold={can_set_threshold}
                                    threshold={approval_threshold}
                                />
                            )}
                            {canRaise && ready && warehouses.length > 1 && (
                                <MoveStockDialog context={context} reasons={transfer_reasons} direct={can_adjust_directly} />
                            )}
                            <Link
                                href={route('inventory.stock.transfers.index')}
                                className="text-muted-foreground text-sm underline underline-offset-2"
                            >
                                In-transit transfers{in_transit_count > 0 ? ` (${in_transit_count})` : ''}
                            </Link>
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

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search product SKU or name…"
                        />
                    </FilterField>
                    <FilterField label="Warehouse" htmlFor="filter-warehouse">
                        <SearchSelect id="filter-warehouse" value={filters.warehouse_id} onChange={(e) => setFilter('warehouse_id', e.target.value)}>
                            <option value="">All warehouses</option>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </FilterField>
                </FilterBar>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">Product</th>
                                <th className="py-2">Warehouse</th>
                                <th className="py-2 text-right">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            {stock.data.map((row) => (
                                <tr
                                    key={`${row.product_id}-${row.warehouse_id}`}
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-b"
                                >
                                    <td className="py-2">
                                        {row.product.name} <span className="text-muted-foreground">({row.product.sku})</span>
                                    </td>
                                    <td className="text-muted-foreground py-2">{row.warehouse.name}</td>
                                    <td className="py-2 text-right tabular-nums">
                                        {quantity(row.quantity)}
                                        {row.product.unit_of_measure && (
                                            <span className="text-muted-foreground"> {row.product.unit_of_measure.abbreviation}</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {stock.data.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="text-muted-foreground py-4 text-center">
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
                    </table>
                </div>

                <Pagination meta={stock} />
            </div>
        </AppLayout>
    );
}
