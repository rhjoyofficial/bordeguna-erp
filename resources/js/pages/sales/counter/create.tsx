import { CustomerQuickCreate } from '@/components/customer-quick-create';
import InputError from '@/components/input-error';
import { CreditOverridePanel, NO_OVERRIDE, type CreditAuthoriser } from '@/components/sales/credit-override';
import { LineDiscount } from '@/components/sales/line-discount';
import { SearchSelect } from '@/components/search-select';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { holdPopups, releasePopups } from '@/lib/popup-engine';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';

interface Option {
    id: number;
    name: string;
    code?: string | null;
    sku?: string | null;
    is_default?: boolean;
    is_system?: boolean;
}

interface CustomerOption {
    id: number;
    name: string;
    code: string | null;
    phone: string | null;
    phone_normalized: string | null;
    is_system: boolean;
}

interface MoneyAccountOption {
    id: number;
    name: string;
    type: string;
}

interface ProductCard {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    category_id: number | null;
}

// The index signature is what lets Inertia's useForm accept an array of these
// as form data; without it the whole line array is not FormDataConvertible.
interface Line {
    [key: string]: string | undefined;
    product_id: string;
    quantity: string;
    // What the line shows and, only if the cashier changed it, what is sent.
    unit_price: string;
    // What the price list says for this customer and quantity (the resolver).
    list_price: string;
    // '1' once the cashier has typed a price: a new list price for a new
    // quantity then no longer overwrites it.
    edited: string;
    discount: string;
    override_reason: string;
    explanation?: string;
    // ⭐ Phase 2 / 1B (17 Oct 2026): "since HH:MM" on a market product's line.
    market_since?: string;
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
    lines: {
        product_id: number | string;
        quantity: number | string;
        unit_price?: number | string | null;
        discount?: number | string | null;
        override_reason?: string | null;
    }[];
}

interface MyTill {
    shift_id: number;
    money_account_id: number;
    name: string | null;
}

interface LastSale {
    invoice_id: number;
    invoice_number: string;
    total: string;
    on_account: boolean;
    slip_url: string;
    cash_given: string | null;
    change: string | null;
}

// ⚠ A value no money account id can take: the empty value means "not chosen
// yet", which is NOT the same as "the customer owes it". Until 21 Sep 2026 the
// two were one value, so an unchosen picker was a credit sale.
const ON_ACCOUNT = 'on_account';

const money = (value: number) => value.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * A typed amount in paisa, or null when it is not a plain amount. For the
 * screen's own arithmetic only: the server works the real figures out exactly.
 */
const paisa = (value: string): number | null => {
    const trimmed = value.trim();

    if (!/^\d+(\.\d{1,2})?$/.test(trimmed)) return null;

    const [whole, fraction = ''] = trimmed.split('.');

    return Number(whole) * 100 + Number((fraction + '00').slice(0, 2));
};

/** Whether the cashier's price differs from the list price: only then is it sent. */
const priceChanged = (line: Line) =>
    line.edited === '1' && line.unit_price.trim() !== '' && (line.list_price === '' || Number(line.unit_price) !== Number(line.list_price));

/**
 * The counter, in the POS anatomy Joy picked from DreamsPOS: the catalogue as
 * a card grid with search and category chips on the left, the growing order on
 * the right, one tap per item.
 *
 * ⚠ A restyle of the screen, not of the sale. It posts to the same
 * RecordCounterSale action, the price still comes from the resolver per
 * customer and quantity, and every refusal (no price, no stock, closed month,
 * missing mapping, credit) lands readably under the order.
 *
 * ⚠⚠ FAST AND SAFE KEYS (Sales UX audit #4, 21 Sep 2026). This used to be one
 * <form>, so Enter in ANY box - the search a barcode scanner types into, a
 * quantity, a price - completed the sale. It is not a form any more: only the
 * Finish button finishes. Enter in the search adds the product whose barcode or
 * SKU is exactly what was typed, which is what a scanner sends.
 */
