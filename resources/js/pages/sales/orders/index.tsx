import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { BdPlaceFields } from '@/components/form/bd-place-fields';
import InputError from '@/components/input-error';
import { AgentPicker, type CommissionAgentOption } from '@/components/sales/agent-picker';
import { BrandBadge, type BrandOption } from '@/components/sales/brand';
import { Chip, CourierSaysCell, type CourierWord } from '@/components/sales/sales-ui';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { lineBrandMismatch, productsForBrand } from '@/lib/brand-products';
import { dhakaToday, formatDate, showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { plainPrice, salesLineTotal, salesUnitPriceFor, type SalesEntryMode } from '@/lib/sales-line';
import { formatMoney, formatQuantity } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Option {
    id: number;
    name: string;
    sku?: string;
    /** Products only: the brand it is sold under (practice run, 18 Oct 2026). */
    brand_id?: number | null;
    unit?: string | null;
    phone?: string | null;
    type?: string;
    is_default?: boolean;
    credit_limit?: string | null;
    weight_grams?: number | null;
    address?: string | null;
    /** Customers only (24 Oct 2026): the place of their last delivery, whole, or null. */
    saved_place?: { district: string; thana: string; area: string; address_line: string; landmark: string } | null;
}

interface OrderRow {
    id: number;
    order_number: string;
    status: 'open' | 'partially_delivered' | 'delivered' | 'closed' | 'cancelled';
    ordered_at: string;
    expected_date: string | null;
    total_ordered: string;
    total_delivered: string;
    customer: { name: string };
    warehouse: { name: string } | null;
    // ⚠ Null for an online order still waiting for its payment: it is not sent
    // for approval until the gateway's payment is booked (SSLCommerz slice P2c).
    approval_request: { status: 'pending' | 'approved' | 'rejected' } | null;
    // Website Phase 2, slice 2.4. Null on every order typed into the ERP.
    channel: 'internal' | 'website' | null;
    shop_reference: string | null;
    // Joy, 11 Oct 2026. Null on an order taken before brands were recorded.
    brand: { id: number; name: string } | null;
    // ⭐ "Courier says" (15 Oct 2026): the courier's last word on the parcel
    // already on this order's challan. Null until a challan is booked.
    courier_says: CourierWord | null;
}

interface Exposure {
    credit_limit: string | null;
    open_invoices: string;
    delivered_not_invoiced: string;
    undelivered_orders: string;
    total: string;
}

interface CommittedRow {
    product_id: number;
    product_name: string;
    product_sku: string;
    committed: string;
    on_hand: string;
    short: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Orders', href: '/sales/orders' },
];

/** The queues of the order book, in the order a sale moves (SalesOrderController::QUEUES). */
const QUEUES = ['approval', 'dispatch', 'moving', 'problem', 'delivered'] as const;

const statusVariant: Record<OrderRow['status'], 'secondary' | 'default' | 'outline' | 'destructive'> = {
    open: 'secondary',
    partially_delivered: 'outline',
    delivered: 'default',
    closed: 'outline',
    cancelled: 'destructive',
};

const approvalVariant: Record<'pending' | 'approved' | 'rejected', 'secondary' | 'default' | 'destructive'> = {
    pending: 'secondary',
    approved: 'default',
    rejected: 'destructive',
};

/*
 * ⭐ Joy, 19 Oct 2026: a line is typed by its unit price OR its total, as on the
 * purchase order. `entry_mode` is the box typed last; in 'total' mode the unit
 * price is worked out from the total (lib/sales-line.ts) and that unit price is
 * what is sent, so the server stores exactly one figure as it always has.
 */
type LineDraft = {
    uid: number;
    product_id: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    entry_mode: SalesEntryMode;
    description: string;
    /** Corrections wave 1: the order line being edited (null on a new line). */
    id?: number | null;
    /** What already left on this line (Edit undelivered part). */
    delivered?: number;
};

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): the order the form opens on, to Edit it
 * or to Copy it into a new one (SalesOrderActions::form). The same form as a
 * new order, never a second one.
 */
export type OrderSeed = {
    id: number | null;
    /** ⭐ Commission agreements (25 Oct 2026): "employee:12" and the like, or null. */
    agent?: string | null;
    order_number: string;
    mode: 'edit' | 'edit_undelivered' | 'copy';
    customer_id: number;
    brand_id: number | null;
    warehouse_id: number | null;
    ordered_at: string | null;
    expected_date: string | null;
    note: string | null;
    special_instructions: string | null;
    delivery_charge: string;
    weight_grams: number | null;
    address: AddressDraft;
    lines: { id: number | null; product_id: number; quantity: string; unit_price: string; description: string; delivered: number }[];
    /** ⭐ B20: the replacement order of an exchange, linked to its return. */
    exchange_for?: { id: number; return_number: string } | null;
};

interface LinePrice {
    unit_price: string | null;
    explanation: string | null;
    refusal: string | null;
    loading: boolean;
    /** F21: what the goods last cost, only for people who may read costs. */
    last_cost?: string | null;
}

/** F36: where the parcel goes, checked on the server against the ERP's list. */
type AddressDraft = {
    name: string;
    phone: string;
    district: string;
    thana: string;
    area: string;
    address_line: string;
    landmark: string;
};

interface CustomerType {
    value: string;
    label: string;
}

interface PriceGroupOption {
    id: number;
    code: string;
    name: string;
}

let nextUid = 1;
/*
 * ⚠ Joy, 17 Oct 2026: the line columns are sized to what they hold, not shares
 * of the page. 3fr/1fr/1fr/1fr stretched a quantity box to 15rem on a laptop.
 * The product grows to 30rem and stops; the header row uses the same template.
 * ⚠ The last track is max-content, not auto: an auto track stretches to fill
 * the row, which drew the Remove button as a bar across half the screen.
 */
// ⚠ 19 Oct 2026: a line number in front and the total is a box now, so both
// money columns are the same width and line up under their headings.
const LINE_COLUMNS = 'sm:grid-cols-[1.5rem_minmax(14rem,30rem)_7rem_9rem_9rem_max-content]';
// The rows under a line (note, price explanation, warnings) start under the product.
const UNDER_LINE = 'col-span-2 sm:col-span-5 sm:col-start-2';

const newLine = (): LineDraft => ({
    uid: nextUid++,
    product_id: '',
    quantity: '',
    unit_price: '',
    line_total: '',
    entry_mode: 'rate',
    description: '',
});

/** The unit price a line is at: what was typed, else what the server answered. */
const effectiveUnit = (line: LineDraft, listed: string | null | undefined): string =>
    line.unit_price !== '' ? line.unit_price : listed ? money2(listed) : '';

// One way to write money across the ERP (practice run F19/F27): 2 decimals, commas.
const money = (value: number) => formatMoney(value);
// The server's 900.0000 shown as 900.00; two prices equal in value are the same price.
const money2 = (value: string) => parseFloat(value).toFixed(2);
const samePrice = (a: string, b: string | null | undefined) => b != null && b !== '' && parseFloat(a) === parseFloat(b);

