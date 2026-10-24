import { EntityQuickCreate } from '@/components/entity-quick-create';
import { DateWords } from '@/components/form/date-words';
import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { advancePaisa, type AdvanceRow, AllocateAdvances, filledAdvanceRows } from '@/components/purchasing/allocate-advances';
import { PaymentTermsFields, useTermsLabel } from '@/components/purchasing/payment-terms-fields';
import { ProductQuickCreate } from '@/components/purchasing/product-quick-create';
import { PurchaseQuantityTotals } from '@/components/purchasing/purchase-quantity-totals';
import { filledSplitLines, type SplitLine, SplitPaymentLines } from '@/components/purchasing/split-payment-lines';
import { SupplierCreditPanel, useSupplierCredit } from '@/components/purchasing/supplier-credit-panel';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { addDays, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type PaymentTerms } from '@/lib/payment-terms';
import { deriveLineTotal, deriveUnitPrice, type EntryMode } from '@/lib/purchase-line';
import { type DiscountType, roundingToTaka, workOrder } from '@/lib/purchase-order-figures';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';

/**
 * ⭐ The full purchase order page (purchase redesign PR1, 18 Oct 2026). Joy:
 * "when I click New purchase order a new page comes with all the important
 * fields ... auto-generate what can be; too many steps, make it short".
 *
 * Laid out like Tally's purchase voucher: the party and dates on top, the
 * items with discount and VAT, then the additional costs and the totals, and
 * the three switches that make it the whole purchase on one page (goods in,
 * bill, payment).
 * ⛔ Joy, 19 Oct 2026: NO keyboard shortcuts. Ctrl+A (meant as "select all")
 * was wired to save and silently saved a real order at Tk 18,001,800. The
 * order saves only by its button; Enter never submits (lib/no-enter-submit).
 *
 * ⚠ Every figure on this page is a PREVIEW (lib/purchase-order-figures.ts);
 * the server works each one out again and its answer is what is saved.
 */

type Option = { id: number; name: string };

type SupplierOption = Option & {
    code: string | null;
    phone: string | null;
    terms: PaymentTerms | null;
    lead_time_days: number | null;
    last_warehouse_id: number | null;
};

type ProductOption = Option & {
    sku: string;
    barcode: string | null;
    stocked: boolean;
    unit_id: number | null;
    unit: string | null;
    units: { id: number; abbreviation: string; factor: string }[];
    last_cost: { unit_cost: string; po_number: string | null; received_on: string | null } | null;
    tax_rate: string;
    weight_grams: number | null;
    tracks_expiry: boolean;
};

type MoneyAccountOption = Option & { type: string; balance?: string | null };

type CostType = { value: string; label: string; into_cost: boolean; needs_head: boolean };

type LineDraft = {
    product_id: string;
    description: string;
    quantity: string;
    unit_id: string;
    unit_cost: string;
    line_total: string;
    entry_mode: EntryMode;
    discount_type: DiscountType;
    discount_value: string;
    tax_rate: string;
    batch_number: string;
    manufactured_date: string;
    expiry_date: string;
};

type ChargeDraft = {
    cost_type: string;
    description: string;
    amount: string;
    allocation_method: 'value' | 'quantity' | 'weight' | 'direct';
    line_index: number | null;
    payee: 'this' | 'supplier' | 'money';
    supplier_id: string;
    money_account_id: string;
    /** Initial plan B1 (R49): a one-off person paid from the account. */
    payee_name: string;
    treatment: '' | 'capitalize' | 'expense';
    override_reason: string;
    expense_account_id: string;
};

type ScheduleDraft = { due_on: string; amount: string; note: string };

type EditOrder = {
    id: number;
    po_number: string;
    supplier_id: number;
    warehouse_id: number;
    order_date: string;
    expected_date: string | null;
    note: string | null;
    supplier_reference: string | null;
    buyer_id: number | null;
    purchasing_employee_id: number | null;
    terms_text: string | null;
    payment_terms_type: string | null;
    payment_terms_days: number | null;
    discount_type: DiscountType | null;
    discount_value: string;
    rounding: string;
    lines: Partial<LineDraft & { product_id: number; unit_id: number | null }>[];
    charges: Partial<ChargeDraft & { supplier_id: number | null; money_account_id: number | null; expense_account_id: number | null }>[];
    schedule: ScheduleDraft[];
};

type FormData = {
    supplier_id: string;
    warehouse_id: string;
    order_date: string;
    expected_date: string;
    supplier_reference: string;
    buyer_id: string;
    /** Initial plan B1 (R40): the HR employee who handled the purchase. */
    purchasing_employee_id: string;
    payment_terms_type: string;
    payment_terms_days: string;
    terms_text: string;
    note: string;
    discount_type: DiscountType;
    discount_value: string;
    rounding: string;
    lines: LineDraft[];
    charges: ChargeDraft[];
    schedule: ScheduleDraft[];
    attachments: File[];
    receive_now: boolean;
    bill_now: boolean;
    pay_now: boolean;
    challan_number: string;
    backdate_reason: string;
    bill_number: string;
    due_date: string;
    due_date_reason: string;
    duplicate_reason: string;
    payment_money_account_id: string;
    payment_amount: string;
    payment_reference: string;
    excess_to_advance: boolean;
    /** Initial plan B2 (R57): the other accounts; "Paid from" pays the rest. */
    split_lines: SplitLine[];
    /** Initial plan B1 (R56): earlier advances taken off the bill. */
    advances: AdvanceRow[];
    request_key: string;
    _method?: string;
};

const blankLine = (): LineDraft => ({
    product_id: '',
    description: '',
    quantity: '',
    unit_id: '',
    unit_cost: '',
    line_total: '',
    entry_mode: 'rate',
    discount_type: '',
    discount_value: '',
    tax_rate: '',
    batch_number: '',
    manufactured_date: '',
    expiry_date: '',
});

const blankCharge = (): ChargeDraft => ({
    cost_type: 'freight',
    description: '',
    amount: '',
    allocation_method: 'value',
    line_index: null,
    payee: 'this',
    supplier_id: '',
    money_account_id: '',
    payee_name: '',
    treatment: '',
    override_reason: '',
    expense_account_id: '',
});

const selectClass = 'border-input bg-background h-10 rounded-md border px-2 text-sm';
const taka = (paisa: number) => formatMoney(paisa / 100);
const str = (value: unknown) => (value === null || value === undefined ? '' : String(value));
const newKey = () =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

/** Every box Enter can move to, in page order. */
function focusables(form: HTMLElement): HTMLElement[] {
    return Array.from(
        form.querySelectorAll<HTMLElement>('input:not([type=hidden]):not([type=file]):not([type=checkbox]), select, textarea, button[role=combobox]'),
    ).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null && !el.closest('[data-no-enter]'));
}

