import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { BdPlaceFields } from '@/components/form/bd-place-fields';
import InputError from '@/components/input-error';
import { AgentPicker, type CommissionAgentOption } from '@/components/sales/agent-picker';
import { BrandBadge, type BrandOption } from '@/components/sales/brand';
import { CreditOverridePanel, NO_OVERRIDE, type CreditAuthoriser, type CreditOverrideFields } from '@/components/sales/credit-override';
import { LineDiscount } from '@/components/sales/line-discount';
import { OrderStageBadge } from '@/components/sales/order-channel-panel';
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
    /** ⭐ B17: products only, available to sell per warehouse id ('' = where an order with none holds). */
    available?: Record<string, string>;
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
    channel: string | null;
    shop_reference: string | null;
    /** ⭐ B18: the other shop's own order number, the stage, the channel's name. */
    external_reference?: string | null;
    stage?: string;
    channel_label?: string | null;
    confirmation_status?: string | null;
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
    /** IP21: the customer's open advance (2150), credit they already have. */
    advance_with_us?: string;
    /** ⭐ 25 Oct 2026 (Q11 = A): no limit typed, and a trade customer: cash only. */
    cash_only?: boolean;
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
// ⭐ B18 (25 Oct 2026): 'confirm' first, the sales staff's call list.
const QUEUES = ['confirm', 'approval', 'dispatch', 'moving', 'problem', 'delivered'] as const;

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
    /** ⭐ 25 Oct 2026 (Q12 = A): the line discount in taka, typed as a percent. */
    discount: string;
    /** Corrections wave 1: the order line being edited (null on a new line). */
    id?: number | null;
    /** What already left on this line (Edit undelivered part). */
    delivered?: number;
    /** ⭐ B18: a free gift, at zero price. */
    is_gift: boolean;
};

