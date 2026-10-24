import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { pricingSectionsFor } from '@/lib/market-pricing';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

/**
 * ⭐ ONE DIALOG FOR A PRODUCT AND ITS PRICES (21 Sep 2026). Before this a
 * sellable product took two screens and two permissions: the master record
 * here, then its price on Sales > Selling prices. Joy is re-entering the whole
 * catalogue by hand, so the common job is one form: name, unit, MRP, and what
 * each kind of buyer pays.
 *
 * ⚠⚠ Prices are ENTERED, never generated (his rule): a group left at "Not
 * sold" cannot be sold to; only a group that falls back to MRP (Retail) pays
 * MRP when nothing is set. The product is saved first (Inventory), then its
 * prices beside it (Sales), because Inventory references nothing in Sales.
 *
 * ⚠ The hand-written-fetch rules this app lives by: the XSRF token from the
 * cookie, and X-Business-Id on every write (the stale-tab guard).
 *
 * ⭐ Inventory redesign (15 Oct 2026): the dialog takes its KIND from the
 * screen it opened on. From Raw materials it is "New raw material", the form
 * is "What it is / How it is stocked", the type is fixed to raw material, and
 * nothing that belongs to selling (MRP, weight, prices, brand, barcode, sell
 * when out of stock, lead time) is drawn or fetched: a raw material is never
 * sold, so /sales/group-prices is not asked. From Products the third section,
 * "How it sells", holds all of that.
 */

export interface DialogProduct {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    mrp: string | null;
    // ⭐ Phase 2 / 1B (17 Oct 2026): fixed_mrp or market, and this SKU's rule
    // overrides (null = inherits the category's).
    pricing_mode?: 'fixed_mrp' | 'market';
    market_markup_percent?: string | null;
    market_min_margin_percent?: string | null;
    market_rounding_step?: string | null;
    type: string;
    category_id: number | null;
    brand_id: number | null;
    unit_of_measure_id: number;
    tax_class_id: number | null;
    description: string | null;
    low_stock_threshold: string | null;
    content_quantity: string | null;
    content_unit_id: number | null;
    is_active: boolean;
    availability_override: string | null;
    lead_time_days: number | null;
    tracks_expiry?: boolean;
    shelf_life_warning_days?: number | null;
    /** PR2 (18 Oct 2026): days from making to expiry; a new batch's expiry defaults from it. */
    shelf_life_days?: number | null;
    weight_grams?: number | null;
    weight_source?: string | null;
    // ⚠ Once stock has moved, the unit and the service choice are fixed (the server refuses them too).
    has_stock_history?: boolean;
    // Phase 1 Part B (17 Oct 2026): a count stock unit may be split only when this is on.
    allows_fractional_stock?: boolean;
    // ⭐ Initial plan B4 (24 Oct 2026): is it for sale, raw or packaging, its usual supplier, made here.
    can_be_sold?: boolean;
    material_kind?: 'raw' | 'packaging' | null;
    default_supplier_id?: number | null;
    made_here?: boolean;
}

interface Option {
    id: number;
    name: string;
    /** A category's market pricing defaults (Phase 2 / 1B), for the "inherits" hints. */
    market_markup_percent?: string | null;
    market_min_margin_percent?: string | null;
    market_rounding_step?: string | null;
}

interface UnitOption extends Option {
    abbreviation: string;
    /** Phase 1 Part B: mass | volume | count | null. Decides whether the fractional switch is offered. */
    dimension?: 'mass' | 'volume' | 'count' | null;
}

interface GroupPrice {
    id: number;
    code: string;
    name: string;
    falls_back_to_mrp: boolean;
    method: 'none' | 'fixed' | 'percent_off_mrp' | string;
    percent_off: string | null;
    unit_price: string | null;
}

interface PriceRow {
    method: string;
    value: string;
}

const PRODUCT_TYPES = [
    { value: 'finished_good', label: 'Finished good (we sell it)' },
    { value: 'raw_material', label: 'Raw material (we make things from it)' },
    { value: 'service', label: 'Service' },
];

const EMPTY = {
    sku: '',
    barcode: '',
    name: '',
    mrp: '',
    // ⭐ Phase 2 / 1B (17 Oct 2026): how it is priced; blank rule boxes inherit.
    pricing_mode: 'fixed_mrp',
    market_markup_percent: '',
    market_min_margin_percent: '',
    market_rounding_step: '',
    // ⚠ Most things this business keys in are goods it sells, so that is the
    // default; it was Raw material, which every shop product then had to change.
    type: 'finished_good',
    category_id: '',
    brand_id: '',
    unit_of_measure_id: '',
    tax_class_id: '',
    description: '',
    low_stock_threshold: '',
    content_quantity: '',
    content_unit_id: '',
    availability_override: '',
    lead_time_days: '',
    tracks_expiry: false,
    shelf_life_warning_days: '',
    shelf_life_days: '',
    // Courier hub slice 3 (11 Oct 2026): one unit as it ships, whole grams.
    weight_grams: '',
    allows_fractional_stock: false,
    is_active: true,
    // ⭐ B4 (24 Oct 2026): a finished good is for sale by default; the type
    // picker switches it off for a raw material (the server's default too).
    can_be_sold: true,
    material_kind: '',
    default_supplier_id: '',
};

