import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Domain {
    id: number;
    hostname: string;
    is_primary: boolean;
}

interface Store {
    id: number;
    key: string;
    name: string;
    order_prefix: string;
    brand_name: string | null;
    price_group_name: string;
    availability_policy: string;
    theme_key: string | null;
    status: string;
    status_label: string;
    can_go_live: boolean;
    domains: Domain[];
}

interface Option {
    value: string;
    label: string;
}

/**
 * ⚠ A price group is identified by its row id, not by a code string. `Option`
 * above stays as it is because the theme and availability pickers are genuinely
 * keyed by string, and widening it for everybody would hide the difference.
 */
interface PriceGroupOption {
    value: number;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Websites', href: '/commerce/stores' },
    { title: 'Stores', href: '/commerce/stores' },
];

const EMPTY = {
    key: '',
    name: '',
    order_prefix: '',
    brand_id: '' as string,
    price_group_id: null as number | null,
    availability_policy: 'status_based',
};

/**
 * The websites this business runs.
 *
 * A store is a sales CHANNEL: not a tenant, and not a brand. Econic will sell
 * LaLaDia and Bionic products from one store, which is why a store may carry no
 * brand at all.
 *
 * ⚠ The key and the order prefix cannot be changed afterwards, and the form
 * says so where somebody is typing them rather than in a document nobody opens.
 */
export default function StoresIndex({
    stores,
    brands,
    priceGroups,
    availabilityPolicies,
}: {
    stores: Store[];
    brands: { id: number; name: string }[];
    priceGroups: PriceGroupOption[];
    availabilityPolicies: Option[];
}) {
    const { data, setData, post, errors, processing, reset } = useForm(EMPTY);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('commerce.stores.store'), { onSuccess: () => reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stores" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground max-w-3xl text-sm">
                    <p>
                        Every website the group runs is a store here: its catalogue, its prices, its content and its
                        orders. A store starts as a draft and cannot go live until it has a theme and a primary domain,
                        because there would be nothing to render.
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <form onSubmit={submit} className="border-sidebar-border/70 flex flex-col gap-4 rounded-xl border p-4">
                        <h2 className="font-medium">Add a store</h2>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="key">Key</Label>
                            <Input
                                id="key"
                                value={data.key}
                                onChange={(e) => setData('key', e.target.value)}
                                placeholder="bionic"
                            />
                            <p className="text-muted-foreground text-xs">
                                Permanent. This store's cookie and webhook header names are built from it.
                            </p>
                            <InputError message={errors.key} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="order_prefix">Order prefix</Label>
                            <Input
                                id="order_prefix"
                                value={data.order_prefix}
                                onChange={(e) => setData('order_prefix', e.target.value.toUpperCase())}
                                placeholder="BNC"
                            />
                            <p className="text-muted-foreground text-xs">
                                Permanent, and unique across the whole group. The payment merchant is shared, so this is
                                the only thing on a settlement line that says whose money it is.
                            </p>
                            <InputError message={errors.order_prefix} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="brand_id">Brand</Label>
                            <Select value={data.brand_id} onValueChange={(v) => setData('brand_id', v)}>
                                <SelectTrigger id="brand_id">
                                    <SelectValue placeholder="No single brand (multi-brand store)" />
                                </SelectTrigger>
                                <SelectContent>
                                    {brands.map((brand) => (
                                        <SelectItem key={brand.id} value={String(brand.id)}>
                                            {brand.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.brand_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="price_group">Price group</Label>
                            <Select
                                value={data.price_group_id === null ? 'default' : String(data.price_group_id)}
                                onValueChange={(v) => setData('price_group_id', v === 'default' ? null : Number(v))}
                            >
                                <SelectTrigger id="price_group">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {/* ⚠ "Retail (default)" is a real choice, not an
                                        empty one: a store with no group is priced as
                                        RETAIL by the selling-price resolver, and the
                                        picker says so rather than showing a blank
                                        somebody would read as unfinished setup. */}
                                    <SelectItem value="default">Retail (default)</SelectItem>
                                    {priceGroups.map((option) => (
                                        <SelectItem key={option.value} value={String(option.value)}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                Websites sell retail only. Dealer and distributor selling stays manual in the ERP.
                            </p>
                            <InputError message={errors.price_group_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="availability_policy">Availability</Label>
                            <Select
                                value={data.availability_policy}
                                onValueChange={(v) => setData('availability_policy', v)}
                            >
                                <SelectTrigger id="availability_policy">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {availabilityPolicies.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                The goods are made to order, so a website normally keeps selling at zero stock and the
                                order carries a promised lead time.
                            </p>
                            <InputError message={errors.availability_policy} />
                        </div>

                        <Button type="submit" disabled={processing}>
                            Create store
                        </Button>
                    </form>

                    <div className="flex flex-col gap-3 lg:col-span-2">
                        {stores.length === 0 && (
                            <div className="border-sidebar-border/70 text-muted-foreground rounded-xl border p-6 text-sm">
                                No stores yet. The first one is Bionic.
                            </div>
                        )}

                        {stores.map((store) => (
                            <div key={store.id} className="border-sidebar-border/70 rounded-xl border p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <Link
                                            href={route('commerce.stores.edit', store.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {store.name}
                                        </Link>
                                        <span className="text-muted-foreground ml-2 text-sm">{store.order_prefix}</span>
                                    </div>
                                    <Badge variant={store.status === 'live' ? 'default' : 'secondary'}>
                                        {store.status_label}
                                    </Badge>
                                </div>

                                <dl className="text-muted-foreground mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                                    <div>
                                        Brand: <span className="text-foreground">{store.brand_name ?? 'Multi-brand'}</span>
                                    </div>
                                    <div>
                                        Theme:{' '}
                                        <span className="text-foreground">{store.theme_key ?? 'Not chosen yet'}</span>
                                    </div>
                                    <div className="sm:col-span-2">
                                        Domains:{' '}
                                        <span className="text-foreground">
                                            {store.domains.length === 0
                                                ? 'None'
                                                : store.domains
                                                      .map((d) => (d.is_primary ? `${d.hostname} (primary)` : d.hostname))
                                                      .join(', ')}
                                        </span>
                                    </div>
                                </dl>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
