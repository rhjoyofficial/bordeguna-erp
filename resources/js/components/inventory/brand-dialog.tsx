import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogBrand {
    id: number;
    name: string;
    code: string;
    note: string | null;
}

const EMPTY = { name: '', code: '', note: '' };

/**
 * Add a brand or correct one in a popup (14 Oct 2026). The form that sat open
 * above the list is gone; the list is the page and the form appears only when
 * somebody asks for it. Same fields, same routes, same server rules.
 *
 * ⚠ The code is a SKU segment and frozen once the brand exists: update() never
 * reads it. Editing shows it greyed out with the reason, so nobody goes looking
 * for a box that would change it.
 */
export function BrandDialog({ open, onOpenChange, brand }: { open: boolean; onOpenChange: (open: boolean) => void; brand: DialogBrand | null }) {
    const editing = brand !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new brand, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = brand ? { name: brand.name, code: brand.code, note: brand.note ?? '' } : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, brand]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (brand) {
            put(route('inventory.brands.update', brand.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('inventory.brands.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${brand.name}` : 'Add a brand'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="brand-name" size="md" error={errors.name} required>
                            <Input
                                id="brand-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. LaLaDia"
                            />
                        </Field>
                        <Field
                            label="SKU code"
                            htmlFor="brand-code"
                            size="sm"
                            error={errors.code}
                            required={!editing}
                            hint={editing ? 'Permanent: SKUs already carry it.' : undefined}
                        >
                            <Input
                                id="brand-code"
                                aria-required={!editing}
                                disabled={editing}
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="e.g. LLD"
                                className="font-mono"
                            />
                        </Field>
                        <Field label="Note" htmlFor="brand-note" size="grow" error={errors.note}>
                            <Input id="brand-note" value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="optional" />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save brand' : 'Add brand'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