type Form = typeof EMPTY;

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** "520.0000" -> "520", "12.5000" -> "12.5": what a person would type. */
function trimmed(value: string | null): string {
    if (value === null || value === '') return '';
    const number = parseFloat(value);
    return Number.isFinite(number) ? String(number) : value;
}

/** The small upper-case heading of a form section: what it is, how it is stocked, how it sells. */
function SectionLabel({ children }: { children: ReactNode }) {
    return <h3 className="text-muted-foreground text-[11.5px] font-semibold tracking-wide uppercase">{children}</h3>;
}

function money(value: number): string {
    return value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** The price a row comes to, worked out the way the server works it out (paisa, half up). */
function resultingPrice(row: PriceRow, mrp: string): number | null {
    const value = parseFloat(row.value);
    if (row.method === 'fixed') return Number.isFinite(value) && value > 0 ? value : null;
    if (row.method === 'percent_off_mrp') {
        const base = parseFloat(mrp);
        if (!Number.isFinite(base) || base <= 0 || !Number.isFinite(value) || value <= 0 || value >= 100) return null;
        return Math.floor(base * (100 - value) + 0.5) / 100;
    }
    return null;
}

export function ProductDialog({
    open,
    onOpenChange,
    product,
    categories,
    brands,
    units,
    taxClasses,
    suppliers = [],
    kind = 'products',
    onReloading,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product: DialogProduct | null;
    categories: Option[];
    brands: (Option & { code: string })[];
    units: UnitOption[];
    taxClasses: Option[];
    /** B4: the active suppliers a usual supplier may be chosen from. */
    suppliers?: Option[];
    // ⭐ 27 Sep 2026 (Joy): a new product opened from the Raw materials screen
    // is a raw material; from the Products screen, a finished good.
    kind?: 'products' | 'raw';
    /** True while the list reloads after a save, false once it is back (practice run F6). */
    onReloading?: (reloading: boolean) => void;
}) {
    const isRaw = kind === 'raw';
    const defaultType = isRaw ? 'raw_material' : 'finished_good';
    const { current_business_id } = usePage<SharedData>().props;
    const [form, setForm] = useState<Form>(EMPTY);
    const { t } = useTranslation();
    const [errors, setErrors] = useState<Record<string, string>>({});
    // ⭐ B13 (24 Oct 2026): "Possible duplicate" from the 422 body, with its ack.
    const [duplicate, setDuplicate] = useState<SharedData['duplicateWarning'] | null>(null);
    const [groups, setGroups] = useState<GroupPrice[] | null>(null);
    const [prices, setPrices] = useState<Record<number, PriceRow>>({});
    const [otherRules, setOtherRules] = useState(0);
    // F21 (practice run, 17 Oct 2026): what one unit last cost, for a warning
    // (never a block) on a group price below it. Null for people who may not
    // read costs, and for a product never stocked.
    const [lastCost, setLastCost] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [skuHint, setSkuHint] = useState<string | null>(null);
    const [proposing, setProposing] = useState(false);
    const [showMore, setShowMore] = useState(false);
    // ⭐ B4 (24 Oct 2026; R3, R16): a new product's SKU fills itself from the
    // proposal until the person types one of their own; after that it is theirs.
    const [skuTyped, setSkuTyped] = useState(false);

    const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((current) => ({ ...current, [key]: value }));
    const stockLocked = product?.has_stock_history === true;
    // ⭐ Phase 2 / 1B: a market product hides the MRP box (and, 18 Oct 2026, the group price table).
    const isMarket = form.pricing_mode === 'market';
    const sections = pricingSectionsFor(form.pricing_mode);
    const chosenUnit = units.find((unit) => String(unit.id) === form.unit_of_measure_id);

    // Fill the form, then ask Sales what each group pays. A 403 simply means
    // this person does not set prices, and the section stays away.
    useEffect(() => {
        if (!open) return;
        setErrors({});
        setSkuHint(null);
        setShowMore(false);
        setSkuTyped(false);
        setForm(
            product === null
                ? { ...EMPTY, type: defaultType, can_be_sold: defaultType !== 'raw_material' }
                : {
                      sku: product.sku,
                      barcode: product.barcode ?? '',
                      name: product.name,
                      mrp: trimmed(product.mrp),
                      pricing_mode: product.pricing_mode ?? 'fixed_mrp',
                      market_markup_percent: trimmed(product.market_markup_percent ?? null),
                      market_min_margin_percent: trimmed(product.market_min_margin_percent ?? null),
                      market_rounding_step: trimmed(product.market_rounding_step ?? null),
                      type: product.type,
                      category_id: product.category_id ? String(product.category_id) : '',
                      brand_id: product.brand_id ? String(product.brand_id) : '',
                      unit_of_measure_id: String(product.unit_of_measure_id),
                      tax_class_id: product.tax_class_id === null ? '' : String(product.tax_class_id),
                      description: product.description ?? '',
                      // "5.0000" as stored reads "5" in the box (practice run F5).
                      low_stock_threshold: trimmed(product.low_stock_threshold),
                      content_quantity: trimmed(product.content_quantity),
                      content_unit_id: product.content_unit_id === null ? '' : String(product.content_unit_id),
                      availability_override: product.availability_override ?? '',
                      lead_time_days: product.lead_time_days === null ? '' : String(product.lead_time_days),
                      tracks_expiry: product.tracks_expiry ?? false,
                      shelf_life_warning_days:
                          product.shelf_life_warning_days === null || product.shelf_life_warning_days === undefined
                              ? ''
                              : String(product.shelf_life_warning_days),
                      shelf_life_days:
                          product.shelf_life_days === null || product.shelf_life_days === undefined ? '' : String(product.shelf_life_days),
                      weight_grams: product.weight_grams === null || product.weight_grams === undefined ? '' : String(product.weight_grams),
                      allows_fractional_stock: product.allows_fractional_stock ?? false,
                      is_active: product.is_active,
                      can_be_sold: product.can_be_sold ?? product.type !== 'raw_material',
                      material_kind: product.material_kind ?? '',
                      default_supplier_id: product.default_supplier_id ? String(product.default_supplier_id) : '',
                  },
        );

        setGroups(null);
        // A raw material is never sold: no prices to show, none to fetch.
        if (isRaw) return;
        const query = product ? `?product_id=${product.id}` : '';
        fetch(`/sales/group-prices${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { groups: GroupPrice[]; other_rules: number; last_cost?: string | null } | null) => {
                if (body === null) return;
                setGroups(body.groups);
                setOtherRules(body.other_rules);
                setLastCost(body.last_cost ? parseFloat(body.last_cost) : null);
                setPrices(
                    Object.fromEntries(
                        body.groups.map((group) => [
                            group.id,
                            {
                                method: group.method === 'fixed' || group.method === 'percent_off_mrp' ? group.method : 'none',
                                value: trimmed(group.method === 'percent_off_mrp' ? group.percent_off : group.unit_price),
                            },
                        ]),
                    ),
                );
            })
            .catch(() => setGroups(null));
    }, [open, product, defaultType, isRaw]);

    const pricesChanged = useMemo(() => {
        if (groups === null) return false;
        return groups.some((group) => {
            const row = prices[group.id];
            const was = group.method === 'fixed' || group.method === 'percent_off_mrp' ? group.method : 'none';
            const wasValue = group.method === 'percent_off_mrp' ? (group.percent_off ?? '') : (group.unit_price ?? '');
            if (!row) return false;
            // A "% off MRP" price moves when the MRP does, so it is re-saved then.
            if (row.method === 'percent_off_mrp' && form.mrp !== trimmed(product?.mrp ?? null)) return true;
            return row.method !== was || (row.method !== 'none' && parseFloat(row.value) !== parseFloat(wasValue));
        });
    }, [groups, prices, form.mrp, product]);

    const proposeSku = async (auto = false) => {
        if (form.name.trim() === '') {
            if (!auto) setSkuHint('Type the product name first: the SKU is built from it.');
            return;
        }
        setProposing(true);
        setSkuHint(null);
        try {
            const query = new URLSearchParams({ name: form.name, type: form.type });
            if (form.brand_id) query.set('brand_id', form.brand_id);
            if (form.category_id) query.set('category_id', form.category_id);
            if (product) query.set('product_id', String(product.id));
            const response = await fetch(`${route('inventory.products.sku-proposal')}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                setSkuHint('Could not build a SKU just now. Type one in.');
                return;
            }
            const result: { sku: string | null; reason: string | null; note: string | null } = await response.json();
            if (result.sku === null) {
                setSkuHint(result.reason);
                return;
            }
            set('sku', result.sku);
            setSkuHint(
                [
                    auto ? t('ipc.sku.auto') : null,
                    result.note,
                    product ? 'Its SKU is what imports and price lists match on, so change it only if you mean to.' : null,
                ]
                    .filter(Boolean)
                    .join(' ') || null,
            );
        } finally {
            setProposing(false);
        }
    };

    // ⭐ B4 (24 Oct 2026; R3, R16): a NEW product's SKU builds itself as the
    // name, type, brand and category are filled in, until the person types
    // their own. An existing product's SKU is its identity (imports and price
    // lists match on it), so it only changes when somebody asks.
    useEffect(() => {
        if (!open || product !== null || skuTyped || form.name.trim() === '') return;
        const timer = window.setTimeout(() => void proposeSku(true), 600);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, product, skuTyped, form.name, form.type, form.brand_id, form.category_id]);

    const send = async (url: string, method: 'POST' | 'PUT', body: unknown) =>
        fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                ...(current_business_id ? { 'X-Business-Id': String(current_business_id) } : {}),
            },
            body: JSON.stringify(body),
        });

    const flatten = (bag: Record<string, string[] | string>): Record<string, string> =>
        Object.fromEntries(Object.entries(bag).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]));

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        await save(null);
    };

    const save = async (ack: string | null) => {
        setSaving(true);
        setErrors({});
        setDuplicate(null);

        try {
            const payload = { ...form, is_active: product ? form.is_active : true, duplicate_ack: ack };
            const saved = await send(
                product ? route('inventory.products.update', product.id) : route('inventory.products.store'),
                product ? 'PUT' : 'POST',
                payload,
            );

            if (saved.status === 422) {
                const body = await saved.json();
                setErrors(flatten(body.errors ?? {}));
                setDuplicate(body.duplicate ?? null);
                if (
                    Object.keys(body.errors ?? {}).some((key) =>
                        [
                            'description',
                            'low_stock_threshold',
                            'tax_class_id',
                            'content_quantity',
                            'content_unit_id',
                            'lead_time_days',
                            'availability_override',
                            'tracks_expiry',
                            'shelf_life_warning_days',
                            'shelf_life_days',
                        ].includes(key),
                    )
                ) {
                    setShowMore(true);
                }
                return;
            }

            // INV-005 (14 Oct 2026): a refusal is not a fault to retry.
            if (saved.status === 403) {
                setErrors({ form: t('stock.product.not_allowed') });
                return;
            }

            if (!saved.ok) {
                setErrors({ form: 'The product could not be saved. Reload the page and try again.' });
                return;
            }

            const { id } = (await saved.json()) as { id: number };

            // ⚠ 18 Oct 2026 (P16): a market product's group table is hidden, so it is never saved from here.
            if (groups !== null && pricesChanged && sections.groupPrices) {
                const priced = await send(`/sales/group-prices/${id}`, 'PUT', {
                    prices: groups.map((group) => ({
                        price_group_id: group.id,
                        method: prices[group.id]?.method ?? 'none',
                        value: prices[group.id]?.value === '' ? null : prices[group.id]?.value,
                    })),
                });

                if (!priced.ok) {
                    const body = priced.status === 422 ? await priced.json() : { errors: {} };
                    setErrors({
                        ...flatten(body.errors ?? {}),
                        form: 'The product was saved, but its prices were not. Fix the prices below and save again.',
                    });
                    router.reload({ only: ['products', 'counts'] });
                    return;
                }
            }

            // 17 Oct 2026: a save that changes the Type moves the item to the
            // other screen, and the list it was just on reloads without it. Say
            // where it went, with a way there, instead of letting it vanish.
            const belongsHere = isRaw ? form.type === 'raw_material' : form.type !== 'raw_material';

            if (belongsHere) {
                toast.success(product ? `${form.name} saved.` : `${form.name} added.`);
            } else {
                toast.success(isRaw ? 'Saved. It now lives under Products.' : 'Saved. It now lives under Raw materials.', {
                    duration: 10000,
                    action: {
                        label: isRaw ? 'Open Products' : 'Open Raw materials',
                        onClick: () => router.visit(route(isRaw ? 'inventory.products.index' : 'inventory.raw-materials.index')),
                    },
                });
            }
            onOpenChange(false);
            // ⚠ 'counts' with the list (practice run F2/F8, 17 Oct 2026): the header
            // note and the chips read "3 raw materials" and "All 3" beside a list of 4.
            onReloading?.(true);
            router.reload({ only: ['products', 'counts'], onFinish: () => onReloading?.(false) });
        } finally {
            setSaving(false);
        }
    };

    // The server refuses a % off MRP price while MRP is empty (SetProductGroupPrices), and a
    // priced row with no amount; net content is a pair (quantity and unit, or neither).
    const priceRows = groups === null ? [] : groups.map((group) => prices[group.id]?.method ?? 'none');
    const mrpRequired = priceRows.includes('percent_off_mrp');
    const amountRequired = priceRows.some((method) => method !== 'none');
    const contentRequired = !!form.content_quantity || !!form.content_unit_id;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>
                            {product ? (isRaw ? 'Edit raw material' : `Edit ${product.name}`) : isRaw ? 'New raw material' : 'New product'}
                        </DialogTitle>
                        <p className="text-muted-foreground text-xs">
                            {isRaw
                                ? 'Something we make things from. It is bought, stocked and consumed, never sold.'
                                : 'Something we sell. Its prices are saved beside it.'}
                        </p>
                        {/* ⭐ B4 (R7): "Made here" follows from a recipe in use; it is never typed. */}
                        {product?.made_here && (
                            <p className="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                                {t('ipc.made_here')}: {t('ipc.made_here_hint')}
                            </p>
                        )}
                    </DialogHeader>

                    <SectionLabel>What it is</SectionLabel>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="pd-name" required>
                                Name
                            </Label>
                            <Input
                                id="pd-name"
                                aria-required
                                value={form.name}
                                onChange={(e) => set('name', e.target.value)}
                                placeholder={isRaw ? 'e.g. Ghee (bulk)' : 'e.g. Mangrove Gold Honey 500g'}
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between gap-2">
                                <Label htmlFor="pd-sku" required>
                                    SKU
                                </Label>
                                <button
                                    type="button"
                                    onClick={() => void proposeSku()}
                                    disabled={proposing}
                                    className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2 disabled:opacity-50"
                                >
                                    {proposing ? 'working' : 'build it for me'}
                                </button>
                            </div>
                            <Input
                                id="pd-sku"
                                aria-required
                                value={form.sku}
                                onChange={(e) => {
                                    set('sku', e.target.value);
                                    setSkuTyped(true);
                                }}
                                placeholder={isRaw ? 'RM-GHE-BULK' : 'LLD-HON-MANG-500G'}
                                className="font-mono"
                            />
                            <InputError message={errors.sku} />
                            {skuHint && <p className="text-muted-foreground text-xs">{skuHint}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-category">Category</Label>
                            <SearchSelect
                                id="pd-category"
                                value={form.category_id}
                                onChange={(e) => set('category_id', e.target.value)}
                                quickCreate={{ kind: 'category', reload: ['categories'] }}
                            >
                                <option value="">None</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        {/* Type is fixed to raw material on the Raw materials screen: the
                            tab already said what this is. */}
                        {!isRaw && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-type" required>
                                    Type
                                </Label>
                                <SearchSelect
                                    id="pd-type"
                                    value={form.type}
                                    onChange={(e) => {
                                        const type = e.target.value;
                                        // B4: a new raw material starts not for sale, anything else for sale.
                                        setForm((current) => ({ ...current, type, ...(product ? {} : { can_be_sold: type !== 'raw_material' }) }));
                                    }}
                                >
                                    {PRODUCT_TYPES.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                            disabled={stockLocked && (type.value === 'service') !== (product?.type === 'service')}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                                {stockLocked && (
                                    <p className="text-muted-foreground text-xs">
                                        Has stock movements, so it cannot switch between stocked and service.
                                    </p>
                                )}
                                <InputError message={errors.type} />
                            </div>
                        )}
                        {!isRaw && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-brand">Brand</Label>
                                <SearchSelect
                                    id="pd-brand"
                                    value={form.brand_id}
                                    onChange={(e) => set('brand_id', e.target.value)}
                                    quickCreate={{ kind: 'brand', reload: ['brands'] }}
                                >
                                    <option value="">None</option>
                                    {brands.map((brand) => (
                                        <option key={brand.id} value={brand.id}>
                                            {brand.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.brand_id} />
                            </div>
                        )}
                        {!isRaw && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-barcode">Barcode</Label>
                                <Input
                                    id="pd-barcode"
                                    value={form.barcode}
                                    onChange={(e) => set('barcode', e.target.value)}
                                    placeholder="scan or type; optional"
                                    className="w-full"
                                />
                                <InputError message={errors.barcode} />
                            </div>
                        )}
                    </div>

                    {/* ⭐ Initial plan B4 (Joy, 24 Oct 2026; R17, R18, R22). */}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {form.type === 'raw_material' && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-kind">{t('ipc.kind.label')}</Label>
                                <SearchSelect id="pd-kind" fit value={form.material_kind} onChange={(e) => set('material_kind', e.target.value)}>
                                    <option value="">{t('ipc.kind.raw')}</option>
                                    <option value="packaging">{t('ipc.kind.packaging')}</option>
                                </SearchSelect>
                                <InputError message={errors.material_kind} />
                            </div>
                        )}
                        {form.type !== 'service' && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-supplier">{t('ipc.supplier.label')}</Label>
                                <SearchSelect
                                    id="pd-supplier"
                                    fit
                                    value={form.default_supplier_id}
                                    onChange={(e) => set('default_supplier_id', e.target.value)}
                                >
                                    <option value="">{t('ipc.supplier.none')}</option>
                                    {suppliers.map((supplier) => (
                                        <option key={supplier.id} value={supplier.id}>
                                            {supplier.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <p className="text-muted-foreground text-xs">{t('ipc.supplier.hint')}</p>
                                <InputError message={errors.default_supplier_id} />
                            </div>
                        )}
                        <div className="grid gap-1 sm:col-span-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.can_be_sold} onChange={(e) => set('can_be_sold', e.target.checked)} />
                                {t('ipc.sale.label')}
                            </label>
                            <p className="text-muted-foreground text-xs">
                                {form.type === 'raw_material' ? t('ipc.sale.hint_raw') : t('ipc.sale.hint_product')}
                            </p>
                            <InputError message={errors.can_be_sold} />
                        </div>
                    </div>

                    <SectionLabel>How it is stocked</SectionLabel>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="grid gap-2">
                            {/* ⭐ Phase 1 Part B (17 Oct 2026): named as the STOCK unit, and the lock
                                said before it bites, not only after. */}
                            <Label htmlFor="pd-unit" required>
                                {t('units.stock_unit.label')}
                                <span className="text-muted-foreground ml-1 text-xs font-normal">({t('units.stock_unit.locked_note')})</span>
                            </Label>
                            <SearchSelect
                                id="pd-unit"
                                value={form.unit_of_measure_id}
                                onChange={(e) => set('unit_of_measure_id', e.target.value)}
                                quickCreate={{ kind: 'unit', reload: ['units'] }}
                                disabled={stockLocked}
                            >
                                <option value="">Select unit</option>
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name} ({unit.abbreviation})
                                    </option>
                                ))}
                            </SearchSelect>
                            {stockLocked && <p className="text-muted-foreground text-xs">{t('units.stock_unit.locked_now')}</p>}
                            {chosenUnit?.dimension === 'count' && (
                                <label className="flex items-start gap-2 text-xs">
                                    <input
                                        type="checkbox"
                                        className="mt-0.5"
                                        checked={form.allows_fractional_stock}
                                        onChange={(e) => set('allows_fractional_stock', e.target.checked)}
                                    />
                                    <span>
                                        {t('units.fractional.label')}
                                        <span className="text-muted-foreground block">{t('units.fractional.hint')}</span>
                                    </span>
                                </label>
                            )}
                            <InputError message={errors.allows_fractional_stock} />
                            {units.length === 0 && (
                                <p className="text-muted-foreground text-xs">
                                    No units yet. Open this list and choose &ldquo;New unit&rdquo; to add one.
                                </p>
                            )}
                            <InputError message={errors.unit_of_measure_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-low">Reorder when stock falls below</Label>
                            <Input
                                id="pd-low"
                                inputMode="decimal"
                                value={form.low_stock_threshold}
                                onChange={(e) => set('low_stock_threshold', e.target.value)}
                                placeholder="optional"
                            />
                            <InputError message={errors.low_stock_threshold} />
                        </div>
                        {/* ⭐ 26 Sep 2026 (Joy, Q8/Q9): which products must carry a batch and
                            an expiry when received, and when to warn about short shelf life. */}
                        <div className="grid gap-2 sm:col-span-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.tracks_expiry} onChange={(e) => set('tracks_expiry', e.target.checked)} />
                                Tracks expiry: a goods receipt must carry the batch number and expiry date
                            </label>
                            <InputError message={errors.tracks_expiry} />
                        </div>
                        {form.tracks_expiry && (
                            <div className="grid gap-2">
                                <Label htmlFor="pd-shelf">Warn when fewer days of shelf life are left</Label>
                                <Input
                                    id="pd-shelf"
                                    type="number"
                                    min={0}
                                    max={3650}
                                    value={form.shelf_life_warning_days}
                                    onChange={(e) => set('shelf_life_warning_days', e.target.value)}
                                    placeholder="90"
                                    className="w-28"
                                />
                                <InputError message={errors.shelf_life_warning_days} />
                            </div>
                        )}
                        {/* ⭐ PR2 (18 Oct 2026): Tally's shelf life. A new batch's expiry
                            defaults to its made-on date plus these days. */}
                        <div className="grid gap-2">
                            <Label htmlFor="pd-shelf-life">{t('batchno.shelf_life')}</Label>
                            <Input
                                id="pd-shelf-life"
                                type="number"
                                min={1}
                                max={3650}
                                value={form.shelf_life_days}
                                onChange={(e) => set('shelf_life_days', e.target.value)}
                                placeholder={t('batchno.optional')}
                                className="w-28"
                            />
                            <p className="text-muted-foreground text-xs">{t('batchno.shelf_life_hint')}</p>
                            <InputError message={errors.shelf_life_days} />
                        </div>
                    </div>

                    {!isRaw && (
                        <>
                            <SectionLabel>How it sells</SectionLabel>
                            {/* ⭐ Phase 2 / 1B (17 Oct 2026): the pricing mode decides whether the MRP
                                means anything. A market product is priced from each lot at goods
                                receipt and never from an MRP, so the MRP box steps aside. */}
                            <div className="grid gap-2">
                                <Label htmlFor="pd-pricing-mode">{t('market.mode')}</Label>
                                <SearchSelect
                                    id="pd-pricing-mode"
                                    value={form.pricing_mode}
                                    onChange={(e) => set('pricing_mode', e.target.value as Form['pricing_mode'])}
                                >
                                    <option value="fixed_mrp">{t('market.mode.fixed_mrp')}</option>
                                    <option value="market">{t('market.mode.market')}</option>
                                </SearchSelect>
                                {isMarket && <p className="text-muted-foreground text-xs">{t('market.mode_hint')}</p>}
                                <InputError message={errors.pricing_mode} />
                            </div>
                            {/* ⭐ 18 Oct 2026 (Joy, D-E; practice P16): no markup rules in Phase 1,
                                and a market product has no MRP and no group price table. The rule
                                columns stay in the database, ignored; the price is typed as today's
                                price at each goods receipt or on Selling prices. */}
                            {sections.todaysPriceNote && (
                                <p className="text-muted-foreground rounded-lg border border-dashed px-3 py-2 text-xs">
                                    {t('market.price_set_where')}
                                </p>
                            )}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {sections.mrp && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="pd-mrp" required={mrpRequired}>
                                            MRP (taka)
                                        </Label>
                                        <Input
                                            id="pd-mrp"
                                            aria-required={mrpRequired}
                                            type="number"
                                            inputMode="decimal"
                                            min={0}
                                            step="0.01"
                                            value={form.mrp}
                                            onChange={(e) => set('mrp', e.target.value)}
                                            placeholder="e.g. 520"
                                            className="w-full"
                                        />
                                        <InputError message={errors.mrp} />
                                    </div>
                                )}
                                {/* ⭐ Courier hub slice 3 (Joy, 11 Oct 2026): a parcel's weight is
                                    worked out from this, so every product needs one. */}
                                <div className="grid gap-2">
                                    <Label htmlFor="pd-weight">Weight (g)</Label>
                                    <Input
                                        id="pd-weight"
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        step={1}
                                        value={form.weight_grams}
                                        onChange={(e) => set('weight_grams', e.target.value)}
                                        placeholder="one unit as it ships"
                                        className="w-full"
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        {product?.weight_source === 'from_net_content' && form.weight_grams === String(product.weight_grams)
                                            ? 'Taken from the net content. Weigh one packed unit and type it to correct it.'
                                            : 'Blank shows as weight missing. A new product with its net content in g or kg starts from that.'}
                                    </p>
                                    <InputError message={errors.weight_grams} />
                                </div>
                            </div>
                        </>
                    )}

                    {isRaw && (
                        <p className="text-muted-foreground rounded-lg border border-dashed px-3 py-2 text-xs">
                            No prices here: a raw material is never sold. What it costs comes from the goods receipt that brings it in.
                        </p>
                    )}

                    {groups !== null && sections.groupPrices && (
                        <section className="grid gap-2">
                            <h3 className="text-sm font-semibold">Prices</h3>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[34rem] text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left">
                                            <th className="py-1.5 font-normal">Sells to</th>
                                            <th className="py-1.5 font-normal">
                                                Price is
                                                <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                    *
                                                </span>
                                            </th>
                                            <th className="py-1.5 font-normal">
                                                Amount
                                                {amountRequired && (
                                                    <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                                        *
                                                    </span>
                                                )}
                                            </th>
                                            <th className="py-1.5 text-right font-normal">They pay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {groups.map((group) => {
                                            const row = prices[group.id] ?? { method: 'none', value: '' };
                                            const result = resultingPrice(row, form.mrp);
                                            const mrp = parseFloat(form.mrp);
                                            // What this group would pay, MRP when it falls back to it.
                                            const pays =
                                                row.method === 'none'
                                                    ? group.falls_back_to_mrp && Number.isFinite(mrp) && mrp > 0
                                                        ? mrp
                                                        : null
                                                    : result;
                                            const below = lastCost !== null && pays !== null && pays < lastCost;
                                            const setRow = (next: Partial<PriceRow>) =>
                                                setPrices((current) => ({ ...current, [group.id]: { ...row, ...next } }));
                                            return (
                                                <tr key={group.id} className="border-b last:border-0">
                                                    <td className="py-1.5 pr-2">{group.name}</td>
                                                    <td className="py-1.5 pr-2">
                                                        <select
                                                            aria-label={`${group.name} price`}
                                                            aria-required
                                                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                                            value={row.method}
                                                            onChange={(e) => setRow({ method: e.target.value, value: '' })}
                                                        >
                                                            <option value="none">{group.falls_back_to_mrp ? 'MRP (default)' : 'Not sold'}</option>
                                                            <option value="fixed">A fixed price</option>
                                                            <option value="percent_off_mrp">% off MRP</option>
                                                        </select>
                                                    </td>
                                                    <td className="py-1.5 pr-2">
                                                        {row.method !== 'none' && (
                                                            <Input
                                                                aria-label={`${group.name} amount`}
                                                                aria-required
                                                                type="number"
                                                                inputMode="decimal"
                                                                step="0.01"
                                                                min={0}
                                                                className="h-9 w-28"
                                                                value={row.value}
                                                                onChange={(e) => setRow({ value: e.target.value })}
                                                                placeholder={row.method === 'fixed' ? 'taka' : '%'}
                                                            />
                                                        )}
                                                        <InputError
                                                            message={errors[`prices.${group.id}.value`] ?? errors[`prices.${group.id}.method`]}
                                                        />
                                                    </td>
                                                    <td className="py-1.5 text-right tabular-nums">
                                                        {row.method === 'none' ? (
                                                            group.falls_back_to_mrp && Number.isFinite(mrp) && mrp > 0 ? (
                                                                money(mrp)
                                                            ) : (
                                                                <span className="text-muted-foreground">-</span>
                                                            )
                                                        ) : result === null ? (
                                                            <span className="text-muted-foreground">-</span>
                                                        ) : (
                                                            money(result)
                                                        )}
                                                        {below && (
                                                            <p className="text-xs font-normal text-amber-700 dark:text-amber-400">
                                                                Below the last cost of {money(lastCost)}: you lose {money(lastCost - pays)} a unit
                                                            </p>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            <InputError message={errors.prices} />
                            {otherRules > 0 && (
                                <p className="text-muted-foreground text-xs">
                                    This product also has {otherRules} quantity break, customer or discount price{otherRules === 1 ? '' : 's'}. Those
                                    stay as they are; manage them on Sales &gt; Selling prices.
                                </p>
                            )}
                        </section>
                    )}

                    <section className="grid gap-3">
                        <button
                            type="button"
                            className="w-fit text-sm font-semibold underline-offset-2 hover:underline"
                            onClick={() => setShowMore((v) => !v)}
                            aria-expanded={showMore}
                        >
                            {showMore
                                ? 'Less'
                                : isRaw
                                  ? 'More (description, VAT class, net content)'
                                  : 'More (description, VAT class, net content, sell when out of stock, lead time)'}
                        </button>
                        {showMore && (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label htmlFor="pd-description">Description</Label>
                                    <Input
                                        id="pd-description"
                                        value={form.description}
                                        onChange={(e) => set('description', e.target.value)}
                                        placeholder="optional"
                                    />
                                    <InputError message={errors.description} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="pd-tax">VAT class</Label>
                                    <SearchSelect id="pd-tax" value={form.tax_class_id} onChange={(e) => set('tax_class_id', e.target.value)}>
                                        <option value="">Not classified</option>
                                        {taxClasses.map((taxClass) => (
                                            <option key={taxClass.id} value={taxClass.id}>
                                                {taxClass.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    <InputError message={errors.tax_class_id} />
                                </div>
                                {!isRaw && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="pd-availability">Sell when out of stock</Label>
                                        <SearchSelect
                                            id="pd-availability"
                                            value={form.availability_override}
                                            onChange={(e) => set('availability_override', e.target.value)}
                                        >
                                            <option value="">As the channel decides</option>
                                            <option value="strict">Never</option>
                                        </SearchSelect>
                                        <InputError message={errors.availability_override} />
                                    </div>
                                )}
                                <div className="grid gap-2">
                                    <Label htmlFor="pd-content" required={contentRequired}>
                                        Net content per unit
                                    </Label>
                                    <div className="flex gap-2">
                                        <Input
                                            id="pd-content"
                                            aria-required={contentRequired}
                                            inputMode="decimal"
                                            value={form.content_quantity}
                                            onChange={(e) => set('content_quantity', e.target.value)}
                                            placeholder="e.g. 500"
                                        />
                                        <SearchSelect
                                            id="pd-content-unit"
                                            value={form.content_unit_id}
                                            onChange={(e) => set('content_unit_id', e.target.value)}
                                            quickCreate={{ kind: 'unit', reload: ['units'] }}
                                        >
                                            <option value="">unit</option>
                                            {units.map((unit) => (
                                                <option key={unit.id} value={unit.id}>
                                                    {unit.abbreviation}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </div>
                                    <InputError message={errors.content_quantity ?? errors.content_unit_id} />
                                </div>
                                {!isRaw && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="pd-lead">Made-to-order lead time (days)</Label>
                                        <Input
                                            id="pd-lead"
                                            type="number"
                                            min={1}
                                            max={365}
                                            value={form.lead_time_days}
                                            onChange={(e) => set('lead_time_days', e.target.value)}
                                            placeholder="not used by the shops"
                                            className="w-40"
                                        />
                                        <InputError message={errors.lead_time_days} />
                                    </div>
                                )}
                            </div>
                        )}
                    </section>

                    {product && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
                            Active (an inactive {isRaw ? 'raw material cannot be bought or consumed' : 'product cannot be sold or bought'}, and stays
                            on old documents)
                        </label>
                    )}

                    {errors.form && <p className="text-destructive text-sm font-medium">{errors.form}</p>}

                    <DuplicateWarning errors={errors} busy={saving} warning={duplicate} onSaveAnyway={(ack) => void save(ack)} />

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving}>{saving ? 'Saving' : product ? 'Save' : isRaw ? 'Add raw material' : 'Add product'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