// Last ten digits: 01711-000000, +8801711000000 and 1711000000 are one phone.
const phoneKey = (phone: string | null | undefined) => (phone ?? '').replace(/\D/g, '').slice(-10);

/*
 * ⚠ C-SO-3, shown as the customer is picked: what they already stand at - open
 * invoices plus approved undelivered orders - next to their credit limit. The
 * number comes from the same CustomerExposure the order screen shows. Since
 * 21 Sep 2026 approval refuses an order over the limit, so the box says so.
 */
function ExposureBox({ customerId }: { customerId: string }) {
    const { t } = useTranslation();
    const [exposure, setExposure] = useState<Exposure | null>(null);

    useEffect(() => {
        if (!customerId) {
            setExposure(null);
            return;
        }

        let cancelled = false;

        fetch(route('sales.orders.exposure'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ customer_id: Number(customerId) }),
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                if (!cancelled) setExposure(data);
            })
            .catch(() => {
                if (!cancelled) setExposure(null);
            });

        return () => {
            cancelled = true;
        };
    }, [customerId]);

    if (!exposure) return null;

    const overLimit = exposure.credit_limit !== null && parseFloat(exposure.total) > parseFloat(exposure.credit_limit);

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border w-fit max-w-full rounded-md border p-3 text-sm">
            <p className="text-muted-foreground">
                {t('orders.exposure', {
                    invoices: formatMoney(exposure.open_invoices),
                    delivered: formatMoney(exposure.delivered_not_invoiced),
                    orders: formatMoney(exposure.undelivered_orders),
                })}{' '}
                <span className="text-foreground font-medium">{formatMoney(exposure.total)}</span>
                {exposure.credit_limit !== null && <> {t('orders.against_limit', { limit: formatMoney(exposure.credit_limit) })}</>}
            </p>
            {overLimit && <p className="text-destructive mt-1 font-medium">{t('orders.over_limit')}</p>}
        </div>
    );
}

/*
 * ⭐ One line of the order with its price, asked of the server as it is typed
 * (21 Sep 2026): the same resolver the order runs, so what the form shows is
 * what the order will say, and a product with no price for this customer is
 * named here instead of refusing the whole order on submit.
 */
