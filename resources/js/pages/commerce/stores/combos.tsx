import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * The bundles this shop sells (website slice 2.5a).
 *
 * ⚠ A bundle is jars sold together, never a new product (D25). Every jar is still
 * billed at the price list's own price for this shop; a bundle price below that
 * becomes a saving shared across the jars on the order.
 *
 * ⚠ The price field is only editable by somebody who may set selling prices.
 * Everybody who may edit content can still compose a bundle - the price they
 * cannot touch is sent back unchanged.
 *
 * ⚠ The figures and the refusal on each bundle come from the server, recomputed
 * on every view through the same class the shop and the checkout ask. This
 * screen adds nothing up.
 */

interface ComboItem {
    product_id: number;
    name: string | null;
    sku: string | null;
    quantity: number;
}

interface Combo {
    id: number;
    title: string;
    slug: string;
    summary: string | null;
    image_media_id: number | null;
    price: string | null;
    position: number;
    published_at: string | null;
    items: ComboItem[];
    parts_total: string | null;
    total: string | null;
    saving: string | null;
    refusal: string | null;
}

interface ProductOption {
    id: number;
    name: string;
    sku: string;
}

interface MediaItem {
    id: number;
    uuid: string;
    alt_text: string | null;
    is_decorative: boolean;
}

interface StoreSummary {
    id: number;
    name: string;
    price_group: string;
}

type ItemDraft = { product_id: string; quantity: string };

type ComboForm = {
    title: string;
    slug: string;
    summary: string;
    image_media_id: number | null;
    price: string;
    position: number;
    items: ItemDraft[];
};

function ItemsEditor({
    items,
    products,
    errors,
    onChange,
}: {
    items: ItemDraft[];
    products: ProductOption[];
    errors: Record<string, string>;
    onChange: (items: ItemDraft[]) => void;
}) {
    const options = products.map((product) => ({ value: String(product.id), label: product.name, keywords: product.sku }));

    return (
        <div className="grid gap-2">
            <Label required>In this bundle</Label>
            {items.map((item, index) => (
                <div key={index} className="flex flex-wrap items-start gap-2">
                    <div className="grid w-80 gap-1">
                        <SearchableSelect
                            value={item.product_id}
                            onChange={(value) => onChange(items.map((row, i) => (i === index ? { ...row, product_id: value } : row)))}
                            options={options}
                            placeholder="Choose a product"
                            searchPlaceholder="Name or SKU"
                        />
                        <InputError message={errors[`items.${index}.product_id`]} />
                    </div>
                    <div className="grid w-24 gap-1">
                        <Input
                            type="number"
                            min={1}
                            max={99}
                            aria-label="Quantity"
                            aria-required
                            value={item.quantity}
                            onChange={(e) => onChange(items.map((row, i) => (i === index ? { ...row, quantity: e.target.value } : row)))}
                        />
                        <InputError message={errors[`items.${index}.quantity`]} />
                    </div>
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange(items.filter((_, i) => i !== index))}>
                        Remove
                    </Button>
                </div>
            ))}
            <div>
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...items, { product_id: '', quantity: '1' }])}>
                    Add a product
                </Button>
            </div>
            <InputError message={errors.items} />
        </div>
    );
}

