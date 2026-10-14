import { UnitDialog } from '@/components/inventory/unit-dialog';
import { ReportHeading } from '@/components/report/report-shell';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Unit {
    id: number;
    name: string;
    abbreviation: string;
    products_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Units of measure', href: '/inventory/units' },
];

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
    return (
        <tr className="border-b">
            <td className="p-3">{unit.name}</td>
            <td className="text-muted-foreground p-3 font-mono">{unit.abbreviation}</td>
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Units of measure" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Units of measure" actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add unit</Button>} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Abbreviation</th>
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
                                    <td colSpan={4} className="text-muted-foreground p-4 text-center">
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
