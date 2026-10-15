import { BrandDialog } from '@/components/inventory/brand-dialog';
import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Brand {
    id: number;
    name: string;
    code: string;
    note: string | null;
    is_active: boolean;
    products_count: number;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Brands');

/**
 * Brands are the axis profit reports run along, so they are rows here rather
 * than free text on products. The code is a SKU segment and is permanent once
 * created; a brand is retired, never deleted, so history keeps its label.
 */
export default function BrandsIndex({ brands }: { brands: Brand[] }) {
    // ⭐ Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). undefined is
    // closed, null is a new brand.
    const [dialog, setDialog] = useState<Brand | null | undefined>(undefined);
    // ⚠ Staff read this page and were offered Add, Edit and Retire, all 403.
    const { manageCatalog } = useInventoryAbilities();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Brands" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add brand</Button>} />

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
                                    {manageCatalog && (
                                        <>
                                            <Button variant="ghost" size="sm" onClick={() => setDialog(brand)}>
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.patch(route('inventory.brands.toggle-active', brand.id), {}, { preserveScroll: true })
                                                }
                                            >
                                                {brand.is_active ? 'Retire' : 'Reactivate'}
                                            </Button>
                                        </>
                                    )}
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

                {manageCatalog && (
                    <BrandDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} brand={dialog ?? null} />
                )}
            </div>
        </AppLayout>
    );
}
