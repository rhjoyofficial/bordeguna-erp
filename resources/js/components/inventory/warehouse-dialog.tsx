import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogWarehouse {
    id: number;
    name: string;
    code: string;
    is_active: boolean;
    not_for_sale?: boolean;
}

const EMPTY = { name: '', code: '', is_active: true as boolean, not_for_sale: false as boolean };

/**
 * Add a warehouse or correct its name and code in one popup (14 Oct 2026).
 * Before this, Add was a form above the list and Edit turned the row itself
 * into inputs; both are this dialog now. Same fields, same routes, same server
 * rules.
 *
 * ⚠ `is_active` is not drawn here: the status is the row's own button. It rides
 * along because update() requires it, and the popup sends back exactly what the
 * row already has, as the in-place edit did. store() does not read it.
 */
export function WarehouseDialog({
    open,
    onOpenChange,
    warehouse,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    warehouse: DialogWarehouse | null;
}) {
    const { t } = useTranslation();
    const editing = warehouse !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new warehouse, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = warehouse
            ? { name: warehouse.name, code: warehouse.code, is_active: warehouse.is_active, not_for_sale: warehouse.not_for_sale ?? false }
            : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, warehouse]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (warehouse) {
            put(route('inventory.warehouses.update', warehouse.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('inventory.warehouses.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${warehouse.name}` : 'Add a warehouse'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="warehouse-name" size="md" error={errors.name} required>
                            <Input
                                id="warehouse-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Cold Storage"
                            />
                        </Field>
                        <Field label="Code" htmlFor="warehouse-code" size="xs" error={errors.code} required>
                            <Input
                                id="warehouse-code"
                                aria-required
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="e.g. COLD"
                            />
                        </Field>
                    </FormRow>

                    {/* ⭐ Initial plan B7 (Joy, 24 Oct 2026): the quarantine flag. */}
                    <Field label={t('ips.q.badge')} htmlFor="warehouse-not-for-sale" error={errors.not_for_sale} hint={t('ips.q.flag_hint')}>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                id="warehouse-not-for-sale"
                                type="checkbox"
                                checked={data.not_for_sale}
                                onChange={(e) => setData('not_for_sale', e.target.checked)}
                            />
                            {t('ips.q.flag')}
                        </label>
                    </Field>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save warehouse' : 'Add warehouse'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
