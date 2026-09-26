import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

interface Option {
    id: number;
    name: string;
    sku?: string;
    unit?: string | null;
    phone?: string | null;
    type?: string;
    is_default?: boolean;
    credit_limit?: string | null;
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
}

interface Exposure {
    credit_limit: string | null;
    open_invoices: string;
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Sales orders', href: '/sales/orders' }];

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

type LineDraft = { uid: number; product_id: string; quantity: string; unit_price: string };

interface LinePrice {
    unit_price: string | null;
    explanation: string | null;
    refusal: string | null;
    loading: boolean;
}

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
const newLine = (): LineDraft => ({ uid: nextUid++, product_id: '', quantity: '', unit_price: '' });

const money = (value: number) => value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
        <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-md border p-3 text-sm">
            <p className="text-muted-foreground">
                {t('orders.exposure', { invoices: exposure.open_invoices, orders: exposure.undelivered_orders })}{' '}
                <span className="text-foreground font-medium">{exposure.total}</span>
                {exposure.credit_limit !== null && <> {t('orders.against_limit', { limit: exposure.credit_limit })}</>}
            </p>
            {overLimit && (
                <p className="text-destructive mt-1 font-medium">
                    {t('orders.over_limit')}
                </p>
            )}
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
    products,
    price,
    canOverride,
    canRemove,
    errors,
    onChange,
    onPriced,
    onRemove,
}: {
    line: LineDraft;
    index: number;
    customerId: string;
    products: Option[];
    price: LinePrice | undefined;
    canOverride: boolean;
    canRemove: boolean;
    errors: Record<string, string | undefined>;
    onChange: (patch: Partial<LineDraft>) => void;
    onPriced: (price: LinePrice | undefined) => void;
    onRemove: () => void;
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

            try {
                const response = await fetch(`${route('sales.orders.price')}?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const body = response.ok ? await response.json() : null;

                if (!cancelled) {
                    onPriced(
                        body
                            ? { unit_price: body.unit_price, explanation: body.explanation, refusal: body.refusal, loading: false }
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
    }, [customerId, line.product_id, line.quantity]);

    const product = products.find((p) => String(p.id) === line.product_id);
    const listed = price?.unit_price ? money2(price.unit_price) : '';
    const unitPrice = line.unit_price !== '' ? line.unit_price : listed;
    const lineTotal = unitPrice !== '' && line.quantity !== '' ? parseFloat(unitPrice) * parseFloat(line.quantity) : null;

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border grid grid-cols-2 items-start gap-2 border-b pb-2 sm:grid-cols-[3fr_1fr_1fr_1fr_auto]">
            <div className="col-span-2 grid gap-1 sm:col-span-1">
                <SearchableSelect
                    value={line.product_id}
                    onChange={(v) => onChange({ product_id: v, unit_price: '' })}
                    options={products.map((p) => ({ value: String(p.id), label: p.sku ? `${p.sku} - ${p.name}` : p.name }))}
                    placeholder={t('orders.select_product')}
                    searchPlaceholder={t('orders.search_products')}
                />
                <InputError message={errors[`lines.${index}.product_id`]} />
            </div>
            <div className="grid gap-1">
                <Input
                    placeholder={product?.unit ? t('orders.quantity_in', { unit: product.unit }) : t('orders.quantity')}
                    inputMode="decimal"
                    value={line.quantity}
                    onChange={(e) => onChange({ quantity: e.target.value })}
                />
                <InputError message={errors[`lines.${index}.quantity`]} />
            </div>
            <div className="grid gap-1">
                {canOverride ? (
                    <Input
                        placeholder={t('orders.price')}
                        inputMode="decimal"
                        value={unitPrice}
                        onChange={(e) => onChange({ unit_price: e.target.value })}
                        aria-label={t('orders.unit_price')}
                    />
                ) : (
                    <p className="flex h-9 items-center text-sm tabular-nums">{price?.loading ? '…' : unitPrice || '-'}</p>
                )}
                {canOverride && line.unit_price !== '' && price?.unit_price && !samePrice(line.unit_price, price.unit_price) && (
                    <button type="button" className="text-muted-foreground w-fit text-xs underline" onClick={() => onChange({ unit_price: '' })}>
                        {t('orders.changed_from', { price: listed })}
                    </button>
                )}
                <InputError message={errors[`lines.${index}.unit_price`]} />
            </div>
            <p className="flex h-9 items-center justify-end text-sm font-medium tabular-nums">
                {lineTotal !== null && !isNaN(lineTotal) ? money(lineTotal) : ''}
            </p>
            <Button type="button" variant="ghost" size="sm" onClick={onRemove} disabled={!canRemove}>
                {t('orders.remove')}
            </Button>
            {(price?.refusal || price?.explanation) && (
                <p className={`col-span-2 text-xs sm:col-span-5 ${price.refusal ? 'text-destructive' : 'text-muted-foreground'}`}>
                    {price.refusal ?? price.explanation}
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
                    <DialogDescription>{t('orders.quick_intro')}</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="quick-name">{t('orders.quick_name')}</Label>
                        <Input id="quick-name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus />
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
                            <Label>{t('orders.quick_who')}</Label>
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

function CreateOrderForm({
    customers,
    warehouses,
    products,
    canOverridePrice,
    canAddCustomer,
    customerTypes,
    priceGroups,
    onClose,
    initialCustomerId = null,
}: {
    customers: Option[];
    warehouses: Option[];
    products: Option[];
    canOverridePrice: boolean;
    canAddCustomer: boolean;
    customerTypes: CustomerType[];
    priceGroups: PriceGroupOption[];
    onClose: () => void;
    initialCustomerId?: number | null;
}) {
    const { t } = useTranslation();
    const { data, setData, post, errors, processing, transform } = useForm<{
        customer_id: string;
        warehouse_id: string;
        expected_date: string;
        note: string;
        lines: LineDraft[];
    }>({
        customer_id: initialCustomerId ? String(initialCustomerId) : '',
        // The default warehouse, as everywhere else; "decided at delivery" is one click away.
        warehouse_id: String(warehouses.find((w) => w.is_default)?.id ?? ''),
        expected_date: '',
        note: '',
        lines: [newLine()],
    });
    const [prices, setPrices] = useState<Record<number, LinePrice | undefined>>({});
    const [quickOpen, setQuickOpen] = useState(false);
    const [typedCustomer, setTypedCustomer] = useState('');
    const lineErrors = errors as Record<string, string | undefined>;

    // ⚠ A price goes to the server only when somebody allowed to change it did.
    // Otherwise the server resolves it, as it always has.
    transform((form) => ({
        ...form,
        lines: form.lines.map((line) => ({
            product_id: line.product_id,
            quantity: line.quantity,
            ...(canOverridePrice && line.unit_price !== '' && !samePrice(line.unit_price, prices[line.uid]?.unit_price)
                ? { unit_price: line.unit_price }
                : {}),
        })),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.orders.store'));
    };

    const updateLine = (uid: number, patch: Partial<LineDraft>) =>
        setData(
            'lines',
            data.lines.map((line) => (line.uid === uid ? { ...line, ...patch } : line)),
        );
    const removeLine = (uid: number) =>
        setData(
            'lines',
            data.lines.filter((line) => line.uid !== uid),
        );

    const total = data.lines.reduce((sum, line) => {
        const unit = line.unit_price !== '' ? line.unit_price : prices[line.uid]?.unit_price;
        return unit && line.quantity ? sum + parseFloat(unit) * parseFloat(line.quantity) : sum;
    }, 0);
    const unpriced = data.lines.some((line) => line.product_id && prices[line.uid]?.refusal && line.unit_price === '');

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
                    onCreated={(id) => setData('customer_id', id)}
                />
            )}
            <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-xl border p-4">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="font-medium">{t('orders.new')}</h3>
                    <Button type="button" variant="ghost" size="sm" onClick={onClose}>
                        {t('orders.close')}
                    </Button>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label>{t('orders.form_customer')}</Label>
                        <SearchableSelect
                            value={data.customer_id}
                            onChange={(v) => setData('customer_id', v)}
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
                    <div className="grid gap-2">
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
                    <div className="grid gap-2">
                        <Label>{t('orders.expected_date')}</Label>
                        <Input type="date" value={data.expected_date} onChange={(e) => setData('expected_date', e.target.value)} />
                    </div>
                </div>

                <ExposureBox customerId={data.customer_id} />

                <div className="flex flex-col gap-2">
                    <div className="text-muted-foreground hidden grid-cols-[3fr_1fr_1fr_1fr_auto] gap-2 text-xs sm:grid">
                        <span>{t('orders.product')}</span>
                        <span>{t('orders.quantity')}</span>
                        <span>{t('orders.price')}</span>
                        <span className="text-right">{t('orders.line_total')}</span>
                        <span className="w-16" />
                    </div>
                    {!data.customer_id && (
                        <p className="text-muted-foreground text-sm">{t('orders.pick_customer_first')}</p>
                    )}
                    {data.lines.map((line, index) => (
                        <OrderLineRow
                            key={line.uid}
                            line={line}
                            index={index}
                            customerId={data.customer_id}
                            products={products}
                            price={prices[line.uid]}
                            canOverride={canOverridePrice}
                            canRemove={data.lines.length > 1}
                            errors={lineErrors}
                            onChange={(patch) => updateLine(line.uid, patch)}
                            onPriced={(price) => setPrices((current) => ({ ...current, [line.uid]: price }))}
                            onRemove={() => removeLine(line.uid)}
                        />
                    ))}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-fit"
                            onClick={() => setData('lines', [...data.lines, newLine()])}
                        >
                            {t('orders.add_line')}
                        </Button>
                        <p className="text-sm">
                            {t('orders.order_total')} <span className="font-medium tabular-nums">{money(total)}</span>
                        </p>
                    </div>
                </div>

                <InputError message={errors.lines} />

                <div className="grid gap-2">
                    <Label>{t('orders.note_optional')}</Label>
                    <Input value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>

                <p className="text-muted-foreground text-xs">
                    {t('orders.price_fixed_note')}
                </p>

                <Button disabled={processing || unpriced} className="w-fit">
                    {t('orders.record')}
                </Button>
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
    filters: { search: string; status: string };
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/orders', initialFilters);
    // ⭐ The form is at the top, one click away (21 Sep 2026): it used to sit
    // under the whole order list and the stock table.
    const [creating, setCreating] = useState(
        () => typeof window !== 'undefined' && (new URLSearchParams(window.location.search).has('new') || preselect_customer !== null),
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('orders.title')} />
            <div className="flex flex-col gap-6 p-4">
                {can_create && !creating && (
                    <Button className="w-fit" onClick={() => setCreating(true)}>
                        {t('orders.new')}
                    </Button>
                )}
                {can_create && creating && (
                    <CreateOrderForm
                        initialCustomerId={preselect_customer}
                        customers={customers}
                        warehouses={warehouses}
                        products={products}
                        canOverridePrice={can_override_price}
                        canAddCustomer={can_add_customer}
                        customerTypes={customer_types}
                        priceGroups={price_groups}
                        onClose={() => setCreating(false)}
                    />
                )}

                {own_only && (
                    <p className="text-muted-foreground text-sm">{t('orders.own_only')}</p>
                )}

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label={t('orders.search')} htmlFor="filter-search" className="min-w-56 flex-1">
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
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">{t('orders.col_number')}</th>
                            <th className="py-2">{t('orders.col_customer')}</th>
                            <th className="py-2">{t('orders.col_ordered')}</th>
                            <th className="py-2">{t('orders.col_delivered')}</th>
                            <th className="py-2">{t('orders.col_expected')}</th>
                            <th className="py-2">{t('orders.col_approval')}</th>
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
                                        <p className="text-muted-foreground text-xs">{t('orders.website')} {order.shop_reference ?? ''}</p>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{order.customer.name}</td>
                                <td className="py-2">{order.total_ordered}</td>
                                <td className="py-2">{order.total_delivered}</td>
                                <td className="text-muted-foreground py-2">{order.expected_date ?? '-'}</td>
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
                                    <Badge variant={statusVariant[order.status]} className="capitalize">
                                        {order.status.replace('_', ' ')}
                                    </Badge>
                                </td>
                            </tr>
                        ))}
                        {orders.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? t('orders.none_match') : t('orders.none_yet')}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

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
                                            <td className="p-3 text-right tabular-nums">{row.on_hand}</td>
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