export default function PurchaseOrderForm({
    order,
    nextNumber,
    today,
    suppliers,
    warehouses,
    defaultWarehouseId,
    products,
    units,
    categories,
    currentUserId,
    employees = [],
    moneyAccounts,
    expenseAccounts,
    costTypes,
    vat,
    rights,
    copy = null,
    needs_edit_reason = false,
    edit_reasons = [],
}: {
    order: EditOrder | null;
    /** ⭐ Corrections wave 1 (19 Oct 2026): Copy fills a NEW order from another one. */
    copy?: (Omit<EditOrder, 'id' | 'po_number'> & { id: null; po_number: null; copied_from: string }) | null;
    /** ⭐ Corrections wave 1: an approved order is changed with a reason. */
    needs_edit_reason?: boolean;
    edit_reasons?: { id: number; name: string }[];
    nextNumber: string;
    today: string;
    suppliers: SupplierOption[];
    warehouses: Option[];
    defaultWarehouseId: number | null;
    products: ProductOption[];
    units: { id: number; name: string; abbreviation: string }[];
    categories: Option[];
    buyers: Option[];
    currentUserId: number;
    /** Initial plan B1 (R40): HR employees for "Purchasing employee". */
    employees?: Option[];
    moneyAccounts: MoneyAccountOption[];
    expenseAccounts: { id: number; label: string }[];
    costTypes: CostType[];
    vat: { applies: boolean };
    rights: {
        approve_order: boolean;
        receive: boolean;
        bill: boolean;
        pay: boolean;
        advance: boolean;
        landed_costs: boolean;
        create_product: boolean;
        create_supplier: boolean;
    };
}) {
    const { t, tr } = useTranslation();
    const termsLabel = useTermsLabel();
    const editing = order !== null;
    const formRef = useRef<HTMLFormElement>(null);
    // ⭐ Corrections wave 1: the values come from the order being edited, or
    // from the order being copied; only `order` makes this an edit.
    const source = order ?? copy;
    const [editReasonId, setEditReasonId] = useState('');
    const [editReason, setEditReason] = useState('');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Purchase orders', href: '/purchasing/purchase-orders' },
        { title: editing ? order.po_number : t('pof.title_new'), href: '#' },
    ];

    const { data, setData, post, errors, processing, transform } = useForm<FormData>({
        supplier_id: str(source?.supplier_id),
        warehouse_id: str(source?.warehouse_id ?? defaultWarehouseId),
        order_date: source?.order_date ?? today,
        expected_date: str(source?.expected_date),
        supplier_reference: str(source?.supplier_reference),
        buyer_id: str(source?.buyer_id ?? currentUserId),
        purchasing_employee_id: str(source?.purchasing_employee_id),
        payment_terms_type: str(source?.payment_terms_type),
        payment_terms_days: str(source?.payment_terms_days),
        terms_text: str(source?.terms_text),
        note: str(source?.note),
        discount_type: (source?.discount_type ?? '') as DiscountType,
        discount_value: str(source?.discount_value),
        rounding: str(source?.rounding),
        lines: source
            ? source.lines.map((line) => ({ ...blankLine(), ...line, product_id: str(line.product_id), unit_id: str(line.unit_id) }))
            : [blankLine()],
        charges: source
            ? source.charges.map((charge) => ({
                  ...blankCharge(),
                  ...charge,
                  supplier_id: str(charge.supplier_id),
                  money_account_id: str(charge.money_account_id),
                  expense_account_id: str(charge.expense_account_id),
                  treatment: (charge.treatment ?? '') as ChargeDraft['treatment'],
                  override_reason: str(charge.override_reason),
                  description: str(charge.description),
                  payee_name: str(charge.payee_name),
              }))
            : [],
        schedule: source?.schedule ?? [],
        attachments: [],
        receive_now: false,
        bill_now: false,
        pay_now: false,
        challan_number: '',
        backdate_reason: '',
        bill_number: '',
        due_date: '',
        due_date_reason: '',
        duplicate_reason: '',
        payment_money_account_id: '',
        payment_amount: '',
        payment_reference: '',
        excess_to_advance: false,
        split_lines: [],
        advances: [],
        request_key: newKey(),
    });

    const fieldError = (key: string) => (errors as Record<string, string | undefined>)[key];
    const productById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products]);
    const chosenSupplier = suppliers.find((s) => String(s.id) === data.supplier_id) ?? null;
    const costTypeOf = (value: string) => costTypes.find((type) => type.value === value);

    // ── The live figures (a preview; the server decides) ────────────────
    const factorOf = (line: LineDraft): number => {
        const product = productById.get(line.product_id);
        const unit = product?.units.find((u) => String(u.id) === line.unit_id);

        return unit ? Number(unit.factor) || 1 : 1;
    };

    const intoCost = (charge: ChargeDraft) =>
        charge.treatment === '' ? (costTypeOf(charge.cost_type)?.into_cost ?? false) : charge.treatment === 'capitalize';

    const figures = workOrder(
        data.lines.map((line) => ({
            ...line,
            factor: factorOf(line),
            weight_grams: productById.get(line.product_id)?.weight_grams ?? null,
        })),
        { discount_type: data.discount_type, discount_value: data.discount_value, rounding: data.rounding, vat_in_cost: !vat.applies },
        data.charges.map((charge) => ({
            amount: charge.amount,
            allocation_method: charge.allocation_method,
            line_index: charge.line_index,
            into_cost: intoCost(charge),
            to_this_supplier: charge.payee === 'this' || (charge.payee === 'supplier' && charge.supplier_id === data.supplier_id),
        })),
    );

    const credit = useSupplierCredit(chosenSupplier?.id ?? null, Math.max(0, figures.grand));

    // ── Masters created from inside the order ("New supplier" / "New item") ──
    const [creatingSupplier, setCreatingSupplier] = useState(false);
    const [typedSupplier, setTypedSupplier] = useState('');
    const [creatingProductFor, setCreatingProductFor] = useState<number | null>(null);
    const [typedProduct, setTypedProduct] = useState('');

    // ── Lines ─────────────────────────────────────────────────────────────
    const setLine = (index: number, patch: Partial<LineDraft>) =>
        setData((all) => ({ ...all, lines: all.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)) }));

    const [focusLine, setFocusLine] = useState<number | null>(null);

    const addLine = () => {
        setData('lines', [...data.lines, blankLine()]);
        setFocusLine(data.lines.length);
    };

    useEffect(() => {
        if (focusLine === null) {
            return;
        }

        formRef.current?.querySelector<HTMLElement>(`[data-line="${focusLine}"] button[role=combobox]`)?.focus();
        setFocusLine(null);
    }, [focusLine, data.lines.length]);

    const removeLine = (index: number) => {
        setData((all) => ({
            ...all,
            lines: all.lines.filter((_, i) => i !== index),
            // A "one item" charge on the removed line loses its item; later items move up.
            charges: all.charges.map((charge) =>
                charge.line_index === null
                    ? charge
                    : charge.line_index === index
                      ? { ...charge, line_index: null }
                      : charge.line_index > index
                        ? { ...charge, line_index: charge.line_index - 1 }
                        : charge,
            ),
        }));
    };

    /** The rate a product starts at: the last received price, in the unit the line is typed in. */
    const autofillRate = (product: ProductOption | undefined, unitId: string): string => {
        if (!product?.last_cost) {
            return '';
        }

        const unit = product.units.find((u) => String(u.id) === unitId);
        const rate = Number(product.last_cost.unit_cost) * (unit ? Number(unit.factor) || 1 : 1);

        return String(Math.round(rate * 10000) / 10000);
    };

    const pickProduct = (index: number, productId: string) => {
        const product = productById.get(productId);
        const unitId = str(product?.unit_id);
        const rate = autofillRate(product, unitId);
        const line = data.lines[index];

        setLine(index, {
            product_id: productId,
            unit_id: unitId,
            unit_cost: rate,
            entry_mode: 'rate',
            line_total: deriveLineTotal(line.quantity, rate),
            tax_rate: product && Number(product.tax_rate) > 0 ? product.tax_rate : '',
        });
    };

    const pickUnit = (index: number, unitId: string) => {
        const line = data.lines[index];
        const product = productById.get(line.product_id);
        const wasAutofilled = line.unit_cost === autofillRate(product, line.unit_id);
        const rate = wasAutofilled ? autofillRate(product, unitId) : line.unit_cost;

        setLine(index, {
            unit_id: unitId,
            unit_cost: rate,
            line_total: line.entry_mode === 'rate' ? deriveLineTotal(line.quantity, rate) : line.line_total,
        });
    };

    const typeQuantity = (index: number, quantity: string) => {
        const line = data.lines[index];
        setLine(
            index,
            line.entry_mode === 'total'
                ? { quantity, unit_cost: deriveUnitPrice(quantity, line.line_total) }
                : { quantity, line_total: deriveLineTotal(quantity, line.unit_cost) },
        );
    };
    const typeRate = (index: number, unit_cost: string) =>
        setLine(index, { unit_cost, entry_mode: 'rate', line_total: deriveLineTotal(data.lines[index].quantity, unit_cost) });
    const typeTotal = (index: number, line_total: string) =>
        setLine(index, { line_total, entry_mode: 'total', unit_cost: deriveUnitPrice(data.lines[index].quantity, line_total) });

    // ── Supplier picked: everything that follows from it ────────────────
    const pickSupplier = (value: string) => {
        const supplier = suppliers.find((s) => String(s.id) === value);

        setData((all) => ({
            ...all,
            supplier_id: value,
            // The warehouse their last order went to, else the default.
            warehouse_id: supplier?.last_warehouse_id ? String(supplier.last_warehouse_id) : all.warehouse_id,
            // Their usual lead time from the order date; blank when unknown.
            expected_date: supplier?.lead_time_days ? addDays(all.order_date, supplier.lead_time_days) : all.expected_date,
            payment_terms_type: '',
            payment_terms_days: '',
        }));

        // The supplier's quotation number is the next thing to ask for.
        window.setTimeout(() => document.getElementById('po-supplier-reference')?.focus(), 0);
    };

    // ── Charges and the payment plan ─────────────────────────────────────
    const setCharge = (index: number, patch: Partial<ChargeDraft>) =>
        setData((all) => ({ ...all, charges: all.charges.map((charge, i) => (i === index ? { ...charge, ...patch } : charge)) }));
    const setSchedule = (index: number, patch: Partial<ScheduleDraft>) =>
        setData((all) => ({ ...all, schedule: all.schedule.map((row, i) => (i === index ? { ...row, ...patch } : row)) }));

    // ── Pay now ──────────────────────────────────────────────────────────
    const payAmount = data.payment_amount === '' ? figures.grand / 100 : Number(data.payment_amount) || 0;
    const advanced = data.bill_now && rights.advance ? advancePaisa(data.advances) : 0;
    const balanceDue = figures.grand - advanced - Math.round((data.pay_now ? payAmount : 0) * 100);
    const backdated = data.order_date < today;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        transform((payload) => ({
            ...payload,
            // A line left blank at the end is not part of the order.
            lines: payload.lines.filter((line, index) => line.product_id !== '' || index === 0),
            ...(editing ? { _method: 'put' } : {}),
            ...(editing && needs_edit_reason ? { reason_id: editReasonId, reason_text: editReason.trim() } : {}),
            payment_amount: payload.pay_now ? (payload.payment_amount === '' ? (figures.grand / 100).toFixed(2) : payload.payment_amount) : '',
            // B2: a split only with a bill (an advance comes from one account).
            split_lines: payload.pay_now && payload.bill_now ? filledSplitLines(payload.split_lines) : [],
            advances: payload.bill_now && rights.advance ? filledAdvanceRows(payload.advances) : [],
        }));

        post(editing ? `/purchasing/purchase-orders/${order.id}` : '/purchasing/purchase-orders', { preserveScroll: true, forceFormData: true });
    };

    const lineCaption = (index: number) => {
        const product = productById.get(data.lines[index].product_id);

        return product ? product.name : t('pof.item_n', { n: index + 1 });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? t('pof.title_edit', { number: order.po_number }) : t('pof.title_new')} />

            <form ref={formRef} onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{editing ? t('pof.title_edit', { number: order.po_number }) : t('pof.title_new')}</h1>
                        <p className="text-muted-foreground text-sm">
                            {editing ? t('pof.edit_intro') : t('pof.number_preview', { number: nextNumber })}
                        </p>
                        {copy && <p className="mt-1 text-sm">{t('corr.po.copy_banner', { number: copy.copied_from })}</p>}
                        {editing && needs_edit_reason && (
                            <div className="mt-2 flex max-w-xl flex-col gap-1.5 rounded-md border border-amber-300 p-2.5 text-sm dark:border-amber-700">
                                <p>{t('corr.po.edit_reason')}</p>
                                <select
                                    aria-label={t('corr.reason')}
                                    value={editReasonId}
                                    onChange={(e) => setEditReasonId(e.target.value)}
                                    className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                                >
                                    <option value="">{t('corr.reason_pick')}</option>
                                    {edit_reasons.map((reason) => (
                                        <option key={reason.id} value={reason.id}>
                                            {reason.name}
                                        </option>
                                    ))}
                                </select>
                                <textarea
                                    aria-label={t('corr.reason_words')}
                                    value={editReason}
                                    onChange={(e) => setEditReason(e.target.value)}
                                    className="border-input bg-background/80 min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                                />
                                {fieldError('reason_text') && <p className="text-destructive text-xs">{fieldError('reason_text')}</p>}
                            </div>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" asChild>
                            <Link href={editing ? `/purchasing/purchase-orders/${order.id}` : '/purchasing/purchase-orders'}>{t('pof.cancel')}</Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {editing ? t('pof.save_changes') : t('pof.save')}
                        </Button>
                    </div>
                </div>

                {/* ── Party and dates ─────────────────────────────────────── */}
                <section className="flex flex-col gap-3 rounded-xl border p-4">
                    <FormRow className="gap-3">
                        <Field label={t('pof.supplier')} required error={fieldError('supplier_id')} className="w-72">
                            <div data-master="supplier">
                                <SearchableSelect
                                    value={data.supplier_id}
                                    onChange={pickSupplier}
                                    options={suppliers.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                        keywords: [s.code, s.phone].filter(Boolean).join(' '),
                                    }))}
                                    placeholder={t('pof.pick_supplier')}
                                    searchPlaceholder={t('pof.search_supplier')}
                                    onCreate={
                                        rights.create_supplier
                                            ? (typed) => {
                                                  setTypedSupplier(typed);
                                                  setCreatingSupplier(true);
                                              }
                                            : undefined
                                    }
                                    createLabel={t('pof.new_supplier')}
                                    required
                                />
                            </div>
                        </Field>
                        <Field label={t('pof.supplier_reference')} htmlFor="po-supplier-reference" size="md" error={fieldError('supplier_reference')}>
                            <Input
                                id="po-supplier-reference"
                                placeholder={t('pof.supplier_reference_hint')}
                                value={data.supplier_reference}
                                onChange={(e) => setData('supplier_reference', e.target.value)}
                            />
                        </Field>
                        <Field label={t('po.order_date')} required error={fieldError('order_date')}>
                            <Input type="date" max={today} value={data.order_date} onChange={(e) => setData('order_date', e.target.value)} />
                            <DateWords value={data.order_date} />
                        </Field>
                        <Field label={t('pof.expected_date')} error={fieldError('expected_date')}>
                            <Input type="date" value={data.expected_date} onChange={(e) => setData('expected_date', e.target.value)} />
                            <DateWords value={data.expected_date} />
                        </Field>
                        <Field label={t('pof.deliver_to')} required error={fieldError('warehouse_id')} className="w-56">
                            <SearchableSelect
                                value={data.warehouse_id}
                                onChange={(v) => setData('warehouse_id', v)}
                                options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                                placeholder={t('pof.pick_warehouse')}
                            />
                        </Field>
                        {/* Initial plan B1 (R40): the HR employee who handled it; the
                            login that typed it is still kept as the buyer. */}
                        <Field label={t('ipp.employee')} error={fieldError('purchasing_employee_id')} className="w-56">
                            <SearchableSelect
                                value={data.purchasing_employee_id}
                                onChange={(v) => setData('purchasing_employee_id', v)}
                                options={employees.map((e) => ({ value: String(e.id), label: e.name }))}
                                placeholder={t('ipp.employee_pick')}
                                clearable
                            />
                        </Field>
                    </FormRow>
                    <FormRow className="gap-3">
                        <PaymentTermsFields
                            idPrefix="po"
                            label={t('terms.order_terms')}
                            type={data.payment_terms_type}
                            days={data.payment_terms_days}
                            onType={(value) => setData('payment_terms_type', value)}
                            onDays={(value) => setData('payment_terms_days', value)}
                            defaultLabel={
                                chosenSupplier?.terms
                                    ? t('terms.suppliers_named', { terms: termsLabel(chosenSupplier.terms.type, chosenSupplier.terms.days) ?? '' })
                                    : t('terms.suppliers')
                            }
                            typeError={fieldError('payment_terms_type')}
                            daysError={fieldError('payment_terms_days')}
                        />
                    </FormRow>
                    {credit && <SupplierCreditPanel credit={credit} context="order" />}
                </section>

                {/* ── Items ──────────────────────────────────────────────── */}
                <section className="flex flex-col gap-2">
                    <h2 className="text-base font-semibold">{t('pof.items')}</h2>
                    {!vat.applies && <p className="text-muted-foreground text-xs">{t('pof.vat_off_hint')}</p>}
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-[64rem] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="w-8 p-2">#</th>
                                    <th className="p-2">{t('pof.item')}</th>
                                    <th className="p-2">{t('pof.quantity')}</th>
                                    <th className="p-2">{t('pof.rate')}</th>
                                    <th className="p-2">{t('pof.amount')}</th>
                                    <th className="p-2">{t('pof.discount')}</th>
                                    <th className="p-2">{t('pof.vat')}</th>
                                    <th className="p-2 text-right">{t('pof.line_total')}</th>
                                    <th className="p-2 text-right">{t('pof.landed_unit')}</th>
                                    <th className="p-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {data.lines.map((line, index) => {
                                    const product = productById.get(line.product_id);
                                    const worked = figures.lines[index];
                                    const unitLabel = product?.units.find((u) => String(u.id) === line.unit_id)?.abbreviation ?? product?.unit ?? '';
                                    const lineValue = worked.net + worked.vat;
                                    const err = (field: string) => fieldError(`lines.${index}.${field}`);

                                    return [
                                        <tr key={`l${index}`} data-line={index} className="border-t align-top">
                                            <td className="text-muted-foreground p-2 pt-4">{index + 1}</td>
                                            <td className="p-2">
                                                <div className="grid w-72 gap-1" data-master="product" data-index={index}>
                                                    <SearchableSelect
                                                        value={line.product_id}
                                                        onChange={(v) => {
                                                            pickProduct(index, v);
                                                            window.setTimeout(() => {
                                                                const all = focusables(formRef.current!);
                                                                const trigger = formRef.current?.querySelector<HTMLElement>(
                                                                    `[data-line="${index}"] button[role=combobox]`,
                                                                );
                                                                all[all.indexOf(trigger!) + 1]?.focus();
                                                            }, 0);
                                                        }}
                                                        options={products.map((p) => ({
                                                            value: String(p.id),
                                                            label: p.sku ? `${p.sku} - ${p.name}` : p.name,
                                                            keywords: p.barcode ?? undefined,
                                                        }))}
                                                        placeholder={t('pof.pick_item')}
                                                        searchPlaceholder={t('pof.search_item')}
                                                        onCreate={
                                                            rights.create_product
                                                                ? (typed) => {
                                                                      setTypedProduct(typed);
                                                                      setCreatingProductFor(index);
                                                                  }
                                                                : undefined
                                                        }
                                                        createLabel={t('pof.new_product')}
                                                        invalid={!!err('product_id')}
                                                    />
                                                    <Input
                                                        aria-label={t('pof.description')}
                                                        placeholder={t('pof.description_hint')}
                                                        className="h-8 text-xs"
                                                        value={line.description}
                                                        onChange={(e) => setLine(index, { description: e.target.value })}
                                                    />
                                                    <InputError message={err('product_id') ?? err('description')} />
                                                </div>
                                            </td>
                                            <td className="p-2">
                                                <div className="flex w-40 items-start gap-1">
                                                    <Input
                                                        aria-label={t('pof.quantity')}
                                                        inputMode="decimal"
                                                        className="w-20 text-right"
                                                        value={line.quantity}
                                                        onChange={(e) => typeQuantity(index, e.target.value)}
                                                    />
                                                    {product && product.units.length > 1 ? (
                                                        <select
                                                            aria-label={t('pof.unit')}
                                                            className={`${selectClass} w-20`}
                                                            value={line.unit_id}
                                                            onChange={(e) => pickUnit(index, e.target.value)}
                                                        >
                                                            {product.units.map((unit) => (
                                                                <option key={unit.id} value={unit.id}>
                                                                    {unit.abbreviation}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    ) : (
                                                        <span className="text-muted-foreground pt-2.5 text-xs">{unitLabel}</span>
                                                    )}
                                                </div>
                                                <InputError message={err('quantity') ?? err('unit_id')} />
                                            </td>
                                            <td className="p-2">
                                                <div className="grid w-32 gap-1">
                                                    <Input
                                                        aria-label={t('pof.rate')}
                                                        inputMode="decimal"
                                                        className={`text-right ${line.entry_mode === 'total' ? 'text-muted-foreground' : ''}`}
                                                        value={line.unit_cost}
                                                        onChange={(e) => typeRate(index, e.target.value)}
                                                    />
                                                    {product?.last_cost && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {t('pof.last_price', { price: product.last_cost.unit_cost })}
                                                            {product.last_cost.received_on ? ` (${showDate(product.last_cost.received_on)})` : ''}
                                                        </p>
                                                    )}
                                                    <InputError message={err('unit_cost')} />
                                                </div>
                                            </td>
                                            <td className="p-2">
                                                <div className="grid w-32 gap-1">
                                                    <Input
                                                        aria-label={t('pof.amount')}
                                                        inputMode="decimal"
                                                        className={`text-right ${line.entry_mode === 'rate' ? 'text-muted-foreground' : ''}`}
                                                        value={line.line_total}
                                                        onChange={(e) => typeTotal(index, e.target.value)}
                                                    />
                                                    <InputError message={err('line_total')} />
                                                </div>
                                            </td>
                                            <td className="p-2">
                                                <div className="flex w-36 items-start gap-1">
                                                    <Input
                                                        aria-label={t('pof.discount')}
                                                        inputMode="decimal"
                                                        className="w-20 text-right"
                                                        value={line.discount_value}
                                                        onChange={(e) =>
                                                            setLine(index, {
                                                                discount_value: e.target.value,
                                                                discount_type: line.discount_type === '' ? 'percent' : line.discount_type,
                                                            })
                                                        }
                                                    />
                                                    <select
                                                        aria-label={t('pof.discount_kind')}
                                                        className={`${selectClass} w-16`}
                                                        value={line.discount_type === '' ? 'percent' : line.discount_type}
                                                        onChange={(e) => setLine(index, { discount_type: e.target.value as DiscountType })}
                                                    >
                                                        <option value="percent">%</option>
                                                        <option value="amount">Tk</option>
                                                    </select>
                                                </div>
                                                {worked.orderDiscount > 0 && (
                                                    <p className="text-muted-foreground text-xs">
                                                        {t('pof.order_share', { amount: taka(worked.orderDiscount) })}
                                                    </p>
                                                )}
                                                <InputError message={err('discount_value')} />
                                            </td>
                                            <td className="p-2" data-line-end={data.receive_now ? undefined : index}>
                                                <div className="flex w-24 items-center gap-1">
                                                    <Input
                                                        aria-label={t('pof.vat_rate')}
                                                        inputMode="decimal"
                                                        className="w-16 text-right"
                                                        placeholder="0"
                                                        value={line.tax_rate}
                                                        onChange={(e) => setLine(index, { tax_rate: e.target.value })}
                                                    />
                                                    <span className="text-muted-foreground text-xs">%</span>
                                                </div>
                                                {worked.vat > 0 && <p className="text-muted-foreground text-xs tabular-nums">{taka(worked.vat)}</p>}
                                                <InputError message={err('tax_rate')} />
                                            </td>
                                            <td className="p-2 pt-4 text-right font-medium tabular-nums">{taka(lineValue)}</td>
                                            <td className="text-muted-foreground p-2 pt-4 text-right text-xs tabular-nums">
                                                {worked.landedPerUnit !== null && product?.stocked
                                                    ? `${formatMoney((worked.landedPerUnit / 100).toFixed(2))}${unitLabel ? ` /${unitLabel}` : ''}`
                                                    : ''}
                                            </td>
                                            <td className="p-2 pt-2.5" data-no-enter>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeLine(index)}
                                                    disabled={data.lines.length === 1}
                                                >
                                                    {t('pof.remove')}
                                                </Button>
                                            </td>
                                        </tr>,
                                        data.receive_now && product?.stocked ? (
                                            <tr key={`b${index}`} className="bg-muted/20">
                                                <td />
                                                <td colSpan={9} className="p-2" data-line-end={index}>
                                                    <div className="flex flex-wrap items-start gap-3">
                                                        <Field label={t('pof.batch')} error={err('batch_number')} size="sm">
                                                            <Input
                                                                value={line.batch_number}
                                                                placeholder={t('batchno.auto')}
                                                                onChange={(e) => setLine(index, { batch_number: e.target.value })}
                                                            />
                                                        </Field>
                                                        <Field label={t('pof.made_on')} error={err('manufactured_date')}>
                                                            <Input
                                                                type="date"
                                                                value={line.manufactured_date}
                                                                onChange={(e) => setLine(index, { manufactured_date: e.target.value })}
                                                            />
                                                        </Field>
                                                        <Field label={t('pof.expiry')} required={product.tracks_expiry} error={err('expiry_date')}>
                                                            <Input
                                                                type="date"
                                                                value={line.expiry_date}
                                                                onChange={(e) => setLine(index, { expiry_date: e.target.value })}
                                                            />
                                                        </Field>
                                                    </div>
                                                </td>
                                            </tr>
                                        ) : null,
                                    ];
                                })}
                            </tbody>
                        </table>
                    </div>
                    <PurchaseQuantityTotals
                        rows={data.lines
                            .filter((line) => line.product_id !== '')
                            .map((line) => {
                                const product = productById.get(line.product_id);

                                return {
                                    quantity: line.quantity,
                                    unit: product?.units.find((u) => String(u.id) === line.unit_id)?.abbreviation ?? product?.unit ?? null,
                                    factor: factorOf(line),
                                    weight_grams: product?.weight_grams ?? null,
                                };
                            })}
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" size="sm" onClick={addLine}>
                            {t('pof.add_item')}
                        </Button>
                        <p className="text-muted-foreground text-xs">{t('pof.items_hint')}</p>
                    </div>
                    <InputError message={fieldError('lines')} />
                </section>

                <div className="grid gap-6 lg:grid-cols-[1fr_24rem]">
                    <div className="flex flex-col gap-6">
                        {/* ── Charges (Tally's additional cost of purchase) ── */}
                        <section className="flex flex-col gap-2">
                            <h2 className="text-base font-semibold">{t('pof.charges')}</h2>
                            <p className="text-muted-foreground text-xs">{t('pof.charges_hint')}</p>
                            {data.charges.map((charge, index) => {
                                const type = costTypeOf(charge.cost_type);
                                const treatment = charge.treatment === '' ? (type?.into_cost ? 'capitalize' : 'expense') : charge.treatment;
                                const overridden = charge.treatment !== '' && (charge.treatment === 'capitalize') !== (type?.into_cost ?? false);
                                const err = (field: string) => fieldError(`charges.${index}.${field}`);

                                return (
                                    <div key={index} className="flex flex-col gap-2 rounded-lg border p-3">
                                        <div className="flex flex-wrap items-start gap-3">
                                            <Field label={t('pof.charge_type')} error={err('cost_type')}>
                                                <select
                                                    className={selectClass}
                                                    value={charge.cost_type}
                                                    onChange={(e) =>
                                                        setCharge(index, { cost_type: e.target.value, treatment: '', override_reason: '' })
                                                    }
                                                >
                                                    {costTypes.map((option) => (
                                                        <option key={option.value} value={option.value}>
                                                            {tr(`landed.type.${option.value}`, option.label)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </Field>
                                            <Field label={t('pof.amount')} required error={err('amount')} size="sm">
                                                <Input
                                                    inputMode="decimal"
                                                    className="text-right"
                                                    value={charge.amount}
                                                    onChange={(e) => setCharge(index, { amount: e.target.value })}
                                                />
                                            </Field>
                                            <Field label={t('pof.charge_description')} error={err('description')} size="md">
                                                <Input
                                                    value={charge.description}
                                                    onChange={(e) => setCharge(index, { description: e.target.value })}
                                                />
                                            </Field>
                                            <Field label={t('pof.into_cost')}>
                                                <select
                                                    className={selectClass}
                                                    value={treatment}
                                                    onChange={(e) => setCharge(index, { treatment: e.target.value as ChargeDraft['treatment'] })}
                                                >
                                                    <option value="capitalize">{t('pof.into_cost_yes')}</option>
                                                    <option value="expense">{t('pof.into_cost_no')}</option>
                                                </select>
                                            </Field>
                                            {treatment === 'capitalize' && (
                                                <Field label={t('pof.split_by')} error={err('allocation_method') ?? err('line_index')}>
                                                    <div className="flex gap-1">
                                                        <select
                                                            className={selectClass}
                                                            value={charge.allocation_method}
                                                            onChange={(e) =>
                                                                setCharge(index, {
                                                                    allocation_method: e.target.value as ChargeDraft['allocation_method'],
                                                                    line_index: e.target.value === 'direct' ? (charge.line_index ?? 0) : null,
                                                                })
                                                            }
                                                        >
                                                            <option value="value">{t('pof.split_value')}</option>
                                                            <option value="quantity">{t('pof.split_quantity')}</option>
                                                            <option value="weight">{t('pof.split_weight')}</option>
                                                            <option value="direct">{t('pof.split_direct')}</option>
                                                        </select>
                                                        {charge.allocation_method === 'direct' && (
                                                            <select
                                                                className={selectClass}
                                                                value={charge.line_index ?? 0}
                                                                onChange={(e) => setCharge(index, { line_index: Number(e.target.value) })}
                                                            >
                                                                {data.lines.map((_, lineIndex) => (
                                                                    <option key={lineIndex} value={lineIndex}>
                                                                        {lineCaption(lineIndex)}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        )}
                                                    </div>
                                                </Field>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap items-start gap-3">
                                            <Field label={t('pof.payee')} error={err('payee')}>
                                                <select
                                                    className={selectClass}
                                                    value={charge.payee}
                                                    onChange={(e) => setCharge(index, { payee: e.target.value as ChargeDraft['payee'] })}
                                                >
                                                    <option value="this">{t('pof.payee_this')}</option>
                                                    <option value="supplier">{t('pof.payee_other')}</option>
                                                    <option value="money">{t('pof.payee_money')}</option>
                                                </select>
                                            </Field>
                                            {charge.payee === 'supplier' && (
                                                <Field label={t('pof.supplier')} className="w-60">
                                                    <SearchableSelect
                                                        value={charge.supplier_id}
                                                        onChange={(v) => setCharge(index, { supplier_id: v })}
                                                        options={suppliers.map((s) => ({ value: String(s.id), label: s.name }))}
                                                        placeholder={t('pof.pick_supplier')}
                                                    />
                                                </Field>
                                            )}
                                            {charge.payee === 'money' && (
                                                <Field label={t('pof.paid_from')} className="w-60">
                                                    <SearchableSelect
                                                        value={charge.money_account_id}
                                                        onChange={(v) => setCharge(index, { money_account_id: v })}
                                                        options={moneyAccounts.map((a) => ({ value: String(a.id), label: a.name }))}
                                                        placeholder={t('pof.pick_account')}
                                                    />
                                                </Field>
                                            )}
                                            {charge.payee === 'money' && (
                                                <Field label={t('ipp.payee_name')} error={err('payee_name')} size="md">
                                                    <Input
                                                        value={charge.payee_name}
                                                        placeholder={t('ipp.payee_name_hint')}
                                                        maxLength={120}
                                                        onChange={(e) => setCharge(index, { payee_name: e.target.value })}
                                                    />
                                                </Field>
                                            )}
                                            {overridden && (
                                                <Field label={t('pof.why')} required error={err('override_reason')} size="grow">
                                                    <Input
                                                        value={charge.override_reason}
                                                        onChange={(e) => setCharge(index, { override_reason: e.target.value })}
                                                    />
                                                </Field>
                                            )}
                                            {treatment === 'expense' && type?.needs_head && (
                                                <Field label={t('pof.expense_head')} required error={err('expense_account_id')} className="w-64">
                                                    <SearchableSelect
                                                        value={charge.expense_account_id}
                                                        onChange={(v) => setCharge(index, { expense_account_id: v })}
                                                        options={expenseAccounts.map((a) => ({ value: String(a.id), label: a.label }))}
                                                        placeholder={t('pof.pick_head')}
                                                    />
                                                </Field>
                                            )}
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="mt-6"
                                                onClick={() =>
                                                    setData(
                                                        'charges',
                                                        data.charges.filter((_, i) => i !== index),
                                                    )
                                                }
                                            >
                                                {t('pof.remove')}
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                onClick={() => setData('charges', [...data.charges, blankCharge()])}
                            >
                                {t('pof.add_charge')}
                            </Button>
                            {data.charges.length > 0 && !rights.landed_costs && (
                                <p className="text-muted-foreground text-xs">{t('pof.charges_no_right')}</p>
                            )}
                        </section>

                        {/* ── Payment plan ───────────────────────────────────── */}
                        <section className="flex flex-col gap-2">
                            <h2 className="text-base font-semibold">{t('pof.schedule')}</h2>
                            <p className="text-muted-foreground text-xs">{t('pof.schedule_hint')}</p>
                            {data.schedule.map((row, index) => (
                                <div key={index} className="flex flex-wrap items-start gap-3">
                                    <Field label={t('pof.due_on')} required error={fieldError(`schedule.${index}.due_on`)}>
                                        <Input type="date" value={row.due_on} onChange={(e) => setSchedule(index, { due_on: e.target.value })} />
                                    </Field>
                                    <Field label={t('pof.amount')} required error={fieldError(`schedule.${index}.amount`)} size="sm">
                                        <Input
                                            inputMode="decimal"
                                            className="text-right"
                                            value={row.amount}
                                            onChange={(e) => setSchedule(index, { amount: e.target.value })}
                                        />
                                    </Field>
                                    <Field label={t('pof.schedule_note')} size="grow">
                                        <Input value={row.note} onChange={(e) => setSchedule(index, { note: e.target.value })} />
                                    </Field>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="mt-6"
                                        onClick={() =>
                                            setData(
                                                'schedule',
                                                data.schedule.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        {t('pof.remove')}
                                    </Button>
                                </div>
                            ))}
                            <InputError message={fieldError('schedule')} />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                onClick={() =>
                                    setData('schedule', [...data.schedule, { due_on: data.expected_date || data.order_date, amount: '', note: '' }])
                                }
                            >
                                {t('pof.add_schedule')}
                            </Button>
                        </section>

                        {/* ── Terms, note, files ───────────────────────────── */}
                        <section className="flex flex-col gap-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="po-terms-text">{t('pof.terms_text')}</Label>
                                <textarea
                                    id="po-terms-text"
                                    rows={3}
                                    className="border-input bg-background rounded-md border p-2 text-sm"
                                    placeholder={t('pof.terms_text_hint')}
                                    value={data.terms_text}
                                    onChange={(e) => setData('terms_text', e.target.value)}
                                />
                                <InputError message={fieldError('terms_text')} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="po-note">{t('pof.note')}</Label>
                                <textarea
                                    id="po-note"
                                    rows={2}
                                    className="border-input bg-background rounded-md border p-2 text-sm"
                                    value={data.note}
                                    onChange={(e) => setData('note', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1.5" data-no-enter>
                                <Label htmlFor="po-files">{t('pof.attachments')}</Label>
                                <input
                                    id="po-files"
                                    type="file"
                                    multiple
                                    accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx,.doc,.docx"
                                    className="text-sm"
                                    onChange={(e) => setData('attachments', Array.from(e.target.files ?? []))}
                                />
                                <p className="text-muted-foreground text-xs">{t('pof.attachments_hint')}</p>
                                <InputError message={fieldError('attachments') ?? fieldError('attachments.0')} />
                            </div>
                        </section>
                    </div>

                    {/* ── Totals, like the foot of a Tally voucher ───────────── */}
                    <aside className="flex h-fit flex-col gap-3 rounded-xl border p-4">
                        <dl className="flex flex-col gap-1.5 text-sm">
                            <div className="flex justify-between">
                                <dt>{t('pof.subtotal')}</dt>
                                <dd className="tabular-nums">{taka(figures.goods)}</dd>
                            </div>
                            {figures.discounts - figures.orderDiscount > 0 && (
                                <div className="flex justify-between">
                                    <dt>{t('pof.line_discounts')}</dt>
                                    <dd className="tabular-nums">-{taka(figures.discounts - figures.orderDiscount)}</dd>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <dt>{t('pof.order_discount')}</dt>
                                <dd className="flex items-center gap-1">
                                    <Input
                                        aria-label={t('pof.order_discount')}
                                        inputMode="decimal"
                                        className="h-8 w-20 text-right"
                                        value={data.discount_value}
                                        onChange={(e) =>
                                            setData((all) => ({
                                                ...all,
                                                discount_value: e.target.value,
                                                discount_type: all.discount_type || 'percent',
                                            }))
                                        }
                                    />
                                    <select
                                        aria-label={t('pof.discount_kind')}
                                        className="border-input bg-background h-8 rounded-md border px-1 text-sm"
                                        value={data.discount_type || 'percent'}
                                        onChange={(e) => setData('discount_type', e.target.value as DiscountType)}
                                    >
                                        <option value="percent">%</option>
                                        <option value="amount">Tk</option>
                                    </select>
                                    <span className="w-24 text-right tabular-nums">
                                        {figures.orderDiscount > 0 ? `-${taka(figures.orderDiscount)}` : ''}
                                    </span>
                                </dd>
                            </div>
                            <InputError message={fieldError('discount_value')} />
                            <div className="flex justify-between">
                                <dt>{vat.applies ? t('pof.vat_total') : t('pof.vat_total_in_cost')}</dt>
                                <dd className="tabular-nums">{taka(figures.vat)}</dd>
                            </div>
                            {figures.supplierCharges > 0 && (
                                <div className="flex justify-between">
                                    <dt>{t('pof.supplier_charges')}</dt>
                                    <dd className="tabular-nums">{taka(figures.supplierCharges)}</dd>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <dt>{t('pof.rounding')}</dt>
                                <dd className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-8"
                                        onClick={() => setData('rounding', (roundingToTaka(figures.grand - figures.rounding) / 100).toFixed(2))}
                                    >
                                        {t('pof.round')}
                                    </Button>
                                    <Input
                                        aria-label={t('pof.rounding')}
                                        inputMode="decimal"
                                        className="h-8 w-20 text-right"
                                        value={data.rounding}
                                        onChange={(e) => setData('rounding', e.target.value)}
                                    />
                                </dd>
                            </div>
                            <InputError message={fieldError('rounding')} />
                            <div className="flex justify-between border-t pt-2 text-base font-semibold">
                                <dt>{t('pof.grand_total')}</dt>
                                <dd className="tabular-nums">{taka(figures.grand)}</dd>
                            </div>
                            {figures.otherCharges > 0 && (
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <dt>{t('pof.other_charges')}</dt>
                                    <dd className="tabular-nums">{taka(figures.otherCharges)}</dd>
                                </div>
                            )}
                            <div className="text-muted-foreground flex justify-between text-xs">
                                <dt>{t('pof.total_cost')}</dt>
                                <dd className="tabular-nums">{taka(figures.totalCost)}</dd>
                            </div>
                        </dl>

                        {/* ── The short path: the rest of the purchase, here ── */}
                        {!editing && (
                            <div className="flex flex-col gap-3 border-t pt-3">
                                {rights.receive && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.receive_now}
                                            onCheckedChange={(v) =>
                                                setData((all) => ({ ...all, receive_now: v === true, bill_now: v === true ? all.bill_now : false }))
                                            }
                                        />
                                        {t('pof.receive_now')}
                                    </label>
                                )}
                                {data.receive_now && (
                                    <div className="flex flex-col gap-2 pl-6">
                                        <Field label={t('pof.challan')} error={fieldError('challan_number')} size="md">
                                            <Input value={data.challan_number} onChange={(e) => setData('challan_number', e.target.value)} />
                                        </Field>
                                        {backdated && (
                                            <Field label={t('pof.backdate_reason')} required error={fieldError('backdate_reason')}>
                                                <Input value={data.backdate_reason} onChange={(e) => setData('backdate_reason', e.target.value)} />
                                            </Field>
                                        )}
                                        {!rights.approve_order && <p className="text-muted-foreground text-xs">{t('pof.receive_needs_approval')}</p>}
                                    </div>
                                )}
                                {rights.bill && data.receive_now && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={data.bill_now} onCheckedChange={(v) => setData('bill_now', v === true)} />
                                        {t('pof.bill_now')}
                                    </label>
                                )}
                                {data.bill_now && (
                                    <div className="flex flex-col gap-2 pl-6">
                                        <Field label={t('pof.bill_number')} required error={fieldError('bill_number')} size="md">
                                            <Input value={data.bill_number} onChange={(e) => setData('bill_number', e.target.value)} />
                                        </Field>
                                        <Field label={t('pof.due_date')} error={fieldError('due_date')}>
                                            <Input type="date" value={data.due_date} onChange={(e) => setData('due_date', e.target.value)} />
                                        </Field>
                                        {(data.due_date !== '' || fieldError('due_date_reason')) && (
                                            <Field label={t('pof.due_date_reason')} error={fieldError('due_date_reason')}>
                                                <Input value={data.due_date_reason} onChange={(e) => setData('due_date_reason', e.target.value)} />
                                            </Field>
                                        )}
                                        {fieldError('duplicate') && (
                                            <Field label={t('pof.duplicate_reason')} required error={fieldError('duplicate')}>
                                                <Input value={data.duplicate_reason} onChange={(e) => setData('duplicate_reason', e.target.value)} />
                                            </Field>
                                        )}
                                    </div>
                                )}
                                {data.bill_now && rights.advance && (
                                    <AllocateAdvances
                                        supplierId={data.supplier_id}
                                        rows={data.advances}
                                        onChange={(rows) => setData('advances', rows)}
                                        billPaisa={figures.grand}
                                        errors={errors as Record<string, string | undefined>}
                                    />
                                )}
                                {((data.bill_now && rights.pay) || (!data.bill_now && rights.advance)) && (
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={data.pay_now} onCheckedChange={(v) => setData('pay_now', v === true)} />
                                        {data.bill_now ? t('pof.pay_now') : t('pof.pay_advance')}
                                    </label>
                                )}
                                {data.pay_now && (
                                    <div className="flex flex-col gap-2 pl-6">
                                        <Field label={t('pof.paid_from')} required error={fieldError('payment_money_account_id')} className="w-60">
                                            <SearchableSelect
                                                value={data.payment_money_account_id}
                                                onChange={(v) => setData('payment_money_account_id', v)}
                                                options={moneyAccounts.map((a) => ({
                                                    value: String(a.id),
                                                    label: a.balance != null ? `${a.name} (${formatMoney(a.balance)})` : a.name,
                                                }))}
                                                placeholder={t('pof.pick_account')}
                                            />
                                        </Field>
                                        <Field label={t('pof.pay_amount')} error={fieldError('payment_amount')} size="sm">
                                            <Input
                                                inputMode="decimal"
                                                className="text-right"
                                                placeholder={(figures.grand / 100).toFixed(2)}
                                                value={data.payment_amount}
                                                onChange={(e) => setData('payment_amount', e.target.value)}
                                            />
                                        </Field>
                                        {data.bill_now && (
                                            <SplitPaymentLines
                                                accounts={moneyAccounts}
                                                primaryAccountId={data.payment_money_account_id}
                                                rows={data.split_lines}
                                                onChange={(rows) => setData('split_lines', rows)}
                                                totalPaisa={Math.round(payAmount * 100)}
                                                errors={errors as Record<string, string | undefined>}
                                            />
                                        )}
                                        <Field label={t('pof.pay_reference')} size="md">
                                            <Input value={data.payment_reference} onChange={(e) => setData('payment_reference', e.target.value)} />
                                        </Field>
                                        {data.bill_now && rights.advance && (
                                            <label className="flex items-center gap-2 text-xs">
                                                <Checkbox
                                                    checked={data.excess_to_advance}
                                                    onCheckedChange={(v) => setData('excess_to_advance', v === true)}
                                                />
                                                {t('pof.excess_to_advance')}
                                            </label>
                                        )}
                                    </div>
                                )}
                                <div className="flex justify-between border-t pt-2 text-sm font-semibold">
                                    <span>{t('pof.balance_due')}</span>
                                    <span className="tabular-nums">{taka(balanceDue)}</span>
                                </div>
                            </div>
                        )}
                        <InputError message={fieldError('bill_now')} />
                        <Button type="submit" disabled={processing}>
                            {editing
                                ? t('pof.save_changes')
                                : data.bill_now
                                  ? t('pof.save_all')
                                  : data.receive_now
                                    ? t('pof.save_receive')
                                    : t('pof.save')}
                        </Button>
                    </aside>
                </div>
            </form>

            <EntityQuickCreate
                kind="supplier"
                open={creatingSupplier}
                onOpenChange={setCreatingSupplier}
                initialName={typedSupplier}
                onCreated={(row) => router.reload({ only: ['suppliers'], onSuccess: () => pickSupplier(String(row.id)) })}
            />
            <ProductQuickCreate
                open={creatingProductFor !== null}
                onOpenChange={(open) => !open && setCreatingProductFor(null)}
                initialName={typedProduct}
                units={units}
                categories={categories}
                onCreated={(row) => {
                    const index = creatingProductFor ?? 0;
                    router.reload({ only: ['products'], onSuccess: () => setLine(index, { product_id: String(row.id) }) });
                }}
            />
        </AppLayout>
    );
}
