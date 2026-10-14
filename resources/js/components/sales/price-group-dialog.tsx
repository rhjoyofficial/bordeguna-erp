import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogPriceGroup {
    id: number;
    code: string;
    name: string;
    prices_include_tax: boolean;
    falls_back_to_mrp: boolean;
    is_default: boolean;
    is_active: boolean;
}

const EMPTY = {
    code: '',
    name: '',
    prices_include_tax: true as boolean,
    falls_back_to_mrp: false as boolean,
    is_active: true as boolean,
};

/**
 * Add a price group or correct one in one popup (Joy, 14 Oct 2026: "click add
 * button pop up will add, same when edit"). Before this, Add was a form under
 * the list and Edit turned the row itself into inputs; both are this dialog
 * now. Same fields, same routes, same server rules.
 *
 * ⚠ The code is fixed once made (prices and customers point at the group, and
 * importers match it by code), so an edit shows it but cannot change it, and
 * update() does not read it. `is_active` is only drawn for an edit: store()
 * makes every new group active and does not read it either.
 *
 * ⚠ RETAIL is the consumer price: it always pays MRP and stays active. The two
 * boxes are locked for it here, and the server forces both whatever is sent.
 */
export function PriceGroupDialog({
    open,
    onOpenChange,
    group,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    group: DialogPriceGroup | null;
}) {
    const editing = group !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new group, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = group
            ? {
                  code: group.code,
                  name: group.name,
                  prices_include_tax: group.prices_include_tax,
                  falls_back_to_mrp: group.falls_back_to_mrp,
                  is_active: group.is_active,
              }
            : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, group]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (group) {
            put(route('sales.price-groups.update', group.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('sales.price-groups.store'), { preserveScroll: true, onSuccess: close });
    };

    const locked = Boolean(group?.is_default);

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${group.name}` : 'Add a price group'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="price-group-name" size="md" error={errors.name} required>
                            <Input
                                id="price-group-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Retail shop"
                            />
                        </Field>
                        <Field
                            label="Code"
                            htmlFor="price-group-code"
                            size="sm"
                            error={errors.code}
                            required={!editing}
                            hint={editing ? 'Permanent once made.' : undefined}
                        >
                            <Input
                                id="price-group-code"
                                aria-required={!editing}
                                disabled={editing}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_'))}
                                placeholder="RETAIL_SHOP"
                                className="font-mono"
                            />
                        </Field>
                    </FormRow>

                    <div className="grid gap-2 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={data.falls_back_to_mrp}
                                disabled={locked}
                                onChange={(e) => setData('falls_back_to_mrp', e.target.checked)}
                            />
                            Pays MRP when no price is set
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={data.prices_include_tax}
                                onChange={(e) => setData('prices_include_tax', e.target.checked)}
                            />
                            Prices include VAT
                        </label>
                        {editing && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={data.is_active}
                                    disabled={locked}
                                    onChange={(e) => setData('is_active', e.target.checked)}
                                />
                                Active
                            </label>
                        )}
                        {locked && (
                            <p className="text-muted-foreground text-xs">
                                Retail is the consumer price: it always pays MRP when nothing is set and stays active.
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save group' : 'Add group'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
