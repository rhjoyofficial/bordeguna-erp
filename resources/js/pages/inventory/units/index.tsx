import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Unit {
    id: number;
    name: string;
    abbreviation: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Units of measure', href: '/inventory/units' },
];

export default function UnitsIndex({ units }: { units: Unit[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ name: '', abbreviation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.units.store'), { onSuccess: () => reset() });
    };

    const destroy = (unit: Unit) => {
        router.delete(route('inventory.units.destroy', unit.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Units of measure" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="flex items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Kilogram" />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="abbreviation">Abbreviation</Label>
                        <Input
                            id="abbreviation"
                            value={data.abbreviation}
                            onChange={(e) => setData('abbreviation', e.target.value)}
                            placeholder="e.g. kg"
                            className="w-32"
                        />
                        <InputError message={errors.abbreviation} />
                    </div>
                    <Button disabled={processing}>Add</Button>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Name</th>
                            <th className="py-2">Abbreviation</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {units.map((unit) => (
                            <tr key={unit.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{unit.name}</td>
                                <td className="text-muted-foreground py-2">{unit.abbreviation}</td>
                                <td className="py-2 text-right">
                                    <Button variant="ghost" size="sm" onClick={() => destroy(unit)}>
                                        Delete
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {units.length === 0 && (
                            <tr>
                                <td colSpan={3} className="text-muted-foreground py-4 text-center">
                                    No units yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
