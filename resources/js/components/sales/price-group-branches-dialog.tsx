import { Field, FormRow } from '@/components/form/field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

export interface DialogBranch {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    is_active: boolean;
    customers: number;
}

const EMPTY = { name: '', address: '', phone: '', is_active: true as boolean };

/**
 * The branches of a group's organisation (Joy, 26 Oct 2026: "all the customer
 * goes under the Group name Sangram NGO, then all customer under it, with
 * selectable branch name or can be null"). One popup: the list, and above it
 * the form for a new branch or the one being edited.
 *
 * ⚠ A branch is switched off, never deleted: customers keep pointing at it.
 */
export function PriceGroupBranchesDialog({
    open,
    onOpenChange,
    group,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    group: { id: number; name: string; branches: DialogBranch[] } | null;
}) {
    // null = adding a new branch.
    const [editing, setEditing] = useState<DialogBranch | null>(null);
    const { data, setData, post, put, errors, processing, clearErrors, setDefaults } = useForm(EMPTY);

    const load = (branch: DialogBranch | null) => {
        const next = branch ? { name: branch.name, address: branch.address ?? '', phone: branch.phone ?? '', is_active: branch.is_active } : EMPTY;
        setEditing(branch);
        setDefaults(next);
        setData(next);
        clearErrors();
    };

    useEffect(() => {
        if (open) {
            load(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, group?.id]);

    if (group === null) {
        return null;
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('sales.price-groups.branches.update', [group.id, editing.id]), { preserveScroll: true, onSuccess: () => load(null) });

            return;
        }

        post(route('sales.price-groups.branches.store', group.id), { preserveScroll: true, onSuccess: () => load(null) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-3xl" aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>Branches of {group.name}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-3 rounded-md border p-3">
                    <p className="text-sm font-medium">{editing ? `Edit ${editing.name}` : 'Add a branch'}</p>
                    <FormRow>
                        <Field label="Branch name" htmlFor="branch-name" size="md" error={errors.name} required>
                            <Input
                                id="branch-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Kuakata"
                            />
                        </Field>
                        <Field label="Phone" htmlFor="branch-phone" size="sm" error={errors.phone}>
                            <Input id="branch-phone" inputMode="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        </Field>
                    </FormRow>
                    <Field label="Address" htmlFor="branch-address" error={errors.address}>
                        <textarea
                            id="branch-address"
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                    </Field>
                    {editing && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                            Active
                        </label>
                    )}
                    <div className="flex gap-2">
                        <Button size="sm" disabled={processing}>
                            {editing ? 'Save branch' : 'Add branch'}
                        </Button>
                        {editing && (
                            <Button type="button" size="sm" variant="ghost" onClick={() => load(null)}>
                                Cancel edit
                            </Button>
                        )}
                    </div>
                </form>

                <div className="max-h-[50vh] overflow-auto">
                    {group.branches.length === 0 ? (
                        <p className="text-muted-foreground py-4 text-sm">No branches yet. Customers of this group can still have no branch.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2">Branch</th>
                                    <th className="py-2">Phone</th>
                                    <th className="py-2">Customers</th>
                                    <th className="py-2 text-right" />
                                </tr>
                            </thead>
                            <tbody>
                                {group.branches.map((branch) => (
                                    <tr key={branch.id} className="border-b align-top">
                                        <td className="py-2">
                                            {branch.name}
                                            {!branch.is_active && (
                                                <Badge variant="destructive" className="ml-2">
                                                    inactive
                                                </Badge>
                                            )}
                                            {branch.address && <div className="text-muted-foreground text-xs">{branch.address}</div>}
                                        </td>
                                        <td className="text-muted-foreground py-2">{branch.phone}</td>
                                        <td className="text-muted-foreground py-2">{branch.customers}</td>
                                        <td className="py-2 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => load(branch)}>
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
