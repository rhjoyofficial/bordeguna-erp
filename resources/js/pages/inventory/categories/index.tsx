import { MasterRowActions, type MasterRowState } from '@/components/corrections/master-row-actions';
import { CategoryDialog } from '@/components/inventory/category-dialog';
import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Category {
    id: number;
    name: string;
    slug: string;
    code: string | null;
    /** Market pricing defaults (Phase 2 / 1B, 17 Oct 2026); null = no rule. */
    market_markup_percent: string | null;
    market_min_margin_percent: string | null;
    market_rounding_step: string | null;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Categories');

export default function CategoriesIndex({
    categories,
    recordActions,
    deleteReasons,
}: {
    categories: Category[];
    recordActions: Record<number, MasterRowState>;
    deleteReasons: ReasonOption[];
}) {
    // ⭐ Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). undefined is
    // closed, null is a new category.
    const [dialog, setDialog] = useState<Category | null | undefined>(undefined);
    // ⚠ Staff read this page (inventory.reports.view) and were offered Add,
    // Edit and Delete, every one of which answered 403 (21 Sep 2026 audit).
    const { manageCatalog } = useInventoryAbilities();

    const missingCodes = categories.filter((category) => !category.code).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categories" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add category</Button>} />

                <div className="text-muted-foreground max-w-3xl text-sm">
                    {missingCodes > 0 && (
                        <p>
                            {missingCodes} {missingCodes === 1 ? 'category has' : 'categories have'} no code yet. Products in{' '}
                            {missingCodes === 1 ? 'it' : 'them'} need their SKU typed by hand until one is set.
                        </p>
                    )}
                </div>

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
                                {/* ⭐ 18 Oct 2026 (Joy, D-E): no market pricing rules column in Phase 1. */}
                                <td className="py-2 text-right">
                                    {manageCatalog && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => setDialog(category)}>
                                                Edit
                                            </Button>
                                            {/* ⭐ B24 (24 Oct 2026): Delete when never used, else Switch off with a reason. */}
                                            <MasterRowActions state={recordActions[category.id]} name={category.name} reasons={deleteReasons} />
                                        </>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {categories.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground py-4 text-center">
                                    No categories yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                {manageCatalog && (
                    <CategoryDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} category={dialog ?? null} />
                )}
            </div>
        </AppLayout>
    );
}
