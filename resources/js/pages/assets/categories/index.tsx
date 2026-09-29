import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Category {
    id: number;
    name: string;
    code: string;
    default_tracking: string;
    default_tracking_label: string;
    is_active: boolean;
    items_count: number;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Asset categories', href: '/assets/categories' },
];

function CategoryForm({ category, trackings, onDone }: { category: Category | null; trackings: Option[]; onDone?: () => void }) {
    const { data, setData, post, put, errors, processing, reset } = useForm({
        name: category?.name ?? '',
        code: category?.code ?? '',
        default_tracking: category?.default_tracking ?? 'individual',
        is_active: category?.is_active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (category) {
            put(`/assets/categories/${category.id}`, { preserveScroll: true, onSuccess: () => onDone?.() });
        } else {
            post('/assets/categories', { preserveScroll: true, onSuccess: () => reset() });
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1">
                <Label required>Name</Label>
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-56" placeholder="Kitchen equipment" />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-1">
                <Label required>Code on the tag</Label>
                <Input
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value.toUpperCase())}
                    className="w-24 font-mono uppercase"
                    placeholder="KIT"
                    maxLength={6}
                    disabled={category !== null && category.items_count > 0}
                />
                <InputError message={errors.code} />
            </div>
            <div className="grid gap-1">
                <Label required>New ones are</Label>
                <SearchableSelect fit value={data.default_tracking} onChange={(v) => setData('default_tracking', v)} options={trackings} />
                <InputError message={errors.default_tracking} />
            </div>
            {category && (
                <div className="flex items-center gap-2 self-center">
                    <Checkbox id={`active-${category.id}`} checked={data.is_active} onCheckedChange={(on) => setData('is_active', on === true)} />
                    <Label htmlFor={`active-${category.id}`} className="font-normal">
                        In use
                    </Label>
                </div>
            )}
            <Button size="sm" disabled={processing}>
                {category ? 'Save' : 'Add category'}
            </Button>
            {category && (
                <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            )}
        </form>
    );
}

/**
 * The kinds of thing the business keeps (slice A1).
 *
 * ⚠ Switched off, never deleted: things keep their category. A code already on
 * a sticker cannot change.
 */
export default function AssetCategories({ categories, trackings, can_manage }: { categories: Category[]; trackings: Option[]; can_manage: boolean }) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asset categories" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Asset categories</h1>
                </div>

                {can_manage && (
                    <div className="rounded-lg border p-3">
                        <CategoryForm category={null} trackings={trackings} />
                    </div>
                )}

                <ul className="grid gap-2">
                    {categories.map((category) => (
                        <li key={category.id} className="rounded-lg border p-3 text-sm">
                            {editing === category.id ? (
                                <CategoryForm category={category} trackings={trackings} onDone={() => setEditing(null)} />
                            ) : (
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{category.name}</span>
                                        <span className="text-muted-foreground font-mono text-xs">{category.code}</span>
                                        <span className="text-muted-foreground">
                                            {category.default_tracking_label} · {category.items_count} thing{category.items_count === 1 ? '' : 's'}
                                        </span>
                                        {!category.is_active && <Badge variant="outline">Switched off</Badge>}
                                    </div>
                                    {can_manage && (
                                        <Button size="sm" variant="ghost" onClick={() => setEditing(category.id)}>
                                            Edit
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </AppLayout>
    );
}
