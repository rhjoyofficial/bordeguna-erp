import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Category {
    id: number;
    name: string;
    slug: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Categories', href: '/inventory/categories' },
];

export default function CategoriesIndex({ categories }: { categories: Category[] }) {
    const { data, setData, post, errors, processing, reset } = useForm({ name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('inventory.categories.store'), { onSuccess: () => reset() });
    };

    const destroy = (category: Category) => {
        router.delete(route('inventory.categories.destroy', category.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categories" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="flex items-end gap-3">
                    <div className="grid gap-2">
                        <Label htmlFor="name">New category</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Raw Materials" />
                        <InputError message={errors.name} />
                    </div>
                    <Button disabled={processing}>Add</Button>
                </form>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border border-b text-left text-muted-foreground">
                            <th className="py-2">Name</th>
                            <th className="py-2">Slug</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {categories.map((category) => (
                            <tr key={category.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{category.name}</td>
                                <td className="py-2 text-muted-foreground">{category.slug}</td>
                                <td className="py-2 text-right">
                                    <Button variant="ghost" size="sm" onClick={() => destroy(category)}>
                                        Delete
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {categories.length === 0 && (
                            <tr>
                                <td colSpan={3} className="py-4 text-center text-muted-foreground">
                                    No categories yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
