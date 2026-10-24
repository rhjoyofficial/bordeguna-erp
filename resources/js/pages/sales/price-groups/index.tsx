import { MasterRowActions, type MasterRowState } from '@/components/corrections/master-row-actions';
import { type ReasonOption } from '@/components/purchasing/correction-actions';
import { PriceGroupDialog, type DialogPriceGroup } from '@/components/sales/price-group-dialog';
import { ActionsRow } from '@/components/sales/sales-ui';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Group extends DialogPriceGroup {
    customers: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/overview' },
    { title: 'Price groups', href: '/sales/price-groups' },
];

/**
 * The kinds of buyer a price is set for, and which of them pay MRP when a
 * product has no price of their own (21 Sep 2026). ⚠ There was no screen at
 * all before: a group existed only if a seeder planted it.
 *
 * Add and Edit open one popup over the list (14 Oct 2026, Joy: "add edit will
 * from the pop up, not like current above the list"). Until then Add was a
 * form under the table and Edit turned the row into inputs.
 */
export default function PriceGroupsIndex({
    groups,
    recordActions,
    deleteReasons,
}: {
    groups: Group[];
    recordActions: Record<number, MasterRowState>;
    deleteReasons: ReasonOption[];
}) {
    // undefined is closed, null is a new group. ⚠ The whole page sits behind
    // sales.prices.manage, so whoever sees it may add and edit.
    const [dialog, setDialog] = useState<Group | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Price groups" />
            <div className="flex flex-col gap-4 p-4">
                <ActionsRow>
                    <Button size="sm" onClick={() => setDialog(null)}>
                        Add group
                    </Button>
                </ActionsRow>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-2">Group</th>
                                <th className="py-2">Code</th>
                                <th className="py-2">With no price set</th>
                                <th className="py-2">Prices include VAT</th>
                                <th className="py-2">Customers</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <tr key={group.id} className="border-b">
                                    <td className="py-2">
                                        {group.name}
                                        {group.is_default && (
                                            <Badge variant="secondary" className="ml-2">
                                                default
                                            </Badge>
                                        )}
                                        {!group.is_active && (
                                            <Badge variant="destructive" className="ml-2">
                                                inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2 font-mono text-xs">{group.code}</td>
                                    <td className="py-2">{group.falls_back_to_mrp ? 'Pays MRP' : 'Cannot buy'}</td>
                                    <td className="text-muted-foreground py-2">{group.prices_include_tax ? 'Yes' : 'No'}</td>
                                    <td className="text-muted-foreground py-2">{group.customers}</td>
                                    <td className="py-2 text-right">
                                        <Button variant="ghost" size="sm" onClick={() => setDialog(group)}>
                                            Edit
                                        </Button>
                                        {/* ⭐ B24 (24 Oct 2026): Delete when never used, else Switch off with a reason. */}
                                        <MasterRowActions state={recordActions[group.id]} name={group.name} reasons={deleteReasons} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <PriceGroupDialog open={dialog !== undefined} onOpenChange={(open) => !open && setDialog(undefined)} group={dialog ?? null} />
            </div>
        </AppLayout>
    );
}
