import { EntityQuickCreate } from '@/components/entity-quick-create';
import { DateWords } from '@/components/form/date-words';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { advancePaisa, AllocateAdvances, filledAdvanceRows, type AdvanceRow } from '@/components/purchasing/allocate-advances';
import { PurchaseQuantityTotals } from '@/components/purchasing/purchase-quantity-totals';
import { filledSplitLines, SplitPaymentLines, type SplitLine } from '@/components/purchasing/split-payment-lines';
import { noTakeOff, takeOffPaisa, takeOffPayload, TakeOffWhatTheyOwe, type TakeOff } from '@/components/purchasing/take-off-what-they-owe';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { dueDateFor, type PaymentTerms } from '@/lib/payment-terms';
import { deriveLineTotal, deriveUnitPrice, linePaisaOf, type EntryMode } from '@/lib/purchase-line';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Option {
    id: number;
    name: string;
}

/** ⭐ Ruling P6: a supplier with the terms its bills default to. */
interface SupplierOption extends Option {
    terms?: PaymentTerms | null;
}

interface ProductOption extends Option {
    sku: string;
    unit: string | null;
    tracks_expiry: boolean;
    /** Initial plan B1 (R51): grams per purchase unit, for the total weight. */
    weight_grams?: number | null;
    last_cost: { unit_cost: string; po_number: string | null; received_on: string | null } | null;
    /** ⭐ Initial plan B4 (24 Oct 2026; R22): the item's usual supplier. */
    default_supplier_id?: number | null;
}

interface MoneyAccountOption extends Option {
    type: string;
    balance?: string | null;
}

interface CostType {
    value: string;
    label: string;
    into_stock: boolean;
}

/** A line is typed EITHER as a unit price OR as a line total, as on the order form. */
type LineDraft = {
    product_id: string;
    quantity: string;
    unit_cost: string;
    line_total: string;
    entry_mode: EntryMode;
    batch_number: string;
    expiry_date: string;
};

type CostDraft = {
    cost_type: string;
    amount: string;
    payee: 'supplier' | 'money';
    money_account_id: string;
    description: string;
    /** Initial plan B1 (R49): a one-off person paid from the account. */
    payee_name: string;
};

const emptyLine = (): LineDraft => ({
    product_id: '',
    quantity: '',
    unit_cost: '',
    line_total: '',
    entry_mode: 'rate',
    batch_number: '',
    expiry_date: '',
});

const emptyCost = (): CostDraft => ({ cost_type: 'freight', amount: '', payee: 'supplier', money_account_id: '', description: '', payee_name: '' });

/** Money in this form is paisa, added as integers. */
const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);
const taka = (paisa: number) => formatMoney(paisa / 100);

/** A fresh key per form: the same submission sent twice saves once (RecordQuickPurchase). */
const newRequestKey = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Buy and receive', href: '/purchasing/buy-and-receive/new' },
];

/**
 * ⭐ Purchases > Buy and receive (ruling P4, 18 Oct 2026): goods bought and
 * brought in together, entered once. The server makes the order, receipt,
 * extra costs, bill and payment through their own actions, all or nothing.
 * Everything worked out here (totals, what is left owing) is for the person
 * typing; the server works each figure out again.
 */
