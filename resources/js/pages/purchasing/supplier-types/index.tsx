import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SupplierTypeDialog, type DialogSupplierType } from '@/components/purchasing/supplier-type-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type SupplierType = DialogSupplierType;

interface Props {
    types: SupplierType[];
    /** purchasing.suppliers.manage: the page's own gate, beside the tab's. */
    canManageTypes: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Suppliers', href: '/purchasing/suppliers' },
    { title: 'Supplier types', href: '/purchasing/supplier-types' },
];

/** "3 suppliers, 1 tax rate", or null when nothing uses the type. */
function usedBy(type: SupplierType): string | null {
    const parts = [
        type.suppliers > 0 ? `${type.suppliers} ${type.suppliers === 1 ? 'supplier' : 'suppliers'}` : null,
        type.rates > 0 ? `${type.rates} ${type.rates === 1 ? 'tax rate' : 'tax rates'}` : null,
    ].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

/**
 * Purchases > Suppliers > Supplier types (Joy, 17 Oct 2026: more types than
 * the seven fixed ones). The type picks the TDS/VDS rates offered when a
 * supplier is paid.
 *
 * ⚠ A type something uses cannot be deleted, only switched off: the row then
 * says what uses it instead of offering Delete. A switched-off type is not
 * offered for new suppliers or rates; the ones that have it keep it.
 */
export default function SupplierTypes({ types, canManageTypes }: Props) {
    // `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<SupplierType | null | undefined>(undefined);
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    const post = (name: string, type: SupplierType) => router.post(route(name, type.id), {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier types" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        The kinds of supplier you buy from. A supplier's type decides which TDS and VDS rates are offered when you pay them.
                    </p>
                    {canManageTypes && (
                        <Button size="sm" onClick={() => setDialog(null)}>
                            Add type
                        </Button>
                    )}
                </div>

                <InputError message={errors.type} />

                <div className="bg-card overflow-x-auto rounded-xl border p-4">
                    {types.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No supplier types yet.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Name</th>
                                    <th className="hidden py-2 pr-3 sm:table-cell">Key</th>
                                    <th className="py-2 pr-3">Used by</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {types.map((type) => {
                                    const used = usedBy(type);

                                    return (
                                        <tr key={type.id} className="border-b align-top">
                                            <td className="py-2 pr-3">
                                                <span className="font-medium">{type.name}</span>
                                                {!type.is_active && (
                                                    <Badge variant="secondary" className="ml-2">
                                                        Switched off
                                                    </Badge>
                                                )}
                                                <div className="text-muted-foreground font-mono text-xs sm:hidden">{type.key}</div>
                                            </td>
                                            <td className="text-muted-foreground hidden py-2 pr-3 font-mono text-xs sm:table-cell">{type.key}</td>
                                            <td className="text-muted-foreground py-2 pr-3">{used ?? 'Not used'}</td>
                                            <td className="py-2 text-right">
                                                {canManageTypes && (
                                                    <div className="flex flex-wrap justify-end gap-x-3 gap-y-1">
                                                        <button type="button" className="text-xs underline" onClick={() => setDialog(type)}>
                                                            Edit
                                                        </button>
                                                        {type.is_active ? (
                                                            <ConfirmDialog
                                                                trigger={
                                                                    <button type="button" className="text-xs underline">
                                                                        Deactivate
                                                                    </button>
                                                                }
                                                                title={`Switch off ${type.name}?`}
                                                                description={
                                                                    used
                                                                        ? `It will not be offered for new suppliers or rates. The ${used} that have it keep it.`
                                                                        : 'It will not be offered for new suppliers or rates. You can switch it back on at any time.'
                                                                }
                                                                confirmLabel="Deactivate"
                                                                onConfirm={() => post('purchasing.supplier-types.deactivate', type)}
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-xs underline"
                                                                onClick={() => post('purchasing.supplier-types.reactivate', type)}
                                                            >
                                                                Reactivate
                                                            </button>
                                                        )}
                                                        {used === null ? (
                                                            <ConfirmDialog
                                                                trigger={
                                                                    <button type="button" className="text-muted-foreground text-xs underline">
                                                                        Delete
                                                                    </button>
                                                                }
                                                                title={`Delete ${type.name}?`}
                                                                description="No supplier and no tax rate uses it, so nothing else changes."
                                                                confirmLabel="Delete"
                                                                destructive
                                                                onConfirm={() =>
                                                                    router.delete(route('purchasing.supplier-types.destroy', type.id), {
                                                                        preserveScroll: true,
                                                                    })
                                                                }
                                                            />
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">In use, so it cannot be deleted</span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>

                {canManageTypes && (
                    <SupplierTypeDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} type={dialog ?? null} />
                )}
            </div>
        </AppLayout>
    );
}
