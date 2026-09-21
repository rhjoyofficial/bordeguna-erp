import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
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
 */

export interface DialogProduct {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    mrp: string | null;
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
}

interface Option {
    id: number;
    name: string;
}

interface UnitOption extends Option {
    abbreviation: string;
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
    is_active: true,
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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product: DialogProduct | null;
    categories: Option[];
    brands: (Option & { code: string })[];
    units: UnitOption[];
    taxClasses: Option[];
}) {
    const { current_business_id } = usePage<SharedData>().props;
    const [form, setForm] = useState<Form>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [groups, setGroups] = useState<GroupPrice[] | null>(null);
    const [prices, setPrices] = useState<Record<number, PriceRow>>({});
    const [otherRules, setOtherRules] = useState(0);
    const [saving, setSaving] = useState(false);
    const [skuHint, setSkuHint] = useState<string | null>(null);
    const [proposing, setProposing] = useState(false);
    const [showMore, setShowMore] = useState(false);

    const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((current) => ({ ...current, [key]: value }));

    // Fill the form, then ask Sales what each group pays. A 403 simply means
    // this person does not set prices, and the section stays away.
    useEffect(() => {
        if (!open) return;
        setErrors({});
        setSkuHint(null);
        setShowMore(false);
        setForm(
            product === null
                ? EMPTY
                : {
                      sku: product.sku,
                      barcode: product.barcode ?? '',
                      name: product.name,
                      mrp: trimmed(product.mrp),
                      type: product.type,
                      category_id: product.category_id ? String(product.category_id) : '',
                      brand_id: product.brand_id ? String(product.brand_id) : '',
                      unit_of_measure_id: String(product.unit_of_measure_id),
                      tax_class_id: product.tax_class_id === null ? '' : String(product.tax_class_id),
                      description: product.description ?? '',
                      low_stock_threshold: product.low_stock_threshold ?? '',
                      content_quantity: product.content_quantity ?? '',
                      content_unit_id: product.content_unit_id === null ? '' : String(product.content_unit_id),
                      availability_override: product.availability_override ?? '',
                      lead_time_days: product.lead_time_days === null ? '' : String(product.lead_time_days),
                      is_active: product.is_active,
                  },
        );

        setGroups(null);
        const query = product ? `?product_id=${product.id}` : '';
        fetch(`/sales/group-prices${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { groups: GroupPrice[]; other_rules: number } | null) => {
                if (body === null) return;
                setGroups(body.groups);
                setOtherRules(body.other_rules);
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
    }, [open, product]);

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

    const proposeSku = async () => {
        if (form.name.trim() === '') {
            setSkuHint('Type the product name first: the SKU is built from it.');
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
                [result.note, product ? 'Its SKU is what imports and price lists match on, so change it only if you mean to.' : null]
                    .filter(Boolean)
                    .join(' ') || null,
            );
        } finally {
            setProposing(false);
        }
    };

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
        setSaving(true);
        setErrors({});

        try {
            const payload = { ...form, is_active: product ? form.is_active : true };
            const saved = await send(
                product ? route('inventory.products.update', product.id) : route('inventory.products.store'),
                product ? 'PUT' : 'POST',
                payload,
            );

            if (saved.status === 422) {
                const body = await saved.json();
                setErrors(flatten(body.errors ?? {}));
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
                        ].includes(key),
                    )
                ) {
                    setShowMore(true);
                }
                return;
            }

            if (!saved.ok) {
                setErrors({ form: 'The product could not be saved. Reload the page and try again.' });
                return;
            }

            const { id } = (await saved.json()) as { id: number };

            if (groups !== null && pricesChanged) {
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
                    router.reload({ only: ['products'] });
                    return;
                }
            }

            toast.success(product ? `${form.name} saved.` : `${form.name} added.`);
            onOpenChange(false);
            router.reload({ only: ['products'] });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{product ? `Edit ${product.name}` : 'New product'}</DialogTitle>
                        <DialogDescription>Name, unit and MRP, then what each kind of buyer pays. Everything else is under More.</DialogDescription>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="pd-name" required>
                                Name
                            </Label>
                            <Input
                                id="pd-name"
                                value={form.name}
                                onChange={(e) => set('name', e.target.value)}
                                placeholder="e.g. Sundarban Honey 500g"
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-unit" required>
                                Unit
                            </Label>
                            <SearchSelect id="pd-unit" value={form.unit_of_measure_id} onChange={(e) => set('unit_of_measure_id', e.target.value)}>
                                <option value="">Select unit</option>
                                {units.map((unit) => (
                                    <option key={unit.id} value={unit.id}>
                                        {unit.name} ({unit.abbreviation})
                                    </option>
                                ))}
                            </SearchSelect>
                            {units.length === 0 && (
                                <p className="text-xs text-amber-600 dark:text-amber-500">
                                    No units yet. Add them under Inventory &gt; Units of measure first.
                                </p>
                            )}
                            <InputError message={errors.unit_of_measure_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-mrp">MRP (taka)</Label>
                            <Input
                                id="pd-mrp"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="0.01"
                                value={form.mrp}
                                onChange={(e) => set('mrp', e.target.value)}
                                placeholder="e.g. 520"
                                className="w-full"
                            />
                            <p className="text-muted-foreground text-xs">What a consumer or retail shop pays when no Retail price is set.</p>
                            <InputError message={errors.mrp} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-type" required>
                                Type
                            </Label>
                            <SearchSelect id="pd-type" value={form.type} onChange={(e) => set('type', e.target.value)}>
                                {PRODUCT_TYPES.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-brand">Brand</Label>
                            <SearchSelect id="pd-brand" value={form.brand_id} onChange={(e) => set('brand_id', e.target.value)}>
                                <option value="">None</option>
                                {brands.map((brand) => (
                                    <option key={brand.id} value={brand.id}>
                                        {brand.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.brand_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="pd-category">Category</Label>
                            <SearchSelect id="pd-category" value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                                <option value="">None</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </div>
                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between gap-2">
                                <Label htmlFor="pd-sku" required>
                                    SKU
                                </Label>
                                <button
                                    type="button"
                                    onClick={proposeSku}
                                    disabled={proposing}
                                    className="text-muted-foreground hover:text-foreground text-xs underline underline-offset-2 disabled:opacity-50"
                                >
                                    {proposing ? 'working' : 'build it for me'}
                                </button>
                            </div>
                            <Input id="pd-sku" value={form.sku} onChange={(e) => set('sku', e.target.value)} className="font-mono" />
                            <InputError message={errors.sku} />
                            {skuHint && <p className="text-muted-foreground text-xs">{skuHint}</p>}
                        </div>
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
                    </div>

                    {groups !== null && (
                        <section className="grid gap-2">
                            <div>
                                <h3 className="text-sm font-semibold">Prices</h3>
                                <p className="text-muted-foreground text-xs">
                                    A group marked "Not sold" cannot buy this product until you set its price. Changes start today; earlier sales keep
                                    the price they had.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[34rem] text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left">
                                            <th className="py-1.5 font-normal">Sells to</th>
                                            <th className="py-1.5 font-normal">Price is</th>
                                            <th className="py-1.5 font-normal">Amount</th>
                                            <th className="py-1.5 text-right font-normal">They pay</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {groups.map((group) => {
                                            const row = prices[group.id] ?? { method: 'none', value: '' };
                                            const result = resultingPrice(row, form.mrp);
                                            const mrp = parseFloat(form.mrp);
                                            const setRow = (next: Partial<PriceRow>) =>
                                                setPrices((current) => ({ ...current, [group.id]: { ...row, ...next } }));
                                            return (
                                                <tr key={group.id} className="border-b last:border-0">
                                                    <td className="py-1.5 pr-2">{group.name}</td>
                                                    <td className="py-1.5 pr-2">
                                                        <select
                                                            aria-label={`${group.name} price`}
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
                            {showMore ? 'Less' : 'More (description, reorder level, VAT class, content)'}
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
                                    <p className="text-muted-foreground text-xs">VAT is off. Recorded for later; changes nothing now.</p>
                                    <InputError message={errors.tax_class_id} />
                                </div>
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
                                <div className="grid gap-2">
                                    <Label htmlFor="pd-content">Net content per unit</Label>
                                    <div className="flex gap-2">
                                        <Input
                                            id="pd-content"
                                            inputMode="decimal"
                                            value={form.content_quantity}
                                            onChange={(e) => set('content_quantity', e.target.value)}
                                            placeholder="e.g. 500"
                                        />
                                        <SearchSelect
                                            id="pd-content-unit"
                                            value={form.content_unit_id}
                                            onChange={(e) => set('content_unit_id', e.target.value)}
                                        >
                                            <option value="">unit</option>
                                            {units.map((unit) => (
                                                <option key={unit.id} value={unit.id}>
                                                    {unit.abbreviation}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </div>
                                    <p className="text-muted-foreground text-xs">Only used by the production yield check.</p>
                                    <InputError message={errors.content_quantity ?? errors.content_unit_id} />
                                </div>
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
                                    />
                                    <InputError message={errors.lead_time_days} />
                                </div>
                            </div>
                        )}
                    </section>

                    {product && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.is_active} onChange={(e) => set('is_active', e.target.checked)} />
                            Active (an inactive product cannot be sold or bought, and stays on old documents)
                        </label>
                    )}

                    {errors.form && <p className="text-destructive text-sm font-medium">{errors.form}</p>}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button disabled={saving}>{saving ? 'Saving' : product ? 'Save' : 'Add product'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
