import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * The coupons this shop offers (website slice 2.5b).
 *
 * ⚠ Writing a coupon needs permission to manage selling prices: a coupon decides
 * what a customer pays. Everybody who may view the shop can read this screen.
 *
 * ⚠ "Used" is counted from orders on every view, and a cancelled or rejected
 * order does not count. There is no counter here to drift.
 *
 * ⚠ The discount a coupon gives, and every rule about when it applies, is worked
 * out by the server. This screen describes a coupon; it never prices one.
 */

interface Coupon {
    id: number;
    code: string;
    type: 'percent' | 'fixed';
    value: string;
    min_purchase: string | null;
    usage_limit: number | null;
    limit_per_customer: number | null;
    starts_at: string | null;
    ends_at: string | null;
    is_active: boolean;
    is_public: boolean;
    applies_to: 'all' | 'products' | 'combos';
    product_ids: number[];
    combo_ids: number[];
    is_free_delivery: boolean;
    gift_product_id: number | null;
    gift_quantity: number;
    times_used: number;
    advertisable: boolean;
}

interface ProductOption {
    id: number;
    name: string;
    sku: string;
}

interface ComboOption {
    id: number;
    title: string;
}

type CouponForm = {
    code: string;
    type: 'percent' | 'fixed';
    value: string;
    min_purchase: string;
    usage_limit: string;
    limit_per_customer: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
    is_public: boolean;
    applies_to: 'all' | 'products' | 'combos';
    product_ids: number[];
    combo_ids: number[];
    is_free_delivery: boolean;
    gift_product_id: string;
    gift_quantity: string;
};

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

function terms(coupon: Coupon): string {
    const off = coupon.type === 'percent' ? `${parseFloat(coupon.value)}% off` : `BDT ${coupon.value} off`;
    const parts = [parseFloat(coupon.value) > 0 ? off : null];
    if (coupon.is_free_delivery) parts.push('free delivery');
    if (coupon.gift_product_id) parts.push('a gift');
    if (coupon.min_purchase) parts.push(`over BDT ${coupon.min_purchase}`);
    if (coupon.applies_to !== 'all') parts.push(`only on chosen ${coupon.applies_to === 'products' ? 'products' : 'bundles'}`);
    return parts.filter(Boolean).join(', ');
}

