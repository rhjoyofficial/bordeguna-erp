import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { showDate } from '@/lib/dates';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

/** A hand-typed entry as the month grid carries it; only these have an id. */
export interface DialogCalendarItem {
    id: number | null;
    date: string;
    title: string;
    label: string;
    /** A hand-typed entry's own fields (14 Oct 2026); absent on everything else. */
    edit?: {
        description: string | null;
        location: string | null;
        starts_at: string;
        ends_at: string | null;
        all_day: boolean;
        is_private: boolean;
    } | null;
}

const EMPTY = {
    title: '',
    description: '',
    location: '',
    starts_at: '',
    ends_at: '',
    all_day: true as boolean,
    is_private: false as boolean,
};

/**
 * Put something on the calendar, or correct it, in a popup opened from the
 * day or from the entry (Joy, 14 Oct 2026: "click add button pop up will add
 * ... same when edit"). The form that appeared under a picked day is gone.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. A new entry starts at 09:00 on the day it
 * was opened from, exactly as the old form filled it.
 *
 * ⚠ An edit starts from the entry's OWN fields, which the month grid now sends
 * (`edit`). Before 14 Oct 2026 it sent only the title and the day, and saving a
 * correction cleared the entry's place, end, all-day flag and description.
 */
export function CalendarItemDialog({
    open,
    onOpenChange,
    date,
    item,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    date: string;
    item: DialogCalendarItem | null;
}) {
    const editing = item !== null && item.id !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: the picked day for a new entry, the entry's own for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = item?.edit
            ? {
                  ...EMPTY,
                  title: item.title,
                  description: item.edit.description ?? '',
                  location: item.edit.location ?? '',
                  starts_at: item.edit.starts_at,
                  ends_at: item.edit.ends_at ?? '',
                  all_day: item.edit.all_day,
                  is_private: item.edit.is_private,
              }
            : item
              ? { ...EMPTY, title: item.title, starts_at: `${item.date}T09:00`, is_private: item.label === 'Private' }
              : { ...EMPTY, starts_at: `${date}T09:00` };
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item, date]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (item?.id) {
            put(route('calendar.update', item.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('calendar.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${item.title}` : `Add to ${showDate(date)}`}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="What is happening" htmlFor="calendar-title" className="w-80" error={errors.title} required>
                            <Input id="calendar-title" aria-required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </Field>
                        <Field label="Where" htmlFor="calendar-location" className="w-80" error={errors.location}>
                            <Input id="calendar-location" value={data.location} onChange={(e) => setData('location', e.target.value)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Starts" htmlFor="calendar-starts-at" error={errors.starts_at} required>
                            <Input
                                id="calendar-starts-at"
                                aria-required
                                type="datetime-local"
                                value={data.starts_at}
                                onChange={(e) => setData('starts_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Ends (optional)" htmlFor="calendar-ends-at" error={errors.ends_at}>
                            <Input
                                id="calendar-ends-at"
                                type="datetime-local"
                                value={data.ends_at}
                                onChange={(e) => setData('ends_at', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <div className="flex flex-wrap items-center gap-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.all_day} onChange={(e) => setData('all_day', e.target.checked)} />
                            All day
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_private} onChange={(e) => setData('is_private', e.target.checked)} />
                            Only me
                        </label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add to the calendar'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
