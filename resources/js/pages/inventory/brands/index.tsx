import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Brand {
    id: number;
    name: string;
    code: string;
    note: string | null;
    is_active: boolean;
    products_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Brands', href: '/inventory/brands' },
];

const EMPTY = { name: '', code: '', note: '' };

/**
 * Brands are the axis profit reports run along, so they are rows here rather
 * than free text on products. The code is a SKU segment and is permanent once
 * created; a brand is retired, never deleted, so history keeps its label.
 */
export default function BrandsIndex({ brands }: { brands: Brand[] }) {
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);
    const [editing, setEditing] = useState<Brand | null>(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('inventory.brands.update', editing.id), {
                onSuccess: () => {
                    setEditing(null);
                    reset();
                },
            });

            return;
        }

        post(route('inventory.brands.store'), { onSuccess: () => reset() });
    };

    const edit = (brand: Brand) => {
        clearErrors();
        setEditing(brand);
        setData({ name: brand.name, code: brand.code, note: brand.note ?? '' });
    };

    const cancel = () => {
        clearErrors();
        setEditing(null);
        reset();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Brands" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground max-w-3xl text-sm">
                    <p>
                        A brand is what the profit report groups by, and its <strong>code</strong> is the SKU segment it contributes, so{' '}
                        <code className="font-mono">LLD</code> is what makes <code className="font-mono">LLD-DRY-CHU-125G</code> a LaLaDia product.
                        The code is permanent once created. Raw materials and packaging carry no brand on purpose.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{editing ? `Editing ${editing.name}` : 'New brand'}</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. LaLaDia" />
                            <InputError message={errors.name} />
                        </div>
                        {!editing && (
                            <div className="grid gap-2">
                                <Label htmlFor="code">SKU code</Label>
                                <Input
                                    id="code"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                    placeholder="e.g. LLD"
                                    className="w-32 font-mono"
                                />
                                <InputError message={errors.code} />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="note">Note</Label>
                            <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                            <InputError message={errors.note} />
                        </div>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add'}</Button>
                        {editing && (
                            <Button type="button" variant="ghost" onClick={cancel}>
                                Cancel
                            </Button>
                        )}
                    </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Name</th>
                            <th className="py-2">SKU code</th>
                            <th className="py-2">Products</th>
                            <th className="py-2">Note</th>
                            <th className="py-2">Status</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {brands.map((brand) => (
                            <tr
                                key={brand.id}
                                className={`border-sidebar-border/70 dark:border-sidebar-border border-b ${brand.is_active ? '' : 'opacity-50'}`}
                            >
                                <td className="py-2">{brand.name}</td>
                                <td className="py-2 font-mono text-xs">{brand.code}</td>
                                <td className="text-muted-foreground py-2">{brand.products_count}</td>
                                <td className="text-muted-foreground py-2 text-xs">{brand.note ?? ''}</td>
                                <td className="py-2">
                                    <Badge variant={brand.is_active ? 'default' : 'secondary'}>{brand.is_active ? 'Active' : 'Retired'}</Badge>
                                </td>
                                <td className="py-2 text-right">
                                    <Button variant="ghost" size="sm" onClick={() => edit(brand)}>
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.patch(route('inventory.brands.toggle-active', brand.id), {}, { preserveScroll: true })}
                                    >
                                        {brand.is_active ? 'Retire' : 'Reactivate'}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {brands.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-muted-foreground py-4 text-center">
                                    No brands yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