function CouponEditor({
    storeId,
    coupon,
    products,
    combos,
    onDone,
}: {
    storeId: number;
    coupon: Coupon | null;
    products: ProductOption[];
    combos: ComboOption[];
    onDone: () => void;
}) {
    const { data, setData, post, put, errors, processing } = useForm<CouponForm>({
        code: coupon?.code ?? '',
        type: coupon?.type ?? 'percent',
        value: coupon?.value ?? '',
        min_purchase: coupon?.min_purchase ?? '',
        usage_limit: coupon?.usage_limit ? String(coupon.usage_limit) : '',
        limit_per_customer: coupon?.limit_per_customer ? String(coupon.limit_per_customer) : '',
        starts_at: coupon?.starts_at ?? '',
        ends_at: coupon?.ends_at ?? '',
        is_active: coupon?.is_active ?? true,
        is_public: coupon?.is_public ?? false,
        applies_to: coupon?.applies_to ?? 'all',
        product_ids: coupon?.product_ids ?? [],
        combo_ids: coupon?.combo_ids ?? [],
        is_free_delivery: coupon?.is_free_delivery ?? false,
        gift_product_id: coupon?.gift_product_id ? String(coupon.gift_product_id) : '',
        gift_quantity: coupon ? String(coupon.gift_quantity) : '1',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (coupon) {
            put(`/commerce/stores/${storeId}/coupons/${coupon.id}`, options);
        } else {
            post(`/commerce/stores/${storeId}/coupons`, options);
        }
    };

    const productOptions = products.map((product) => ({ value: String(product.id), label: product.name, keywords: product.sku }));
    const fieldErrors = errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="coupon-code">Code</Label>
                    <Input id="coupon-code" value={data.code} onChange={(e) => setData('code', e.target.value.toUpperCase())} />
                    <InputError message={errors.code} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="coupon-type">Takes off</Label>
                    <select
                        id="coupon-type"
                        className={selectClass}
                        value={data.type}
                        onChange={(e) => setData('type', e.target.value as CouponForm['type'])}
                    >
                        <option value="percent">A percentage</option>
                        <option value="fixed">An amount (BDT)</option>
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="coupon-value">{data.type === 'percent' ? 'Percent' : 'Amount (BDT)'}</Label>
                    <Input id="coupon-value" value={data.value} onChange={(e) => setData('value', e.target.value)} />
                    <InputError message={errors.value} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="coupon-min">Minimum basket (BDT)</Label>
                    <Input id="coupon-min" value={data.min_purchase} placeholder="None" onChange={(e) => setData('min_purchase', e.target.value)} />
                    <p className="text-muted-foreground text-xs">Checked against the whole basket.</p>
                    <InputError message={errors.min_purchase} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="coupon-limit">Uses in total</Label>
                    <Input
                        id="coupon-limit"
                        value={data.usage_limit}
                        placeholder="No limit"
                        onChange={(e) => setData('usage_limit', e.target.value)}
                    />
                    <p className="text-muted-foreground text-xs">A cancelled order does not use one up.</p>
                    <InputError message={errors.usage_limit} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="coupon-per">Uses per customer</Label>
                    <Input
                        id="coupon-per"
                        value={data.limit_per_customer}
                        placeholder="No limit"
                        onChange={(e) => setData('limit_per_customer', e.target.value)}
                    />
                    <p className="text-muted-foreground text-xs">A customer is known by their phone number.</p>
                    <InputError message={errors.limit_per_customer} />
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="coupon-starts">Starts</Label>
                    <Input id="coupon-starts" type="datetime-local" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                    <InputError message={errors.starts_at} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="coupon-ends">Ends</Label>
                    <Input id="coupon-ends" type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                    <InputError message={errors.ends_at} />
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="coupon-applies">Applies to</Label>
                <select
                    id="coupon-applies"
                    className={`${selectClass} max-w-xs`}
                    value={data.applies_to}
                    onChange={(e) => setData('applies_to', e.target.value as CouponForm['applies_to'])}
                >
                    <option value="all">The whole basket, bundles included</option>
                    <option value="products">Only chosen products</option>
                    <option value="combos">Only chosen bundles</option>
                </select>

                {data.applies_to === 'products' && (
                    <div className="grid gap-2">
                        <div className="w-80">
                            <SearchableSelect
                                value=""
                                onChange={(value) =>
                                    value && !data.product_ids.includes(Number(value)) && setData('product_ids', [...data.product_ids, Number(value)])
                                }
                                options={productOptions}
                                placeholder="Add a product"
                                searchPlaceholder="Name or SKU"
                            />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {data.product_ids.map((id) => (
                                <Badge key={id} variant="outline" className="gap-2">
                                    {products.find((product) => product.id === id)?.name ?? id}
                                    <button
                                        type="button"
                                        aria-label="Remove"
                                        onClick={() =>
                                            setData(
                                                'product_ids',
                                                data.product_ids.filter((other) => other !== id),
                                            )
                                        }
                                    >
                                        ×
                                    </button>
                                </Badge>
                            ))}
                        </div>
                        <InputError message={fieldErrors.product_ids} />
                    </div>
                )}

                {data.applies_to === 'combos' && (
                    <div className="grid gap-2">
                        {combos.length === 0 && <p className="text-muted-foreground text-sm">This shop has no bundles yet.</p>}
                        {combos.map((combo) => (
                            <div key={combo.id} className="flex items-center gap-2">
                                <Checkbox
                                    id={`combo-${combo.id}`}
                                    checked={data.combo_ids.includes(combo.id)}
                                    onCheckedChange={(next) =>
                                        setData(
                                            'combo_ids',
                                            next === true ? [...data.combo_ids, combo.id] : data.combo_ids.filter((other) => other !== combo.id),
                                        )
                                    }
                                />
                                <Label htmlFor={`combo-${combo.id}`} className="text-sm font-normal">
                                    {combo.title}
                                </Label>
                            </div>
                        ))}
                        <InputError message={fieldErrors.combo_ids} />
                    </div>
                )}
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label>Free gift</Label>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="w-72">
                            <SearchableSelect
                                value={data.gift_product_id}
                                onChange={(value) => setData('gift_product_id', value)}
                                options={productOptions}
                                placeholder="No gift"
                                searchPlaceholder="Name or SKU"
                                clearable
                            />
                        </div>
                        {data.gift_product_id && (
                            <Input
                                className="w-20"
                                type="number"
                                min={1}
                                max={20}
                                aria-label="Gift quantity"
                                value={data.gift_quantity}
                                onChange={(e) => setData('gift_quantity', e.target.value)}
                            />
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        The gift is charged at its price and taken off as part of the coupon, so its cost shows under discounts allowed.
                    </p>
                    <InputError message={errors.gift_product_id} />
                </div>
                <div className="grid content-start gap-3">
                    {(
                        [
                            ['is_free_delivery', 'Free delivery'],
                            ['is_active', 'Switched on'],
                            ['is_public', 'Public (may be advertised on the shop)'],
                        ] as const
                    ).map(([field, label]) => (
                        <div key={field} className="flex items-center gap-2">
                            <Checkbox id={`coupon-${field}`} checked={data[field]} onCheckedChange={(next) => setData(field, next === true)} />
                            <Label htmlFor={`coupon-${field}`} className="text-sm font-normal">
                                {label}
                            </Label>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex items-center gap-2">
                <Button disabled={processing}>Save</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function BulkForm({ storeId, onDone }: { storeId: number; onDone: () => void }) {
    const { data, setData, post, errors, processing } = useForm({
        prefix: '',
        count: '20',
        type: 'percent',
        value: '',
        min_purchase: '',
        ends_at: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/commerce/stores/${storeId}/coupons/bulk`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">Single-use codes for handing out: each works once, for one customer, on the whole basket.</p>
            <div className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="bulk-prefix">Prefix</Label>
                    <Input
                        id="bulk-prefix"
                        value={data.prefix}
                        placeholder="e.g. EID"
                        onChange={(e) => setData('prefix', e.target.value.toUpperCase())}
                    />
                    <InputError message={errors.prefix} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="bulk-count">How many</Label>
                    <Input id="bulk-count" value={data.count} onChange={(e) => setData('count', e.target.value)} />
                    <InputError message={errors.count} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="bulk-ends">Ends</Label>
                    <Input id="bulk-ends" type="datetime-local" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                    <InputError message={errors.ends_at} />
                </div>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="bulk-type">Takes off</Label>
                    <select id="bulk-type" className={selectClass} value={data.type} onChange={(e) => setData('type', e.target.value)}>
                        <option value="percent">A percentage</option>
                        <option value="fixed">An amount (BDT)</option>
                    </select>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="bulk-value">{data.type === 'percent' ? 'Percent' : 'Amount (BDT)'}</Label>
                    <Input id="bulk-value" value={data.value} onChange={(e) => setData('value', e.target.value)} />
                    <InputError message={errors.value} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="bulk-min">Minimum basket (BDT)</Label>
                    <Input id="bulk-min" value={data.min_purchase} placeholder="None" onChange={(e) => setData('min_purchase', e.target.value)} />
                    <InputError message={errors.min_purchase} />
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Button disabled={processing}>Create codes</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function StoreCoupons({
    store,
    coupons,
    products,
    combos,
    can_manage,
}: {
    store: { id: number; name: string };
    coupons: Coupon[];
    products: ProductOption[];
    combos: ComboOption[];
    can_manage: boolean;
}) {
    const [editing, setEditing] = useState<number | 'new' | 'bulk' | null>(null);
    const { errors } = usePage().props as { errors: Record<string, string> };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Websites', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
        { title: 'Coupons', href: `/commerce/stores/${store.id}/coupons` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${store.name} coupons`} />

            <div className="grid gap-6 p-4">
                <HeadingSmall
                    title={`${store.name} coupons`}
                    description="Codes a customer types in the basket. A coupon's discount is posted to Discounts Allowed, so its cost is visible in the profit and loss."
                />

                {errors.coupon && <p className="text-destructive text-sm">{errors.coupon}</p>}

                {!can_manage && (
                    <p className="text-muted-foreground text-sm">Only somebody who may set selling prices can create or change a coupon.</p>
                )}

                {can_manage && editing === 'new' && (
                    <CouponEditor storeId={store.id} coupon={null} products={products} combos={combos} onDone={() => setEditing(null)} />
                )}
                {can_manage && editing === 'bulk' && <BulkForm storeId={store.id} onDone={() => setEditing(null)} />}

                {can_manage && editing !== 'new' && editing !== 'bulk' && (
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setEditing('new')}>New coupon</Button>
                        <Button variant="outline" onClick={() => setEditing('bulk')}>
                            Create single-use codes
                        </Button>
                    </div>
                )}

                {coupons.length === 0 && <p className="text-muted-foreground text-sm">This shop offers no coupons yet.</p>}

                {coupons.map((coupon) =>
                    editing === coupon.id ? (
                        <CouponEditor
                            key={coupon.id}
                            storeId={store.id}
                            coupon={coupon}
                            products={products}
                            combos={combos}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <div key={coupon.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                            <div className="grid gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono font-medium">{coupon.code}</span>
                                    <Badge variant={coupon.is_active ? 'default' : 'secondary'}>{coupon.is_active ? 'On' : 'Off'}</Badge>
                                    {coupon.is_public && <Badge variant="outline">Public</Badge>}
                                    {coupon.is_active && !coupon.advertisable && <Badge variant="secondary">Not usable right now</Badge>}
                                </div>
                                <p className="text-sm">{terms(coupon)}</p>
                                <p className="text-muted-foreground text-sm">
                                    Used {coupon.times_used}
                                    {coupon.usage_limit ? ` of ${coupon.usage_limit}` : ''} time{coupon.times_used === 1 ? '' : 's'}
                                    {coupon.ends_at ? ` · ends ${coupon.ends_at.replace('T', ' ')}` : ''}
                                </p>
                            </div>
                            {can_manage && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setEditing(coupon.id)}>
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`/commerce/stores/${store.id}/coupons/${coupon.id}`, { preserveScroll: true })}
                                    >
                                        Delete
                                    </Button>
                                </div>
                            )}
                        </div>
                    ),
                )}

                <div>
                    <Button variant="ghost" asChild>
                        <Link href={`/commerce/stores/${store.id}`}>Back to the shop</Link>
                    </Button>
                </div>
            </div>
        </AppLayout>
    );
}
