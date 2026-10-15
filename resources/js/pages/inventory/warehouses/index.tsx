import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { WarehouseDialog } from '@/components/inventory/warehouse-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Warehouse {
    id: number;
    name: string;
    code: string;
    is_default: boolean;
    is_active: boolean;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Warehouses');

/**
 * One warehouse row. The status is a button (30 Aug 2026 audit, root cause C:
 * `update` accepted `is_active` all along and the screen never sent it). Edit
 * opens the same popup as Add (14 Oct 2026); until then it turned the row
 * itself into inputs.
 *
 * ⚠ The default warehouse's status button is disabled with the reason in its
 * title; the server refuses too, so a stale page cannot get past it.
 */
function WarehouseRow({ warehouse, canManage, onEdit }: { warehouse: Warehouse; canManage: boolean; onEdit: () => void }) {
    const toggle = () => router.patch(route('inventory.warehouses.toggle-active', warehouse.id), {}, { preserveScroll: true });
    const makeDefault = () => router.patch(route('inventory.warehouses.make-default', warehouse.id), {}, { preserveScroll: true });

    return (
        <tr className={cn('border-b', !warehouse.is_active && 'opacity-60')}>
            <td className="p-3">{warehouse.name}</td>
            <td className="text-muted-foreground p-3 font-mono">{warehouse.code}</td>
            <td className="p-3">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={toggle}
                        disabled={!canManage || (warehouse.is_default && warehouse.is_active)}
                        title={
                            warehouse.is_default
                                ? 'The default warehouse cannot be switched off'
                                : warehouse.is_active
                                  ? 'Click to switch off'
                                  : 'Click to switch on'
                        }
                        className="rounded-full disabled:cursor-not-allowed"
                    >
                        <StatusBadge warehouse={warehouse} />
                    </button>
                    {warehouse.is_default && <Badge variant="outline">Default</Badge>}
                </div>
            </td>
            <td className="p-3 text-right whitespace-nowrap">
                {canManage && (
                    <>
                        {/* 21 Sep 2026: `is_default` had no control anywhere, so the
                            warehouse every screen preselects could not be chosen. */}
                        {!warehouse.is_default && warehouse.is_active && (
                            <Button variant="ghost" size="sm" onClick={makeDefault}>
                                Make default
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={warehouse.is_default}
                            onClick={() => router.delete(route('inventory.warehouses.destroy', warehouse.id), { preserveScroll: true })}
                            title={warehouse.is_default ? 'The default warehouse cannot be deleted' : undefined}
                        >
                            Delete
                        </Button>
                    </>
                )}
            </td>
        </tr>
    );
}

function StatusBadge({ warehouse }: { warehouse: Warehouse }) {
    return <Badge variant={warehouse.is_active ? 'default' : 'secondary'}>{warehouse.is_active ? 'Active' : 'Inactive'}</Badge>;
}

export default function WarehousesIndex({ warehouses }: { warehouses: Warehouse[] }) {
    // ⭐ Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). undefined is
    // closed, null is a new warehouse.
    const [dialog, setDialog] = useState<Warehouse | null | undefined>(undefined);
    // ⚠ Staff read this page (inventory.reports.view) and were offered Add,
    // Edit and Delete, every one of which answered 403.
    const { manageCatalog } = useInventoryAbilities();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Warehouses" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add warehouse</Button>} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Name</th>
                                <th className="p-3">Code</th>
                                <th className="p-3">Status</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {warehouses.map((warehouse) => (
                                <WarehouseRow
                                    key={warehouse.id}
                                    warehouse={warehouse}
                                    canManage={manageCatalog}
                                    onEdit={() => setDialog(warehouse)}
                                />
                            ))}
                            {warehouses.length === 0 && (
                                <tr>
                                    <td colSpan={4} className="text-muted-foreground p-4 text-center">
                                        {manageCatalog
                                            ? 'No warehouses yet. Add the first one with Add warehouse: stock, opening stock and counts all need one.'
                                            : 'No warehouses yet. Ask a manager to add one.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {manageCatalog && (
                    <WarehouseDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} warehouse={dialog ?? null} />
                )}
            </div>
        </AppLayout>
    );
}
