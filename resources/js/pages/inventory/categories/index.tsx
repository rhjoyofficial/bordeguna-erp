import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Category {
    id: number;
    name: string;
    slug: string;
    code: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Categories', href: '/inventory/categories' },
];

const EMPTY = { name: '', code: '' };

export default function CategoriesIndex({ categories }: { categories: Category[] }) {
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);
    const [editing, setEditing] = useState<Category | null>(null);
    // ⚠ Staff read this page (inventory.reports.view) and were offered Add,
    // Edit and Delete, every one of which answered 403 (21 Sep 2026 audit).
    const { manageCatalog } = useInventoryAbilities();

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('inventory.categories.update', editing.id), {
                onSuccess: () => {
                    setEditing(null);
                    reset();
                },
            });

            return;
        }

        post(route('inventory.categories.store'), { onSuccess: () => reset() });
    };

    const edit = (category: Category) => {
        clearErrors();
        setEditing(category);
        setData({ name: category.name, code: category.code ?? '' });
    };

    const cancel = () => {
        clearErrors();
        setEditing(null);
        reset();
    };

    const destroy = (category: Category) => {
        router.delete(route('inventory.categories.destroy', category.id));
    };

    const missingCodes = categories.filter((category) => !category.code).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categories" />
            <div className="flex flex-col gap-6 p-4">
                <div className="text-muted-foreground max-w-3xl text-sm">
                    <p>
                        The <strong>SKU code</strong> is the part of a product code that comes from its category, so{' '}
                        <code className="font-mono">DAT</code> is what makes <code className="font-mono">BNC-DAT-AJW-1KG-LRG</code> a date. Fill it in
                        and every new product in that category can have its SKU built for you.
                    </p>
                    <p className="mt-2">
                        Packaging carries its own family in the code, so a jar category is <code className="font-mono">PK-JAR</code> and its products
                        come out as <code className="font-mono">PK-JAR-500G</code>.
                    </p>
                    {missingCodes > 0 && (
                        <p className="mt-2">
                            {missingCodes} {missingCodes === 1 ? 'category has' : 'categories have'} no code yet. Products in{' '}
                            {missingCodes === 1 ? 'it' : 'them'} need their SKU typed by hand until one is set.
                        </p>
                    )}
                </div>

                {manageCatalog && (
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="name" required>
                                {editing ? `Editing ${editing.name}` : 'New category'}
                            </Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="e.g. Bulk Honey" />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="code">SKU code</Label>
                            <Input
                                id="code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="e.g. HON"
                                className="w-40 font-mono"
                            />
                            <InputError message={errors.code} />
                        </div>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add'}</Button>
                        {editing && (
                            <Button type="button" variant="ghost" onClick={cancel}>
                                Cancel
                            </Button>
                        )}
                    </form>
                )}

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Name</th>
                            <th className="py-2">SKU code</th>
                            <th className="py-2">Slug</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {categories.map((category) => (
                            <tr key={category.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2">{category.name}</td>
                                <td className="py-2 font-mono text-xs">
                                    {category.code ?? <span className="text-muted-foreground font-sans">not set</span>}
                                </td>
                                <td className="text-muted-foreground py-2">{category.slug}</td>
                                <td className="py-2 text-right">
                                    {manageCatalog && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => edit(category)}>
                                                Edit
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => destroy(category)}>
                                                Delete
                                            </Button>
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {categories.length === 0 && (
                            <tr>
                                <td colSpan={4} className="text-muted-foreground py-4 text-center">
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