export default function CounterSale({
    warehouses,
    products,
    categories = [],
    cardPrices = {},
    customers,
    moneyAccounts,
    myTill = null,
    canOverridePrice = false,
    lastSale = null,
    walkInId,
    heldCarts = [],
    resumeCart = null,
    canAddCustomer = false,
    preferredWarehouseId = null,
    onHand = {},
    marketSince = {},
    marketUnpriced = [],
    discountCap = null,
    creditAuthorisers = [],
}: {
    warehouses: Option[];
    products: ProductCard[];
    categories: { id: number; name: string }[];
    cardPrices: Record<number, number>;
    customers: CustomerOption[];
    moneyAccounts: MoneyAccountOption[];
    myTill: MyTill | null;
    canOverridePrice: boolean;
    lastSale: LastSale | null;
    walkInId: number | null;
    heldCarts: HeldCart[];
    resumeCart: ResumeCart | null;
    canAddCustomer?: boolean;
    preferredWarehouseId?: number | null;
    onHand?: Record<number, Record<number, string>>;
    /** ⭐ Phase 2 / 1B: {product_id: "HH:MM"} for every market product with a confirmed price. */
    marketSince?: Record<number, string>;
    /** Market products off the grid today, by name, because nobody confirmed a price. */
    marketUnpriced?: string[];
    /** ⭐ 25 Oct 2026 (Q12 = A): this person's Discount % cap; null = the Owner. */
    discountCap?: number | null;
    /** ⭐ 25 Oct 2026 (Q11 = A): who may be chosen to override a credit limit. */
    creditAuthorisers?: CreditAuthoriser[];
}) {
    const { t } = useTranslation();
    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.sales'), href: '/sales/overview' },
        { title: t('nav.counter'), href: '/sales/counter' },
    ];

    // ⚠⚠ The cashier's OWN till, from their open shift, or nothing. Never the
    // first account in the list: that put cash into whichever account sorted
    // first alphabetically (audit #6).
    const tillUsable = myTill !== null && moneyAccounts.some((a) => a.id === myTill.money_account_id);
    const defaultMoney = tillUsable ? String(myTill!.money_account_id) : '';

    const { data, setData, post, errors, processing, transform } = useForm({
        customer_id: resumeCart?.customer_id ? String(resumeCart.customer_id) : walkInId ? String(walkInId) : '',
        // ⭐ F24: a resumed cart's warehouse, else where this cashier last sold
        // from (remembered by the server), else the default.
        warehouse_id: resumeCart?.warehouse_id
            ? String(resumeCart.warehouse_id)
            : String(preferredWarehouseId ?? warehouses.find((w) => w.is_default)?.id ?? warehouses[0]?.id ?? ''),
        money_account_id: defaultMoney,
        invoice_discount: '0',
        discount_reason: '',
        cash_given: '',
        reference: '',
        note: '',
        held_cart_id: resumeCart?.id ?? null,
        ...NO_OVERRIDE,
        lines: (resumeCart?.lines ?? []).map((line) => ({
            product_id: String(line.product_id),
            quantity: String(line.quantity),
            // A resumed cart is priced again, as a fresh one would be; a price
            // changed before the hold is not carried over.
            unit_price: '',
            list_price: '',
            edited: '',
            discount: String(line.discount ?? '0'),
            override_reason: String(line.override_reason ?? ''),
        })) as Line[],
    });

    // A sale on the till holds popups back (Settings > Popups); they show once
    // the cart is empty again. The hold is released when the screen unmounts.
    const cartOpen = data.lines.length > 0;
    useEffect(() => {
        if (cartOpen) {
            holdPopups('pos-sale');
        } else {
            releasePopups('pos-sale');
        }

        return () => releasePopups('pos-sale');
    }, [cartOpen]);

    const [search, setSearch] = useState('');
    // ⭐ 27 Sep 2026 (Joy): add a customer mid-sale from the picker.
    const [addingCustomer, setAddingCustomer] = useState(false);
    const [typedCustomer, setTypedCustomer] = useState('');
    const [scanMessage, setScanMessage] = useState<string | null>(null);
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [dismissedSale, setDismissedSale] = useState<number | null>(null);
    const [pendingPrices, setPendingPrices] = useState(0);
    const searchRef = useRef<HTMLInputElement>(null);

    const productsById = useMemo(() => new Map(products.map((p) => [String(p.id), p])), [products]);
    const selectedAccount = moneyAccounts.find((a) => String(a.id) === data.money_account_id) ?? null;
    const payingCash = selectedAccount?.type === 'cash';
    const isWalkIn = walkInId !== null && data.customer_id === String(walkInId);

    // ⚠ Browsing shows only what can be sold at a price today; raw materials and
    // packaging have none and only cluttered the till (30 Sep 2026 walk-through).
    // A search or scan still finds every product, priced or not.
    const browsing = search.trim() === '';
    const unpricedHidden = browsing ? products.filter((p) => cardPrices[p.id] === undefined).length : 0;

    const shown = products.filter((p) => {
        if (categoryId !== null && p.category_id !== categoryId) return false;
        if (browsing) return cardPrices[p.id] !== undefined;
        const needle = search.trim().toLowerCase();
        return (
            p.name.toLowerCase().includes(needle) || (p.sku ?? '').toLowerCase().includes(needle) || (p.barcode ?? '').toLowerCase().includes(needle)
        );
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

    // The customer a delayed price lookup should ask about: the one chosen NOW,
    // not the one captured when the lookup was scheduled.
    const customerRef = useRef(data.customer_id);
    customerRef.current = data.customer_id;

    // ⚠ Only the newest answer per product is applied. Typing 1, then 12, sends
    // two lookups; if the one for 1 comes back last it must not win.
    const lookupSeq = useRef(new Map<string, number>());
    const repriceTimers = useRef(new Map<string, number>());

    // ⚠ The price comes from the SAME resolver the sale will use. A screen that
    // works out its own price is a second pricing engine, and it disagrees with
    // the invoice the moment a rule changes. ⚠⚠ And since 21 Sep 2026 the
    // server prices the sale itself: this is what the screen SHOWS.
    const fetchPrice = async (productId: string, quantity: string, customerId: string = customerRef.current) => {
        if (!productId || !quantity || Number(quantity) <= 0) return;

        const seq = (lookupSeq.current.get(productId) ?? 0) + 1;
        lookupSeq.current.set(productId, seq);

        // ⚠⚠ A GET. This used to POST with an `X-CSRF-TOKEN` read from a meta tag
        // the layout does not emit, so every call was rejected and the price
        // silently never arrived. Reading needs no token.
        const query = new URLSearchParams({ product_id: productId, quantity });

        if (customerId) query.set('customer_id', customerId);
        // ⭐ D-B (18 Oct 2026): the owner price is the cost of the batch this warehouse issues.
        if (data.warehouse_id) query.set('warehouse_id', String(data.warehouse_id));

        setPendingPrices((n) => n + 1);

        try {
            const response = await fetch(`${route('sales.counter.price')}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (lookupSeq.current.get(productId) !== seq) return;

            if (!response.ok) {
                patchLine(productId, { explanation: t('pos.price_lookup_failed') });

                return;
            }

            const body = await response.json();
            // A plain number in the price box ("340", not the stored "340.0000").
            const listPrice = body.unit_price === null || body.unit_price === undefined ? '' : String(Number(body.unit_price));

            // A price the cashier typed stays; the list price under it follows
            // the quantity, so the trail compares against the right figure.
            setData((current) => ({
                ...current,
                lines: current.lines.map((l) =>
                    l.product_id === productId
                        ? {
                              ...l,
                              list_price: listPrice,
                              unit_price: l.edited === '1' ? l.unit_price : listPrice,
                              // ⭐ Phase 2 / 1B: a market product with no confirmed price says why.
                              explanation: listPrice ? body.explanation : (body.refusal ?? t('pos.no_price_for_product')),
                              market_since: body.market_since ?? undefined,
                          }
                        : l,
                ),
            }));
        } catch {
            if (lookupSeq.current.get(productId) === seq) patchLine(productId, { explanation: t('pos.price_lookup_failed') });
        } finally {
            setPendingPrices((n) => Math.max(0, n - 1));
        }
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
            lines: [
                ...current.lines,
                { product_id: id, quantity: '1', unit_price: '', list_price: '', edited: '', discount: '0', override_reason: '' },
            ],
        }));
        void fetchPrice(id, '1');
    };

    // ⚠ A typed quantity re-prices as it is typed (after a short pause), not
    // only when the box loses focus: "12" then Finish used to bill at the
    // 1-unit price shown on screen (audit #4). The server prices the sale
    // itself either way; this keeps the total on screen honest.
    const setQuantity = (productId: string, quantity: string, immediately: boolean) => {
        patchLine(productId, { quantity });
        window.clearTimeout(repriceTimers.current.get(productId));

        if (immediately) {
            void fetchPrice(productId, quantity);

            return;
        }

        repriceTimers.current.set(
            productId,
            window.setTimeout(() => void fetchPrice(productId, quantity), 300),
        );
    };

    const removeLine = (productId: string) => setData((current) => ({ ...current, lines: current.lines.filter((l) => l.product_id !== productId) }));

    // The customer decides the price, so changing them re-asks for every line.
    // ⚠ And a walk-in cannot owe money, so "on account" is taken back.
    const changeCustomer = (customerId: string) => {
        setData((current) => ({
            ...current,
            customer_id: customerId,
            money_account_id:
                walkInId !== null && customerId === String(walkInId) && current.money_account_id === ON_ACCOUNT
                    ? defaultMoney
                    : current.money_account_id,
        }));
        data.lines.forEach((l) => void fetchPrice(l.product_id, l.quantity, customerId));
    };

    const reset = () =>
        setData((current) => ({
            ...current,
            lines: [],
            invoice_discount: '0',
            discount_reason: '',
            cash_given: '',
            reference: '',
            note: '',
            held_cart_id: null,
        }));

    // A resumed cart carries products and quantities but NO prices - the
    // resolver answers again, exactly as it would for a fresh cart. Prices may
    // have changed while the cart sat held, and that is correct.
    useEffect(() => {
        if (resumeCart !== null) {
            resumeCart.lines.forEach((line) => void fetchPrice(String(line.product_id), String(line.quantity)));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The search box is where a scanner types, so it has the focus on a wide
    // screen. ⚠ Not on a phone: focusing there throws the keyboard over the
    // catalogue before anybody asked for it.
    useEffect(() => {
        if (window.matchMedia('(min-width: 1024px)').matches) searchRef.current?.focus();
    }, []);

    // ⚠ Enter in the search adds the product whose barcode or SKU is EXACTLY
    // what was typed (a scanner types the code, then Enter). Any other Enter
    // here does nothing: it never completes the sale.
    const onSearchKey = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') return;

        event.preventDefault();
        const code = search.trim().toLowerCase();

        if (code === '') return;

        const match = products.find((p) => (p.barcode ?? '').toLowerCase() === code) ?? products.find((p) => (p.sku ?? '').toLowerCase() === code);

        if (match === undefined) {
            setScanMessage(t('pos.scan_no_match', { code: search.trim() }));

            return;
        }

        addProduct(match);
        setSearch('');
        setScanMessage(null);
    };

    const [holdLabel, setHoldLabel] = useState('');

    // Park the cart so the queue keeps moving. Nothing posts, nothing reserves.
    const holdCart = () => {
        router.post(
            route('sales.counter.hold'),
            {
                label: holdLabel || null,
                customer_id: data.customer_id || null,
                warehouse_id: data.warehouse_id,
                lines: data.lines.map((l) => ({
                    product_id: l.product_id,
                    quantity: l.quantity,
                    discount: l.discount,
                    override_reason: l.override_reason,
                })),
            },
            { preserveScroll: true, onSuccess: () => setHoldLabel('') },
        );
    };

    // In paisa, so the change the screen shows is exact for what it shows.
    const lineNetPaisa = (line: Line) =>
        Math.round((Number(line.quantity) || 0) * (Number(line.unit_price) || 0) * 100) - (paisa(line.discount || '0') ?? 0);
    const subtotalPaisa = data.lines.reduce((sum, line) => sum + lineNetPaisa(line), 0);
    const totalPaisa = Math.max(0, subtotalPaisa - (paisa(data.invoice_discount || '0') ?? 0));
    const cashGivenPaisa = payingCash ? paisa(data.cash_given) : null;
    const billDiscounted = (paisa(data.invoice_discount || '0') ?? 0) > 0;

    const finish = () => {
        // Only what the server should read: a price is sent ONLY when the
        // cashier changed it, so an untouched line is priced from the list.
        transform((current) => ({
            customer_id: current.customer_id,
            warehouse_id: current.warehouse_id,
            money_account_id: current.money_account_id === ON_ACCOUNT ? '' : current.money_account_id,
            invoice_discount: current.invoice_discount,
            discount_reason: current.discount_reason,
            cash_given: payingCash ? current.cash_given : '',
            reference: current.reference,
            note: current.note,
            held_cart_id: current.held_cart_id,
            // ⭐ 25 Oct 2026 (Q11 = A): sent only with a sale on account.
            ...(current.money_account_id === ON_ACCOUNT
                ? { override_by: current.override_by, override_password: current.override_password, override_reason: current.override_reason }
                : {}),
            lines: current.lines.map((line) => ({
                product_id: line.product_id,
                quantity: line.quantity,
                discount: line.discount || '0',
                override_reason: line.override_reason,
                ...(priceChanged(line) ? { unit_price: line.unit_price.trim() } : {}),
            })),
        }));

        post(route('sales.counter.store'), {
            // ⚠⚠ A refusal keeps the cart as it is; a finished sale comes back
            // as a FRESH counter, not with the last customer's cart still in it.
            preserveState: 'errors',
            // The override password is never kept, whatever the answer.
            onFinish: () => setData('override_password', ''),
            onSuccess: (page) => {
                // The slip opens in its own tab. A browser that blocks it still
                // has the Print slip link on the fresh counter.
                const sale = (page.props as { lastSale?: LastSale | null }).lastSale;

                if (sale?.slip_url) window.open(sale.slip_url, '_blank');
            },
        });
    };

    // ⭐ 25 Oct 2026 (Q11 = A): the last credit refusal, kept while the
    // Override box is filled in.
    const [creditMessage, setCreditMessage] = useState<string | null>(null);
    const creditError = (errors as Record<string, string | undefined>).credit;
    useEffect(() => {
        if (creditError) setCreditMessage(creditError);
    }, [creditError]);

    const notChosen = data.money_account_id === '';
    const lineError = (index: number) =>
        errors[`lines.${index}.product_id` as keyof typeof errors] ??
        errors[`lines.${index}.quantity` as keyof typeof errors] ??
        errors[`lines.${index}.unit_price` as keyof typeof errors] ??
        errors[`lines.${index}.discount` as keyof typeof errors] ??
        errors[`lines.${index}.override_reason` as keyof typeof errors];

    const customerOptions = customers.map((c) => ({
        value: String(c.id),
        label: `${c.name}${c.is_system ? ` (${t('pos.default')})` : ''}`,
        hint: c.phone ?? undefined,
        // ⚠ Found by the number a regular gives at the counter, typed any way:
        // as stored, and in its matching form (01711..., without +880).
        keywords: [c.phone, c.phone_normalized, c.code].filter(Boolean).join(' '),
    }));

    const showSale = lastSale !== null && dismissedSale !== lastSale.invoice_id;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('pos.title')} />
            {/* ⚠⚠ NOT a <form>: Enter must never complete the sale. */}
            <div className="flex flex-col gap-4 p-4 lg:h-[calc(100vh-4rem)] lg:flex-row lg:overflow-hidden">
                {/* The catalogue: search, category chips, one card per product. */}
                <div className="flex min-w-0 flex-1 flex-col gap-3 lg:overflow-hidden">
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            ref={searchRef}
                            className="max-w-xs"
                            placeholder={t('pos.search_placeholder')}
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                setScanMessage(null);
                            }}
                            onKeyDown={onSearchKey}
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
                            {/* While browsing, only chips that can show a priced card. */}
                            {categories
                                .filter((c) => !browsing || products.some((p) => p.category_id === c.id && cardPrices[p.id] !== undefined))
                                .map((c) => (
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
                    {scanMessage && (
                        <p role="alert" className="text-destructive text-sm font-medium">
                            {scanMessage}
                        </p>
                    )}

                    {unpricedHidden > 0 && (
                        <p className="text-muted-foreground text-xs">{t('pos.unpriced_hidden', { count: String(unpricedHidden) })}</p>
                    )}
                    {/* ⭐ Phase 2 / 1B: market products with no confirmed price today, named,
                        so "where is the hilsa" has its answer on the screen. */}
                    {browsing && marketUnpriced.length > 0 && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            {t('market.hidden_unpriced', { names: marketUnpriced.join(', ') })}
                        </p>
                    )}

                    <div className="lg:overflow-y-auto">
                        {shown.length === 0 ? (
                            <p className="text-muted-foreground p-4 text-sm">{t('pos.no_match')}</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                {shown.map((p) => {
                                    const inCart = data.lines.some((l) => l.product_id === String(p.id));
                                    const price = cardPrices[p.id];
                                    // ⭐ F28: on hand in the "Sold from" warehouse.
                                    const stock = onHand[Number(data.warehouse_id)]?.[p.id] ?? '0';
                                    const out = Number(stock) <= 0;

                                    return (
                                        <button
                                            key={p.id}
                                            type="button"
                                            onClick={() => addProduct(p)}
                                            className={`rounded-lg border p-3 text-left transition-colors ${
                                                inCart
                                                    ? 'border-primary bg-primary/5'
                                                    : 'border-sidebar-border/70 dark:border-sidebar-border hover:border-primary/50'
                                            } ${out ? 'opacity-60' : ''}`}
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
                                            {/* ⭐ Phase 2 / 1B: the badge on a market product's card. */}
                                            {marketSince[p.id] !== undefined && (
                                                <p className="mt-0.5 inline-block rounded-full bg-sky-100 px-1.5 py-px text-[10.5px] font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                                    {t('market.todays_price_since', { time: marketSince[p.id] })}
                                                </p>
                                            )}
                                            <p className={`mt-0.5 text-xs tabular-nums ${out ? 'text-destructive' : 'text-muted-foreground'}`}>
                                                {out ? t('pos.none_here') : t('pos.on_hand', { qty: stock })}
                                            </p>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>

                {/* The order. Everything the sale needs, ending in one button. */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border flex w-full flex-col rounded-xl border lg:w-[26rem] lg:shrink-0 lg:overflow-y-auto">
                    {/* The sale just finished: its slip, and the change to hand back. */}
                    {showSale && (
                        <div className="border-border space-y-1 border-b bg-emerald-50 px-4 py-3 text-sm dark:bg-emerald-950/30">
                            <p className="font-medium">
                                {t('pos.sale_done', { number: lastSale!.invoice_number, total: money(Number(lastSale!.total)) })}
                            </p>
                            {lastSale!.on_account && <p>{t('pos.sale_on_account')}</p>}
                            {lastSale!.cash_given !== null && lastSale!.change !== null && (
                                <p className="text-base font-semibold">
                                    {t('pos.received_change', {
                                        received: money(Number(lastSale!.cash_given)),
                                        change: money(Number(lastSale!.change)),
                                    })}
                                </p>
                            )}
                            <div className="flex items-center gap-3 pt-1">
                                <a href={lastSale!.slip_url} target="_blank" rel="noreferrer" className="text-primary text-sm font-medium underline">
                                    {t('pos.print_slip')}
                                </a>
                                <button
                                    type="button"
                                    className="text-muted-foreground text-xs"
                                    onClick={() => setDismissedSale(lastSale!.invoice_id)}
                                >
                                    {t('pos.dismiss')}
                                </button>
                            </div>
                        </div>
                    )}

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
                        {/* ⚠ No open shift: cash has no till to go into, and the server
                            refuses a cash sale until one is open (26 Sep 2026). */}
                        {myTill === null && (
                            <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs dark:border-amber-800 dark:bg-amber-950/30">
                                {t('pos.no_open_till')}{' '}
                                <Link href={route('sales.shifts')} className="font-medium underline">
                                    {t('pos.open_shift_link')}
                                </Link>
                                . {t('pos.bank_still_works')}
                            </div>
                        )}
                        {myTill !== null && !tillUsable && (
                            <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs dark:border-amber-800 dark:bg-amber-950/30">
                                {t('pos.till_not_usable', { name: myTill.name ?? `#${myTill.money_account_id}` })}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1">
                                <Label htmlFor="customer" className="text-xs" required>
                                    {t('pos.customer')}
                                </Label>
                                <SearchableSelect
                                    id="customer"
                                    value={data.customer_id}
                                    onChange={changeCustomer}
                                    options={customerOptions}
                                    onCreate={
                                        canAddCustomer
                                            ? (typed) => {
                                                  setTypedCustomer(typed);
                                                  setAddingCustomer(true);
                                              }
                                            : undefined
                                    }
                                    createLabel="New customer"
                                />
                                {canAddCustomer && (
                                    <CustomerQuickCreate
                                        open={addingCustomer}
                                        onOpenChange={setAddingCustomer}
                                        initialName={typedCustomer}
                                        onCreated={(row) => router.reload({ only: ['customers'], onSuccess: () => changeCustomer(String(row.id)) })}
                                    />
                                )}
                                <InputError message={errors.customer_id} />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="warehouse" className="text-xs" required>
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
                                <Label htmlFor="money" className="text-xs" required>
                                    {t('pos.paid_into')}
                                </Label>
                                {/* ⚠ "On account" is a real choice, not a missing value: the
                                    invoice then stands open as a receivable. A walk-in is
                                    never offered it - nobody would know whom to ask. */}
                                <SearchSelect id="money" value={data.money_account_id} onChange={(e) => setData('money_account_id', e.target.value)}>
                                    <option value="">{t('pos.choose_paid_into')}</option>
                                    {moneyAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                    {!isWalkIn && <option value={ON_ACCOUNT}>{t('pos.on_account')}</option>}
                                </SearchSelect>
                                <InputError message={errors.money_account_id} />
                                {payingCash && tillUsable && selectedAccount!.id !== myTill!.money_account_id && (
                                    <p className="text-xs text-amber-700 dark:text-amber-400">
                                        {t('pos.not_your_till', { name: selectedAccount!.name, till: myTill!.name ?? '' })}
                                    </p>
                                )}
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
                                data.lines.map((line, index) => {
                                    const product = productsById.get(line.product_id);
                                    const changed = priceChanged(line);
                                    const discounted = (paisa(line.discount || '0') ?? 0) > 0;

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
                                                        inputMode="decimal"
                                                        value={line.quantity}
                                                        onChange={(e) => setQuantity(line.product_id, e.target.value, false)}
                                                        onBlur={() => setQuantity(line.product_id, line.quantity, true)}
                                                        aria-required
                                                        onKeyDown={(e) => {
                                                            // Enter settles the quantity and goes back to the
                                                            // search for the next scan. It never finishes the sale.
                                                            if (e.key === 'Enter') {
                                                                e.preventDefault();
                                                                setQuantity(line.product_id, line.quantity, true);
                                                                searchRef.current?.focus();
                                                            }
                                                        }}
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
                                                {/* ⚠ Read-only without the price-override permission: the
                                                    server refuses a changed price from such a person anyway,
                                                    and a box that looks editable is a promise it cannot keep. */}
                                                <div className="flex items-center gap-1">
                                                    <span className="text-muted-foreground text-xs">@</span>
                                                    <Input
                                                        className="h-8 w-24"
                                                        aria-label={t('pos.unit_price')}
                                                        inputMode="decimal"
                                                        value={line.unit_price}
                                                        readOnly={!canOverridePrice}
                                                        title={canOverridePrice ? undefined : t('pos.price_locked')}
                                                        onChange={(e) => patchLine(line.product_id, { unit_price: e.target.value, edited: '1' })}
                                                    />
                                                </div>
                                                {/* ⭐ 25 Oct 2026 (Q12 = A): Discount % per line for
                                                    everybody, held to the person's cap by the server. */}
                                                <div className="flex items-center gap-1">
                                                    <span className="text-muted-foreground text-xs">{t('pos.less')}</span>
                                                    <LineDiscount
                                                        compact
                                                        gross={(Number(line.quantity) || 0) * (Number(line.list_price || line.unit_price) || 0)}
                                                        value={line.discount === '0' ? '' : line.discount}
                                                        onChange={(taka) => patchLine(line.product_id, { discount: taka === '' ? '0' : taka })}
                                                        capPercent={discountCap}
                                                    />
                                                </div>
                                                <p className="ml-auto text-sm font-semibold tabular-nums">{money(lineNetPaisa(line) / 100)}</p>
                                            </div>
                                            {/* ⭐ Phase 2 / 1B: a market line wears its badge; other lines keep the plain explanation. */}
                                            {line.market_since ? (
                                                <p className="mt-1 inline-block rounded-full bg-sky-100 px-1.5 py-px text-[10.5px] font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">
                                                    {t('market.todays_price_since', { time: showDate(line.market_since) })}
                                                </p>
                                            ) : (
                                                line.explanation && <p className="text-muted-foreground mt-1 text-xs">{line.explanation}</p>
                                            )}
                                            {/* A changed price or a discount says what the list said,
                                                and asks why: the reason is kept on the invoice. */}
                                            {changed && line.list_price !== '' && (
                                                <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                                    {t('pos.list_price', { price: money(Number(line.list_price)) })}
                                                </p>
                                            )}
                                            {((canOverridePrice && changed) || discounted) && (
                                                <Input
                                                    className="mt-1 h-8"
                                                    aria-label={t('pos.override_reason_placeholder')}
                                                    placeholder={t('pos.override_reason_placeholder')}
                                                    value={line.override_reason}
                                                    onChange={(e) => patchLine(line.product_id, { override_reason: e.target.value })}
                                                    aria-required
                                                />
                                            )}
                                            {/* ⚠ Price, discount and reason too: a refusal on a key the
                                                page does not render looks like a dead button (audit #1). */}
                                            <InputError message={lineError(index)} />
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>

                    <div className="border-border mt-auto flex flex-col gap-2 border-t p-4">
                        <div className="text-muted-foreground flex items-center justify-between text-sm">
                            <span>{t('pos.subtotal')}</span>
                            <span className="tabular-nums">{money(subtotalPaisa / 100)}</span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <Label htmlFor="invoice_discount" className="text-muted-foreground text-sm font-normal">
                                {t('pos.invoice_discount')}
                            </Label>
                            <Input
                                id="invoice_discount"
                                className="h-8 w-24 text-right"
                                inputMode="decimal"
                                value={data.invoice_discount}
                                readOnly={!canOverridePrice}
                                title={canOverridePrice ? undefined : t('pos.price_locked')}
                                onChange={(e) => setData('invoice_discount', e.target.value)}
                            />
                        </div>
                        {canOverridePrice && billDiscounted && (
                            <Input
                                className="h-8"
                                aria-label={t('pos.discount_reason_placeholder')}
                                placeholder={t('pos.discount_reason_placeholder')}
                                value={data.discount_reason}
                                onChange={(e) => setData('discount_reason', e.target.value)}
                                aria-required
                            />
                        )}
                        <InputError message={errors.invoice_discount ?? errors.discount_reason} />
                        <div className="flex items-baseline justify-between">
                            <span className="text-sm font-medium">{t('pos.total')}</span>
                            <span className="text-2xl font-semibold tabular-nums">{money(totalPaisa / 100)}</span>
                        </div>

                        {/* Cash tendered, for the change only. Not money the books
                            record: the sale records its total, whatever was handed over. */}
                        {payingCash && (
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="cash_given" className="text-muted-foreground text-sm font-normal">
                                    {t('pos.cash_given')}
                                </Label>
                                <Input
                                    id="cash_given"
                                    className="h-8 w-28 text-right"
                                    inputMode="decimal"
                                    value={data.cash_given}
                                    onChange={(e) => setData('cash_given', e.target.value)}
                                />
                            </div>
                        )}
                        {cashGivenPaisa !== null && data.lines.length > 0 && (
                            <p className={`text-right text-sm font-semibold tabular-nums ${cashGivenPaisa < totalPaisa ? 'text-destructive' : ''}`}>
                                {cashGivenPaisa < totalPaisa
                                    ? t('pos.cash_short', { amount: money((totalPaisa - cashGivenPaisa) / 100) })
                                    : t('pos.change_due', { amount: money((cashGivenPaisa - totalPaisa) / 100) })}
                            </p>
                        )}
                        <InputError message={errors.cash_given} />

                        {/* C-SLS-E1. Where a refused sale says why: no price in force,
                            not enough stock, a closed month, a missing mapping. The
                            till is the one screen where all four can happen, and
                            before this every one of them arrived as a blank error. */}
                        <InputError message={errors.lines} />
                        {/* ⚠ Said again beside the button: a credit refusal is written
                            on "Paid into", which can be scrolled out of sight. */}
                        {/* ⭐ 25 Oct 2026 (Q11 = A): over the limit, Override is offered here. */}
                        {creditMessage !== null && data.money_account_id === ON_ACCOUNT ? (
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
                        ) : (
                            errors.money_account_id && <InputError message={errors.money_account_id} />
                        )}
                        {notChosen && data.lines.length > 0 && <p className="text-muted-foreground text-xs">{t('pos.choose_paid_into_first')}</p>}
                        {/* ⚠⚠ The ONLY way to finish a sale. */}
                        <Button
                            type="button"
                            onClick={finish}
                            disabled={processing || data.lines.length === 0 || notChosen || pendingPrices > 0}
                            size="lg"
                            className="w-full"
                        >
                            {data.money_account_id === ON_ACCOUNT ? t('pos.finish_on_account') : t('pos.take_payment')}
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
            </div>
        </AppLayout>
    );
}
