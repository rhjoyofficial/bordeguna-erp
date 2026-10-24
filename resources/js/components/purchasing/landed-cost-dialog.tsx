import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface ReceiptOption {
    id: number;
    received_at: string | null;
    po_number: string | null;
    supplier: string | null;
    supplier_id: number | null;
    challan: string | null;
    lines: { id: number; product: string | null; sku: string | null; quantity_received: string; batch_number: string | null; stocked: boolean }[];
}

export interface Option {
    value: string;
    label: string;
}

export interface CostTypeOption extends Option {
    default_treatment: string;
}

export type LineDraft = {
    cost_type: string;
    treatment: string;
    override_reason: string;
    allocation_method: string;
    goods_receipt_line_id: string;
    amount: string;
    payee: 'supplier' | 'money';
    supplier_id: string;
    money_account_id: string;
    expense_account_id: string;
    description: string;
    /** Initial plan B1 (R49): a one-off person paid from the account. */
    payee_name: string;
};

export interface DialogLandedCost {
    id: number;
    number: string;
    goods_receipt_id: number;
    incurred_on: string | null;
    note: string | null;
    lines: {
        cost_type: string;
        treatment: string;
        override_reason: string | null;
        allocation_method: string;
        goods_receipt_line_id: number | null;
        amount: string;
        supplier_id: number | null;
        money_account_id: number | null;
        expense_account_id: number | null;
        description: string | null;
        payee_name?: string | null;
    }[];
}

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

/** An expense already booked around the receipt that may be one of its costs (18 Oct 2026, decision D-A). */
interface ExpenseCandidate {
    source: string;
    id: number;
    number: string;
    date: string;
    amount: string;
    amount_paisa: number;
    head: string | null;
    url: string;
    types: string[];
    names_document: boolean;
}

