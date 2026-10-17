import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { UnitDialog } from '@/components/inventory/unit-dialog';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Unit {
    id: number;
    name: string;
    abbreviation: string;
    /** Phase 1 Part B: mass | volume | count | null, filled from the name, never typed. */
    dimension: 'mass' | 'volume' | 'count' | null;
    factor_to_base: string | null;
    products_count: number;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Units');

/**
 * One unit. Edit opens the same popup as Add (14 Oct 2026); until then it
 * turned the row into inputs in place, like warehouses.
 *
 * ⚠ The update route existed and the screen offered only Delete (21 Sep 2026
 * audit), so a misspelt unit already on products could be neither fixed nor
 * removed. Delete is disabled, with the reason, once products use the unit;
 * the server refuses too, so a stale page cannot get past it.
 */
function UnitRow({ unit, canManage, onEdit }: { unit: Unit; canManage: boolean; onEdit: () => void }) {
    const { t } = useTranslation();

    return (
        <tr className="border-b">
            <td className="p-3">{unit.name}</td>
            <td className="text-muted-foreground p-3 font-mono">{unit.abbreviation}</td>
            <td className="text-muted-foreground p-3 text-xs">
                {unit.dimension === null ? t('units.dimension.none') : t(`units.dimension.${unit.dimension}`)}
                {unit.factor_to_base !== null && unit.dimension !== 'count' && (
                    <span className="ml-1 tabular-nums">
                        (= {Number(unit.factor_to_base)} {unit.dimension === 'mass' ? 'g' : 'mL'})
                    </span>
                )}
            </td>
            <td className="text-muted-foreground p-3 text-right tabular-nums">{unit.products_count}</td>
            <td className="p-3 text-right whitespace-nowrap">
                {canManage && (
                    <>
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={unit.products_count > 0}
                            title={unit.products_count > 0 ? 'Products use this unit, so it cannot be deleted. Edit it instead.' : undefined}
                            onClick={() => router.delete(route('inventory.units.destroy', unit.id), { preserveScroll: true })}
                        >
                            Delete
                        </Button>
                    </>
                )}
            </td>
        </tr>
    );
}

export default function UnitsIndex({ units }: { units: Unit[] }) {
    // ⭐ Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). undefined is
    // closed, null is a new unit.
    const [dialog, setDialog] = useState<Unit | null | undefined>(undefined);
    // ⚠ Staff read this page and were offered Add and Delete, both 403.
    const { manageCatalog } = useInventoryAbilities();
    const { t } = useTranslation();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Units of measure" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add unit</Button>} />
                <p className="text-muted-foreground max-w-3xl text-sm">{t('units.dimension.hint')}</p>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Abbreviation</th>
                                <th className="p-3">{t('units.dimension')}</th>
                                <th className="p-3 text-right">Products</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {units.map((unit) => (
                                <UnitRow key={unit.id} unit={unit} canManage={manageCatalog} onEdit={() => setDialog(unit)} />
                            ))}
                            {units.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                        {manageCatalog
                                            ? 'No units yet. Add the ones you count in with Add unit; a product cannot be saved without one.'
                                            : 'No units yet. Ask a manager to add them.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {manageCatalog && (
                    <UnitDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} unit={dialog ?? null} />
                )}
            </div>
        </AppLayout>
    );
}