/** ⭐ B18 (25 Oct 2026): what the form offers for channel, delivery and payment. */
export type OrderOptions = {
    channels: { value: string; label: string }[];
    sites: string[];
    delivery_methods: { value: string; label: string }[];
    payments: { value: string; label: string }[];
    paid_accounts: { id: number; name: string }[];
    /** Joy's answer 9 (25 Oct 2026): SSL Commerz Clearing, picked when "Paid online (SSL)" is chosen. */
    ssl_clearing_id?: number | null;
    /** Cash boxes and Employee cash accounts; holder_employee_id names an Employee cash account's holder. */
    cash_accounts: { id: number; name: string; holder_employee_id?: number | null }[];
    riders: { id: number; name: string }[];
    may_backdate: boolean;
    may_change_courier_charge: boolean;
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
    // ⭐ B18
    channel?: string | null;
    channel_site?: string | null;
    external_reference?: string | null;
    delivery_method?: string | null;
    rider_employee_id?: number | null;
    cod_money_account_id?: number | null;
    payment?: string | null;
    order_discount?: string;
    address: AddressDraft;
    lines: {
        id: number | null;
        product_id: number;
        quantity: string;
        unit_price: string;
        discount?: string;
        description: string;
        delivered: number;
        is_gift?: boolean;
    }[];
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
// ⭐ 25 Oct 2026 (Q12 = A): a Discount column (% and Tk) after the total.
const LINE_COLUMNS = 'sm:grid-cols-[1.5rem_minmax(14rem,30rem)_7rem_9rem_9rem_max-content_max-content]';
// The rows under a line (note, price explanation, warnings) start under the product.
const UNDER_LINE = 'col-span-2 sm:col-span-6 sm:col-start-2';

const newLine = (): LineDraft => ({
    uid: nextUid++,
    product_id: '',
    quantity: '',
    unit_price: '',
    line_total: '',
    entry_mode: 'rate',
    description: '',
    discount: '',
    is_gift: false,
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

    // IP21: the advance they hold with us is counted, as the server's credit check does.
    const advance = parseFloat(exposure.advance_with_us ?? '0');
    const net = Math.max(0, parseFloat(exposure.total) - advance);
    const overLimit = exposure.credit_limit !== null && net > parseFloat(exposure.credit_limit);

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
            {advance > 0 && (
                <p className="mt-1 text-emerald-700 dark:text-emerald-400">{t('ssf.credit.advance', { amount: formatMoney(advance) })}</p>
            )}
            {overLimit && <p className="text-destructive mt-1 font-medium">{t('orders.over_limit')}</p>}
            {exposure.cash_only && net > 0 && <p className="mt-1 font-medium text-amber-700 dark:text-amber-400">{t('salesctl.credit.cash_only')}</p>}
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
    discountCap,
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
    /** The person's Discount % cap (null = the Owner, no cap). */
    discountCap: number | null;
}) {
    const { t } = useTranslation();
    useEffect(() => {
        // ⭐ B18: a gift is at zero; nothing to look up.
        if (!customerId || !line.product_id || line.is_gift) {
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
    }, [customerId, warehouseId, orderDate, line.product_id, line.quantity, line.is_gift]);

    const product = products.find((p) => String(p.id) === line.product_id);
    const otherBrand = lineBrandMismatch(product, brandId);
    const listed = !line.is_gift && price?.unit_price ? money2(price.unit_price) : '';
    const unitPrice = line.is_gift ? '0.00' : effectiveUnit(line, price?.unit_price);
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
                {product?.available !== undefined && (
                    <AvailableHint available={product.available[warehouseId] ?? product.available[''] ?? '0'} wanted={line.quantity} />
                )}
                <InputError message={errors[`lines.${index}.quantity`]} />
            </div>
            <div className="grid gap-1">
                {canOverride && !line.is_gift ? (
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
                {canOverride && !line.is_gift ? (
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
            {line.is_gift ? (
                <span className="text-muted-foreground flex h-9 items-center text-xs">{t('och.gift')}</span>
            ) : (
                <LineDiscount
                    gross={(parseFloat(line.quantity) || 0) * (parseFloat(listed || unitPrice) || 0)}
                    value={line.discount}
                    onChange={(taka) => onChange({ discount: taka })}
                    capPercent={discountCap}
                    error={errors[`lines.${index}.discount`]}
                />
            )}
            <Button type="button" variant="ghost" size="sm" className="text-muted-foreground" onClick={onRemove} disabled={!canRemove}>
                {t('orders.remove')}
            </Button>
            <div className={`flex flex-wrap items-center gap-3 ${UNDER_LINE}`}>
                {/* ⭐ B18: a free gift, at zero; its cost goes to Customer Gift. */}
                <label className="flex w-fit items-center gap-2 text-xs" title={t('och.gift_hint')}>
                    <Checkbox
                        checked={line.is_gift}
                        onCheckedChange={(checked) =>
                            onChange({ is_gift: checked === true, unit_price: '', line_total: '', entry_mode: 'rate', discount: '' })
                        }
                        disabled={(line.delivered ?? 0) > 0}
                    />
                    {t('och.gift')}
                </label>
                {line.is_gift && <span className="text-muted-foreground text-xs">{t('och.gift_hint')}</span>}
            </div>
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
            {line.product_id !== '' && !line.is_gift && (
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
    discountCap = null,
    creditAuthorisers = [],
    options,
}: {
    /** ⭐ B18: channel, delivery and payment choices. */
    options: OrderOptions;
    seed?: OrderSeed | null;
    commissionAgents?: CommissionAgentOption[];
    discountCap?: number | null;
    creditAuthorisers?: CreditAuthoriser[];
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
    const { data, setData, post, put, errors, processing, transform } = useForm<
        CreditOverrideFields & {
            floor_reason: string;
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
            // ⭐ B18
            channel: string;
            channel_site: string;
            external_reference: string;
            allow_duplicate_reference: boolean;
            delivery_method: string;
            rider_employee_id: string;
            cod_money_account_id: string;
            payment: string;
            paid_money_account_id: string;
            paid_reference: string;
            order_discount: string;
        }
    >({
        ...NO_OVERRIDE,
        // ⭐ 25 Oct 2026 (Q16 = A): why a market product goes below its minimum price.
        floor_reason: '',
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
        hand_over: seed?.delivery_method === 'hand',
        weight_grams: seed?.weight_grams ? String(seed.weight_grams) : '',
        agent: seed?.agent ?? '',
        // ⭐ B18 (25 Oct 2026): a phone order by cash on delivery unless changed.
        channel: seed?.channel && seed.channel !== 'internal' ? seed.channel : seed ? '' : 'phone',
        channel_site: seed?.channel_site ?? '',
        external_reference: seed?.external_reference ?? '',
        allow_duplicate_reference: false,
        delivery_method: seed?.delivery_method ?? '',
        rider_employee_id: seed?.rider_employee_id ? String(seed.rider_employee_id) : '',
        cod_money_account_id: seed?.cod_money_account_id ? String(seed.cod_money_account_id) : '',
        payment: seed?.payment ?? 'cod',
        paid_money_account_id: '',
        paid_reference: '',
        order_discount: seed?.order_discount ?? '',
        lines: seed
            ? seed.lines.map((line) => ({
                  ...newLine(),
                  id: editing ? line.id : null,
                  product_id: String(line.product_id),
                  quantity: line.quantity,
                  unit_price: line.unit_price,
                  discount: line.discount && parseFloat(line.discount) > 0 ? line.discount : '',
                  description: line.description,
                  delivered: line.delivered,
                  is_gift: line.is_gift ?? false,
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
            ...(canOverridePrice && !line.is_gift && line.unit_price !== '' && !samePrice(line.unit_price, prices[line.uid]?.unit_price)
                ? { unit_price: line.unit_price }
                : {}),
            // ⭐ 25 Oct 2026 (Q12 = A): always sent, so clearing it on an edit clears it.
            discount: line.is_gift || line.discount.trim() === '' ? '0' : line.discount.trim(),
            ...(line.is_gift ? { is_gift: true } : {}),
            ...(line.description.trim() !== '' ? { description: line.description.trim() } : {}),
        })),
        // An address only once a district, thana or street was given: the
        // name and phone are filled in from the customer by themselves.
        // 24 Oct 2026: a hand-over order sends no address at all.
        address: !form.hand_over && (form.address.district || form.address.thana || form.address.address_line.trim()) ? form.address : null,
        hand_over: form.hand_over,
        ...(seed?.exchange_for ? { exchange_for_sales_return_id: seed.exchange_for.id } : {}),
        // ⭐ B18: only what the chosen method and payment use.
        channel_site: form.channel === 'website' ? form.channel_site : '',
        rider_employee_id: form.delivery_method === 'own_staff' ? form.rider_employee_id : '',
        cod_money_account_id: form.delivery_method === 'own_staff' ? form.cod_money_account_id : '',
        paid_money_account_id: form.payment === 'ssl_paid' ? form.paid_money_account_id : '',
        paid_reference: form.payment === 'ssl_paid' ? form.paid_reference : '',
        order_discount: form.order_discount.trim() === '' ? '0' : form.order_discount.trim(),
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

    // ⭐ 25 Oct 2026 (Q11 = A): the last credit refusal stays on screen with its
    // Override box while a Manager or the Owner types their password; the
    // password is cleared after every try, never kept in the form.
    const [creditMessage, setCreditMessage] = useState<string | null>(null);
    const creditError = (errors as Record<string, string | undefined>).credit;
    useEffect(() => {
        if (creditError) setCreditMessage(creditError);
    }, [creditError]);
    const clearPassword = { onFinish: () => setData('override_password', '') };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing && seed?.id) {
            put(route('sales.orders.update', seed.id), clearPassword);

            return;
        }

        post(route('sales.orders.store'), clearPassword);
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
    const discounts = data.lines.reduce((sum, line) => {
        const value = parseFloat(line.discount);
        return Number.isFinite(value) && value > 0 ? sum + value : sum;
    }, 0);
    const pricedLines = data.lines.filter((line) => line.product_id !== '').length;
    const charge = parseFloat(data.delivery_charge);
    const chargeValue = data.delivery_method === 'hand' ? 0 : Number.isFinite(charge) && charge > 0 ? charge : 0;
    const orderDiscount = Math.max(0, parseFloat(data.order_discount) || 0);
    const total = goods - discounts - orderDiscount + chargeValue;

    // ⭐ B18: the courier's zone charge for the district, and a repeated shop
    // order number, asked of the server as they are typed.
    const [check, setCheck] = useState<{ courier_charge: string | null; courier_zone: string | null; duplicate: string | null }>({
        courier_charge: null,
        courier_zone: null,
        duplicate: null,
    });
    useEffect(() => {
        const timer = window.setTimeout(async () => {
            const query = new URLSearchParams();
            if (data.address.district) query.set('district', data.address.district);
            if (data.brand_id) query.set('brand_id', data.brand_id);
            if (data.external_reference.trim()) query.set('reference', data.external_reference.trim());
            if (editing && seed?.id) query.set('except', String(seed.id));
            try {
                const response = await fetch(`${route('sales.orders.channel-check')}?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (response.ok) setCheck(await response.json());
            } catch {
                // The server checks again on save.
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [data.address.district, data.brand_id, data.external_reference, editing, seed?.id]);
    const courierCharge = check.courier_charge;
    // A courier order takes the zone charge; a Manager or the Owner may type over it.
    useEffect(() => {
        if (data.delivery_method === 'courier' && courierCharge !== null && !(options.may_change_courier_charge && data.delivery_charge !== '')) {
            setData('delivery_charge', parseFloat(courierCharge).toFixed(2));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.delivery_method, courierCharge]);
    const pickMethod = (method: string) =>
        setData((current) => ({
            ...current,
            delivery_method: method,
            hand_over: method === 'hand',
            delivery_charge:
                method === 'hand'
                    ? ''
                    : method === 'courier' && courierCharge !== null
                      ? parseFloat(courierCharge).toFixed(2)
                      : current.delivery_charge,
        }));
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
                                    min={options.may_backdate || editing ? undefined : today}
                                    value={data.ordered_at}
                                    onChange={(e) => setData('ordered_at', e.target.value)}
                                    aria-required
                                    disabled={partlyDelivered || (!options.may_backdate && !editing)}
                                />
                                {!options.may_backdate && !editing && <p className="text-muted-foreground text-xs">{t('och.staff_today')}</p>}
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
                        {/* ⭐ B18 (25 Oct 2026): how the order came, and the other shop's own number. */}
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid w-full gap-2 sm:w-52">
                                <Label htmlFor="order-channel">{t('och.channel')}</Label>
                                <SearchSelect id="order-channel" value={data.channel} onChange={(e) => setData('channel', e.target.value)}>
                                    {data.channel === '' && <option value="">{t('och.choose_channel')}</option>}
                                    {options.channels.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {t(`och.channel.${c.value}` as 'och.channel.phone')}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.channel} />
                            </div>
                            {data.channel === 'website' && (
                                <div className="grid w-full gap-2 sm:w-48">
                                    <Label htmlFor="order-site" required>
                                        {t('och.site')}
                                    </Label>
                                    <SearchSelect
                                        id="order-site"
                                        value={options.sites.includes(data.channel_site) || data.channel_site === '' ? data.channel_site : '__other'}
                                        onChange={(e) => setData('channel_site', e.target.value === '__other' ? ' ' : e.target.value)}
                                    >
                                        <option value="">{t('och.choose_channel')}</option>
                                        {options.sites.map((site) => (
                                            <option key={site} value={site}>
                                                {site}
                                            </option>
                                        ))}
                                        <option value="__other">{t('och.site_other')}</option>
                                    </SearchSelect>
                                    {!options.sites.includes(data.channel_site) && data.channel_site !== '' && (
                                        <Input
                                            aria-label={t('och.site_name')}
                                            placeholder={t('och.site_name')}
                                            maxLength={60}
                                            value={data.channel_site.trim()}
                                            onChange={(e) => setData('channel_site', e.target.value === '' ? ' ' : e.target.value)}
                                        />
                                    )}
                                    <InputError message={errors.channel_site} />
                                </div>
                            )}
                            <div className="grid w-full gap-2 sm:w-44">
                                <Label htmlFor="order-reference">{t('och.reference')}</Label>
                                <Input
                                    id="order-reference"
                                    maxLength={60}
                                    value={data.external_reference}
                                    onChange={(e) => setData('external_reference', e.target.value)}
                                />
                                <InputError message={errors.external_reference} />
                            </div>
                        </div>
                        {(check.duplicate !== null || errors.external_reference) && data.external_reference.trim() !== '' && (
                            <div className="flex w-fit flex-col gap-1 rounded-md border border-amber-300/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300">
                                {check.duplicate !== null && <span>{check.duplicate}</span>}
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={data.allow_duplicate_reference}
                                        onCheckedChange={(checked) => setData('allow_duplicate_reference', checked === true)}
                                    />
                                    {t('och.save_anyway')}
                                </label>
                            </div>
                        )}
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
                                <span>{t('salesctl.disc.heading')}</span>
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
                                    discountCap={discountCap}
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
                                {discounts > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">{t('salesctl.disc.summary')}</dt>
                                        <dd className="text-right tabular-nums">-{money(discounts)}</dd>
                                    </>
                                )}
                                {orderDiscount > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">{t('och.summary_order_discount')}</dt>
                                        <dd className="text-right tabular-nums">-{money(orderDiscount)}</dd>
                                    </>
                                )}
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
                        {/* ⭐ B18: a discount on the whole order, on top of the lines, within the cap. */}
                        <div className="grid w-fit gap-1">
                            <Label htmlFor="order-discount">{t('och.order_discount')}</Label>
                            <Input
                                id="order-discount"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="0.01"
                                className="w-36 text-right"
                                value={data.order_discount}
                                onChange={(e) => setData('order_discount', e.target.value)}
                                placeholder="0"
                            />
                            <p className="text-muted-foreground text-xs">{t('och.order_discount_hint')}</p>
                            <InputError message={(errors as Record<string, string | undefined>).order_discount} />
                        </div>
                    </FormSection>

                    {/* F35 + F36: what the customer pays for delivery, and where the
                        parcel goes. Needed to book a courier from the order. */}
                    <FormSection title={t('orders.section_delivery')} hint={t('orders.delivery_address_hint')}>
                        {/* ⭐ B18 (25 Oct 2026): how it travels. A hand delivery needs no
                            address (24 Oct 2026); a courier takes the zone charge. */}
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid w-full gap-1 sm:w-64">
                                <Label htmlFor="order-method">{t('och.delivery_method')}</Label>
                                <SearchSelect id="order-method" value={data.delivery_method} onChange={(e) => pickMethod(e.target.value)}>
                                    <option value="">{t('och.choose_channel')}</option>
                                    {options.delivery_methods.map((m) => (
                                        <option key={m.value} value={m.value}>
                                            {t(`och.method.${m.value}` as 'och.method.courier')}
                                        </option>
                                    ))}
                                </SearchSelect>
                                {data.delivery_method === 'hand' && <p className="text-muted-foreground text-xs">{t('och.hand_no_charge')}</p>}
                                <InputError message={(errors as Record<string, string | undefined>).delivery_method} />
                            </div>
                            {data.delivery_method === 'own_staff' && (
                                <>
                                    <div className="grid w-full gap-1 sm:w-56">
                                        <Label htmlFor="order-rider">{t('och.rider')}</Label>
                                        <SearchSelect
                                            id="order-rider"
                                            value={data.rider_employee_id}
                                            onChange={(e) => {
                                                // ⭐ IP1 meets IP16: the person's own Employee cash account
                                                // is where what he collects goes, unless another is chosen.
                                                const riderId = e.target.value;
                                                const own = options.cash_accounts.find(
                                                    (a) => a.holder_employee_id != null && String(a.holder_employee_id) === riderId,
                                                );
                                                setData((current) => ({
                                                    ...current,
                                                    rider_employee_id: riderId,
                                                    cod_money_account_id: own ? String(own.id) : current.cod_money_account_id,
                                                }));
                                            }}
                                        >
                                            <option value="">{t('och.choose_rider')}</option>
                                            {options.riders.map((r) => (
                                                <option key={r.id} value={String(r.id)}>
                                                    {r.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </div>
                                    <div className="grid w-full gap-1 sm:w-56">
                                        <Label htmlFor="order-cod-account">{t('och.cod_account')}</Label>
                                        <SearchSelect
                                            id="order-cod-account"
                                            value={data.cod_money_account_id}
                                            onChange={(e) => setData('cod_money_account_id', e.target.value)}
                                        >
                                            <option value="">{t('och.choose_channel')}</option>
                                            {options.cash_accounts.map((a) => (
                                                <option key={a.id} value={String(a.id)}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        <p className="text-muted-foreground max-w-xs text-xs">{t('och.cod_account_hint')}</p>
                                        <InputError message={(errors as Record<string, string | undefined>).cod_money_account_id} />
                                    </div>
                                </>
                            )}
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
                            {data.delivery_method !== 'hand' && (
                                <div className="grid gap-1">
                                    <Label htmlFor="order-delivery-charge">
                                        {data.delivery_method === 'courier'
                                            ? t('och.courier_charge')
                                            : data.delivery_method === ''
                                              ? t('orders.delivery_charge')
                                              : t('och.typed_charge')}
                                    </Label>
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
                                        readOnly={data.delivery_method === 'courier' && !options.may_change_courier_charge}
                                    />
                                    <p className="text-muted-foreground max-w-xs text-xs">
                                        {data.delivery_method === 'courier'
                                            ? check.courier_zone === null
                                                ? t('och.courier_pick_district')
                                                : options.may_change_courier_charge
                                                  ? t('och.courier_zone_owner', { zone: check.courier_zone, charge: courierCharge ?? '' })
                                                  : t('och.courier_zone', { zone: check.courier_zone })
                                            : t('orders.delivery_charge_hint')}
                                    </p>
                                    <InputError message={errors.delivery_charge} />
                                </div>
                            )}
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

                    {/* ⭐ B18 (25 Oct 2026): mostly cash on delivery, some paid by SSL, some paid before. */}
                    <FormSection title={t('och.payment')}>
                        <div className="flex flex-wrap items-start gap-3">
                            <div className="grid w-full gap-1 sm:w-56">
                                <Label htmlFor="order-payment">{t('och.payment')}</Label>
                                <SearchSelect
                                    id="order-payment"
                                    value={data.payment}
                                    onChange={(e) => {
                                        setData('payment', e.target.value);
                                        // Answer 9: the SSL money waits in SSL Commerz Clearing until SSL pays out.
                                        if (e.target.value === 'ssl_paid' && data.paid_money_account_id === '' && options.ssl_clearing_id) {
                                            setData('paid_money_account_id', String(options.ssl_clearing_id));
                                        }
                                    }}
                                >
                                    {options.payments.map((p) => (
                                        <option key={p.value} value={p.value}>
                                            {t(`och.pay.${p.value}` as 'och.pay.cod')}
                                        </option>
                                    ))}
                                </SearchSelect>
                                {data.payment === 'advance' && <p className="text-muted-foreground max-w-xs text-xs">{t('och.advance_hint')}</p>}
                            </div>
                            {data.payment === 'ssl_paid' && !editing && options.paid_accounts.length > 0 && (
                                <>
                                    <div className="grid w-full gap-1 sm:w-56">
                                        <Label htmlFor="order-paid-into">{t('och.paid_into')}</Label>
                                        <SearchSelect
                                            id="order-paid-into"
                                            value={data.paid_money_account_id}
                                            onChange={(e) => setData('paid_money_account_id', e.target.value)}
                                        >
                                            <option value="">{t('och.choose_channel')}</option>
                                            {options.paid_accounts.map((a) => (
                                                <option key={a.id} value={String(a.id)}>
                                                    {a.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                        <p className="text-muted-foreground max-w-xs text-xs">{t('och.paid_into_hint')}</p>
                                        <InputError message={(errors as Record<string, string | undefined>).paid_money_account_id} />
                                    </div>
                                    <div className="grid w-full gap-1 sm:w-48">
                                        <Label htmlFor="order-paid-ref">{t('och.paid_reference')}</Label>
                                        <Input
                                            id="order-paid-ref"
                                            maxLength={255}
                                            value={data.paid_reference}
                                            onChange={(e) => setData('paid_reference', e.target.value)}
                                        />
                                    </div>
                                </>
                            )}
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

                {/* ⭐ 25 Oct 2026 (Q16 = A): asked only once the server says a line is below its minimum. */}
                {(errors.floor_reason !== undefined || data.floor_reason !== '') && (
                    <div className="grid w-fit gap-1 px-4">
                        <Label htmlFor="floor_reason" required>
                            {t('salesctl.floor.reason')}
                        </Label>
                        <Input
                            id="floor_reason"
                            className="w-80 max-w-full"
                            maxLength={500}
                            value={data.floor_reason}
                            onChange={(e) => setData('floor_reason', e.target.value)}
                        />
                        <InputError message={errors.floor_reason} />
                    </div>
                )}

                {creditMessage !== null && (
                    <div className="px-4">
                        <CreditOverridePanel
                            message={creditMessage}
                            authorisers={creditAuthorisers}
                            value={{
                                override_by: data.override_by,
                                override_password: data.override_password,
                                override_reason: data.override_reason,
                            }}
                            onChange={(patch) => setData((current) => ({ ...current, ...patch }))}
                            errors={errors}
                        />
                    </div>
                )}

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
    discount_cap_percent = null,
    credit_authorisers = [],
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
    order_options,
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
    /** ⭐ 25 Oct 2026 (Q12 = A): this person's Discount % cap; null = the Owner. */
    discount_cap_percent?: number | null;
    /** ⭐ 25 Oct 2026 (Q11 = A): who may be chosen to override a credit limit. */
    credit_authorisers?: CreditAuthoriser[];
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
    /** ⭐ B18: channel, delivery and payment choices. */
    order_options: OrderOptions;
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
                        discountCap={discount_cap_percent}
                        creditAuthorisers={credit_authorisers}
                        canAddCustomer={can_add_customer}
                        customerTypes={customer_types}
                        priceGroups={price_groups}
                        brands={brands}
                        lastBrandId={last_brand_id}
                        lastWarehouseId={last_warehouse_id}
                        districts={districts}
                        commissionAgents={commission_agents}
                        options={order_options}
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
                            {queue === 'confirm' ? t('och.queue_confirm') : t(`orders.queue_${queue}`)}
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
                                <th className="py-2">{t('och.col_stage')}</th>
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
                                        {order.channel === 'website' && !order.channel_label && (
                                            <p className="text-muted-foreground text-xs">
                                                {t('orders.website')} {order.shop_reference ?? ''}
                                            </p>
                                        )}
                                        {/* ⭐ B18: how it came, and their own number. */}
                                        {order.channel && order.channel !== 'internal' && order.channel_label && (
                                            <p className="text-muted-foreground text-xs">
                                                {t(`och.channel.${order.channel}` as 'och.channel.phone')}{' '}
                                                {order.external_reference ?? order.shop_reference ?? ''}
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
                                    <td className="py-2">{order.stage && <OrderStageBadge stage={order.stage} />}</td>
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <tr>
                                    <td colSpan={10} className="text-muted-foreground py-4 text-center">
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

/**
 * ⭐ B17 (25 Oct 2026, Q4 = A): under each quantity, what is available to sell
 * in the order's warehouse (on hand less what confirmed orders and approved
 * requests hold). More than that: the order will wait for a Manager or the
 * Owner to confirm it as a backorder.
 */
function AvailableHint({ available, wanted }: { available: string; wanted: string }) {
    const { t } = useTranslation();
    const short = wanted !== '' && Number(wanted) > Number(available);

    return (
        <span className={short ? 'text-xs text-amber-700 dark:text-amber-400' : 'text-muted-foreground text-xs'}>
            {short ? t('rsv.line_short', { quantity: formatQuantity(available) }) : t('rsv.line_available', { quantity: formatQuantity(available) })}
        </span>
    );
}
