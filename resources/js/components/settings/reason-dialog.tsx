import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogReason {
    id: number;
    context: string;
    name: string;
}

/**
 * Add a reason to one list, or rename one, in a small popup (Joy, 14 Oct 2026:
 * "click add button pop up will add, same when edit"). Before this every list
 * had its own add box above it and Rename turned the row into an input.
 *
 * Same field, same routes, same server rules: a name is unique within its
 * list, and the refusal shows under the box. The list a reason belongs to is
 * fixed: a rename changes only the name, whatever else arrives with it.
 */
export function ReasonDialog({
    open,
    onOpenChange,
    context,
    contextLabel,
    reason,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    context: string;
    contextLabel: string;
    reason: DialogReason | null;
}) {
    const editing = reason !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm({ context, name: '' });

    // Fresh field every time it opens: blank for a new reason, the name for a rename.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = { context, name: reason?.name ?? '' };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, context, reason]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (reason) {
            put(route('reasons.update', reason.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('reasons.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Rename ${reason.name}` : `Add a ${contextLabel.toLowerCase()} reason`}</DialogTitle>
                    </DialogHeader>

                    {/* A reason is a word or two; the box was the width of the page. */}
                    <FormRow>
                        <Field label="Reason" htmlFor="reason-name" size="lg" error={errors.name} required>
                            <Input
                                id="reason-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="e.g. Damaged"
                                autoFocus
                            />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add reason'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
