import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogNote {
    id: number;
    title: string;
    body: string | null;
    is_private: boolean;
    is_pinned: boolean;
    remind_on: string | null;
}

const EMPTY = {
    title: '',
    body: '',
    is_private: false as boolean,
    is_pinned: false as boolean,
    remind_on: '',
};

function fromNote(note: DialogNote): typeof EMPTY {
    return {
        title: note.title,
        body: note.body ?? '',
        is_private: note.is_private,
        is_pinned: note.is_pinned,
        remind_on: note.remind_on ?? '',
    };
}

/**
 * Write a note or correct one in a popup (Joy, 14 Oct 2026: "click add button
 * pop up will add ... same when edit"). The form that sat open above the
 * board is gone; the board is the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ Edit is only offered on a note this
 * person may change; the server refuses anyone else's words regardless.
 */
export function NoteDialog({ open, onOpenChange, note }: { open: boolean; onOpenChange: (open: boolean) => void; note: DialogNote | null }) {
    const editing = note !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new note, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = note ? fromNote(note) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, note]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (note) {
            put(route('notes.update', note.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('notes.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${note.title}` : 'Add a note'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Title" htmlFor="note-title" size="grow" error={errors.title} required>
                            <Input id="note-title" aria-required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </Field>
                    </FormRow>

                    <div className="grid gap-2">
                        <Label htmlFor="note-body">Note</Label>
                        <textarea
                            id="note-body"
                            className="border-input bg-background min-h-28 rounded-md border p-2 text-sm"
                            value={data.body}
                            onChange={(e) => setData('body', e.target.value)}
                        />
                        <InputError message={errors.body} />
                    </div>

                    <FormRow>
                        <Field label="Bring this back on" htmlFor="note-remind-on" error={errors.remind_on}>
                            <Input id="note-remind-on" type="date" value={data.remind_on} onChange={(e) => setData('remind_on', e.target.value)} />
                        </Field>
                        <Field label="Who sees it" htmlFor="note-private">
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input
                                    id="note-private"
                                    type="checkbox"
                                    checked={data.is_private}
                                    onChange={(e) => setData('is_private', e.target.checked)}
                                />
                                Only me
                            </label>
                        </Field>
                        <Field label="Pinned" htmlFor="note-pinned">
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input
                                    id="note-pinned"
                                    type="checkbox"
                                    checked={data.is_pinned}
                                    onChange={(e) => setData('is_pinned', e.target.checked)}
                                />
                                Pin to the top
                            </label>
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save note' : 'Add note'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
