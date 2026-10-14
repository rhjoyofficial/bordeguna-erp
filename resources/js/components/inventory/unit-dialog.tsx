import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogUnit {
    id: number;
    name: string;
    abbreviation: string;
}

const EMPTY = { name: '', abbreviation: '' };

/**
 * Add a unit of measure or correct one in one popup (14 Oct 2026). Before
 * this, Add was a form above the list and Edit turned the row itself into
 * inputs; both are this dialog now. Same fields, same routes, same server
 * rules.
 *
 * ⚠ Renaming is safe even for a unit products already use: they point at the
 * row, not at the text. The abbreviation is unique per business and a repeat
 * comes back as a sentence under its box.
 */
export function UnitDialog({ open, onOpenChange, unit }: { open: boolean; onOpenChange: (open: boolean) => void; unit: DialogUnit | null }) {
    const editing = unit !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new unit, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = unit ? { name: unit.name, abbreviation: unit.abbreviation } : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, unit]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (unit) {
            put(route('inventory.units.update', unit.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('inventory.units.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${unit.name}` : 'Add a unit'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="unit-name" size="md" error={errors.name} required>
                            <Input
                                id="unit-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Kilogram"
                            />
                        </Field>
                        <Field label="Abbreviation" htmlFor="unit-abbreviation" size="xs" error={errors.abbreviation} required>
                            <Input
                                id="unit-abbreviation"
                                aria-required
                                value={data.abbreviation}
                                onChange={(e) => setData('abbreviation', e.target.value)}
                                placeholder="e.g. kg"
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save unit' : 'Add unit'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