function ComboEditor({
    store,
    combo,
    products,
    media,
    canSetPrice,
    onDone,
}: {
    store: StoreSummary;
    combo: Combo | null;
    products: ProductOption[];
    media: MediaItem[];
    canSetPrice: boolean;
    onDone: () => void;
}) {
    const { data, setData, post, put, errors, processing } = useForm<ComboForm>({
        title: combo?.title ?? '',
        slug: combo?.slug ?? '',
        summary: combo?.summary ?? '',
        image_media_id: combo?.image_media_id ?? null,
        price: combo?.price ?? '',
        position: combo?.position ?? 0,
        items: combo ? combo.items.map((item) => ({ product_id: String(item.product_id), quantity: String(item.quantity) })) : [],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (combo) {
            put(`/commerce/stores/${store.id}/combos/${combo.id}`, options);
        } else {
            post(`/commerce/stores/${store.id}/combos`, options);
        }
    };

    const fieldErrors = errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4">
            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`title-${combo?.id ?? 'new'}`} required>
                        Name
                    </Label>
                    <Input id={`title-${combo?.id ?? 'new'}`} aria-required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    <InputError message={errors.title} />
                </div>
                {combo && (
                    <div className="grid gap-2">
                        <Label htmlFor={`slug-${combo.id}`} required>
                            Web address
                        </Label>
                        <Input id={`slug-${combo.id}`} aria-required value={data.slug} onChange={(e) => setData('slug', e.target.value)} />
                        <p className="text-muted-foreground text-xs">/combos/{data.slug || '…'}</p>
                        <InputError message={errors.slug} />
                    </div>
                )}
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`summary-${combo?.id ?? 'new'}`}>Short description</Label>
                <Input id={`summary-${combo?.id ?? 'new'}`} value={data.summary} onChange={(e) => setData('summary', e.target.value)} />
                <InputError message={errors.summary} />
            </div>

            <ItemsEditor items={data.items} products={products} errors={fieldErrors} onChange={(items) => setData('items', items)} />

            <div className="grid gap-4 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor={`price-${combo?.id ?? 'new'}`}>Bundle price (BDT)</Label>
                    <Input
                        id={`price-${combo?.id ?? 'new'}`}
                        value={data.price}
                        disabled={!canSetPrice}
                        placeholder="Empty: the price of its contents"
                        onChange={(e) => setData('price', e.target.value)}
                    />
                    <p className="text-muted-foreground text-xs">
                        {canSetPrice
                            ? `Leave empty to sell at the price of its contents in ${store.price_group}. A price below that is shared across the jars as a saving; a price above it cannot be sold.`
                            : 'Only somebody who may set selling prices can change this.'}
                    </p>
                    <InputError message={errors.price} />
                </div>
                {combo && (
                    <div className="grid gap-2">
                        <Label htmlFor={`image-${combo.id}`}>Picture</Label>
                        <select
                            id={`image-${combo.id}`}
                            className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                            value={data.image_media_id ?? ''}
                            onChange={(e) => setData('image_media_id', e.target.value === '' ? null : Number(e.target.value))}
                        >
                            <option value="">No picture</option>
                            {media.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.alt_text || (item.is_decorative ? 'Decorative image' : item.uuid.slice(0, 8))}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-xs">From this shop's media library only.</p>
                        <InputError message={errors.image_media_id} />
                    </div>
                )}
            </div>

            <div className="flex items-center gap-2">
                <Button disabled={processing}>{combo ? 'Save' : 'Save as draft'}</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function StoreCombos({
    store,
    combos,
    products,
    media,
    can_manage,
    can_set_price,
}: {
    store: StoreSummary;
    combos: Combo[];
    products: ProductOption[];
    media: MediaItem[];
    can_manage: boolean;
    can_set_price: boolean;
}) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const { errors } = usePage().props as { errors: Record<string, string> };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Online shops', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
        { title: 'Bundles', href: `/commerce/stores/${store.id}/combos` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${store.name} bundles`} />

            <div className="grid gap-6 p-4">
                <HeadingSmall
                    title={`${store.name} bundles`}
                    description={`Jars sold together. Each jar is billed at its ${store.price_group} price; a bundle price below that is a saving shared across them.`}
                />

                {(errors.published_at || errors.combo) && <p className="text-destructive text-sm">{errors.published_at || errors.combo}</p>}

                {can_manage && editing === 'new' && (
                    <ComboEditor
                        store={store}
                        combo={null}
                        products={products}
                        media={media}
                        canSetPrice={can_set_price}
                        onDone={() => setEditing(null)}
                    />
                )}

                {can_manage && editing !== 'new' && (
                    <div>
                        <Button onClick={() => setEditing('new')}>New bundle</Button>
                    </div>
                )}

                {combos.length === 0 && <p className="text-muted-foreground text-sm">This shop sells no bundles yet.</p>}

                {combos.map((combo) =>
                    editing === combo.id ? (
                        <ComboEditor
                            key={combo.id}
                            store={store}
                            combo={combo}
                            products={products}
                            media={media}
                            canSetPrice={can_set_price}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <div key={combo.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                            <div className="grid gap-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{combo.title}</span>
                                    <Badge variant={combo.published_at ? 'default' : 'secondary'}>{combo.published_at ? 'On sale' : 'Draft'}</Badge>
                                </div>
                                <p className="text-muted-foreground text-sm">/combos/{combo.slug}</p>
                                <p className="text-sm">
                                    {combo.items.map((item) => `${item.quantity} × ${item.name ?? 'Missing product'}`).join(', ')}
                                </p>
                                {combo.total && (
                                    <p className="text-muted-foreground text-sm">
                                        Contents BDT {combo.parts_total} · Bundle BDT {combo.total}
                                        {combo.saving && combo.saving !== '0.00' ? ` · Saving BDT ${combo.saving}` : ''}
                                        {combo.price === null ? ' (no bundle price set)' : ''}
                                    </p>
                                )}
                                {/* ⚠ Recomputed on every view. A price list change can push
                                    a published bundle above its contents. */}
                                {combo.refusal && <p className="text-sm text-amber-600 dark:text-amber-500">{combo.refusal}</p>}
                            </div>
                            {can_manage && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={() => setEditing(combo.id)}>
                                        Edit
                                    </Button>
                                    {combo.published_at ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(`/commerce/stores/${store.id}/combos/${combo.id}/unpublish`, {}, { preserveScroll: true })
                                            }
                                        >
                                            Take off sale
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            disabled={combo.refusal !== null}
                                            onClick={() =>
                                                router.post(`/commerce/stores/${store.id}/combos/${combo.id}/publish`, {}, { preserveScroll: true })
                                            }
                                        >
                                            Publish
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`/commerce/stores/${store.id}/combos/${combo.id}`, { preserveScroll: true })}
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
