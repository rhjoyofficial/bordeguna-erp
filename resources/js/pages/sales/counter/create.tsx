import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';

interface Option {
    id: number;
    name: string;
    code?: string | null;
    sku?: string | null;
    is_default?: boolean;
    is_system?: boolean;
}

interface ProductCard {
    id: number;
    name: string;
    sku: string | null;
    category_id: number | null;
}

// The index signature is what lets Inertia's useForm accept an array of these
// as form data; without it the whole line array is not FormDataConvertible.
interface Line {
    [key: string]: string | undefined;
    product_id: string;
    quantity: string;
    unit_price: string;
    discount: string;
    explanation?: string;
}

// A held cart is a bookmark of the counter state, never a document: nothing
// reserved, nothing posted, prices re-resolved on resume.
interface HeldCart {
    id: number;
    label: string | null;
    customer_id: number | null;
    item_count: number;
    held_by: string | null;
    held_at: string;
}

interface ResumeCart {
    id: number;
    label: string | null;
    customer_id: number | null;
    warehouse_id: number;
    lines: { product_id: number | string; quantity: number | string; unit_price?: number | string | null; discount?: number | string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Counter', href: '/sales/counter' },
];

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The counter, in the POS anatomy Joy picked from DreamsPOS: the catalogue as
 * a card grid with search and category chips on the left, the growing order on
 * the right, one tap per item.
 *
 * ⚠ A restyle of the screen, not of the sale. It posts the same form to the
 * same RecordCounterSale action, the cart price still comes from the resolver
 * per customer and quantity, and every refusal (no price, no stock, closed
 * month, missing mapping) still lands readably under the order. The reference
 * page's Hold/Void/Transaction buttons were NOT copied - there is no engine
 * behind them here, and a dead button is worse than no button.
 */
export default function CounterSale({
    warehouses,
    products,
    categories = [],
    cardPrices = {},
    customers,
    moneyAccounts,
    walkInId,
    heldCarts = [],
    resumeCart = null,
}: {
    warehouses: Option[];
    products: ProductCard[];
    categories: { id: number; name: string }[];
    cardPrices: Record<number, number>;
    customers: Option[];
    moneyAccounts: Option[];
    walkInId: number | null;
    heldCarts: HeldCart[];
    resumeCart: ResumeCart | null;
}) {
    const { t } = useTranslation();

    const { data, setData, post, errors, processing } = useForm({
        customer_id: resumeCart?.customer_id ? String(resumeCart.customer_id) : walkInId ? String(walkInId) : '',
        warehouse_id: resumeCart?.warehouse_id
            ? String(resumeCart.warehouse_id)
            : String(warehouses.find((w) => w.is_default)?.id ?? warehouses[0]?.id ?? ''),
        money_account_id: String(moneyAccounts[0]?.id ?? ''),
        invoice_discount: '0',
        reference: '',
        note: '',
        held_cart_id: resumeCart?.id ?? null,
        lines: (resumeCart?.lines ?? []).map((line) => ({
            product_id: String(line.product_id),
            quantity: String(line.quantity),
            unit_price: '',
            discount: String(line.discount ?? '0'),
        })) as Line[],
    });

    const [search, setSearch] = useState('');
    const [categoryId, setCategoryId] = useState<number | null>(null);

    const productsById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products]);

    const shown = products.filter((p) => {
        if (categoryId !== null && p.category_id !== categoryId) return false;
        if (search.trim() === '') return true;
        const needle = search.trim().toLowerCase();
        return p.name.toLowerCase().includes(needle) || (p.sku ?? '').toLowerCase().includes(needle);
    });

    // ⚠ Lines are keyed by product, not by index: a card tap either adds the
    // product or bumps its quantity, so one product is always one line. That is
    // also what makes the async price patch below safe - a response finds its
    // line by product id even if the cart changed while it was in flight.
    const patchLine = (productId: string, patch: Partial<Line>) =>
        setData((current) => ({
            ...current,
            lines: current.lines.map((l) => (l.product_id === productId ? { ...l, ...patch } : l)),
        }));

    // ⚠ The price comes from the SAME resolver the sale will use. A screen that
    // works out its own price is a second pricing engine, and it disagrees with
    // the invoice the moment a rule changes.
    const fetchPrice = async (productId: string, quantity: string, customerId: string = data.customer_id) => {
        if (!productId || !quantity) return;

        // ⚠⚠ A GET. This used to POST with an `X-CSRF-TOKEN` read from a meta tag
        // the layout does not emit, so every call was rejected and the price
        // silently never arrived. Reading needs no token.
        const query = new URLSearchParams({ product_id: productId, quantity });

        if (customerId) query.set('customer_id', customerId);

        const response = await fetch(`${route('sales.counter.price')}?${query.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            patchLine(productId, { explanation: t('pos.price_lookup_failed') });

            return;
        }

        const body = await response.json();
        patchLine(productId, {
            unit_price: body.unit_price ?? '',
            explanation: body.unit_price ? body.explanation : t('pos.no_price_for_product'),
        });
    };

    const addProduct = (product: ProductCard) => {
        const id = String(product.id);
        const existing = data.lines.find((l) => l.product_id === id);

        if (existing) {
            const quantity = String((Number(existing.quantity) || 0) + 1);
            patchLine(id, { quantity });
            void fetchPrice(id, quantity);

            return;
        }

        setData((current) => ({
            ...current,
            lines: [...current.lines, { product_id: id, quantity: '1', unit_price: '', discount: '0' }],
        }));
        void fetchPrice(id, '1');
    };

    const setQuantity = (productId: string, quantity: string, refetch: boolean) => {
        patchLine(productId, { quantity });
        if (refetch) void fetchPrice(productId, quantity);
    };

    const removeLine = (productId: string) => setData((current) => ({ ...current, lines: current.lines.filter((l) => l.product_id !== productId) }));

    // The customer decides the price, so changing them re-asks for every line.
    const changeCustomer = (customerId: string) => {
        setData('customer_id', customerId);
        data.lines.forEach((l) => void fetchPrice(l.product_id, l.quantity, customerId));
    };

    const reset = () => setData((current) => ({ ...current, lines: [], invoice_discount: '0', reference: '', note: '', held_cart_id: null }));

    // A resumed cart carries products and quantities but NO prices - the
    // resolver answers again, exactly as it would for a fresh cart. Prices may
    // have changed while the cart sat held, and that is correct.
    useEffect(() => {
        if (resumeCart !== null) {
            resumeCart.lines.forEach((line) => void fetchPrice(String(line.product_id), String(line.quantity)));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const [holdLabel, setHoldLabel] = useState('');

    // Park the cart so the queue keeps moving. Nothing posts, nothing reserves.
    const holdCart = () => {
        router.post(
            route('sales.counter.hold'),
            {
                label: holdLabel || null,
                customer_id: data.customer_id || null,
                warehouse_id: data.warehouse_id,
                lines: data.lines,
            },
            { preserveScroll: true, onSuccess: () => setHoldLabel('') },
        );
    };

    const subtotal = data.lines.reduce((sum, line) => {
        const gross = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
        return sum + gross - (Number(line.discount) || 0);
    }, 0);
    const total = Math.max(0, subtotal - (Number(data.invoice_discount) || 0));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.counter.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('pos.title')} />
            <form onSubmit={submit} className="flex flex-col gap-4 p-4 lg:h-[calc(100vh-4rem)] lg:flex-row lg:overflow-hidden">
                {/* The catalogue: search, category chips, one card per product. */}
                <div className="flex min-w-0 flex-1 flex-col gap-3 lg:overflow-hidden">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            className="max-w-xs"
                            placeholder={t('pos.search_placeholder')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            aria-label={t('pos.search_aria')}
                        />
                        <div className="flex flex-wrap gap-1.5">
                            <button
                                type="button"
                                onClick={() => setCategoryId(null)}
                                className={
                                    categoryId === null
                                        ? 'bg-primary text-primary-foreground rounded-full px-3 py-1 text-xs font-medium'
                                        : 'border-border text-muted-foreground hover:text-foreground rounded-full border px-3 py-1 text-xs'
                                }
                            >
                                {t('pos.all')}
                            </button>
                            {categories.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => setCategoryId(categoryId === c.id ? null : c.id)}
                                    className={
                                        categoryId === c.id
                                            ? 'bg-primary text-primary-foreground rounded-full px-3 py-1 text-xs font-medium'
                                            : 'border-border text-muted-foreground hover:text-foreground rounded-full border px-3 py-1 text-xs'
                                    }
                                >
                                    {c.name}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="lg:overflow-y-auto">
                        {shown.length === 0 ? (
                            <p className="text-muted-foreground p-4 text-sm">{t('pos.no_match')}</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                {shown.map((p) => {
                                    const inCart = data.lines.some((l) => l.product_id === String(p.id));
                                    const price = cardPrices[p.id];

                                    return (
                                        <button
                                            key={p.id}
                                            type="button"
                                            onClick={() => addProduct(p)}
                                            className={`rounded-lg border p-3 text-left transition-colors ${
                                                inCart
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-sidebar-border/70 dark:border-sidebar-border hover:border-primary/50'
                                            }`}
                                        >
                                            <p className="line-clamp-2 text-sm font-medium">{p.name}</p>
                                            <p className="text-muted-foreground mt-0.5 truncate text-xs">{p.sku}</p>
                                            {/* A shelf label: the walk-in price today. The cart
                                                re-resolves per customer and quantity. */}
                                            <p className="mt-1.5 text-sm font-semibold tabular-nums">
                                                {price !== undefined ? (
                                                    money(price)
                                                ) : (
                                                    <span className="text-muted-foreground font-normal">{t('pos.no_price_set')}</span>
                                                )}
                                            </p>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>

                {/* The order. Everything the sale needs, ending in one button. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border flex w-full flex-col rounded-xl border lg:w-[26rem] lg:shrink-0">
                    <div className="border-border flex items-center justify-between border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            {t('pos.order')}
                            {data.lines.length > 0 && (
                                <span className="text-muted-foreground ml-2 font-normal">
                                    {t(data.lines.length === 1 ? 'pos.item_one' : 'pos.item_many', { count: data.lines.length })}
                                </span>
                            )}
                        </h2>
                        {data.lines.length > 0 && (
                            <Button type="button" variant="ghost" size="sm" onClick={reset}>
                                {t('pos.clear')}
                            </Button>
                        )}
                    </div>

                    {/* Held carts: bookmarks waiting for their customer to come
                        back. Resume loads one; the hold itself survives until
                        its sale completes or it is discarded on purpose. */}
                    {heldCarts.length > 0 && (
                        <div className="border-border bg-muted/30 space-y-1.5 border-b px-4 py-2.5">
                            <p className="text-muted-foreground text-xs font-medium">{t('pos.held_carts')}</p>
                            {heldCarts.map((cart) => (
                                <div key={cart.id} className="flex items-center gap-2 text-sm">
                                    <span className="min-w-0 flex-1 truncate">
                                        {cart.label ?? `#${cart.id}`}
                                        <span className="text-muted-foreground ml-1 text-xs">
                                            ({t(cart.item_count === 1 ? 'pos.item_one' : 'pos.item_many', { count: cart.item_count })})
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7"
                                        disabled={resumeCart?.id === cart.id}
                                        onClick={() => router.get(route('sales.counter.create'), { resume: cart.id })}
                                    >
                                        {t('pos.resume_cart')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="text-destructive h-7"
                                        onClick={() => router.delete(route('sales.counter.hold.discard', cart.id), { preserveScroll: true })}
                                    >
                                        {t('pos.discard_cart')}
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}

                    <div className="flex flex-col gap-3 p-4">
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1">
                                <Label htmlFor="customer" className="text-xs">
                                    {t('pos.customer')}
                                </Label>
                                <SearchSelect id="customer" value={data.customer_id} onChange={(e) => changeCustomer(e.target.value)}>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                            {c.is_system ? ` (${t('pos.default')})` : ''}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.customer_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="warehouse" className="text-xs">
                                    {t('pos.sold_from')}
                                </Label>
                                <SearchSelect id="warehouse" value={data.warehouse_id} onChange={(e) => setData('warehouse_id', e.target.value)}>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.warehouse_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="money" className="text-xs">
                                    {t('pos.paid_into')}
                                </Label>
                                {/* ⚠ "On account" is a real choice, not a missing value: the
                                    invoice then stands open as a receivable. */}
                                <SearchSelect id="money" value={data.money_account_id} onChange={(e) => setData('money_account_id', e.target.value)}>
                                    <option value="">{t('pos.on_account')}</option>
                                    {moneyAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.money_account_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="reference" className="text-xs">
                                    {t('pos.reference')}
                                </Label>
                                <Input
                                    id="reference"
                                    className="h-9"
                                    value={data.reference}
                                    onChange={(e) => setData('reference', e.target.value)}
                                    placeholder={t('pos.reference_placeholder')}
                                />
                            </div>
                        </div>

                        <div className="flex flex-col gap-2 lg:max-h-[40vh] lg:overflow-y-auto">
                            {data.lines.length === 0 ? (
                                <p className="text-muted-foreground py-6 text-center text-sm">{t('pos.tap_to_start')}</p>
                            ) : (
                                data.lines.map((line) => {
                                    const product = productsById.get(line.product_id);
                                    const lineTotal = (Number(line.quantity) || 0) * (Number(line.unit_price) || 0) - (Number(line.discount) || 0);

                                    return (
                                        <div
                                            key={line.product_id}
                                            className="border-sidebar-border/70 dark:border-sidebar-border rounded-lg border p-2"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-sm font-medium">{product?.name ?? `#${line.product_id}`}</p>
                                                <button
                                                    type="button"
                                                    onClick={() => removeLine(line.product_id)}
                                                    className="text-muted-foreground hover:text-destructive text-xs"
                                                    aria-label={t('pos.remove_aria', { name: product?.name ?? t('pos.line') })}
                                                >
                                                    {t('pos.remove')}
                                                </button>
                                            </div>
                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-8 w-8 p-0"
                                                        onClick={() =>
                                                            setQuantity(line.product_id, String(Math.max(1, (Number(line.quantity) || 1) - 1)), true)
                                                        }
                                                    >
                                                        -
                                                    </Button>
                                                    <Input
                                                        className="h-8 w-16 text-center"
                                                        aria-label={t('pos.quantity')}
                                                        value={line.quantity}
                                                        onChange={(e) => setQuantity(line.product_id, e.target.value, false)}
                                                        onBlur={() => void fetchPrice(line.product_id, line.quantity)}
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-8 w-8 p-0"
                                                        onClick={() => setQuantity(line.product_id, String((Number(line.quantity) || 0) + 1), true)}
                                                    >
                                                        +
                                                    </Button>
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    <span className="text-muted-foreground text-xs">@</span>
                                                    <Input
                                                        className="h-8 w-24"
                                                        aria-label={t('pos.unit_price')}
                                                        value={line.unit_price}
                                                        onChange={(e) => patchLine(line.product_id, { unit_price: e.target.value })}
                                                    />
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    <span className="text-muted-foreground text-xs">{t('pos.less')}</span>
                                                    <Input
                                                        className="h-8 w-20"
                                                        aria-label={t('pos.line_discount')}
                                                        value={line.discount}
                                                        onChange={(e) => patchLine(line.product_id, { discount: e.target.value })}
                                                    />
                                                </div>
                                                <p className="ml-auto text-sm font-semibold tabular-nums">{money(lineTotal)}</p>
                                            </div>
                                            {line.explanation && <p className="text-muted-foreground mt-1 text-xs">{line.explanation}</p>}
                                            <InputError
                                                message={
                                                    errors[`lines.${data.lines.indexOf(line)}.product_id` as keyof typeof errors] ??
                                                    errors[`lines.${data.lines.indexOf(line)}.quantity` as keyof typeof errors]
                                                }
                                            />
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>

                    <div className="border-border mt-auto flex flex-col gap-2 border-t p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-sm">
                            <span>{t('pos.subtotal')}</span>
                            <span className="tabular-nums">{money(subtotal)}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <Label htmlFor="invoice_discount" className="text-muted-foreground text-sm font-normal">
                                {t('pos.invoice_discount')}
                            </Label>
                            <Input
                                id="invoice_discount"
                                className="h-8 w-24 text-right"
                                value={data.invoice_discount}
                                onChange={(e) => setData('invoice_discount', e.target.value)}
                            />
                        </div>
                        <InputError message={errors.invoice_discount} />
                        <div className="flex items-baseline justify-between">
                            <span className="text-sm font-medium">{t('pos.total')}</span>
                            <span className="text-2xl font-semibold tabular-nums">{money(total)}</span>
                        </div>

                        {/* C-SLS-E1. Where a refused sale says why: no price in force,
                            not enough stock, a closed month, a missing mapping. The
                            till is the one screen where all four can happen, and
                            before this every one of them arrived as a blank error. */}
                        <InputError message={errors.lines} />
                        <Button type="submit" disabled={processing || data.lines.length === 0} size="lg" className="w-full">
                            {data.money_account_id ? t('pos.take_payment') : t('pos.finish_on_account')}
                        </Button>

                        {/* Hold: park this cart and serve the next person. */}
                        {data.lines.length > 0 && (
                            <div className="flex gap-2">
                                <Input
                                    className="h-9 flex-1"
                                    placeholder={t('pos.hold_label_placeholder')}
                                    value={holdLabel}
                                    onChange={(e) => setHoldLabel(e.target.value)}
                                />
                                <Button type="button" variant="outline" className="h-9" onClick={holdCart}>
                                    {t('pos.hold_cart')}
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}