export default function BuyAndReceive({
    suppliers,
    warehouses,
    defaultWarehouseId,
    products,
    moneyAccounts,
    costTypes,
    today,
    rights,
    missing,
    employees = [],
}: {
    suppliers: SupplierOption[];
    warehouses: Option[];
    defaultWarehouseId: number | null;
    products: ProductOption[];
    moneyAccounts: MoneyAccountOption[];
    costTypes: CostType[];
    today: string;
    rights: { approve_order: boolean; extra_costs: boolean; post_extra_costs: boolean; pay: boolean; advance: boolean };
    missing: string[];
    /** Initial plan B1 (R40): HR employees for "Purchasing employee". */
    employees?: Option[];
}) {
    const { t } = useTranslation();
    // ⚠ Errors come from the shared page props: the payload is reshaped at
    // submit time, so a useForm would never see the keys the server returns.
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    const [requestKey] = useState(newRequestKey);
    const [processing, setProcessing] = useState(false);
    const [supplierId, setSupplierId] = useState('');
    // ⭐ B4: said under the supplier box when it was filled in from an item's usual supplier.
    const [proposedSupplier, setProposedSupplier] = useState<string | null>(null);
    const [warehouseId, setWarehouseId] = useState(defaultWarehouseId === null ? '' : String(defaultWarehouseId));
    const [purchasedOn, setPurchasedOn] = useState(today || dhakaToday());
    const [backdateReason, setBackdateReason] = useState('');
    const [billNumber, setBillNumber] = useState('');
    const [challanNumber, setChallanNumber] = useState('');
    const [dueDate, setDueDate] = useState('');
    // ⭐ Ruling P6: why a typed due date differs from the supplier's terms.
    const [dueDateReason, setDueDateReason] = useState('');
    // ⭐ Ruling P5: why a bill that looks like an earlier one is recorded anyway.
    const [duplicateReason, setDuplicateReason] = useState('');
    const [note, setNote] = useState('');
    const [employeeId, setEmployeeId] = useState('');
    const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
    const [costs, setCosts] = useState<CostDraft[]>([]);
    const [discountAmount, setDiscountAmount] = useState('');
    const [discountReference, setDiscountReference] = useState('');
    const [payNow, setPayNow] = useState(false);
    const [paymentAccountId, setPaymentAccountId] = useState('');
    const [paymentAmount, setPaymentAmount] = useState('');
    const [paymentReference, setPaymentReference] = useState('');
    const [excessToAdvance, setExcessToAdvance] = useState(false);
    // ⭐ Initial plan B2 (R57): the other accounts; "Paid from" pays the rest.
    const [splitLines, setSplitLines] = useState<SplitLine[]>([]);
    // ⭐ Initial plan B1 (R56): earlier advances taken off the bill.
    const [advanceRows, setAdvanceRows] = useState<AdvanceRow[]>([]);
    // ⭐ Joy, 26 Oct 2026: one amount off everything the firm owes us.
    const [takeOff, setTakeOff] = useState<TakeOff>(noTakeOff);

    // Add a supplier without leaving the screen, as on the order form.
    const [creatingSupplier, setCreatingSupplier] = useState(false);
    const [typedSupplier, setTypedSupplier] = useState('');

    const updateLine = (index: number, patch: Partial<LineDraft>) => setLines(lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    const typeQuantity = (index: number, quantity: string) => {
        const line = lines[index];
        updateLine(
            index,
            line.entry_mode === 'total'
                ? { quantity, unit_cost: deriveUnitPrice(quantity, line.line_total) }
                : { quantity, line_total: deriveLineTotal(quantity, line.unit_cost) },
        );
    };
    const typeUnitPrice = (index: number, unit_cost: string) =>
        updateLine(index, { unit_cost, entry_mode: 'rate', line_total: deriveLineTotal(lines[index].quantity, unit_cost) });
    const typeLineTotal = (index: number, line_total: string) =>
        updateLine(index, { line_total, entry_mode: 'total', unit_cost: deriveUnitPrice(lines[index].quantity, line_total) });
    const updateCost = (index: number, patch: Partial<CostDraft>) => setCosts(costs.map((cost, i) => (i === index ? { ...cost, ...patch } : cost)));

    const goods = lines.reduce((sum, line) => sum + (linePaisaOf(line) ?? 0), 0);
    const discount = toPaisa(discountAmount);
    const extrasOwed = costs.filter((cost) => cost.payee === 'supplier').reduce((sum, cost) => sum + toPaisa(cost.amount), 0);
    const advanced = !rights.advance ? 0 : takeOff.on ? takeOffPaisa(takeOff) : advancePaisa(advanceRows);
    const owed = goods - discount + extrasOwed - advanced;
    const paying = payNow ? toPaisa(paymentAmount) : 0;
    const excess = paying - owed;
    const isBackdated = purchasedOn !== '' && purchasedOn < today;
    // ⭐ Ruling P6: the due date the supplier's terms give, as the server works it out.
    const supplierTerms = suppliers.find((s) => String(s.id) === supplierId)?.terms ?? null;
    const termsDue = supplierTerms ? dueDateFor(supplierTerms.type, supplierTerms.days, purchasedOn) : null;
    const needsDueReason = (dueDate !== '' && termsDue !== null && dueDate !== termsDue) || Boolean(errors.due_date_reason);
    const blocked = missing.length > 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        router.post(
            route('purchasing.buy-and-receive.store'),
            {
                request_key: requestKey,
                supplier_id: supplierId,
                warehouse_id: warehouseId,
                purchased_on: purchasedOn,
                backdate_reason: isBackdated ? backdateReason : null,
                bill_number: billNumber,
                challan_number: challanNumber || null,
                due_date: dueDate || null,
                due_date_reason: needsDueReason ? dueDateReason || null : null,
                duplicate_reason: duplicateReason || null,
                note: note || null,
                purchasing_employee_id: employeeId || null,
                lines: lines.map((line) => ({
                    product_id: line.product_id,
                    quantity: line.quantity,
                    unit_cost: line.unit_cost || null,
                    line_total: line.line_total || null,
                    entry_mode: line.entry_mode,
                    batch_number: line.batch_number || null,
                    expiry_date: line.expiry_date || null,
                })),
                costs: rights.extra_costs
                    ? costs.map((cost) => ({
                          ...cost,
                          money_account_id: cost.payee === 'money' ? cost.money_account_id : null,
                          payee_name: cost.payee === 'money' ? cost.payee_name || null : null,
                      }))
                    : [],
                discount_amount: discount > 0 ? discountAmount : null,
                discount_reference: discount > 0 ? discountReference : null,
                pay_now: rights.pay && payNow,
                payment_money_account_id: rights.pay && payNow ? paymentAccountId : null,
                payment_amount: rights.pay && payNow ? paymentAmount : null,
                payment_reference: rights.pay && payNow ? paymentReference || null : null,
                excess_to_advance: rights.pay && payNow && excess > 0 && excessToAdvance,
                split_lines: rights.pay && payNow ? filledSplitLines(splitLines) : [],
                advances: rights.advance && !takeOff.on ? filledAdvanceRows(advanceRows) : [],
                take_off_amount: rights.advance ? takeOffPayload(takeOff) : null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('quick.title')} />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                {blocked && (
                    <div role="alert" className="border-destructive/50 text-destructive max-w-3xl rounded-lg border p-3 text-sm">
                        {t('quick.missing')} {missing.join(', ')}.
                    </div>
                )}
                {!blocked && !rights.approve_order && (
                    <p className="max-w-3xl rounded-lg border border-amber-500/50 p-3 text-sm">{t('quick.order_needs_approval')}</p>
                )}

                {/* The paper: who, where, when, and their numbers. */}
                <FormRow className="gap-3">
                    <Field label={t('quick.supplier')} required error={errors.supplier_id}>
                        <SearchableSelect
                            value={supplierId}
                            onChange={(v) => {
                                setSupplierId(v);
                                setProposedSupplier(null);
                            }}
                            options={suppliers.map((s) => ({ value: String(s.id), label: s.name }))}
                            placeholder="Select supplier"
                            searchPlaceholder="Search suppliers…"
                            onCreate={(typed) => {
                                setTypedSupplier(typed);
                                setCreatingSupplier(true);
                            }}
                            createLabel="New supplier"
                        />
                        {proposedSupplier && <p className="text-muted-foreground text-xs">{proposedSupplier}</p>}
                        <EntityQuickCreate
                            kind="supplier"
                            open={creatingSupplier}
                            onOpenChange={setCreatingSupplier}
                            initialName={typedSupplier}
                            onCreated={(row) => router.reload({ only: ['suppliers'], onSuccess: () => setSupplierId(String(row.id)) })}
                        />
                    </Field>
                    <Field label={t('quick.warehouse')} required error={errors.warehouse_id}>
                        <SearchableSelect
                            value={warehouseId}
                            onChange={setWarehouseId}
                            options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                            placeholder="Select warehouse"
                            searchPlaceholder="Search warehouses…"
                        />
                    </Field>
                    <Field label={t('quick.date')} required error={errors.purchased_on} hint={t('quick.date_hint')}>
                        <Input type="date" max={today} aria-required value={purchasedOn} onChange={(e) => setPurchasedOn(e.target.value)} />
                        <DateWords value={purchasedOn} />
                    </Field>
                    {isBackdated && (
                        <Field label={t('quick.backdate_reason')} required size="grow" error={errors.backdate_reason}>
                            <Input value={backdateReason} onChange={(e) => setBackdateReason(e.target.value)} />
                        </Field>
                    )}
                </FormRow>
                <FormRow className="gap-3">
                    <Field label={t('quick.bill_number')} required size="md" error={errors.bill_number} hint={t('quick.bill_number_hint')}>
                        <Input aria-required value={billNumber} onChange={(e) => setBillNumber(e.target.value)} />
                    </Field>
                    <Field label={t('quick.challan')} size="sm" error={errors.challan_number}>
                        <Input value={challanNumber} onChange={(e) => setChallanNumber(e.target.value)} />
                    </Field>
                    <Field label={t('ipp.employee')} error={errors.purchasing_employee_id} className="w-56">
                        <SearchableSelect
                            value={employeeId}
                            onChange={setEmployeeId}
                            options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
                            placeholder={t('ipp.employee_pick')}
                            clearable
                        />
                    </Field>
                    <Field label={t('quick.due_date')} error={errors.due_date}>
                        <Input type="date" min={purchasedOn} value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
                        <DateWords value={dueDate} />
                        {termsDue && (
                            <p className="text-muted-foreground mt-1 text-xs" data-terms-due={termsDue}>
                                {t('terms.due_on', { date: showDate(termsDue) })} {dueDate === '' && t('terms.due_blank')}
                            </p>
                        )}
                    </Field>
                    {needsDueReason && (
                        <Field label={t('terms.due_reason')} required size="grow" hint={t('terms.due_reason_hint')} error={errors.due_date_reason}>
                            <Input aria-required value={dueDateReason} onChange={(e) => setDueDateReason(e.target.value)} />
                        </Field>
                    )}
                </FormRow>

                {/* The items, typed as on the order form. */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-medium">
                        {t('quick.items')}
                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                            *
                        </span>
                    </h2>
                    {lines.map((line, index) => {
                        const chosen = products.find((p) => String(p.id) === line.product_id);
                        const paisa = linePaisaOf(line);

                        return (
                            <div key={index} className="flex flex-wrap items-start gap-2">
                                <div className="grid max-w-md min-w-64 flex-1 gap-1">
                                    <SearchableSelect
                                        value={line.product_id}
                                        onChange={(v) => {
                                            updateLine(index, { product_id: v });
                                            // ⭐ B4 (R22): no supplier yet, and this item has a usual one: propose it.
                                            const picked = products.find((p) => String(p.id) === v);
                                            const usual = picked?.default_supplier_id
                                                ? suppliers.find((s) => s.id === picked.default_supplier_id)
                                                : undefined;
                                            if (!supplierId && picked && usual) {
                                                setSupplierId(String(usual.id));
                                                setProposedSupplier(t('ipc.po.supplier_proposed', { supplier: usual.name, product: picked.name }));
                                            }
                                        }}
                                        options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                                        placeholder="Select product"
                                        searchPlaceholder="Search products…"
                                    />
                                    <InputError message={errors[`lines.${index}.product_id`]} />
                                </div>
                                <div className="grid w-32 gap-1">
                                    <div className="flex items-center gap-1.5">
                                        <Input
                                            placeholder={t('purchase_line.quantity')}
                                            aria-label={t('purchase_line.quantity')}
                                            aria-required
                                            value={line.quantity}
                                            onChange={(e) => typeQuantity(index, e.target.value)}
                                        />
                                        {chosen?.unit && <span className="text-muted-foreground shrink-0 text-xs">{chosen.unit}</span>}
                                    </div>
                                    <InputError message={errors[`lines.${index}.quantity`]} />
                                </div>
                                <div className="grid w-44 gap-1">
                                    <Input
                                        placeholder={
                                            chosen?.unit ? t('purchase_line.unit_price_per', { unit: chosen.unit }) : t('purchase_line.unit_price')
                                        }
                                        aria-label={t('purchase_line.unit_price')}
                                        className={line.entry_mode === 'total' ? 'text-muted-foreground' : ''}
                                        value={line.unit_cost}
                                        onChange={(e) => typeUnitPrice(index, e.target.value)}
                                    />
                                    {chosen?.last_cost && (
                                        <p className="text-muted-foreground text-xs">
                                            Last received at {chosen.last_cost.unit_cost}
                                            {chosen.last_cost.received_on && ` (${showDate(chosen.last_cost.received_on)})`}
                                        </p>
                                    )}
                                    <InputError message={errors[`lines.${index}.unit_cost`]} />
                                </div>
                                <div className="grid w-40 gap-1">
                                    <Input
                                        placeholder={t('purchase_line.line_total')}
                                        aria-label={t('purchase_line.line_total')}
                                        className={`text-right tabular-nums ${line.entry_mode === 'rate' ? 'text-muted-foreground' : ''}`}
                                        value={line.line_total}
                                        onChange={(e) => typeLineTotal(index, e.target.value)}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {line.entry_mode === 'total' ? t('purchase_line.total_typed') : t('purchase_line.price_typed')}
                                    </p>
                                    <InputError message={errors[`lines.${index}.line_total`]} />
                                </div>
                                {/* Batch and expiry: required where the product tracks expiry (the receipt's rule). */}
                                {chosen?.tracks_expiry && (
                                    <>
                                        <div className="grid w-32 gap-1">
                                            <Input
                                                placeholder={t('batchno.auto')}
                                                aria-label={t('quick.batch')}
                                                value={line.batch_number}
                                                onChange={(e) => updateLine(index, { batch_number: e.target.value })}
                                            />
                                            <InputError message={errors[`lines.${index}.batch_number`]} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Input
                                                type="date"
                                                aria-label={t('quick.expiry')}
                                                aria-required
                                                value={line.expiry_date}
                                                onChange={(e) => updateLine(index, { expiry_date: e.target.value })}
                                            />
                                            <InputError message={errors[`lines.${index}.expiry_date`]} />
                                        </div>
                                    </>
                                )}
                                <p className="flex h-10 w-28 items-center justify-end text-sm font-medium tabular-nums">
                                    {paisa === null ? '' : taka(paisa)}
                                </p>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-10"
                                    onClick={() => setLines(lines.filter((_, i) => i !== index))}
                                    disabled={lines.length === 1}
                                >
                                    {t('quick.remove')}
                                </Button>
                            </div>
                        );
                    })}
                    <InputError message={errors.lines} />
                    <PurchaseQuantityTotals
                        rows={lines
                            .filter((line) => line.product_id !== '')
                            .map((line) => {
                                const product = products.find((p) => String(p.id) === line.product_id);

                                return { quantity: line.quantity, unit: product?.unit ?? null, weight_grams: product?.weight_grams ?? null };
                            })}
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => setLines([...lines, emptyLine()])}>
                            {t('quick.add_item')}
                        </Button>
                        <p className="text-sm">
                            {t('quick.goods_total')} <span className="font-medium tabular-nums">{taka(goods)}</span>
                        </p>
                    </div>
                </section>

                {/* Extra costs: one landed cost on this receipt, each type on its own default. */}
                {rights.extra_costs && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-sm font-medium">{t('quick.extra_costs')}</h2>
                        <p className="text-muted-foreground max-w-3xl text-xs">{t('quick.extra_costs_hint')}</p>
                        {!rights.post_extra_costs && costs.length > 0 && <p className="text-xs text-amber-700">{t('quick.extra_costs_wait')}</p>}
                        {costs.map((cost, index) => {
                            const type = costTypes.find((c) => c.value === cost.cost_type);

                            return (
                                <div key={index} className="flex flex-wrap items-start gap-2">
                                    <div className="grid gap-1">
                                        <label htmlFor={`cost-type-${index}`} className="sr-only">
                                            {t('quick.cost_type')}
                                        </label>
                                        <SearchSelect
                                            fit
                                            id={`cost-type-${index}`}
                                            value={cost.cost_type}
                                            onChange={(e) => updateCost(index, { cost_type: e.target.value })}
                                        >
                                            {costTypes.map((c) => (
                                                <option key={c.value} value={c.value}>
                                                    {c.label}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        {type && (
                                            <p className="text-muted-foreground text-xs">
                                                {type.into_stock ? t('quick.into_stock') : t('quick.expense')}
                                            </p>
                                        )}
                                        <InputError message={errors[`costs.${index}.cost_type`]} />
                                    </div>
                                    <div className="grid w-32 gap-1">
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder={t('quick.amount')}
                                            aria-label={t('quick.amount')}
                                            className="text-right tabular-nums"
                                            value={cost.amount}
                                            onChange={(e) => updateCost(index, { amount: e.target.value })}
                                        />
                                        <InputError message={errors[`costs.${index}.amount`]} />
                                    </div>
                                    <div className="grid gap-1">
                                        <label htmlFor={`cost-payee-${index}`} className="sr-only">
                                            {t('quick.who')}
                                        </label>
                                        <SearchSelect
                                            fit
                                            id={`cost-payee-${index}`}
                                            value={cost.payee === 'supplier' ? 'supplier' : `money:${cost.money_account_id}`}
                                            onChange={(e) =>
                                                e.target.value === 'supplier'
                                                    ? updateCost(index, { payee: 'supplier', money_account_id: '' })
                                                    : updateCost(index, { payee: 'money', money_account_id: e.target.value.replace('money:', '') })
                                            }
                                        >
                                            <option value="supplier">{t('quick.owed_supplier')}</option>
                                            {moneyAccounts.map((account) => (
                                                <option key={account.id} value={`money:${account.id}`}>
                                                    {t('quick.paid_from')} {account.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        <InputError message={errors[`costs.${index}.money_account_id`] ?? errors[`costs.${index}.supplier_id`]} />
                                    </div>
                                    <div className="grid w-64 gap-1">
                                        <Input
                                            placeholder={t('quick.description')}
                                            aria-label={t('quick.description')}
                                            value={cost.description}
                                            onChange={(e) => updateCost(index, { description: e.target.value })}
                                        />
                                    </div>
                                    {cost.payee === 'money' && (
                                        <div className="grid w-48 gap-1">
                                            <Input
                                                placeholder={t('ipp.payee_name')}
                                                aria-label={t('ipp.payee_name')}
                                                title={t('ipp.payee_name_hint')}
                                                maxLength={120}
                                                value={cost.payee_name}
                                                onChange={(e) => updateCost(index, { payee_name: e.target.value })}
                                            />
                                        </div>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-10"
                                        onClick={() => setCosts(costs.filter((_, i) => i !== index))}
                                    >
                                        {t('quick.remove')}
                                    </Button>
                                </div>
                            );
                        })}
                        <InputError message={errors.costs} />
                        <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => setCosts([...costs, emptyCost()])}>
                            {t('quick.add_cost')}
                        </Button>
                    </section>
                )}

                {/* A trade discount printed on the supplier's bill (wave A's rule). */}
                <FormRow className="gap-3">
                    <Field label={t('quick.discount')} size="sm" error={errors.discount_amount} hint={t('quick.discount_hint')}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="text-right tabular-nums"
                            value={discountAmount}
                            onChange={(e) => setDiscountAmount(e.target.value)}
                        />
                    </Field>
                    {discount > 0 && (
                        <Field label={t('quick.discount_reference')} required size="md" error={errors.discount_reference}>
                            <Input value={discountReference} onChange={(e) => setDiscountReference(e.target.value)} />
                        </Field>
                    )}
                </FormRow>

                {rights.advance && (
                    <TakeOffWhatTheyOwe supplierId={supplierId} value={takeOff} onChange={setTakeOff} billPaisa={goods - discount} errors={errors} />
                )}
                {rights.advance && !takeOff.on && (
                    <AllocateAdvances
                        supplierId={supplierId}
                        rows={advanceRows}
                        onChange={setAdvanceRows}
                        billPaisa={goods - discount + extrasOwed}
                        errors={errors}
                    />
                )}
                {/* The payment: optional, part payment allowed. */}
                <section className="flex flex-col gap-3">
                    <h2 className="text-sm font-medium">{t('quick.payment')}</h2>
                    {!rights.pay && <p className="text-muted-foreground text-sm">{t('quick.no_pay_right')}</p>}
                    {rights.pay && (
                        <label className="flex w-fit items-center gap-2 text-sm">
                            <input type="checkbox" checked={payNow} onChange={(e) => setPayNow(e.target.checked)} />
                            {t('quick.pay_now')}
                        </label>
                    )}
                    {rights.pay && payNow && (
                        <FormRow className="gap-3">
                            <Field label={t('quick.paid_from')} required error={errors.payment_money_account_id ?? errors.money_account_id}>
                                <SearchSelect fit value={paymentAccountId} onChange={(e) => setPaymentAccountId(e.target.value)}>
                                    <option value="">Select an account</option>
                                    {moneyAccounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                            data-hint={account.balance != null ? `Balance now ${formatMoney(account.balance)}` : undefined}
                                        >
                                            {account.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                            <Field label={t('quick.amount_paid')} required size="sm" error={errors.payment_amount}>
                                <div className="flex items-center gap-1.5">
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        className="text-right tabular-nums"
                                        value={paymentAmount}
                                        onChange={(e) => setPaymentAmount(e.target.value)}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setPaymentAmount((Math.max(owed, 0) / 100).toFixed(2))}
                                    >
                                        {t('quick.pay_all')}
                                    </Button>
                                </div>
                            </Field>
                            <Field label={t('quick.reference')} size="lg">
                                <Input
                                    placeholder={t('quick.reference_hint')}
                                    value={paymentReference}
                                    onChange={(e) => setPaymentReference(e.target.value)}
                                />
                            </Field>
                        </FormRow>
                    )}
                    {rights.pay && payNow && (
                        <SplitPaymentLines
                            accounts={moneyAccounts}
                            primaryAccountId={paymentAccountId}
                            rows={splitLines}
                            onChange={setSplitLines}
                            totalPaisa={paying > 0 ? paying : null}
                            errors={errors}
                        />
                    )}
                    {rights.pay && payNow && paying > 0 && excess < 0 && <p className="text-muted-foreground text-xs">{t('quick.part_payment')}</p>}
                    {rights.pay &&
                        payNow &&
                        excess > 0 &&
                        (rights.advance ? (
                            <label className="flex max-w-xl items-start gap-2 rounded-lg border border-amber-500/50 p-3 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={excessToAdvance}
                                    onChange={(e) => setExcessToAdvance(e.target.checked)}
                                />
                                <span>
                                    {taka(excess)} {t('quick.overpay_advance')}
                                </span>
                            </label>
                        ) : (
                            <p className="text-destructive text-sm">{t('quick.overpay_refused')}</p>
                        ))}
                </section>

                <Field label={t('quick.note')} size="grow">
                    <Input value={note} onChange={(e) => setNote(e.target.value)} />
                </Field>

                {/* What the supplier is owed, while typing. The bill is the truth. */}
                <div className="flex flex-wrap gap-6 text-sm">
                    <p>
                        {t('quick.owed_total')} <span className="font-medium tabular-nums">{taka(owed)}</span>
                    </p>
                    {paying > 0 && (
                        <p>
                            {t('quick.left_owing')} <span className="font-medium tabular-nums">{taka(Math.max(owed - paying, 0))}</span>
                        </p>
                    )}
                </div>

                {/* ⭐ Ruling P5: the bill looks like one already on the books.
                    Nothing was saved; say why it is a different bill and save again. */}
                {(errors.duplicate || duplicateReason !== '') && (
                    <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        <p className="font-medium">{t('bill_flex.duplicate_title')}</p>
                        {errors.duplicate && <p>{errors.duplicate}</p>}
                        <Field label={t('bill_flex.duplicate_reason')} required size="grow">
                            <Input aria-required value={duplicateReason} onChange={(e) => setDuplicateReason(e.target.value)} />
                        </Field>
                    </div>
                )}

                {/* Where a refused purchase says why. Nothing was saved. */}
                <InputError message={errors.quick} />

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing || blocked || (excess > 0 && payNow && !(rights.advance && excessToAdvance))}>
                        {processing ? t('quick.saving') : t('quick.save')}
                    </Button>
                    <span className="text-muted-foreground text-xs">{t('quick.all_or_nothing')}</span>
                </div>
            </form>
        </AppLayout>
    );
}
