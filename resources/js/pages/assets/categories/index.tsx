import { AssetCategoryDialog } from '@/components/assets/category-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Category {
    id: number;
    name: string;
    code: string;
    default_tracking: string;
    default_tracking_label: string;
    default_useful_life_months: number | null;
    is_active: boolean;
    items_count: number;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Things', href: '/assets/items' },
    { title: 'Categories', href: '/assets/categories' },
];

/**
 * The kinds of thing the business keeps (slice A1).
 *
 * ⚠ Switched off, never deleted: things keep their category. A code already on
 * a sticker cannot change.
 */
export default function AssetCategories({ categories, trackings, can_manage }: { categories: Category[]; trackings: Option[]; can_manage: boolean }) {
    // ⭐ Add and Edit open one popup over the list (Joy, 14 Oct 2026: "click add
    // button pop up will add, same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Category | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Asset categories" />
            <div className="flex flex-col gap-4 p-4">
                {/* The tab row above names the screen (15 Oct 2026). */}
                <div className="flex flex-wrap items-center justify-end gap-3">
                    {can_manage && (
                        <Button size="sm" onClick={() => setDialog(null)}>
                            Add category
                        </Button>
                    )}
                </div>

                <ul className="grid gap-2">
                    {categories.map((category) => (
                        <li key={category.id} className="rounded-lg border p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">{category.name}</span>
                                    <span className="text-muted-foreground font-mono text-xs">{category.code}</span>
                                    <span className="text-muted-foreground">
                                        {category.default_tracking_label}
                                        {category.default_useful_life_months ? ` · ${category.default_useful_life_months} months` : ''} ·{' '}
                                        {category.items_count} thing{category.items_count === 1 ? '' : 's'}
                                    </span>
                                    {!category.is_active && <Badge variant="outline">Switched off</Badge>}
                                </div>
                                {can_manage && (
                                    <Button size="sm" variant="ghost" onClick={() => setDialog(category)}>
                                        Edit
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>

                {can_manage && (
                    <AssetCategoryDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        category={dialog ?? null}
                        trackings={trackings}
                    />
                )}
            </div>
        </AppLayout>
    );
}
