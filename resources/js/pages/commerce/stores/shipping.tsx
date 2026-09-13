import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

/**
 * What delivery costs on this shop, and which district pays which rate.
 *
 * ⚠ The customer never chooses a band: they choose a district and the shop prices
 * it (slice 2.3). This screen is where the shop decides those prices. A district
 * not listed pays the catch-all, and the catch-all cannot be deleted.
 *
 * ⚠ A changed rate reaches every open basket on its next view, because a basket
 * never stores a charge. A placed order keeps the charge it agreed.
 */

interface Zone {
    id: number;
    name: string;
    charge: string;
    is_default: boolean;
    sort_order: number;
    districts_count: number;
}

interface District {
    name: string;
    bn_name: string | null;
    zone_id: number | null;
}

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

function ZoneRow({ storeId, zone, canManage }: { storeId: number; zone: Zone; canManage: boolean }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, errors, processing } = useForm({ name: zone.name, charge: zone.charge });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/commerce/stores/${storeId}/shipping/zones/${zone.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (editing) {
        return (
            <form onSubmit={submit} className="flex flex-wrap items-start gap-2 rounded-lg border p-3">
                <div className="grid w-56 gap-1">
                    <Input aria-label="Zone name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} />
                </div>
                <div className="grid w-32 gap-1">
                    <Input aria-label="Charge (BDT)" value={data.charge} onChange={(e) => setData('charge', e.target.value)} />
                    <InputError message={errors.charge} />
                </div>
                <Button size="sm" disabled={processing}>
                    Save
                </Button>
                <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                    Cancel
                </Button>
            </form>
        );
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{zone.name}</span>
                <span>BDT {zone.charge}</span>
                {zone.is_default ? (
                    <Badge>Catch-all: every district not listed</Badge>
                ) : (
                    <span className="text-muted-foreground text-sm">
                        {zone.districts_count} district{zone.districts_count === 1 ? '' : 's'}
                    </span>
                )}
            </div>
            {canManage && (
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        Edit
                    </Button>
                    {!zone.is_default && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(`/commerce/stores/${storeId}/shipping/zones/${zone.id}/default`, {}, { preserveScroll: true })
                                }
                            >
                                Make catch-all
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.delete(`/commerce/stores/${storeId}/shipping/zones/${zone.id}`, { preserveScroll: true })}
                            >
                                Delete
                            </Button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export default function StoreShipping({
    store,
    zones,
    districts,
    unknown_districts,
    can_manage,
}: {
    store: { id: number; name: string };
    zones: Zone[];
    districts: District[];
    unknown_districts: string[];
    can_manage: boolean;
}) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [filter, setFilter] = useState('');

    const newZone = useForm({ name: '', charge: '' });
    const addZone: FormEventHandler = (e) => {
        e.preventDefault();
        newZone.post(`/commerce/stores/${store.id}/shipping/zones`, { preserveScroll: true, onSuccess: () => newZone.reset() });
    };

    const mapping = useForm<{ districts: Record<string, number | null> }>({
        districts: Object.fromEntries(districts.map((district) => [district.name, district.zone_id])),
    });

    const saveDistricts: FormEventHandler = (e) => {
        e.preventDefault();
        mapping.put(`/commerce/stores/${store.id}/shipping/districts`, { preserveScroll: true });
    };

    const shown = useMemo(
        () =>
            districts.filter(
                (district) => district.name.toLowerCase().includes(filter.trim().toLowerCase()) || (district.bn_name ?? '').includes(filter.trim()),
            ),
        [districts, filter],
    );

    const catchAll = zones.find((zone) => zone.is_default);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Websites', href: '/commerce/stores' },
        { title: store.name, href: `/commerce/stores/${store.id}` },
        { title: 'Shipping', href: `/commerce/stores/${store.id}/shipping` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${store.name} shipping`} />

            <div className="grid gap-6 p-4">
                <HeadingSmall
                    title={`${store.name} delivery rates`}
                    description="A customer picks their district; the shop decides what that costs. A changed rate reaches open baskets on their next view; placed orders keep the charge they agreed."
                />

                {(errors.zone || errors.districts) && <p className="text-destructive text-sm">{errors.zone || errors.districts}</p>}

                {!can_manage && <p className="text-muted-foreground text-sm">Only an Owner or Manager can change delivery rates.</p>}

                {zones.length === 0 ? (
                    <div className="grid gap-2 rounded-lg border p-4">
                        {/* ⛔ Said plainly: with no rates at all, nobody can check out. */}
                        <p className="text-sm text-amber-600 dark:text-amber-500">This shop has no delivery rates, so no customer can check out.</p>
                        {can_manage && (
                            <div>
                                <Button onClick={() => router.post(`/commerce/stores/${store.id}/shipping/standard`, {}, { preserveScroll: true })}>
                                    Start with the standard bands (60 / 90 / 120)
                                </Button>
                            </div>
                        )}
                    </div>
                ) : (
                    !catchAll && (
                        <p className="text-sm text-amber-600 dark:text-amber-500">
                            No zone is the catch-all, so a district not listed below cannot order. Make one zone the catch-all.
                        </p>
                    )
                )}

                <div className="grid gap-3">
                    <h3 className="text-sm font-medium">Zones</h3>
                    {zones.map((zone) => (
                        <ZoneRow key={zone.id} storeId={store.id} zone={zone} canManage={can_manage} />
                    ))}

                    {can_manage && (
                        <form onSubmit={addZone} className="flex flex-wrap items-end gap-2">
                            <div className="grid w-56 gap-1">
                                <Label htmlFor="new-zone-name">New zone</Label>
                                <Input id="new-zone-name" value={newZone.data.name} onChange={(e) => newZone.setData('name', e.target.value)} />
                                <InputError message={newZone.errors.name} />
                            </div>
                            <div className="grid w-32 gap-1">
                                <Label htmlFor="new-zone-charge">Charge (BDT)</Label>
                                <Input id="new-zone-charge" value={newZone.data.charge} onChange={(e) => newZone.setData('charge', e.target.value)} />
                                <InputError message={newZone.errors.charge} />
                            </div>
                            <Button variant="outline" disabled={newZone.processing}>
                                Add zone
                            </Button>
                        </form>
                    )}
                </div>

                {zones.length > 0 && (
                    <form onSubmit={saveDistricts} className="grid gap-3">
                        <div className="flex flex-wrap items-end justify-between gap-2">
                            <h3 className="text-sm font-medium">Which district pays which zone</h3>
                            <div className="grid w-64 gap-1">
                                <Label htmlFor="district-filter">Find a district</Label>
                                <Input id="district-filter" value={filter} onChange={(e) => setFilter(e.target.value)} />
                            </div>
                        </div>

                        {unknown_districts.length > 0 && (
                            <p className="text-sm text-amber-600 dark:text-amber-500">
                                Mapped under names the geography does not know, so they match nothing: {unknown_districts.join(', ')}.
                            </p>
                        )}

                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {shown.map((district) => (
                                <div key={district.name} className="flex items-center justify-between gap-2 rounded-md border px-3 py-2">
                                    <span className="text-sm">
                                        {district.name}
                                        {district.bn_name && <span className="text-muted-foreground"> · {district.bn_name}</span>}
                                    </span>
                                    <select
                                        aria-label={`Zone for ${district.name}`}
                                        className={selectClass}
                                        disabled={!can_manage}
                                        value={mapping.data.districts[district.name] ?? ''}
                                        onChange={(e) =>
                                            mapping.setData('districts', {
                                                ...mapping.data.districts,
                                                [district.name]: e.target.value === '' ? null : Number(e.target.value),
                                            })
                                        }
                                    >
                                        <option value="">{catchAll ? `Catch-all (${catchAll.name})` : 'Not priced'}</option>
                                        {zones
                                            .filter((zone) => !zone.is_default)
                                            .map((zone) => (
                                                <option key={zone.id} value={zone.id}>
                                                    {zone.name} (BDT {zone.charge})
                                                </option>
                                            ))}
                                    </select>
                                </div>
                            ))}
                        </div>

                        {can_manage && (
                            <div>
                                <Button disabled={mapping.processing}>Save district pricing</Button>
                            </div>
                        )}
                    </form>
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
