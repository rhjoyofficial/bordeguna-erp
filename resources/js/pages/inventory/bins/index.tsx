import { BinDialog } from '@/components/inventory/bin-dialog';
import { ReportHeading } from '@/components/report/report-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInventoryAbilities } from '@/hooks/use-inventory-abilities';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Inventory', href: '/inventory' },
    { title: 'Bins', href: '/inventory/bins' },
];

/**
 * One bin row. Mirrors the warehouses screen: the status is a control, Edit
 * opens the same popup as Add (14 Oct 2026; until then it turned code and name
 * into inputs in place), and the delete refusal for a bin with history arrives
 * as a readable sentence from the server.
 */
function BinRow({ bin, canManage, onEdit }: { bin: Bin; canManage: boolean; onEdit: () => void }) {
    const toggle = () => router.patch(route('inventory.bins.toggle-active', bin.id), {}, { preserveScroll: true });

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
                <button
                    type="button"
                    onClick={toggle}
                    disabled={!canManage}
                    title={canManage ? (bin.is_active ? 'Click to switch off' : 'Click to switch on') : undefined}
                    className="rounded-full disabled:cursor-default"
                >
                    <StatusBadge bin={bin} />
                </button>
            </td>
            <td className="p-3 text-right whitespace-nowrap">
                {canManage && (
                    <>
                        <Button variant="ghost" size="sm" onClick={onEdit}>
                            Edit
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.delete(route('inventory.bins.destroy', bin.id), { preserveScroll: true })}
                        >
                            Delete
                        </Button>
                    </>
                )}
            </td>
        </tr>
    );
}

function StatusBadge({ bin }: { bin: Bin }) {
    return <Badge variant={bin.is_active ? 'default' : 'secondary'}>{bin.is_active ? 'Active' : 'Inactive'}</Badge>;
}

export default function BinsIndex({ bins, warehouses }: { bins: Bin[]; warehouses: Warehouse[] }) {
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
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Bins" actions={manageCatalog && <Button onClick={() => setDialog(null)}>Add bin</Button>} />

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
                                <BinRow key={bin.id} bin={bin} canManage={manageCatalog} onEdit={() => setDialog(bin)} />
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
