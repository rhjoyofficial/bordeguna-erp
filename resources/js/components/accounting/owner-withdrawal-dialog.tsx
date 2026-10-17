import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface ProductOption {
    id: number;
    name: string;
    sku: string | null;
    unit: string | null;
    mrp: string | null;
}

export interface WarehouseOption {
    id: number;
    name: string;
    is_default: boolean;
}

export type LineDraft = {
    product_id: string;
    quantity: string;
    note: string;
};

export interface DialogWithdrawal {
    id: number;
    number: string;
    warehouse_id: number;
    withdrawn_on: string | null;
    note: string | null;
    lines: { product_id: number; quantity: string; note: string | null }[];
}

/** What the server estimates for one typed line (GET owner-withdrawals/estimate). */
interface LineEstimate {
    unit_cost: string | null;
    cost: string;
    short: string;
    portions: { batch_id: number; batch_number: string | null; quantity: string; unit_cost: string }[];
    reference_source: string;
    reference_price: string | null;
    reference: string | null;
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

const blankLine = (): LineDraft => ({ product_id: '', quantity: '', note: '' });

/**
 * Add an owner withdrawal or correct a draft (Phase 2, 17 Oct 2026), in a
 * popup like the other Accounts lists. The proprietor is not chosen here:
 * Settings > Company names him. Each line shows the ESTIMATED batch cost as
 * it is typed, labelled as an estimate: the cost is frozen only when the
 * withdrawal is authorised and its FIFO batch reserved (ruling Q1).
 */
export function OwnerWithdrawalDialog({
    open,
    onOpenChange,
    withdrawal,
    warehouses,
    products,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The draft being edited; null for a new one. */
    withdrawal: DialogWithdrawal | null;
    warehouses: WarehouseOption[];
    products: ProductOption[];
    today: string;
}) {
    const { t } = useTranslation();
    const editing = withdrawal !== null;
    const form = useForm<{ warehouse_id: string; withdrawn_on: string; note: string; lines: LineDraft[] }>({
        warehouse_id: '',
        withdrawn_on: today,
        note: '',
        lines: [],
    });
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = form;
    const [estimates, setEstimates] = useState<Record<number, LineEstimate | null>>({});

    const defaultWarehouse = warehouses.find((w) => w.is_default) ?? warehouses[0] ?? null;

    useEffect(() => {
        if (!open) {
            return;
        }

        const next = withdrawal
            ? {
                  warehouse_id: String(withdrawal.warehouse_id),
                  withdrawn_on: withdrawal.withdrawn_on ?? today,
                  note: withdrawal.note ?? '',
                  lines: withdrawal.lines.map((line) => ({ product_id: String(line.product_id), quantity: line.quantity, note: line.note ?? '' })),
              }
            : {
                  warehouse_id: defaultWarehouse ? String(defaultWarehouse.id) : '',
                  withdrawn_on: today,
                  note: '',
                  lines: [blankLine()],
              };
        setDefaults(next);
        setData(next);
        setEstimates({});
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, withdrawal]);

    // The estimate per line, re-read when the warehouse, product or quantity changes.
    useEffect(() => {
        if (!open || !data.warehouse_id) {
            return;
        }

        const controller = new AbortController();

        data.lines.forEach((line, index) => {
            if (!line.product_id || !line.quantity || Number(line.quantity) <= 0) {
                setEstimates((previous) => ({ ...previous, [index]: null }));
                return;
            }

            const params = new URLSearchParams({ warehouse_id: data.warehouse_id, product_id: line.product_id, quantity: line.quantity });

            fetch(`/accounting/owner-withdrawals/estimate?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((estimate: LineEstimate | null) => setEstimates((previous) => ({ ...previous, [index]: estimate })))
                .catch(() => undefined);
        });

        return () => controller.abort();
    }, [open, data.warehouse_id, data.lines]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const setLine = (index: number, patch: Partial<LineDraft>) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (withdrawal) {
            put(route('accounting.owner-withdrawals.update', withdrawal.id), options);
            return;
        }

        post(route('accounting.owner-withdrawals.store'), options);
    };

    const lineError = (index: number, field: string) => (errors as Record<string, string>)[`lines.${index}.${field}`];

    const referenceLabel = (source: string) =>
        source === 'mrp'
            ? t('owner_withdrawal.reference_mrp')
            : source === 'selling_price'
              ? t('owner_withdrawal.reference_selling_price')
              : t('owner_withdrawal.reference_none');

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `${t('owner_withdrawal.edit')} ${withdrawal.number}` : t('owner_withdrawal.add')}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="ow-warehouse" required>
                                {t('owner_withdrawal.warehouse')}
                            </Label>
                            <select
                                id="ow-warehouse"
                                className={selectClass}
                                required
                                value={data.warehouse_id}
                                onChange={(e) => setData('warehouse_id', e.target.value)}
                            >
                                <option value="">...</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.warehouse_id} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="ow-date" required>
                                {t('owner_withdrawal.withdrawn_on')}
                            </Label>
                            <Input
                                id="ow-date"
                                type="date"
                                required
                                max={today}
                                value={data.withdrawn_on}
                                onChange={(e) => setData('withdrawn_on', e.target.value)}
                            />
                            <InputError message={errors.withdrawn_on} />
                        </div>

                        <div className="flex flex-col gap-1 sm:col-span-2">
                            <Label htmlFor="ow-note">{t('owner_withdrawal.note')}</Label>
                            <Input id="ow-note" maxLength={1000} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            <InputError message={errors.note} />
                        </div>
                    </div>

                    <div className="grid gap-3">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium">{t('owner_withdrawal.lines')}</p>
                            <button
                                type="button"
                                className="text-primary text-xs hover:underline"
                                onClick={() => setData('lines', [...data.lines, blankLine()])}
                            >
                                {t('owner_withdrawal.add_line')}
                            </button>
                        </div>
                        <InputError message={errors.lines} />

                        {data.lines.map((line, index) => {
                            const product = products.find((p) => String(p.id) === line.product_id) ?? null;
                            const estimate = estimates[index] ?? null;

                            return (
                                <div key={index} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-3">
                                    <div className="flex flex-col gap-1 sm:col-span-2">
                                        <Label required>{t('owner_withdrawal.product')}</Label>
                                        <select
                                            className={selectClass}
                                            required
                                            value={line.product_id}
                                            onChange={(e) => setLine(index, { product_id: e.target.value })}
                                        >
                                            <option value="">...</option>
                                            {products.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                    {p.sku ? ` · ${p.sku}` : ''}
                                                    {p.mrp ? ` · MRP ${formatMoney(p.mrp)}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={lineError(index, 'product_id')} />
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <Label required>
                                            {t('owner_withdrawal.quantity')}
                                            {product?.unit ? ` (${product.unit})` : ''}
                                        </Label>
                                        <Input
                                            type="number"
                                            inputMode="decimal"
                                            min="0.0001"
                                            step="0.0001"
                                            required
                                            value={line.quantity}
                                            onChange={(e) => setLine(index, { quantity: e.target.value })}
                                        />
                                        <InputError message={lineError(index, 'quantity')} />
                                    </div>

                                    <div className="flex flex-col gap-1 sm:col-span-2">
                                        <Label>{t('owner_withdrawal.line_note')}</Label>
                                        <Input maxLength={255} value={line.note} onChange={(e) => setLine(index, { note: e.target.value })} />
                                        <InputError message={lineError(index, 'note')} />
                                    </div>

                                    {/* ⭐ The batch-cost preview: an estimate until authorised. */}
                                    <div className="text-xs sm:col-span-1">
                                        {estimate && (
                                            <dl className="grid grid-cols-2 gap-x-2 gap-y-0.5">
                                                <dt className="text-muted-foreground">{t('owner_withdrawal.estimated_cost')}</dt>
                                                <dd className="text-right tabular-nums">
                                                    {formatMoney(estimate.cost)}
                                                    {estimate.unit_cost && (
                                                        <span className="text-muted-foreground"> ({formatQuantity(estimate.unit_cost)})</span>
                                                    )}
                                                </dd>
                                                <dt className="text-muted-foreground">{t('owner_withdrawal.reference')}</dt>
                                                <dd className="text-right tabular-nums">
                                                    {estimate.reference ? formatMoney(estimate.reference) : referenceLabel(estimate.reference_source)}
                                                </dd>
                                            </dl>
                                        )}
                                        {estimate && Number(estimate.short) > 0 && (
                                            <p className="mt-1 font-medium text-amber-700 dark:text-amber-400">
                                                {t('owner_withdrawal.estimate_short', { quantity: formatQuantity(estimate.short) })}
                                            </p>
                                        )}
                                        {estimate && estimate.portions.length > 1 && (
                                            <p className="text-muted-foreground mt-1">
                                                {t('owner_withdrawal.estimate_split', { count: estimate.portions.length })}
                                            </p>
                                        )}
                                    </div>

                                    {data.lines.length > 1 && (
                                        <div className="sm:col-span-3">
                                            <button
                                                type="button"
                                                className="text-muted-foreground text-xs underline"
                                                onClick={() =>
                                                    setData(
                                                        'lines',
                                                        data.lines.filter((_, i) => i !== index),
                                                    )
                                                }
                                            >
                                                {t('owner_withdrawal.remove_line')}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    <p className="text-muted-foreground text-xs">{t('owner_withdrawal.estimate_hint')}</p>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('owner_withdrawal.cancel')}
                        </Button>
                        <Button disabled={processing}>{editing ? t('owner_withdrawal.save') : t('owner_withdrawal.save_draft')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
