import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogGroup {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
}

const EMPTY = { name: '', description: '', is_active: true as boolean };

/**
 * Add or rename a phone book group in a popup (14 Oct 2026), the same pattern
 * as a contact. A group is never deleted, only switched off: once it has fed
 * a send, "who received it" is answered by the group.
 */
export function ContactGroupDialog({
    open,
    onOpenChange,
    group,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    group: DialogGroup | null;
}) {
    const editing = group !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    useEffect(() => {
        if (!open) {
            return;
        }

        const next = group ? { name: group.name, description: group.description ?? '', is_active: group.is_active } : EMPTY;
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
            put(route('crm.contact-groups.update', group.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('crm.contact-groups.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-lg" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${group.name}` : 'Add a group'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="group-name" required>
                            Name
                        </Label>
                        <Input id="group-name" aria-required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="group-description">Description</Label>
                        <Input id="group-description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} />
                    </div>

                    {editing && (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                            Active. An inactive group keeps its people but is not offered on the send pages.
                        </label>
                    )}

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
