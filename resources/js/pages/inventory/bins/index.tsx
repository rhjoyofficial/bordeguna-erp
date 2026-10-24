import { MasterRowActions, type MasterRowState } from '@/components/corrections/master-row-actions';
import { BinDialog } from '@/components/inventory/bin-dialog';
import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Bin {
    id: number;
    warehouse_id: number;
    warehouse: string | null;
    code: string;
    name: string | null;
    is_active: boolean;
    occupied_count: number;
}

interface Warehouse {
    id: number;
    name: string;
    code: string;
}

const breadcrumbs = inventoryCrumbs('Setup', 'Bins');

/**
 * One bin row. Mirrors the warehouses screen: the status is a control, Edit
 * opens the same popup as Add (14 Oct 2026; until then it turned code and name
 * into inputs in place), and the delete refusal for a bin with history arrives
 * as a readable sentence from the server.
 */
function BinRow({
    bin,
    canManage,
    onEdit,
    state,
    reasons,
}: {
    bin: Bin;
    canManage: boolean;
    onEdit: () => void;
    state: MasterRowState | undefined;
    reasons: ReasonOption[];
}) {
    return (
        <tr className={cn('border-b', !bin.is_active && 'opacity-60')}>
            <td className="text-muted-foreground p-3">{bin.warehouse}</td>
            <td className="p-3 font-mono">{bin.code}</td>
            <td className="text-muted-foreground p-3">
                {bin.name}
                {bin.occupied_count > 0 && (
                    <span className="text-muted-foreground/70 ml-2 text-xs">
                        {bin.occupied_count} batch{bin.occupied_count === 1 ? '' : 'es'} placed
                    </span>
                )}
            </td>
            <td className="p-3">
                {/* ⭐ B24: switching off asks for a reason now, from the row's actions. */}
                <StatusBadge bin={bin} />
            </td>
            <td className="p-3 text-right whitespace-nowrap">
                {canManage && (
                    <>
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            Edit
                        </Button>
                        {/* ⭐ B24 (24 Oct 2026): Delete when never used, else Switch off with a reason. */}
                        <MasterRowActions state={state} name={bin.code} reasons={reasons} />
                    </>
                )}
            </td>
        </tr>
    );
}

function StatusBadge({ bin }: { bin: Bin }) {
    return <Badge variant={bin.is_active ? 'default' : 'secondary'}>{bin.is_active ? 'Active' : 'Inactive'}</Badge>;
}

export default function BinsIndex({
    bins,
    warehouses,
    recordActions,
    deleteReasons,
}: {
    bins: Bin[];
    warehouses: Warehouse[];
    recordActions: Record<number, MasterRowState>;
    deleteReasons: ReasonOption[];
}) {
    // ⭐ Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit
    // will from the pop up, not like current above the list"). undefined is
    // closed, null is a new bin.
    const [dialog, setDialog] = useState<Bin | null | undefined>(undefined);

    // ⚠ Staff read this page and were offered Add, Edit, Delete and the status
    // switch, every one of which answered 403 (21 Sep 2026 audit).
    const { manageCatalog } = useInventoryAbilities();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bins" />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add bin</Button>} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Warehouse</th>
                                <th className="p-3">Code</th>
                                <th className="p-3">Name</th>
                                <th className="p-3">Status</th>
                                <th className="p-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bins.map((bin) => (
                                <BinRow
                                    key={bin.id}
                                    bin={bin}
                                    canManage={manageCatalog}
                                    onEdit={() => setDialog(bin)}
                                    state={recordActions[bin.id]}
                                    reasons={deleteReasons}
                                />
                            ))}
                            {bins.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-4 text-center">
                                        {manageCatalog ? 'No bins yet. Add the shelf locations of a warehouse with Add bin.' : 'No bins yet.'}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {manageCatalog && (
                    <BinDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        bin={dialog ?? null}
                        warehouses={warehouses}
                    />
                )}
            </div>
        </AppLayout>
    );
}
