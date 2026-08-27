import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Warehouses', href: '/inventory/warehouses' },
];

export default function WarehousesIndex({ warehouses }: { warehouses: Warehouse[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ name: '', code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.warehouses.store'), { onSuccess: () => reset() });
    };

    const destroy = (warehouse: Warehouse) => {
        router.delete(route('inventory.warehouses.destroy', warehouse.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Warehouses" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="flex items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Cold Storage" />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code</Label>
                        <Input
                            id="code"
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder="e.g. COLD"
                            className="w-32"
                        />
                        <InputError message={errors.code} />
                    </div>
                    <Button disabled={processing}>Add</Button>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Name</th>
                            <th className="py-2">Code</th>
                            <th className="py-2">Status</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {warehouses.map((warehouse) => (
                            <tr key={warehouse.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{warehouse.name}</td>
                                <td className="text-muted-foreground py-2">{warehouse.code}</td>
                                <td className="py-2">
                                    {warehouse.is_default && <Badge variant="secondary">Default</Badge>}
                                    {!warehouse.is_active && <Badge variant="destructive">Inactive</Badge>}
                                </td>
                                <td className="py-2 text-right">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={warehouse.is_default}
                                        onClick={() => destroy(warehouse)}
                                        title={warehouse.is_default ? 'The default warehouse cannot be deleted' : undefined}
                                    >
                                        Delete
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {warehouses.length === 0 && (
                            <tr>
                                <td colSpan={4} className="text-muted-foreground py-4 text-center">
                                    No warehouses yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
