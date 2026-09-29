import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Option {
    value: string;
    label: string;
}

interface Category {
    id: number;
    name: string;
    code: string;
    default_tracking: 'individual' | 'quantity';
}

interface EditingItem {
    id: number;
    tag: string;
    asset_category_id: number;
    tracking: 'individual' | 'quantity';
    name: string;
    quantity: number;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    specification: string | null;
    place: string;
    area: string | null;
    status: string;
    status_is_settable: boolean;
    status_label: string;
    condition: string;
    parent_id: number | null;
    acquired_on: string | null;
    supplier_id: number | null;
    purchase_price: string | null;
    warranty_ends_on: string | null;
    service_ends_on: string | null;
    service_party_id: number | null;
    note: string | null;
}

const textareaClass = 'border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm';

/**
 * Register a thing, or correct what the register says about it (slice A1).
 *
 * ⚠ Its category, tag and way of counting are fixed once registered: the tag is
 * on a sticker. Every rule is checked again on the server.
 */
export default function AssetItemForm({
    item,
    categories,
    places,
    suppliers,
    parties,
    parents,
    statuses,
    conditions,
    trackings,
    max_copies,
    sees_price,
}: {
    item: EditingItem | null;
    categories: Category[];
    places: Option[];
    suppliers: { id: number; name: string }[];
    parties: { id: number; label: string }[];
    parents: { id: number; label: string }[];
    statuses: Option[];
    conditions: Option[];
    trackings: Option[];
    max_copies: number;
    sees_price: boolean;
}) {
    const editing = item !== null;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        editing ? { title: item.tag, href: `/assets/items/${item.id}` } : { title: 'Add a thing', href: '/assets/items/create' },
    ];

    const first = categories[0];
    const { data, setData, post, put, errors, processing, transform } = useForm<{
        asset_category_id: string;
        tracking: string;
        copies: string;
        name: string;
        quantity: string;
        brand: string;
        model: string;
        serial_number: string;
        specification: string;
        place: string;
        area: string;
        status: string;
        condition: string;
        parent_id: string;
        acquired_on: string;
        supplier_id: string;
        purchase_price: string;
        warranty_ends_on: string;
        service_ends_on: string;
        service_party_id: string;
        note: string;
        photos: File[];
    }>({
        asset_category_id: item ? String(item.asset_category_id) : first ? String(first.id) : '',
        tracking: item ? item.tracking : (first?.default_tracking ?? 'individual'),
        copies: '1',
        name: item?.name ?? '',
        quantity: String(item?.quantity ?? 1),
        brand: item?.brand ?? '',
        model: item?.model ?? '',
        serial_number: item?.serial_number ?? '',
        specification: item?.specification ?? '',
        place: item?.place ?? places[0]?.value ?? '',
        area: item?.area ?? '',
        status: item?.status ?? 'in_use',
        condition: item?.condition ?? 'good',
        parent_id: item?.parent_id ? String(item.parent_id) : '',
        acquired_on: item?.acquired_on ?? '',
        supplier_id: item?.supplier_id ? String(item.supplier_id) : '',
        purchase_price: item?.purchase_price ?? '',
        warranty_ends_on: item?.warranty_ends_on ?? '',
        service_ends_on: item?.service_ends_on ?? '',
        service_party_id: item?.service_party_id ? String(item.service_party_id) : '',
        note: item?.note ?? '',
        photos: [],
    });

    const individual = data.tracking === 'individual';

    const chooseCategory = (id: string) => {
        const category = categories.find((c) => String(c.id) === id);
        setData((current) => ({ ...current, asset_category_id: id, tracking: category?.default_tracking ?? current.tracking }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            // ⚠ Category, way of counting and copies are refused on an edit.
            transform((current) => {
                const rest: Partial<typeof current> = { ...current };
                delete rest.asset_category_id;
                delete rest.tracking;
                delete rest.copies;
                delete rest.photos;

                return rest;
            });
            put(`/assets/items/${item.id}`);

            return;
        }

        transform((current) => ({ ...current, copies: individual ? current.copies : '1' }));
        post('/assets/items', { forceFormData: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? `Edit ${item.tag}` : 'Add a thing'} />
            <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{editing ? `Edit ${item.tag}` : 'Add a thing'}</h1>
                </div>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label required={!editing}>Category</Label>
                        {editing ? (
                            <p className="text-sm">{categories.find((c) => c.id === item.asset_category_id)?.name ?? 'Switched off'}</p>
                        ) : (
                            <SearchableSelect
                                value={data.asset_category_id}
                                onChange={chooseCategory}
                                options={categories.map((c) => ({ value: String(c.id), label: `${c.name} (${c.code})` }))}
                                placeholder="Choose a category"
                            />
                        )}
                        <InputError message={errors.asset_category_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label required={!editing}>How it is counted</Label>
                        {editing ? (
                            <p className="text-sm">{trackings.find((t) => t.value === item.tracking)?.label}</p>
                        ) : (
                            <SearchableSelect value={data.tracking} onChange={(v) => setData('tracking', v)} options={trackings} />
                        )}
                        <InputError message={errors.tracking} />
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="name" required>
                            What it is
                        </Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Desk chair, ThinkPad T470s"
                        />
                        <InputError message={errors.name} />
                    </div>

                    {individual && !editing && (
                        <div className="grid gap-2">
                            <Label htmlFor="copies">How many identical ones</Label>
                            <Input
                                id="copies"
                                type="number"
                                min={1}
                                max={max_copies}
                                value={data.copies}
                                onChange={(e) => setData('copies', e.target.value)}
                                className="w-28"
                            />
                            <InputError message={errors.copies} />
                        </div>
                    )}

                    {!individual && (
                        <div className="grid gap-2">
                            <Label htmlFor="quantity" required>
                                Quantity
                            </Label>
                            <Input
                                id="quantity"
                                type="number"
                                min={1}
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                className="w-28"
                            />
                            <InputError message={errors.quantity} />
                        </div>
                    )}
                </section>

                <section className="grid gap-4 sm:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="brand">Brand</Label>
                        <Input id="brand" value={data.brand} onChange={(e) => setData('brand', e.target.value)} />
                        <InputError message={errors.brand} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="model">Model</Label>
                        <Input id="model" value={data.model} onChange={(e) => setData('model', e.target.value)} />
                        <InputError message={errors.model} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="serial_number">Serial number</Label>
                        <Input id="serial_number" value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} />
                        <InputError message={errors.serial_number} />
                    </div>
                    <div className="grid gap-2 sm:col-span-3">
                        <Label htmlFor="specification">Specification</Label>
                        <textarea
                            id="specification"
                            className={textareaClass}
                            value={data.specification}
                            onChange={(e) => setData('specification', e.target.value)}
                            placeholder="Processor, memory, storage, size..."
                        />
                        <InputError message={errors.specification} />
                    </div>
                </section>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label required>Where it is</Label>
                        <SearchableSelect value={data.place} onChange={(v) => setData('place', v)} options={places} placeholder="Choose a place" />
                        <InputError message={(errors as Record<string, string | undefined>).location ?? errors.place} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="area">Room, desk or shelf</Label>
                        <Input id="area" value={data.area} onChange={(e) => setData('area', e.target.value)} placeholder="Studio, software desk" />
                        <InputError message={errors.area} />
                    </div>
                    <div className="grid gap-2">
                        <Label required>Status</Label>
                        {editing && !item.status_is_settable ? (
                            <p className="text-sm">{item.status_label} (changed only by the document that caused it)</p>
                        ) : (
                            <SearchableSelect value={data.status} onChange={(v) => setData('status', v)} options={statuses} />
                        )}
                        <InputError message={errors.status} />
                    </div>
                    <div className="grid gap-2">
                        <Label required>Condition</Label>
                        <SearchableSelect value={data.condition} onChange={(v) => setData('condition', v)} options={conditions} />
                        <InputError message={errors.condition} />
                    </div>
                    {individual && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label>Part of</Label>
                            <SearchableSelect
                                value={data.parent_id}
                                onChange={(v) => setData('parent_id', v)}
                                options={[
                                    { value: '', label: 'Not part of anything' },
                                    ...parents.map((p) => ({ value: String(p.id), label: p.label })),
                                ]}
                                placeholder="Not part of anything"
                            />
                            <InputError message={errors.parent_id} />
                        </div>
                    )}
                </section>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="acquired_on">Bought on</Label>
                        <Input id="acquired_on" type="date" value={data.acquired_on} onChange={(e) => setData('acquired_on', e.target.value)} />
                        <InputError message={errors.acquired_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Bought from</Label>
                        <SearchableSelect
                            value={data.supplier_id}
                            onChange={(v) => setData('supplier_id', v)}
                            options={[{ value: '', label: 'Not recorded' }, ...suppliers.map((s) => ({ value: String(s.id), label: s.name }))]}
                            placeholder="Not recorded"
                        />
                        <InputError message={errors.supplier_id} />
                    </div>
                    {/* ⚠ Only for someone who may see the financial register. */}
                    {sees_price && (
                        <div className="grid gap-2">
                            <Label htmlFor="purchase_price">Price paid</Label>
                            <Input
                                id="purchase_price"
                                inputMode="decimal"
                                value={data.purchase_price}
                                onChange={(e) => setData('purchase_price', e.target.value)}
                                className="w-40 text-right"
                                placeholder="0.00"
                            />
                            <InputError message={errors.purchase_price} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="warranty_ends_on">Warranty ends</Label>
                        <Input
                            id="warranty_ends_on"
                            type="date"
                            value={data.warranty_ends_on}
                            onChange={(e) => setData('warranty_ends_on', e.target.value)}
                        />
                        <InputError message={errors.warranty_ends_on} />
                    </div>
                    {/* Slice A4: a service contract, and who holds it. */}
                    <div className="grid gap-2">
                        <Label htmlFor="service_ends_on">Service contract ends</Label>
                        <Input
                            id="service_ends_on"
                            type="date"
                            value={data.service_ends_on}
                            onChange={(e) => setData('service_ends_on', e.target.value)}
                        />
                        <InputError message={errors.service_ends_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Serviced by</Label>
                        <SearchableSelect
                            value={data.service_party_id}
                            onChange={(v) => setData('service_party_id', v)}
                            options={[
                                { value: '', label: 'Nobody under contract' },
                                ...parties.map((p) => ({ value: String(p.id), label: p.label })),
                            ]}
                        />
                        <InputError message={errors.service_party_id} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="note">Note</Label>
                        <textarea id="note" className={textareaClass} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                    {!editing && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="photos">Photos</Label>
                            <input
                                id="photos"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                                className="w-full min-w-0 text-sm"
                                onChange={(e) => setData('photos', Array.from(e.target.files ?? []).slice(0, 6))}
                            />
                            <InputError message={errors.photos ?? (errors as Record<string, string | undefined>)['photos.0']} />
                        </div>
                    )}
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button disabled={processing}>{editing ? 'Save' : 'Register'}</Button>
                    <Button variant="outline" asChild>
                        <Link href={editing ? `/assets/items/${item.id}` : '/assets/items'}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
