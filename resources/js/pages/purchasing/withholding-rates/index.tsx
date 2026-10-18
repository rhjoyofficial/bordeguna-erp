import { ConfirmDialog } from '@/components/confirm-dialog';
import { supplierTypeName, type SupplierTypeOption } from '@/components/purchasing/supplier-types';
import { WithholdingRateDialog, type DialogWithholdingRate } from '@/components/purchasing/withholding-rate-dialog';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Rate = DialogWithholdingRate;

interface Props {
    rates: Rate[];
    /** purchasing.suppliers.manage: the page's own gate, beside the tab's (PUR-007). */
    canManageRates: boolean;
    kinds: Record<string, string>;
    supplierTypes: SupplierTypeOption[];
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Suppliers', href: '/purchasing/suppliers' },
    { title: 'Tax rates', href: '/purchasing/withholding-rates' },
];

export default function WithholdingRates({ rates, canManageRates, kinds, supplierTypes, today }: Props) {
    // ⚠ Add and edit happen in a popup (Joy, 14 Oct 2026: "click add button
    // pop up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Rate | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tax rates" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        When you pay a supplier you can hold one of these back for NBR. The supplier's type decides which rates are offered.
                    </p>
                    {/* ⚠ The whole page sits behind purchasing.suppliers.manage (its
                        route), and the buttons check the same flag again (PUR-007). */}
                    {canManageRates && (
                        <Button size="sm" onClick={() => setDialog(null)}>
                            Add rate
                        </Button>
                    )}
                </div>

                <div className="bg-card overflow-x-auto rounded-xl border p-4">
                    {rates.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No rates yet.</p>
                    ) : (
                        <table className="w-full min-w-[44rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Tax</th>
                                    <th className="py-2 pr-3">Supplier type</th>
                                    <th className="py-2 pr-3">Name</th>
                                    <th className="py-2 pr-3 text-right">Rate</th>
                                    <th className="py-2 pr-3">From</th>
                                    <th className="py-2 pr-3">Until</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {rates.map((rate) => (
                                    <tr key={rate.id} className="border-b">
                                        <td className="py-2 pr-3 uppercase">{rate.kind}</td>
                                        <td className="py-2 pr-3">
                                            {rate.supplier_type ? supplierTypeName(supplierTypes, rate.supplier_type) : 'Any type'}
                                        </td>
                                        <td className="py-2 pr-3">{rate.name}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{rate.rate}%</td>
                                        <td className="py-2 pr-3">{showDate(rate.effective_from)}</td>
                                        <td className="py-2 pr-3">{showDate(rate.effective_to) || '-'}</td>
                                        <td className="space-x-3 py-2 text-right whitespace-nowrap">
                                            {canManageRates && (
                                                <button type="button" className="text-xs underline" onClick={() => setDialog(rate)}>
                                                    Edit
                                                </button>
                                            )}
                                            {canManageRates && !rate.used && (
                                                <ConfirmDialog
                                                    trigger={<button className="text-muted-foreground text-xs underline">Delete</button>}
                                                    title="Delete this rate?"
                                                    description="No payment used it, so nothing else changes."
                                                    confirmLabel="Delete"
                                                    destructive
                                                    onConfirm={() =>
                                                        router.delete(route('purchasing.withholding-rates.destroy', rate.id), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <WithholdingRateDialog
                    open={dialog !== undefined}
                    onOpenChange={(open) => !open && setDialog(undefined)}
                    rate={dialog ?? null}
                    kinds={kinds}
                    supplierTypes={supplierTypes}
                    today={today}
                />
            </div>
        </AppLayout>
    );
}