function OrderLineRow({
    line,
    index,
    customerId,
    warehouseId,
    orderDate,
    products,
    price,
    canOverride,
    canRemove,
    errors,
    onChange,
    onPriced,
    onRemove,
    brandId,
    brandNames,
}: {
    line: LineDraft;
    index: number;
    customerId: string;
    warehouseId: string;
    /** The order's date: a back-dated order is priced as on that day. */
    orderDate: string;
    products: Option[];
    price: LinePrice | undefined;
    canOverride: boolean;
    canRemove: boolean;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<LineDraft>) => void;
    onPriced: (price: LinePrice | undefined) => void;
    onRemove: () => void;
    /** The order's brand ('' = none chosen) and every brand's name, for the warning. */
    brandId: string;
    brandNames: Map<number, string>;
}) {
    const { t } = useTranslation();
    useEffect(() => {
        if (!customerId || !line.product_id) {
            onPriced(undefined);
            return;
        }

        let cancelled = false;
        onPriced({ unit_price: null, explanation: null, refusal: null, loading: true });

        const timer = window.setTimeout(async () => {
            const query = new URLSearchParams({ product_id: line.product_id, quantity: line.quantity || '1', customer_id: customerId });
            // ⭐ D-B (18 Oct 2026): an owner line is priced at this warehouse's FIFO batch.
            if (warehouseId) query.set('warehouse_id', warehouseId);
            // ⭐ 19 Oct 2026: the price in force on the order's own date.
            if (orderDate) query.set('on', orderDate);

            try {
                const response = await fetch(`${route('sales.orders.price')}?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const body = response.ok ? await response.json() : null;

                if (!cancelled) {
                    onPriced(
                        body
                            ? {
                                  unit_price: body.unit_price,
                                  explanation: body.explanation,
                                  refusal: body.refusal,
                                  loading: false,
                                  last_cost: body.last_cost ?? null,
                              }
                            : { unit_price: null, explanation: null, refusal: 'The price could not be looked up. Try again.', loading: false },
                    );
                }
            } catch {
                if (!cancelled) {
                    onPriced({ unit_price: null, explanation: null, refusal: 'The price could not be looked up. Try again.', loading: false });
                }
            }
        }, 300);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [customerId, warehouseId, orderDate, line.product_id, line.quantity]);

    const product = products.find((p) => String(p.id) === line.product_id);
    const otherBrand = lineBrandMismatch(product, brandId);
    const listed = price?.unit_price ? money2(price.unit_price) : '';
    const unitPrice = effectiveUnit(line, price?.unit_price);
    // The box typed last is shown as typed; the other one is worked out.
    const unitShown = line.entry_mode === 'total' ? (line.unit_price === '' ? '' : plainPrice(line.unit_price)) : unitPrice;
    const totalShown = line.entry_mode === 'total' ? line.line_total : salesLineTotal(line.quantity, unitPrice);
    const solved = line.entry_mode === 'total' ? salesUnitPriceFor(line.quantity, line.line_total) : null;
    const lineTotal = unitPrice !== '' && line.quantity !== '' ? parseFloat(salesLineTotal(line.quantity, unitPrice)) : null;
    // F21: a warning, never a block, when the agreed price is under the last cost.
    const lastCost = price?.last_cost ? parseFloat(price.last_cost) : null;
    const loss = lastCost !== null && unitPrice !== '' && parseFloat(unitPrice) < lastCost ? lastCost - parseFloat(unitPrice) : null;
    const changed = line.unit_price !== '' && listed !== '' && !samePrice(line.unit_price, price?.unit_price);

    const typeRate = (value: string) => onChange({ unit_price: value, line_total: '', entry_mode: 'rate' });
    const typeTotal = (value: string) =>
        value.trim() === ''
            ? onChange({ unit_price: '', line_total: '', entry_mode: 'rate' })
            : onChange({ line_total: value, entry_mode: 'total', unit_price: salesUnitPriceFor(line.quantity, value)?.unitPrice ?? '' });

    return (
        <div className={`border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 items-start gap-2 border-b pb-3 ${LINE_COLUMNS}`}>
            <span className="text-muted-foreground hidden h-9 items-center text-xs tabular-nums sm:flex">{index + 1}</span>
            <div className="col-span-2 grid gap-1 sm:col-span-1">
                <SearchableSelect
                    value={line.product_id}
                    onChange={(v) => onChange({ product_id: v, unit_price: '', line_total: '', entry_mode: 'rate' })}
                    options={productsForBrand(products, brandId, line.product_id).map((p) => ({
                        value: String(p.id),
                        label: p.sku ? `${p.sku} - ${p.name}` : p.name,
                    }))}
                    placeholder={t('orders.select_product')}
                    searchPlaceholder={t('orders.search_products')}
                />
                <InputError message={errors[`lines.${index}.product_id`]} />
            </div>
            <div className="grid gap-1">
                <div className="flex items-center gap-1.5">
                    <Input
                        placeholder={t('orders.quantity')}
                        aria-label={t('orders.quantity')}
                        inputMode="decimal"
                        className="text-right tabular-nums"
                        value={line.quantity}
                        onChange={(e) => onChange({ quantity: e.target.value })}
                        aria-required
                    />
                    {product?.unit && <span className="text-muted-foreground w-7 shrink-0 text-xs">{product.unit}</span>}
                </div>
                <InputError message={errors[`lines.${index}.quantity`]} />
            </div>
            <div className="grid gap-1">
                {canOverride ? (
                    <Input
                        placeholder={price?.loading ? '…' : t('orders.unit_price')}
                        inputMode="decimal"
                        className={`text-right tabular-nums ${line.entry_mode === 'total' ? 'text-muted-foreground' : ''}`}
                        value={unitShown}
                        onChange={(e) => typeRate(e.target.value)}
                        aria-label={t('orders.unit_price')}
                    />
                ) : (
                    <p className="flex h-9 items-center justify-end text-sm tabular-nums">
                        {price?.loading ? '…' : unitPrice ? formatMoney(unitPrice) : '-'}
                    </p>
                )}
                {canOverride && changed && (
                    <button
                        type="button"
                        className="text-muted-foreground w-fit justify-self-end text-xs underline"
                        onClick={() => onChange({ unit_price: '', line_total: '', entry_mode: 'rate' })}
                    >
                        {t('orders.changed_from', { price: listed })}
                    </button>
                )}
                <InputError message={errors[`lines.${index}.unit_price`]} />
            </div>
            <div className="grid gap-1">
                {canOverride ? (
                    <Input
                        placeholder={t('orders.line_total')}
                        inputMode="decimal"
                        className={`text-right font-medium tabular-nums ${line.entry_mode === 'rate' ? 'text-muted-foreground' : ''}`}
                        value={totalShown}
                        onChange={(e) => typeTotal(e.target.value)}
                        aria-label={t('orders.line_total')}
                    />
                ) : (
                    <p className="flex h-9 items-center justify-end text-sm font-medium tabular-nums">
                        {lineTotal !== null && !isNaN(lineTotal) ? money(lineTotal) : ''}
                    </p>
                )}
            </div>
            <Button type="button" variant="ghost" size="sm" className="text-muted-foreground" onClick={onRemove} disabled={!canRemove}>
                {t('orders.remove')}
            </Button>
            <div className={`grid gap-1 ${UNDER_LINE}`}>
                <Input
                    placeholder={t('orders.line_description')}
                    aria-label={t('orders.line_description')}
                    className="h-8 text-sm"
                    maxLength={255}
                    value={line.description}
                    onChange={(e) => onChange({ description: e.target.value })}
                />
                <InputError message={errors[`lines.${index}.description`]} />
            </div>
            {/* ⚠ 17 Oct 2026 (practice run F30): the line under a chosen product is
                kept, empty while the price is looked up, so the explanation arriving
                does not push Record down under a moving cursor. */}
            {line.product_id !== '' && (
                <p
                    className={`min-h-4 text-xs ${UNDER_LINE} ${price?.refusal && line.unit_price === '' ? 'text-destructive' : 'text-muted-foreground'}`}
                >
                    {line.unit_price !== '' && price?.refusal ? '' : (price?.refusal ?? price?.explanation ?? '')}
                </p>
            )}
            {solved !== null && !solved.exact && (
                <p className={`text-xs text-amber-700 dark:text-amber-400 ${UNDER_LINE}`}>
                    {t('orders.total_not_exact', { typed: money(parseFloat(line.line_total)), total: money(parseFloat(solved.total)) })}
                </p>
            )}
            {otherBrand !== null && (
                <p className={`text-xs text-amber-700 dark:text-amber-400 ${UNDER_LINE}`}>
                    {t('orders.other_brand', { brand: brandNames.get(otherBrand) ?? '' })}
                </p>
            )}
            {loss !== null && lastCost !== null && (
                <p className={`text-xs text-amber-700 dark:text-amber-400 ${UNDER_LINE}`}>
                    {t('orders.below_cost', { cost: money(lastCost), loss: money(loss) })}
                </p>
            )}
        </div>
    );
}

/*
 * ⭐ A customer added without leaving the order (21 Sep 2026). The same fields
 * the Customers screen asks for that decide the price: who they are (type) and
 * what they pay (price group). The group is preselected from the type and can
 * be changed; they are two different facts. A phone that is already somebody's
 * is named before a second record is made for the same person.
 */
function QuickCustomerDialog({
    open,
    onOpenChange,
    typed,
    customers,
    customerTypes,
    priceGroups,
    onCreated,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    typed: string;
    customers: Option[];
    customerTypes: CustomerType[];
    priceGroups: PriceGroupOption[];
    onCreated: (customerId: string) => void;
}) {
    const { t } = useTranslation();
    const groupFor = (type: string) => priceGroups.find((group) => group.code === type.toUpperCase())?.id;
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        name: '',
        phone: '',
        type: 'retail',
        price_group_id: '',
        address: '',
    });

    useEffect(() => {
        if (open) {
            clearErrors();
            // Digits (with + - or spaces) are a phone, however few: a search is often partial.
            const looksLikePhone = /\d/.test(typed) && /^[+\d\s-]+$/.test(typed);
            setData({
                name: looksLikePhone ? '' : typed,
                phone: looksLikePhone ? typed : '',
                type: 'retail',
                price_group_id: String(groupFor('retail') ?? ''),
                address: '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const samePhone = phoneKey(data.phone).length === 10 ? customers.find((c) => phoneKey(c.phone) === phoneKey(data.phone)) : undefined;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const name = data.name.trim();
        post(route('sales.customers.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // The newest customer of that name is the one just made.
                const list = (page.props as unknown as { customers: Option[] }).customers;
                const created = list.filter((c) => c.name === name).sort((a, b) => b.id - a.id)[0];
                if (created) onCreated(String(created.id));
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('orders.quick_title')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="quick-name" required>
                            {t('orders.quick_name')}
                        </Label>
                        <Input id="quick-name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus aria-required />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="quick-phone">{t('orders.quick_phone')}</Label>
                        <Input id="quick-phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} inputMode="tel" />
                        <InputError message={errors.phone} />
                        {samePhone && (
                            <p className="text-destructive text-xs">
                                {t('orders.quick_same_phone', { name: samePhone.name })}{' '}
                                <button
                                    type="button"
                                    className="underline"
                                    onClick={() => {
                                        onCreated(String(samePhone.id));
                                        onOpenChange(false);
                                    }}
                                >
                                    {t('orders.quick_use', { name: samePhone.name })}
                                </button>
                            </p>
                        )}
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-1">
                            <Label required>{t('orders.quick_who')}</Label>
                            <SearchableSelect
                                value={data.type}
                                onChange={(v) => setData({ ...data, type: v, price_group_id: String(groupFor(v) ?? data.price_group_id) })}
                                options={customerTypes.map((type) => ({ value: type.value, label: type.label }))}
                            />
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-1">
                            <Label>{t('orders.quick_prices')}</Label>
                            <SearchableSelect
                                value={data.price_group_id}
                                onChange={(v) => setData('price_group_id', v)}
                                options={priceGroups.map((group) => ({ value: String(group.id), label: group.name }))}
                                placeholder="Retail"
                            />
                            <InputError message={errors.price_group_id} />
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="quick-address">{t('orders.quick_address')}</Label>
                        <Input id="quick-address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            {t('orders.quick_cancel')}
                        </Button>
                        <Button disabled={processing}>{t('orders.quick_add')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A titled block of the order form: one heading, one optional line of help. */
function FormSection({ title, hint, children, aside }: { title: string; hint?: string; children: React.ReactNode; aside?: React.ReactNode }) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h4 className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">{title}</h4>
                    {hint && <p className="text-muted-foreground mt-0.5 text-xs">{hint}</p>}
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

function CreateOrderForm({
    customers,
    warehouses,
    products,
    canOverridePrice,
    canAddCustomer,
    customerTypes,
    priceGroups,
    brands,
    lastBrandId,
    lastWarehouseId,
    districts,
    onClose,
    initialCustomerId = null,
    seed = null,
    commissionAgents = [],
}: {
    seed?: OrderSeed | null;
    commissionAgents?: CommissionAgentOption[];
    customers: Option[];
    warehouses: Option[];
    products: Option[];
    canOverridePrice: boolean;
    canAddCustomer: boolean;
    customerTypes: CustomerType[];
    priceGroups: PriceGroupOption[];
    brands: BrandOption[];
    lastBrandId: number | null;
    lastWarehouseId: number | null;
    districts: string[];
    onClose: () => void;
    initialCustomerId?: number | null;
}) {
    // ⭐ Joy, 11 Oct 2026: every order carries its brand. Required while the
    // business sells under brands; the person's last brand is offered first.
    const activeBrands = brands.filter((b) => b.is_active !== false);
    const brandNames = new Map(brands.map((b) => [b.id, b.name]));
    const initialBrand = activeBrands.find((b) => b.id === lastBrandId)?.id ?? (activeBrands.length === 1 ? activeBrands[0].id : null);
    const { t } = useTranslation();
    const today = dhakaToday();
    const editing = seed !== null && seed.mode !== 'copy' && seed.id !== null;
    const partlyDelivered = seed?.mode === 'edit_undelivered';
    const { data, setData, post, put, errors, processing, transform } = useForm<{
        customer_id: string;
        brand_id: string;
        warehouse_id: string;
        ordered_at: string;
        expected_date: string;
        note: string;
        special_instructions: string;
        delivery_charge: string;
        address: AddressDraft;
        hand_over: boolean;
        weight_grams: string;
        agent: string;
        lines: LineDraft[];
    }>({
        customer_id: seed ? String(seed.customer_id) : initialCustomerId ? String(initialCustomerId) : '',
        brand_id: seed?.brand_id ? String(seed.brand_id) : initialBrand === null ? '' : String(initialBrand),
        // F24: the warehouse this person last took an order for, else the
        // default; "decided at delivery" is one click away.
        warehouse_id: seed
            ? seed.warehouse_id === null
                ? ''
                : String(seed.warehouse_id)
            : String(
                  (lastWarehouseId !== null && warehouses.some((w) => w.id === lastWarehouseId) ? lastWarehouseId : null) ??
                      warehouses.find((w) => w.is_default)?.id ??
                      '',
              ),
        // ⭐ Joy, 19 Oct 2026: the selling date, today unless changed, so an
        // order taken earlier can be entered afterwards on its own day.
        ordered_at: seed?.ordered_at ?? today,
        expected_date: seed?.expected_date ?? '',
        note: seed?.note ?? '',
        special_instructions: seed?.special_instructions ?? '',
        delivery_charge: seed?.delivery_charge ?? '',
        address: seed?.address ?? { name: '', phone: '', district: '', thana: '', area: '', address_line: '', landmark: '' },
        hand_over: false,
        weight_grams: seed?.weight_grams ? String(seed.weight_grams) : '',
        agent: seed?.agent ?? '',
        lines: seed
            ? seed.lines.map((line) => ({
                  ...newLine(),
                  id: editing ? line.id : null,
                  product_id: String(line.product_id),
                  quantity: line.quantity,
                  unit_price: line.unit_price,
                  description: line.description,
                  delivered: line.delivered,
              }))
            : [newLine()],
    });
    const [prices, setPrices] = useState<Record<number, LinePrice | undefined>>({});
    const [quickOpen, setQuickOpen] = useState(false);
    const [typedCustomer, setTypedCustomer] = useState('');
    const lineErrors = errors as Record<string, string | undefined>;
    const backdated = data.ordered_at !== '' && data.ordered_at < today;

    // ⚠ A price goes to the server only when somebody allowed to change it did.
    // Otherwise the server resolves it, as it always has. A line typed by its
    // total sends the unit price worked out from it.
    transform((form) => ({
        ...form,
        lines: form.lines.map((line) => ({
            ...(editing && line.id ? { id: line.id } : {}),
            product_id: line.product_id,
            quantity: line.quantity,
            ...(canOverridePrice && line.unit_price !== '' && !samePrice(line.unit_price, prices[line.uid]?.unit_price)
                ? { unit_price: line.unit_price }
                : {}),
            ...(line.description.trim() !== '' ? { description: line.description.trim() } : {}),
        })),
        // An address only once a district, thana or street was given: the
        // name and phone are filled in from the customer by themselves.
        // 24 Oct 2026: a hand-over order sends no address at all.
        address: !form.hand_over && (form.address.district || form.address.thana || form.address.address_line.trim()) ? form.address : null,
        hand_over: form.hand_over,
        ...(seed?.exchange_for ? { exchange_for_sales_return_id: seed.exchange_for.id } : {}),
    }));

    // F36: the recipient starts as the customer (name, phone, address typed on
    // the customer), and stays editable: a gift goes to somebody else's door.
    // ⚠ 24 Oct 2026: the place is prefilled WHOLE (district, thana, street,
    // from the customer's last delivery) or not at all. The customer's saved
    // street alone used to be prefilled with no district, and the save was
    // refused "We do not recognise that district."
    const pickCustomer = (id: string) => {
        const customer = customers.find((c) => String(c.id) === id);
        const place = customer?.saved_place ?? null;
        setData((current) => {
            const blank = !current.address.district && !current.address.thana && !current.address.address_line.trim();

            return {
                ...current,
                customer_id: id,
                address: {
                    ...current.address,
                    name: customer?.name ?? '',
                    phone: customer?.phone ?? '',
                    ...(blank && place !== null && place.district !== '' ? place : {}),
                },
            };
        });
    };
    const pickedCustomer = customers.find((c) => String(c.id) === data.customer_id);
    const savedAddressHint =
        !data.hand_over && pickedCustomer?.address && !pickedCustomer.saved_place && !data.address.district && !data.address.address_line.trim()
            ? pickedCustomer.address
            : null;
    const setAddress = (patch: Partial<AddressDraft>) => setData('address', { ...data.address, ...patch });
    const addressErrors = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing && seed?.id) {
            put(route('sales.orders.update', seed.id));

            return;
        }

        post(route('sales.orders.store'));
    };

    // A new quantity keeps a typed TOTAL and works its unit price out again.
    const updateLine = (uid: number, patch: Partial<LineDraft>) =>
        setData(
            'lines',
            data.lines.map((line) => {
                if (line.uid !== uid) return line;
                const next = { ...line, ...patch };
                if (patch.quantity !== undefined && next.entry_mode === 'total') {
                    next.unit_price = salesUnitPriceFor(next.quantity, next.line_total)?.unitPrice ?? '';
                }
                return next;
            }),
        );
    const removeLine = (uid: number) =>
        setData(
            'lines',
            data.lines.filter((line) => line.uid !== uid),
        );

    const goods = data.lines.reduce((sum, line) => {
        const value = parseFloat(salesLineTotal(line.quantity, effectiveUnit(line, prices[line.uid]?.unit_price)));
        return Number.isFinite(value) ? sum + value : sum;
    }, 0);
    const pricedLines = data.lines.filter((line) => line.product_id !== '').length;
    const charge = parseFloat(data.delivery_charge);
    const chargeValue = Number.isFinite(charge) && charge > 0 ? charge : 0;
    const total = goods + chargeValue;
    const unpriced = data.lines.some((line) => line.product_id && prices[line.uid]?.refusal && line.unit_price === '');

    // ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): the parcel weight from the lines,
    // live. The server works it out again on save; this is the same sum shown
    // early. ⚠ A product with no weight is counted as missing, never as zero.
    const weight = data.lines.reduce(
        (acc, line) => {
            const product = products.find((p) => String(p.id) === line.product_id);
            const quantity = parseFloat(line.quantity);
            if (!product || !Number.isFinite(quantity) || quantity <= 0) return acc;
            if (product.weight_grams === null || product.weight_grams === undefined) return { ...acc, missing: acc.missing + 1 };
            return { ...acc, grams: acc.grams + quantity * product.weight_grams };
        },
        { grams: 0, missing: 0 },
    );
    const weightGrams = Math.round(weight.grams);

    // ⚠ The dialog is a SIBLING of the form, not inside it: React bubbles a
    // portal's submit up the component tree, so adding a customer from inside
    // the form would have recorded the order too.
    return (
        <>
            {canAddCustomer && (
                <QuickCustomerDialog
                    open={quickOpen}
                    onOpenChange={setQuickOpen}
                    typed={typedCustomer}
                    customers={customers}
                    customerTypes={customerTypes}
                    priceGroups={priceGroups}
                    onCreated={pickCustomer}
                />
            )}
            <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col rounded-xl border">
                <div className="border-sidebar-border/70 dark:border-sidebar-border flex items-center justify-between gap-2 border-b px-4 py-3">
                    <h3 className="font-medium">
                        {editing && seed
                            ? t('corr.sales.order.editing', { number: seed.order_number })
                            : seed?.exchange_for
                              ? t('ips.sret.exchange_for', { number: seed.exchange_for.return_number })
                              : seed
                                ? t('corr.sales.order.copying', { number: seed.order_number })
                                : t('orders.new')}
                    </h3>
                    <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                        {t('orders.close')}
                    </Button>
                </div>

                <div className="flex flex-col gap-6 p-4">
                    {editing && (
                        <p className="w-fit rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300">
                            {partlyDelivered ? t('corr.sales.order.editing_undelivered') : t('corr.sales.order.editing_note')}
                        </p>
                    )}
                    <FormSection title={t('orders.section_order')}>
                        {/* ⚠ Joy, 17 Oct 2026: one line, each field as wide as it needs. A
                            wrapping row, not a 4-column grid: the customer grows to 28rem,
                            brand, warehouse and the dates keep their own width. */}
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid w-full gap-2 sm:w-40">
                                <Label htmlFor="order-date" required>
                                    {t('orders.order_date')}
                                </Label>
                                <Input
                                    id="order-date"
                                    type="date"
                                    max={today}
                                    value={data.ordered_at}
                                    onChange={(e) => setData('ordered_at', e.target.value)}
                                    aria-required
                                    disabled={partlyDelivered}
                                />
                                <InputError message={errors.ordered_at} />
                            </div>
                            <div className="grid w-full min-w-0 gap-2 sm:w-auto sm:max-w-md sm:flex-[1_1_18rem]">
                                <Label required>{t('orders.form_customer')}</Label>
                                <SearchableSelect
                                    value={data.customer_id}
                                    onChange={partlyDelivered ? () => undefined : pickCustomer}
                                    options={customers.map((c) => ({
                                        value: String(c.id),
                                        label: c.phone ? `${c.name} - ${c.phone}` : c.name,
                                        keywords: c.phone ?? '',
                                    }))}
                                    placeholder={t('orders.select_customer')}
                                    searchPlaceholder={t('orders.name_or_phone')}
                                    {...(canAddCustomer
                                        ? {
                                              onCreate: (typed: string) => {
                                                  setTypedCustomer(typed);
                                                  setQuickOpen(true);
                                              },
                                              createLabel: t('orders.new_customer'),
                                          }
                                        : {})}
                                />
                                <InputError message={errors.customer_id} />
                            </div>
                            {activeBrands.length > 0 && (
                                <div className="grid w-full gap-2 sm:w-44">
                                    <Label htmlFor="order-brand" required>
                                        {t('orders.brand')}
                                    </Label>
                                    <SearchSelect id="order-brand" value={data.brand_id} onChange={(e) => setData('brand_id', e.target.value)}>
                                        <option value="">{t('orders.choose_brand')}</option>
                                        {activeBrands.map((b) => (
                                            <option key={b.id} value={String(b.id)}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    <InputError message={errors.brand_id} />
                                </div>
                            )}
                            <div className="grid w-full gap-2 sm:w-52">
                                <Label>{t('orders.warehouse')}</Label>
                                <SearchableSelect
                                    value={data.warehouse_id}
                                    onChange={(v) => setData('warehouse_id', v)}
                                    options={warehouses.map((w) => ({ value: String(w.id), label: w.name }))}
                                    placeholder={t('orders.decided_at_delivery')}
                                    clearable
                                    searchPlaceholder={t('orders.search_warehouses')}
                                />
                                <InputError message={errors.warehouse_id} />
                            </div>
                            <div className="grid w-full gap-2 sm:w-40">
                                <Label htmlFor="order-expected">{t('orders.expected_date')}</Label>
                                <Input
                                    id="order-expected"
                                    type="date"
                                    min={data.ordered_at || undefined}
                                    value={data.expected_date}
                                    onChange={(e) => setData('expected_date', e.target.value)}
                                />
                                <InputError message={errors.expected_date} />
                            </div>
                        </div>
                        {backdated && (
                            <p className="w-fit rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300">
                                {t('orders.backdated', { date: showDate(data.ordered_at) })}
                            </p>
                        )}

                        {/* ⚠ Re-check F43, 17 Oct 2026: the exposure line used to appear
                            under the fields after the customer was picked, pushing the lines
                            down as the next click landed. Now one slot of fixed height holds
                            "pick a customer first", then the exposure in the same place. */}
                        <div className="min-h-[3.25rem]">
                            {data.customer_id ? (
                                <ExposureBox customerId={data.customer_id} />
                            ) : (
                                <p className="text-muted-foreground py-3 text-sm">{t('orders.pick_customer_first')}</p>
                            )}
                        </div>
                    </FormSection>

                    <FormSection title={t('orders.section_items')} hint={canOverridePrice ? t('orders.items_hint') : undefined}>
                        <div className="flex flex-col gap-3">
                            <div className={`text-muted-foreground hidden gap-2 text-xs sm:grid ${LINE_COLUMNS}`}>
                                <span>#</span>
                                <span>
                                    {t('orders.product')}
                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                        *
                                    </span>
                                </span>
                                <span className="text-right">
                                    {t('orders.quantity')}
                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                        *
                                    </span>
                                </span>
                                <span className="text-right">{t('orders.unit_price')}</span>
                                <span className="text-right">{t('orders.line_total')}</span>
                                <span className="w-16" />
                            </div>
                            {data.lines.map((line, index) => (
                                <OrderLineRow
                                    key={line.uid}
                                    line={line}
                                    index={index}
                                    customerId={data.customer_id}
                                    warehouseId={data.warehouse_id}
                                    orderDate={data.ordered_at}
                                    products={products}
                                    price={prices[line.uid]}
                                    canOverride={canOverridePrice}
                                    canRemove={data.lines.length > 1}
                                    errors={lineErrors}
                                    onChange={(patch) => updateLine(line.uid, patch)}
                                    onPriced={(price) => setPrices((current) => ({ ...current, [line.uid]: price }))}
                                    onRemove={() => removeLine(line.uid)}
                                    brandId={data.brand_id}
                                    brandNames={brandNames}
                                />
                            ))}
                        </div>
                        {data.lines
                            .map((line, index) => ({ line, index }))
                            .filter(({ line }) => (line.delivered ?? 0) > 0)
                            .map(({ line, index }) => (
                                <p key={line.uid} className="text-muted-foreground text-xs">
                                    #{index + 1}: {t('corr.sales.order.delivered_already', { quantity: formatQuantity(String(line.delivered)) })}
                                </p>
                            ))}
                        {/* As wide as the line columns, so the summary sits under the money. */}
                        <div className="flex flex-wrap items-start justify-between gap-4 sm:max-w-[64rem]">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                onClick={() => setData('lines', [...data.lines, newLine()])}
                            >
                                {t('orders.add_line')}
                            </Button>
                            <dl className="border-sidebar-border/70 dark:border-sidebar-border grid w-full grid-cols-[1fr_auto] gap-x-6 gap-y-1 rounded-lg border p-3 text-sm sm:w-80">
                                <dt className="text-muted-foreground">{t('orders.summary_lines', { count: pricedLines })}</dt>
                                <dd className="text-right tabular-nums">{money(goods)}</dd>
                                <dt className="text-muted-foreground">{t('orders.summary_delivery')}</dt>
                                <dd className="text-right tabular-nums">{money(chargeValue)}</dd>
                                <dt className="border-sidebar-border/70 dark:border-sidebar-border mt-1 border-t pt-1 font-medium">
                                    {t('orders.order_total')}
                                </dt>
                                <dd className="border-sidebar-border/70 dark:border-sidebar-border mt-1 border-t pt-1 text-right font-semibold tabular-nums">
                                    {money(total)}
                                </dd>
                            </dl>
                        </div>
                        <InputError message={errors.lines} />
                        <InputError message={(errors as Record<string, string | undefined>).exchange_for_sales_return_id} />
                    </FormSection>

                    {/* F35 + F36: what the customer pays for delivery, and where the
                        parcel goes. Needed to book a courier from the order. */}
                    <FormSection title={t('orders.section_delivery')} hint={t('orders.delivery_address_hint')}>
                        {/* 24 Oct 2026: a hand-over order needs no address. */}
                        <div className="grid gap-1">
                            <label className="flex w-fit items-center gap-2 text-sm">
                                <Checkbox
                                    id="order-hand-over"
                                    checked={data.hand_over}
                                    onCheckedChange={(checked) => setData('hand_over', checked === true)}
                                />
                                {t('orders.hand_over')}
                            </label>
                            {data.hand_over && <p className="text-muted-foreground text-xs">{t('orders.hand_over_hint')}</p>}
                        </div>
                        {savedAddressHint !== null && (
                            <p className="text-muted-foreground text-xs">{t('orders.saved_address_hint', { address: savedAddressHint })}</p>
                        )}
                        {/* ⚠ Joy, 17 Oct 2026: each field as wide as what goes in it.
                            Who and where on one wrapping row, the street and landmark
                            on the next, the charge and the weight on the last. */}
                        {!data.hand_over && (
                            <>
                                <div className="flex flex-wrap items-start gap-3">
                                    <div className="grid w-full gap-1 sm:w-56">
                                        <Label htmlFor="addr-name">{t('orders.recipient_name')}</Label>
                                        <Input id="addr-name" value={data.address.name} onChange={(e) => setAddress({ name: e.target.value })} />
                                        <InputError message={addressErrors['address.name']} />
                                    </div>
                                    <div className="grid w-full gap-1 sm:w-40">
                                        <Label htmlFor="addr-phone">{t('orders.recipient_phone')}</Label>
                                        <Input
                                            id="addr-phone"
                                            inputMode="tel"
                                            value={data.address.phone}
                                            onChange={(e) => setAddress({ phone: e.target.value })}
                                        />
                                        <InputError message={addressErrors['address.phone']} />
                                    </div>
                                    <BdPlaceFields
                                        idPrefix="order-place"
                                        itemClassName="grid w-full gap-1 sm:w-48"
                                        districts={districts}
                                        value={{ district: data.address.district, thana: data.address.thana, area: data.address.area }}
                                        onChange={(place) => setAddress(place)}
                                        errors={{
                                            district: addressErrors['address.district'],
                                            thana: addressErrors['address.thana'],
                                            area: addressErrors['address.area'],
                                        }}
                                    />
                                </div>
                                <div className="flex flex-wrap items-start gap-3">
                                    <div className="grid w-full min-w-0 gap-1 sm:w-auto sm:max-w-xl sm:flex-[1_1_20rem]">
                                        <Label htmlFor="addr-line">{t('orders.address_line')}</Label>
                                        <Input
                                            id="addr-line"
                                            value={data.address.address_line}
                                            onChange={(e) => setAddress({ address_line: e.target.value })}
                                        />
                                        <InputError message={addressErrors['address.address_line']} />
                                    </div>
                                    <div className="grid w-full gap-1 sm:w-60">
                                        <Label htmlFor="addr-landmark">{t('orders.landmark')}</Label>
                                        <Input
                                            id="addr-landmark"
                                            value={data.address.landmark}
                                            onChange={(e) => setAddress({ landmark: e.target.value })}
                                        />
                                    </div>
                                </div>
                            </>
                        )}
                        <div className="flex flex-wrap items-start gap-x-8 gap-y-3">
                            <div className="grid gap-1">
                                <Label htmlFor="order-delivery-charge">{t('orders.delivery_charge')}</Label>
                                <Input
                                    id="order-delivery-charge"
                                    type="number"
                                    inputMode="decimal"
                                    min={0}
                                    step="0.01"
                                    className="w-36 text-right"
                                    value={data.delivery_charge}
                                    onChange={(e) => setData('delivery_charge', e.target.value)}
                                    placeholder="0"
                                />
                                <p className="text-muted-foreground max-w-xs text-xs">{t('orders.delivery_charge_hint')}</p>
                                <InputError message={errors.delivery_charge} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="order-weight">{t('orders.parcel_weight')}</Label>
                                <div className="flex flex-wrap items-center gap-3">
                                    <Input
                                        id="order-weight"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        step={1}
                                        className="w-36 text-right"
                                        value={data.weight_grams}
                                        onChange={(e) => setData('weight_grams', e.target.value)}
                                        placeholder={weight.missing === 0 && weightGrams > 0 ? String(weightGrams) : ''}
                                    />
                                    <span className="text-muted-foreground text-sm tabular-nums">
                                        {weight.missing === 0
                                            ? t('orders.weight_from_lines', { grams: weightGrams.toLocaleString('en-IN') })
                                            : t('orders.weight_at_least', { grams: weightGrams.toLocaleString('en-IN') })}
                                    </span>
                                    {weight.missing > 0 && (
                                        <Badge variant="outline" className="text-amber-700 dark:text-amber-400">
                                            {t('orders.weight_missing_items', { count: weight.missing })}
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-muted-foreground text-xs">{t('orders.weight_hint')}</p>
                                <InputError message={errors.weight_grams} />
                            </div>
                        </div>
                    </FormSection>

                    <FormSection title={t('orders.section_notes')}>
                        <div className="grid gap-3 lg:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="order-instructions">{t('orders.special_instructions')}</Label>
                                <Input
                                    id="order-instructions"
                                    maxLength={500}
                                    placeholder={t('orders.special_instructions_placeholder')}
                                    value={data.special_instructions}
                                    onChange={(e) => setData('special_instructions', e.target.value)}
                                />
                                <InputError message={errors.special_instructions} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="order-note">{t('orders.note_optional')}</Label>
                                <Input id="order-note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                            </div>
                            <AgentPicker
                                id="order-agent"
                                value={data.agent}
                                options={commissionAgents}
                                error={errors.agent}
                                onChange={(value) => setData('agent', value)}
                            />
                        </div>
                    </FormSection>
                </div>

                {/* The total and the button stay in sight while the form is scrolled. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card/95 sticky bottom-0 flex flex-wrap items-center justify-between gap-3 rounded-b-xl border-t px-4 py-3 backdrop-blur">
                    <p className="text-sm">
                        <span className="text-muted-foreground">{t('orders.order_total')}</span>{' '}
                        <span className="font-semibold tabular-nums">{money(total)}</span>
                        <span className="text-muted-foreground">
                            {' '}
                            · {t('orders.summary_dated')} {showDate(data.ordered_at || today)}
                        </span>
                    </p>
                    <Button disabled={processing || unpriced} className="w-fit">
                        {editing ? t('corr.sales.order.save') : t('orders.record')}
                    </Button>
                </div>
            </form>
        </>
    );
}

export default function SalesOrdersIndex({
    orders,
    committed,
    customers,
    warehouses,
    products,
    can_create,
    can_override_price,
    can_add_customer,
    customer_types,
    price_groups,
    own_only = false,
    preselect_customer = null,
    brands = [],
    last_brand_id = null,
    last_warehouse_id = null,
    districts = [],
    edit_order = null,
    copy_order = null,
    exchange_order = null,
    commission_agents = [],
    queues,
    to_book = null,
    filters: initialFilters,
}: {
    orders: Paginated<OrderRow>;
    committed: CommittedRow[];
    customers: Option[];
    warehouses: Option[];
    products: Option[];
    can_create: boolean;
    can_override_price: boolean;
    can_add_customer: boolean;
    customer_types: CustomerType[];
    price_groups: PriceGroupOption[];
    own_only?: boolean;
    preselect_customer?: number | null;
    brands?: BrandOption[];
    last_brand_id?: number | null;
    last_warehouse_id?: number | null;
    districts?: string[];
    /** Corrections wave 1: the order the form opens on (?edit= / ?copy=). */
    edit_order?: OrderSeed | null;
    copy_order?: OrderSeed | null;
    /** ⭐ B20: the replacement order of an exchange (?exchange=<return id>). */
    exchange_order?: OrderSeed | null;
    /** ⭐ Commission agreements (25 Oct 2026): who may be credited with the order. */
    commission_agents?: CommissionAgentOption[];
    /** Each queue's count within the search and the brand, "all" included. */
    queues: Record<'all' | (typeof QUEUES)[number], number>;
    /** Approved orders no courier has yet, for whoever may book; null for everybody else. */
    to_book?: number | null;
    filters: { search: string; status: string; brand: string; queue: string };
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/orders', initialFilters);
    // ⭐ The form is at the top, one click away (21 Sep 2026): it used to sit
    // under the whole order list and the stock table.
    const [creating, setCreating] = useState(
        () =>
            edit_order !== null ||
            copy_order !== null ||
            exchange_order !== null ||
            (typeof window !== 'undefined' && (new URLSearchParams(window.location.search).has('new') || preselect_customer !== null)),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('orders.title')} />
            <div className="flex flex-col gap-4 p-4">
                {can_create && !creating && (
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {/* Shop orders from a sheet (11 Oct 2026), the same trust as one order. */}
                        <Button variant="outline" size="sm" className="w-fit" asChild>
                            <Link href={route('sales.orders.import')}>Import shop orders</Link>
                        </Button>
                        <Button size="sm" className="w-fit" onClick={() => setCreating(true)}>
                            {t('orders.new')}
                        </Button>
                    </div>
                )}
                {can_create && creating && (
                    <CreateOrderForm
                        seed={edit_order ?? copy_order ?? exchange_order}
                        initialCustomerId={preselect_customer}
                        customers={customers}
                        warehouses={warehouses}
                        products={products}
                        canOverridePrice={can_override_price}
                        canAddCustomer={can_add_customer}
                        customerTypes={customer_types}
                        priceGroups={price_groups}
                        brands={brands}
                        lastBrandId={last_brand_id}
                        lastWarehouseId={last_warehouse_id}
                        districts={districts}
                        commissionAgents={commission_agents}
                        onClose={() => setCreating(false)}
                    />
                )}

                {own_only && <p className="text-muted-foreground text-sm">{t('orders.own_only')}</p>}

                {/* ⭐ The queues as chips with their counts (15 Oct 2026): where an order
                    stands between being taken and being paid for. Server-side filters,
                    kept in the address so a link can be shared; the brand beside them. */}
                <div className="flex flex-wrap items-center gap-2">
                    <Chip active={filters.queue === ''} count={queues.all} onClick={() => setFilter('queue', '')}>
                        {t('orders.queue_all')}
                    </Chip>
                    {QUEUES.map((queue) => (
                        <Chip
                            key={queue}
                            active={filters.queue === queue}
                            count={queues[queue]}
                            tone={
                                queue === 'problem' && queues.problem > 0
                                    ? 'danger'
                                    : queue === 'approval' && queues.approval > 0
                                      ? 'warning'
                                      : undefined
                            }
                            onClick={() => setFilter('queue', queue)}
                        >
                            {t(`orders.queue_${queue}`)}
                        </Chip>
                    ))}
                    {brands.length > 0 && (
                        <>
                            <span className="bg-border mx-1 hidden h-5 w-px sm:block" aria-hidden="true" />
                            <Chip active={(filters.brand ?? '') === ''} onClick={() => setFilter('brand', '')}>
                                {t('orders.all_brands')}
                            </Chip>
                            {brands.map((b) => (
                                <Chip key={b.id} active={filters.brand === String(b.id)} onClick={() => setFilter('brand', String(b.id))}>
                                    {b.name}
                                </Chip>
                            ))}
                            <Chip active={filters.brand === 'none'} onClick={() => setFilter('brand', 'none')}>
                                {t('orders.no_brand')}
                            </Chip>
                        </>
                    )}
                </div>

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label={t('orders.search')} htmlFor="filter-search" className="w-80">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder={t('orders.search_placeholder')}
                        />
                    </FilterField>
                    <FilterField label={t('orders.status')} htmlFor="filter-status">
                        <SearchSelect id="filter-status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                            <option value="">{t('orders.all_statuses')}</option>
                            <option value="open">{t('orders.status_open')}</option>
                            <option value="partially_delivered">{t('orders.status_partially_delivered')}</option>
                            <option value="delivered">{t('orders.status_delivered')}</option>
                            <option value="closed">{t('orders.status_closed')}</option>
                            <option value="cancelled">{t('orders.status_cancelled')}</option>
                        </SearchSelect>
                    </FilterField>
                    <p className="text-muted-foreground self-center text-xs tabular-nums">
                        {t('orders.shown', { shown: orders.data.length, total: orders.total })}
                    </p>
                </FilterBar>

                {/* ⭐ The bulk-booking nudge (15 Oct 2026): approved orders no courier has
                    yet, for whoever may book them in one run with their labels. */}
                {to_book !== null && to_book > 0 && (
                    <div className="border-primary/30 bg-primary/5 flex flex-wrap items-center gap-3 rounded-xl border px-4 py-2.5">
                        <span className="bg-primary/10 text-primary grid size-7 place-items-center rounded-md text-[13px] font-semibold tabular-nums">
                            {to_book}
                        </span>
                        <span className="flex-1 text-sm">{t('orders.to_book_nudge', { count: to_book })}</span>
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/sales/parcels-to-book">
                                <PackageCheck className="size-3.5" />
                                {t('orders.to_book_link')}
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[60rem] text-sm">
                        <thead>
                            <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                                <th className="py-2">{t('orders.col_number')}</th>
                                <th className="py-2">{t('orders.col_customer')}</th>
                                <th className="py-2">{t('orders.col_brand')}</th>
                                <th className="py-2">{t('orders.col_ordered')}</th>
                                <th className="py-2">{t('orders.col_delivered')}</th>
                                <th className="py-2">{t('orders.col_expected')}</th>
                                <th className="py-2">{t('orders.col_approval')}</th>
                                <th className="py-2" title={t('orders.courier_says_hint')}>
                                    {t('orders.col_courier_says')}
                                </th>
                                <th className="py-2">{t('orders.col_status')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.map((order) => (
                                <tr key={order.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        <Link href={route('sales.orders.show', order.id)} className="underline">
                                            {order.order_number}
                                        </Link>
                                        {/* ⚠ Marked, because an order nobody in this
                                        company typed is the one that needs a
                                        confirmation call - and the reference
                                        shown is the one the CUSTOMER quotes. */}
                                        {order.channel === 'website' && (
                                            <p className="text-muted-foreground text-xs">
                                                {t('orders.website')} {order.shop_reference ?? ''}
                                            </p>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">{order.customer.name}</td>
                                    <td className="py-2">
                                        <BrandBadge name={order.brand?.name} />
                                    </td>
                                    {/* Re-check F42, 17 Oct 2026: "2.0000" became "2". */}
                                    <td className="py-2 tabular-nums">{formatQuantity(order.total_ordered)}</td>
                                    <td className="py-2 tabular-nums">{formatQuantity(order.total_delivered)}</td>
                                    <td className="text-muted-foreground py-2">{formatDate(order.expected_date)}</td>
                                    <td className="py-2">
                                        {order.approval_request ? (
                                            <Badge variant={approvalVariant[order.approval_request.status]} className="capitalize">
                                                {order.approval_request.status}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">{t('orders.awaiting_payment')}</Badge>
                                        )}
                                    </td>
                                    <td className="py-2">
                                        <CourierSaysCell word={order.courier_says} />
                                    </td>
                                    <td className="py-2">
                                        <Badge variant={statusVariant[order.status]} className="capitalize">
                                            {order.status.replace('_', ' ')}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <tr>
                                    <td colSpan={9} className="text-muted-foreground py-4 text-center">
                                        {hasActiveFilters ? t('orders.none_match') : t('orders.none_yet')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={orders} />

                {committed.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <h3 className="font-medium">{t('orders.committed_heading')}</h3>
                        {/* ⚠ C-SO-2: no reservation. This is the honest substitute -
                            the promise per product against the shelf, for a human
                            deciding whether to take the next order. Nothing blocks. */}
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">{t('orders.col_product')}</th>
                                        <th className="p-3 text-right">{t('orders.col_committed')}</th>
                                        <th className="p-3 text-right">{t('orders.col_on_hand')}</th>
                                        <th className="p-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {committed.map((row) => (
                                        <tr key={row.product_id} className="border-t">
                                            <td className="p-3">
                                                {row.product_name} <span className="text-muted-foreground">({row.product_sku})</span>
                                            </td>
                                            <td className="p-3 text-right tabular-nums">{row.committed}</td>
                                            <td className="p-3 text-right tabular-nums">{formatQuantity(row.on_hand)}</td>
                                            <td className="p-3">{row.short && <Badge variant="destructive">{t('orders.short')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
