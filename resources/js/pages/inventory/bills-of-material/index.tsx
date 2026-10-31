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
    /** ⭐ Initial plan B5 (24 Oct 2026): its section, rate per stock unit (and which figure), and cost. */
    section: 'raw' | 'packaging';
    unit_rate: string | null;
    rate_basis: 'latest_batch' | null;
    /** Q20: a labelled hint only; the rate is always the latest batch's exact cost. */
    on_hand_average: string | null;
    line_total: string | null;
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
    /** ⭐ B5: the subtotals and the cost of one unit of output. */
    raw_total: string;
    packaging_total: string;
    direct_materials: string;
    cost_per_unit: string | null;
    cost_complete: boolean;
}

const taka = (value: string | number) => `৳${Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * ⭐ Initial plan B5 (Joy, 24 Oct 2026; R25, R26): a recipe read the way he
 * wrote his example. Raw materials, then Packaging materials, each line with
 * its rate and total, each section with its subtotal, then "Subtotal: Direct
 * materials" and the cost of one unit of output. The figures are the
 * server's (RecipeCosting); this only lays them out.
 */
function CostedLines({ bom }: { bom: Bom }) {
    const { t } = useTranslation();
    const sections: { key: 'raw' | 'packaging'; title: string; total: string }[] = [
        { key: 'raw', title: t('ipc.recipe.raw'), total: bom.raw_total },
        { key: 'packaging', title: t('ipc.recipe.packaging'), total: bom.packaging_total },
    ];
    const missing = bom.lines.filter((line) => line.unit_rate === null).length;

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-muted-foreground text-left text-xs">
                    <tr className="border-b">
                        <th className="p-3 font-medium">SKU</th>
                        <th className="p-3 font-medium">Item</th>
                        <th className="p-3 text-right font-medium">
                            Quantity per {formatQuantity(bom.output_quantity)} {bom.output_unit}
                        </th>
                        <th className="p-3 text-right font-medium">{t('ipc.recipe.rate')}</th>
                        <th className="p-3 text-right font-medium">{t('ipc.recipe.line_total')}</th>
                        <th className="p-3 font-medium"></th>
                    </tr>
                </thead>
                {sections.map((section) => {
                    const lines = bom.lines.filter((line) => line.section === section.key);

                    if (lines.length === 0) return null;

                    return (
                        <tbody key={section.key}>
                            <tr className="bg-muted/40">
                                <td colSpan={6} className="px-3 py-1.5 text-xs font-semibold tracking-wide uppercase">
                                    {section.title}
                                </td>
                            </tr>
                            {lines.map((line) => (
                                <tr key={line.component_product_id} className="border-b">
                                    <td className="p-3 font-mono text-xs">{line.sku}</td>
                                    <td className="p-3">
                                        {line.component}
                                        {!line.component_active && <span className="ml-2 text-xs text-amber-600">this component is inactive</span>}
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
                                        {Number(line.wastage_percent) > 0 && (
                                            <span className="text-muted-foreground block text-xs">+{plainNumber(line.wastage_percent)}% wastage</span>
                                        )}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {line.unit_rate === null ? (
                                            <span className="text-muted-foreground text-xs">{t('ipc.recipe.no_rate')}</span>
                                        ) : (
                                            <>
                                                {taka(line.unit_rate)}{' '}
                                                <span className="text-muted-foreground text-xs">
                                                    {t('ipc.recipe.per', { unit: line.unit ?? '' })}
                                                </span>
                                                {line.rate_basis && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {t(`ipc.recipe.basis.${line.rate_basis}`)}
                                                    </span>
                                                )}
                                                {line.on_hand_average !== null && line.on_hand_average !== line.unit_rate && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {t('ipc.recipe.average_hint', { cost: taka(line.on_hand_average) })}
                                                    </span>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="p-3 text-right font-medium tabular-nums">
                                        {line.line_total === null ? '-' : taka(line.line_total)}
                                    </td>
                                    <td className="text-muted-foreground p-3 text-xs">{line.note}</td>
                                </tr>
                            ))}
                            <tr className="border-b">
                                <td colSpan={4} className="text-muted-foreground p-3 text-right text-xs">
                                    {t('ipc.recipe.subtotal', { section: section.title })}
                                </td>
                                <td className="p-3 text-right tabular-nums">{taka(section.total)}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    );
                })}
                <tfoot>
                    <tr>
                        <td colSpan={4} className="p-3 text-right font-semibold">
                            {t('ipc.recipe.direct')}
                        </td>
                        <td className="p-3 text-right font-semibold tabular-nums">{taka(bom.direct_materials)}</td>
                        <td></td>
                    </tr>
                    {bom.cost_per_unit !== null && Number(bom.output_quantity) !== 1 && (
                        <tr>
                            <td colSpan={4} className="text-muted-foreground p-3 pt-0 text-right text-xs">
                                {t('ipc.recipe.per_unit', { unit: bom.output_unit ?? '' })}
                            </td>
                            <td className="p-3 pt-0 text-right text-xs tabular-nums">{taka(bom.cost_per_unit)}</td>
                            <td></td>
                        </tr>
                    )}
                    <tr>
                        <td colSpan={6} className="text-muted-foreground px-3 pb-3 text-xs">
                            {missing > 0 && (
                                <span className="block text-amber-700 dark:text-amber-400">{t('ipc.recipe.incomplete', { count: missing })}</span>
                            )}
                            {t('ipc.recipe.estimate_note')}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
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
                <div className="flex flex-wrap items-center justify-end gap-3">
                    <Button onClick={() => setDialog(null)}>New recipe</Button>
                </div>

                <div className="flex flex-col gap-3">
                    {boms.length === 0 && <div className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No recipes yet.</div>}

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
                                        {bom.effective_from ? ` · ${t('ipc.recipe.in_use_from')} ${formatDate(bom.effective_from)}` : ''}
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
                            <CostedLines bom={bom} />
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