/** Taka typed in the box, in paisa; 0 when it is not a number yet. */
function toPaisa(amount: string): number {
    const value = Number(amount);

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

function blankLine(types: CostTypeOption[], supplierId: number | null): LineDraft {
    const first = types[0];

    return {
        cost_type: first?.value ?? 'packaging',
        treatment: first?.default_treatment ?? 'capitalize',
        override_reason: '',
        allocation_method: 'value',
        goods_receipt_line_id: '',
        amount: '',
        payee: supplierId ? 'supplier' : 'money',
        supplier_id: supplierId ? String(supplierId) : '',
        money_account_id: '',
        expense_account_id: '',
        description: '',
        payee_name: '',
    };
}

/**
 * Add a landed cost or correct a draft (Phase 1, 17 Oct 2026), in a popup
 * like the other Purchases lists. One delivery, a date, and cost lines that
 * each say what for, how it is treated (the type's default, or an override
 * with a reason), how it spreads over the delivery, and who is owed or paid.
 */
export function LandedCostDialog({
    open,
    onOpenChange,
    landedCost,
    receipts,
    presetReceiptId,
    costTypes,
    treatments,
    methods,
    suppliers,
    moneyAccounts,
    expenseAccounts,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The draft being edited; null for a new one. */
    landedCost: DialogLandedCost | null;
    receipts: ReceiptOption[];
    presetReceiptId: number | null;
    costTypes: CostTypeOption[];
    treatments: Option[];
    methods: Option[];
    suppliers: { id: number; name: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    expenseAccounts: { id: number; label: string }[];
    today: string;
}) {
    const { t, tr } = useTranslation();
    const editing = landedCost !== null;
    const [candidates, setCandidates] = useState<ExpenseCandidate[]>([]);
    const [similarPercent, setSimilarPercent] = useState(10);
    const form = useForm<{ goods_receipt_id: string; incurred_on: string; note: string; lines: LineDraft[] }>({
        goods_receipt_id: '',
        incurred_on: today,
        note: '',
        lines: [],
    });
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = form;

    const receipt = receipts.find((r) => String(r.id) === data.goods_receipt_id) ?? null;

    // Fresh fields every time it opens: blank for a new one, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const receiptId = landedCost ? landedCost.goods_receipt_id : (presetReceiptId ?? receipts[0]?.id ?? null);
        const chosen = receipts.find((r) => r.id === receiptId) ?? null;
        const next = landedCost
            ? {
                  goods_receipt_id: String(landedCost.goods_receipt_id),
                  incurred_on: landedCost.incurred_on ?? today,
                  note: landedCost.note ?? '',
                  lines: landedCost.lines.map((line) => ({
                      cost_type: line.cost_type,
                      treatment: line.treatment,
                      override_reason: line.override_reason ?? '',
                      allocation_method: line.allocation_method,
                      goods_receipt_line_id: line.goods_receipt_line_id ? String(line.goods_receipt_line_id) : '',
                      amount: line.amount,
                      payee: (line.supplier_id ? 'supplier' : 'money') as 'supplier' | 'money',
                      supplier_id: line.supplier_id ? String(line.supplier_id) : '',
                      money_account_id: line.money_account_id ? String(line.money_account_id) : '',
                      expense_account_id: line.expense_account_id ? String(line.expense_account_id) : '',
                      description: line.description ?? '',
                      payee_name: line.payee_name ?? '',
                  })),
              }
            : {
                  goods_receipt_id: receiptId ? String(receiptId) : '',
                  incurred_on: today,
                  note: '',
                  lines: [blankLine(costTypes, chosen?.supplier_id ?? null)],
              };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, landedCost, presetReceiptId]);

    // ⭐ 18 Oct 2026 (decision D-A): what is already booked as an expense around
    // this delivery, to warn before the same money is counted a second time.
    useEffect(() => {
        if (!open || data.goods_receipt_id === '') {
            setCandidates([]);

            return;
        }

        fetch(route('purchasing.landed-costs.expense-check', Number(data.goods_receipt_id)), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((body: { similar_percent: number; candidates: ExpenseCandidate[] }) => {
                setSimilarPercent(body.similar_percent);
                setCandidates(body.candidates);
            })
            .catch(() => setCandidates([]));
    }, [open, data.goods_receipt_id]);

    const doubleCounts = (line: LineDraft): ExpenseCandidate[] => {
        const paisa = toPaisa(line.amount);

        if (paisa <= 0) {
            return [];
        }

        return candidates.filter((c) => c.types.includes(line.cost_type) && Math.abs(paisa - c.amount_paisa) * 100 <= similarPercent * paisa);
    };

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

    const defaultTreatmentOf = (type: string) => costTypes.find((c) => c.value === type)?.default_treatment ?? 'expense';
    const treatmentLabel = (value: string) => tr(`landed.treatment.${value}`, treatments.find((o) => o.value === value)?.label ?? value);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const payload = {
            ...data,
            lines: data.lines.map((line) => ({
                ...line,
                supplier_id: line.payee === 'supplier' ? line.supplier_id : '',
                money_account_id: line.payee === 'money' ? line.money_account_id : '',
                payee_name: line.payee === 'money' ? line.payee_name : '',
            })),
        };

        const options = { preserveScroll: true, onSuccess: close };

        if (landedCost) {
            form.transform(() => payload);
            put(route('purchasing.landed-costs.update', landedCost.id), options);

            return;
        }

        form.transform(() => payload);
        post(route('purchasing.landed-costs.store'), options);
    };

    const lineError = (index: number, field: string) => (errors as Record<string, string>)[`lines.${index}.${field}`];

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `${t('landed.edit')} ${landedCost.number}` : t('landed.add')}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1 sm:col-span-2">
                            <Label htmlFor="lc-receipt" required>
                                {t('landed.receipt_pick')}
                            </Label>
                            <select
                                id="lc-receipt"
                                className={selectClass}
                                required
                                disabled={editing}
                                value={data.goods_receipt_id}
                                onChange={(e) => {
                                    const chosen = receipts.find((r) => String(r.id) === e.target.value) ?? null;
                                    setData({
                                        ...data,
                                        goods_receipt_id: e.target.value,
                                        lines: data.lines.map((line) => ({
                                            ...line,
                                            goods_receipt_line_id: '',
                                            supplier_id:
                                                line.payee === 'supplier' && chosen?.supplier_id ? String(chosen.supplier_id) : line.supplier_id,
                                        })),
                                    });
                                }}
                            >
                                <option value="">...</option>
                                {receipts.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        #{r.id} · {showDate(r.received_at) || ''} · {r.po_number ?? ''} · {r.supplier ?? ''}
                                        {r.challan ? ` · challan ${r.challan}` : ''}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">{t('landed.receipt_hint')}</p>
                            <InputError message={errors.goods_receipt_id} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="lc-date" required>
                                {t('landed.incurred_on')}
                            </Label>
                            <Input
                                id="lc-date"
                                type="date"
                                required
                                max={today}
                                value={data.incurred_on}
                                onChange={(e) => setData('incurred_on', e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">{t('landed.incurred_on_hint')}</p>
                            <InputError message={errors.incurred_on} />
                        </div>

                        <div className="flex flex-col gap-1">
                            <Label htmlFor="lc-note">{t('landed.note')}</Label>
                            <Input id="lc-note" maxLength={1000} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            <InputError message={errors.note} />
                        </div>
                    </div>

                    <div className="grid gap-3">
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium">{t('landed.lines')}</p>
                            <button
                                type="button"
                                className="text-primary text-xs hover:underline"
                                onClick={() => setData('lines', [...data.lines, blankLine(costTypes, receipt?.supplier_id ?? null)])}
                            >
                                {t('landed.add_line')}
                            </button>
                        </div>
                        <InputError message={errors.lines} />

                        {data.lines.map((line, index) => {
                            const isDefault = line.treatment === defaultTreatmentOf(line.cost_type);
                            const capitalised = line.treatment === 'capitalize';

                            return (
                                <div key={index} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-3">
                                    <div className="flex flex-col gap-1">
                                        <Label required>{t('landed.cost_type')}</Label>
                                        <select
                                            className={selectClass}
                                            value={line.cost_type}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    cost_type: e.target.value,
                                                    treatment: defaultTreatmentOf(e.target.value),
                                                    override_reason: '',
                                                })
                                            }
                                        >
                                            {costTypes.map((c) => (
                                                <option key={c.value} value={c.value}>
                                                    {tr(`landed.type.${c.value}`, c.label)}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={lineError(index, 'cost_type')} />
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <Label required>{t('landed.treatment')}</Label>
                                        <select
                                            className={selectClass}
                                            value={line.treatment}
                                            onChange={(e) => setLine(index, { treatment: e.target.value })}
                                        >
                                            {treatments.map((o) => (
                                                <option key={o.value} value={o.value}>
                                                    {treatmentLabel(o.value)}
                                                </option>
                                            ))}
                                        </select>
                                        {/* ⭐ 18 Oct 2026: the suggested treatment is always named, so an override reads as one. */}
                                        <p
                                            className={
                                                isDefault ? 'text-muted-foreground text-xs' : 'text-xs font-medium text-amber-700 dark:text-amber-400'
                                            }
                                        >
                                            {isDefault
                                                ? t('landed.treatment_default')
                                                : `${t('landed.treatment_override')} · ${t('landed.default_is', { treatment: treatmentLabel(defaultTreatmentOf(line.cost_type)) })}`}
                                        </p>
                                        <InputError message={lineError(index, 'treatment')} />
                                    </div>

                                    <div className="flex flex-col gap-1">
                                        <Label required>{t('landed.amount')}</Label>
                                        <Input
                                            type="number"
                                            inputMode="decimal"
                                            min="0.01"
                                            step="0.01"
                                            required
                                            value={line.amount}
                                            onChange={(e) => setLine(index, { amount: e.target.value })}
                                        />
                                        <InputError message={lineError(index, 'amount')} />
                                    </div>

                                    {!isDefault && (
                                        <div className="flex flex-col gap-1 sm:col-span-3">
                                            <Label required>{t('landed.override_reason')}</Label>
                                            <Input
                                                maxLength={500}
                                                value={line.override_reason}
                                                onChange={(e) => setLine(index, { override_reason: e.target.value })}
                                            />
                                            <p className="text-muted-foreground text-xs">{t('landed.override_reason_hint')}</p>
                                            <InputError message={lineError(index, 'override_reason')} />
                                        </div>
                                    )}

                                    {capitalised ? (
                                        <>
                                            <div className="flex flex-col gap-1">
                                                <Label required>{t('landed.method')}</Label>
                                                <select
                                                    className={selectClass}
                                                    value={line.allocation_method}
                                                    onChange={(e) => setLine(index, { allocation_method: e.target.value, goods_receipt_line_id: '' })}
                                                >
                                                    {methods.map((o) => (
                                                        <option key={o.value} value={o.value}>
                                                            {tr(`landed.method.${o.value}`, o.label)}
                                                        </option>
                                                    ))}
                                                </select>
                                                {(line.allocation_method === 'quantity' || line.allocation_method === 'weight') && (
                                                    <p className="text-muted-foreground text-xs">{t('landed.method_hint')}</p>
                                                )}
                                                <InputError message={lineError(index, 'allocation_method')} />
                                            </div>
                                            {line.allocation_method === 'direct' && (
                                                <div className="flex flex-col gap-1 sm:col-span-2">
                                                    <Label required>{t('landed.target_line')}</Label>
                                                    <select
                                                        className={selectClass}
                                                        value={line.goods_receipt_line_id}
                                                        onChange={(e) => setLine(index, { goods_receipt_line_id: e.target.value })}
                                                    >
                                                        <option value="">...</option>
                                                        {(receipt?.lines ?? [])
                                                            .filter((l) => l.stocked)
                                                            .map((l) => (
                                                                <option key={l.id} value={l.id}>
                                                                    {l.product ?? l.sku ?? `#${l.id}`} · {formatQuantity(l.quantity_received)}
                                                                    {l.batch_number ? ` · batch ${l.batch_number}` : ''}
                                                                </option>
                                                            ))}
                                                    </select>
                                                    <InputError message={lineError(index, 'goods_receipt_line_id')} />
                                                </div>
                                            )}
                                        </>
                                    ) : (
                                        <div className="flex flex-col gap-1 sm:col-span-3">
                                            <Label required={line.cost_type === 'other' || line.cost_type === 'packaging'}>
                                                {t('landed.expense_account')}
                                            </Label>
                                            <select
                                                className={selectClass}
                                                value={line.expense_account_id}
                                                onChange={(e) => setLine(index, { expense_account_id: e.target.value })}
                                            >
                                                <option value="">
                                                    {line.cost_type === 'other' || line.cost_type === 'packaging'
                                                        ? '...'
                                                        : t('landed.expense_account_hint')}
                                                </option>
                                                {expenseAccounts.map((a) => (
                                                    <option key={a.id} value={a.id}>
                                                        {a.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError message={lineError(index, 'expense_account_id')} />
                                        </div>
                                    )}

                                    <div className="flex flex-col gap-1">
                                        <Label required>{t('landed.payee')}</Label>
                                        <select
                                            className={selectClass}
                                            value={line.payee}
                                            onChange={(e) => setLine(index, { payee: e.target.value as 'supplier' | 'money' })}
                                        >
                                            <option value="supplier">{t('landed.payee_supplier')}</option>
                                            <option value="money">{t('landed.payee_money')}</option>
                                        </select>
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        <Label required>{line.payee === 'supplier' ? t('landed.payee_supplier') : t('landed.payee_money')}</Label>
                                        {line.payee === 'supplier' ? (
                                            <select
                                                className={selectClass}
                                                value={line.supplier_id}
                                                onChange={(e) => setLine(index, { supplier_id: e.target.value })}
                                            >
                                                <option value="">...</option>
                                                {suppliers.map((s) => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <select
                                                className={selectClass}
                                                value={line.money_account_id}
                                                onChange={(e) => setLine(index, { money_account_id: e.target.value })}
                                            >
                                                <option value="">...</option>
                                                {moneyAccounts.map((m) => (
                                                    <option key={m.id} value={m.id}>
                                                        {m.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                        <InputError message={lineError(index, 'supplier_id') ?? lineError(index, 'money_account_id')} />
                                    </div>
                                    {line.payee === 'money' && (
                                        <div className="flex flex-col gap-1">
                                            <Label>{t('ipp.payee_name')}</Label>
                                            <Input
                                                className="w-48"
                                                maxLength={120}
                                                placeholder={t('ipp.payee_name_hint')}
                                                value={line.payee_name}
                                                onChange={(e) => setLine(index, { payee_name: e.target.value })}
                                            />
                                        </div>
                                    )}
                                    <div className="flex flex-col gap-1">
                                        <Label>{t('landed.description')}</Label>
                                        <Input
                                            maxLength={255}
                                            value={line.description}
                                            onChange={(e) => setLine(index, { description: e.target.value })}
                                        />
                                        <InputError message={lineError(index, 'description')} />
                                    </div>

                                    {line.cost_type === 'other' && capitalised && (
                                        <p className="text-muted-foreground text-xs sm:col-span-3">{t('landed.other_classify_hint')}</p>
                                    )}

                                    {doubleCounts(line).length > 0 && (
                                        <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 sm:col-span-3 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                                            <p className="font-medium">{t('landed.double_count_title')}</p>
                                            <ul className="mt-1 list-disc pl-4">
                                                {doubleCounts(line).map((c) => (
                                                    <li key={`${c.source}-${c.id}-${c.amount_paisa}`}>
                                                        {t(capitalised ? 'landed.double_count_capitalise' : 'landed.double_count_expense', {
                                                            number: c.number,
                                                            date: c.date,
                                                            head: c.head ?? '',
                                                            amount: c.amount,
                                                        })}{' '}
                                                        <a href={c.url} target="_blank" rel="noreferrer" className="underline">
                                                            {t('landed.double_count_open')}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

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
                                                {t('landed.remove_line')}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    <p className="text-muted-foreground text-xs">{t('landed.ias2_help')}</p>
                    <p className="text-muted-foreground text-xs">{t('landed.owner_rule')}</p>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? t('landed.save') : t('landed.save_draft')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
