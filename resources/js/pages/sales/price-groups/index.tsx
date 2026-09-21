import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Group {
    id: number;
    code: string;
    name: string;
    prices_include_tax: boolean;
    falls_back_to_mrp: boolean;
    is_default: boolean;
    is_active: boolean;
    customers: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/orders' },
    { title: 'Price groups', href: '/sales/price-groups' },
];

/**
 * The kinds of buyer a price is set for, and which of them pay MRP when a
 * product has no price of their own (21 Sep 2026). ⚠ There was no screen at
 * all before: a group existed only if a seeder planted it.
 */
export default function PriceGroupsIndex({ groups }: { groups: Group[] }) {
    const [editing, setEditing] = useState<Group | null>(null);
    const add = useForm<{ code: string; name: string; prices_include_tax: boolean; falls_back_to_mrp: boolean }>({
        code: '',
        name: '',
        prices_include_tax: true,
        falls_back_to_mrp: false,
    });
    const edit = useForm<{ name: string; prices_include_tax: boolean; falls_back_to_mrp: boolean; is_active: boolean }>({
        name: '',
        prices_include_tax: true,
        falls_back_to_mrp: false,
        is_active: true,
    });

    const submitAdd: FormEventHandler = (e) => {
        e.preventDefault();
        add.post(route('sales.price-groups.store'), { onSuccess: () => add.reset(), preserveScroll: true });
    };

    const startEdit = (group: Group) => {
        edit.clearErrors();
        setEditing(group);
        edit.setData({
            name: group.name,
            prices_include_tax: group.prices_include_tax,
            falls_back_to_mrp: group.falls_back_to_mrp,
            is_active: group.is_active,
        });
    };

    const submitEdit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!editing) return;
        edit.put(route('sales.price-groups.update', editing.id), { onSuccess: () => setEditing(null), preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Price groups" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Price groups</h1>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        Who a price is for. Each product gets a price for each group in the product dialog. A group that does not pay MRP cannot buy a
                        product until its price is set; a group that pays MRP (Retail) is charged the product's MRP when nothing else is set.
                    </p>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-2">Group</th>
                                <th className="py-2">Code</th>
                                <th className="py-2">With no price set</th>
                                <th className="py-2">Prices include VAT</th>
                                <th className="py-2">Customers</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) =>
                                editing?.id === group.id ? (
                                    <tr key={group.id} className="border-b">
                                        <td className="py-2" colSpan={6}>
                                            <form onSubmit={submitEdit} className="flex flex-wrap items-end gap-3">
                                                <div className="grid gap-1">
                                                    <Label htmlFor="edit-name">Name</Label>
                                                    <Input
                                                        id="edit-name"
                                                        value={edit.data.name}
                                                        onChange={(e) => edit.setData('name', e.target.value)}
                                                        className="w-48"
                                                    />
                                                    <InputError message={edit.errors.name} />
                                                </div>
                                                <label className="flex h-9 items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={edit.data.falls_back_to_mrp}
                                                        disabled={group.is_default}
                                                        onChange={(e) => edit.setData('falls_back_to_mrp', e.target.checked)}
                                                    />
                                                    Pays MRP when no price is set
                                                </label>
                                                <label className="flex h-9 items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={edit.data.prices_include_tax}
                                                        onChange={(e) => edit.setData('prices_include_tax', e.target.checked)}
                                                    />
                                                    Prices include VAT
                                                </label>
                                                <label className="flex h-9 items-center gap-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={edit.data.is_active}
                                                        disabled={group.is_default}
                                                        onChange={(e) => edit.setData('is_active', e.target.checked)}
                                                    />
                                                    Active
                                                </label>
                                                <Button disabled={edit.processing}>Save</Button>
                                                <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                                                    Cancel
                                                </Button>
                                            </form>
                                            {group.is_default && (
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    Retail is the consumer price: it always pays MRP when nothing is set and stays active.
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    <tr key={group.id} className="border-b">
                                        <td className="py-2">
                                            {group.name}
                                            {group.is_default && (
                                                <Badge variant="secondary" className="ml-2">
                                                    default
                                                </Badge>
                                            )}
                                            {!group.is_active && (
                                                <Badge variant="destructive" className="ml-2">
                                                    inactive
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="text-muted-foreground py-2 font-mono text-xs">{group.code}</td>
                                        <td className="py-2">{group.falls_back_to_mrp ? 'Pays MRP' : 'Cannot buy'}</td>
                                        <td className="text-muted-foreground py-2">{group.prices_include_tax ? 'Yes' : 'No'}</td>
                                        <td className="text-muted-foreground py-2">{group.customers}</td>
                                        <td className="py-2 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(group)}>
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ),
                            )}
                        </tbody>
                    </table>
                </div>

                <form onSubmit={submitAdd} className="border-sidebar-border/70 flex flex-wrap items-end gap-3 rounded-md border p-4">
                    <div className="grid gap-1">
                        <Label htmlFor="add-name" required>
                            New group
                        </Label>
                        <Input
                            id="add-name"
                            value={add.data.name}
                            onChange={(e) => add.setData('name', e.target.value)}
                            placeholder="e.g. Retail shop"
                            className="w-48"
                        />
                        <InputError message={add.errors.name} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="add-code" required>
                            Code
                        </Label>
                        <Input
                            id="add-code"
                            value={add.data.code}
                            onChange={(e) => add.setData('code', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))}
                            placeholder="RETAIL_SHOP"
                            className="w-40 font-mono"
                        />
                        <InputError message={add.errors.code} />
                    </div>
                    <label className="flex h-9 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={add.data.falls_back_to_mrp}
                            onChange={(e) => add.setData('falls_back_to_mrp', e.target.checked)}
                        />
                        Pays MRP when no price is set
                    </label>
                    <label className="flex h-9 items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={add.data.prices_include_tax}
                            onChange={(e) => add.setData('prices_include_tax', e.target.checked)}
                        />
                        Prices include VAT
                    </label>
                    <Button disabled={add.processing}>Add group</Button>
                </form>
            </div>
        </AppLayout>
    );
}
