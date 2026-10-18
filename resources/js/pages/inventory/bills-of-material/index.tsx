import { ConfirmDialog } from '@/components/confirm-dialog';
import { inventoryCrumbs } from '@/components/inventory/inventory-ui';
import { RecipeDialog, type RecipeProductOption } from '@/components/inventory/recipe-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { formatQuantity, plainNumber } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface BomLine {
    component_product_id: number;
    sku: string | null;
    component: string | null;
    unit: string | null;
    component_active: boolean;
    quantity_per_output: string;
    /** Phase 1 Part B: what was typed, in the unit it was typed in; quantity_per_output is the stock figure. */
    entered_quantity: string;
    entered_unit_id: number | null;
    entered_unit: string | null;
    wastage_percent: string;
    note: string | null;
}

interface Bom {
    id: number;
    output_product_id: number;
    output_sku: string | null;
    output_product: string | null;
    output_unit: string | null;
    output_quantity: string;
    version: number;
    name: string | null;
    is_active: boolean;
    effective_from: string | null;
    note: string | null;
    runs: number;
    /** PR3: what the default warehouse's shelf can make now; null for a switched-off version. */
    can_make: { quantity: string; limited_by_product_id: number; limited_by: string | null } | null;
    lines: BomLine[];
}

const breadcrumbs = inventoryCrumbs('Production', 'Recipes');

export default function BillsOfMaterialIndex({
    boms,
    products,
    canMakeWarehouse,
}: {
    boms: Bom[];
    products: RecipeProductOption[];
    canMakeWarehouse: string | null;
}) {
    // ⭐ New recipe and Edit open one wide popup over the list (Joy, 14 Oct 2026:
    // "click add button pop up will add, same when edit"). `undefined` = closed,
    // `null` = a new recipe.
    const [dialog, setDialog] = useState<Bom | null | undefined>(undefined);
    const { t } = useTranslation();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recipes" />
            <div className="flex flex-col gap-4 p-4">
                {/* No permission check of its own: the whole page needs the same
                    one as saving (inventory.bom.manage). */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">What a run suggests consuming. A recipe posts nothing by itself.</p>
                    <Button onClick={() => setDialog(null)}>New recipe</Button>
                </div>

                <div className="flex flex-col gap-3">
                    {boms.length === 0 && (
                        <div className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                            No recipes yet. The first one worth writing is the pickle packing run.
                        </div>
                    )}

                    {boms.map((bom) => (
                        <div key={bom.id} className={`rounded-lg border ${bom.is_active ? '' : 'opacity-60'}`}>
                            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b p-3">
                                <div>
                                    <p className="font-medium">
                                        {bom.output_product} <span className="text-muted-foreground font-mono text-xs">{bom.output_sku}</span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        v{bom.version}
                                        {bom.name ? ` · ${bom.name}` : ''} · makes {formatQuantity(bom.output_quantity)} {bom.output_unit}
                                        {bom.effective_from ? ` · from ${formatDate(bom.effective_from)}` : ''}
                                        {bom.runs > 0 ? ` · ${bom.runs} run(s) built from it` : ''}
                                        {bom.is_active ? '' : ' · switched off'}
                                    </p>
                                    {bom.can_make && canMakeWarehouse && (
                                        <p
                                            className={`mt-1 text-xs ${Number(bom.can_make.quantity) > 0 ? '' : 'text-amber-700 dark:text-amber-400'}`}
                                        >
                                            {t('production.recipes.can_make', {
                                                warehouse: canMakeWarehouse,
                                                quantity: formatQuantity(bom.can_make.quantity),
                                                unit: bom.output_unit ?? '',
                                                component: bom.can_make.limited_by ?? '',
                                            })}
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {bom.is_active && (
                                        <Button asChild variant="outline" size="sm">
                                            <Link href={`${route('inventory.production-runs.create')}?product=${bom.output_product_id}`}>
                                                {t('production.recipes.make_now')}
                                            </Link>
                                        </Button>
                                    )}
                                    <Button variant="outline" size="sm" onClick={() => setDialog(bom)}>
                                        Edit
                                    </Button>
                                    {bom.is_active ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('inventory.bills-of-material.deactivate', bom.id))}
                                        >
                                            Switch off
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => router.post(route('inventory.bills-of-material.activate', bom.id))}
                                        >
                                            Use this one
                                        </Button>
                                    )}
                                    {/*
                                        ⚠ Offered only for a version no run was built
                                        from. A used version is the answer to "what
                                        recipe was that run built from"; the server
                                        refuses it too.
                                    */}
                                    {/* MFC-009 (14 Oct 2026): asked first, never one click. */}
                                    {bom.runs === 0 && (
                                        <ConfirmDialog
                                            trigger={
                                                <Button variant="ghost" size="sm">
                                                    Delete
                                                </Button>
                                            }
                                            title={t('production.recipe_delete.title')}
                                            description={t('production.recipe_delete.description', {
                                                version: bom.version,
                                                product: bom.output_product ?? bom.output_sku ?? '',
                                            })}
                                            confirmLabel={t('production.recipe_delete.confirm')}
                                            cancelLabel={t('production.cancel')}
                                            destructive
                                            onConfirm={() => router.delete(route('inventory.bills-of-material.destroy', bom.id))}
                                        />
                                    )}
                                </div>
                            </div>
                            <table className="w-full text-sm">
                                <tbody>
                                    {bom.lines.map((line) => (
                                        <tr key={line.component_product_id} className="border-b last:border-0">
                                            <td className="p-3 font-mono text-xs">{line.sku}</td>
                                            <td className="p-3">
                                                {line.component}
                                                {!line.component_active && (
                                                    <span className="ml-2 text-xs text-amber-600">this component is inactive</span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {formatQuantity(line.entered_quantity)} {line.entered_unit}
                                                {line.entered_unit !== line.unit && (
                                                    <span className="text-muted-foreground ml-2 text-xs">
                                                        {t('units.recipe.in_stock', {
                                                            quantity: formatQuantity(line.quantity_per_output),
                                                            unit: line.unit ?? '',
                                                        })}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground w-28 p-3 text-right text-xs">
                                                {Number(line.wastage_percent) > 0 ? `+${plainNumber(line.wastage_percent)}% wastage` : ''}
                                            </td>
                                            <td className="text-muted-foreground p-3 text-xs">{line.note}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
                </div>

                <RecipeDialog
                    open={dialog !== undefined}
                    onOpenChange={(open) => !open && setDialog(undefined)}
                    bom={dialog ?? null}
                    products={products}
                />
            </div>
        </AppLayout>
    );
}
