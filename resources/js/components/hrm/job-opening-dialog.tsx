import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dhakaToday } from '@/lib/dates';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogOpening {
    id: number;
    title: string;
    department_id: number | null;
    designation_id: number | null;
    description: string | null;
    openings: number;
    status: string;
    opened_on: string | null;
    closes_on: string | null;
}

interface Option {
    value: string;
    label: string;
}

// ⚠ A function, not a constant: "today" is read each time the popup opens, so a
// page left open overnight does not offer yesterday's date.
const blank = () => ({
    title: '',
    department_id: '',
    designation_id: '',
    description: '',
    openings: 1,
    status: 'open',
    opened_on: dhakaToday(),
    closes_on: '',
});

type OpeningData = ReturnType<typeof blank>;

function fromOpening(opening: DialogOpening): OpeningData {
    return {
        title: opening.title,
        // ⚠ The ids, not the names. Before 14 Oct 2026 Edit filled these with
        // blanks, so saving any other change quietly cleared the department and
        // designation the opening had.
        department_id: opening.department_id ? String(opening.department_id) : '',
        designation_id: opening.designation_id ? String(opening.designation_id) : '',
        description: opening.description ?? '',
        openings: opening.openings,
        status: opening.status,
        opened_on: opening.opened_on ?? dhakaToday(),
        closes_on: opening.closes_on ?? '',
    };
}

/**
 * Add a job opening or correct one in one popup (Joy, 14 Oct 2026: "click add
 * button pop up will add, same when edit"). The form that sat open above the
 * openings is gone; the openings are the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn.
 */
export function JobOpeningDialog({
    open,
    onOpenChange,
    opening,
    departments,
    designations,
    statuses,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    opening: DialogOpening | null;
    departments: { id: number; name: string }[];
    designations: { id: number; name: string }[];
    statuses: Option[];
}) {
    const editing = opening !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(blank());

    // Fresh fields every time it opens: blank for a new opening, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = opening ? fromOpening(opening) : blank();
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, opening]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (opening) {
            put(route('hrm.recruitment.openings.update', opening.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('hrm.recruitment.openings.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${opening.title}` : 'Add an opening'}</DialogTitle>
                    </DialogHeader>

                    {/* Sized fields (30 Aug 2026 audit): the role grows, the
                        pickers fit, a count is a small box, dates size themselves. */}
                    <FormRow>
                        <Field label="Role" htmlFor="opening-title" size="grow" error={errors.title} required>
                            <Input id="opening-title" aria-required value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </Field>
                        <Field label="Department" htmlFor="opening-department_id" error={errors.department_id}>
                            <SearchSelect
                                id="opening-department_id"
                                value={data.department_id}
                                onChange={(e) => setData('department_id', e.target.value)}
                            >
                                <option value="">Not set</option>
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Designation" htmlFor="opening-designation_id" error={errors.designation_id}>
                            <SearchSelect
                                id="opening-designation_id"
                                value={data.designation_id}
                                onChange={(e) => setData('designation_id', e.target.value)}
                            >
                                <option value="">Not set</option>
                                {designations.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="How many" htmlFor="opening-openings" size="xs" error={errors.openings} required>
                            <Input
                                id="opening-openings"
                                aria-required
                                type="number"
                                min={1}
                                value={data.openings}
                                onChange={(e) => setData('openings', Number(e.target.value))}
                            />
                        </Field>
                        <Field label="Status" htmlFor="opening-status" error={errors.status} required>
                            <SearchSelect id="opening-status" value={data.status} onChange={(e) => setData('status', e.target.value)}>
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Opened" htmlFor="opening-opened_on" error={errors.opened_on} required>
                            <Input
                                id="opening-opened_on"
                                aria-required
                                type="date"
                                value={data.opened_on}
                                onChange={(e) => setData('opened_on', e.target.value)}
                            />
                        </Field>
                        <Field label="Closes" htmlFor="opening-closes_on" error={errors.closes_on}>
                            <Input id="opening-closes_on" type="date" value={data.closes_on} onChange={(e) => setData('closes_on', e.target.value)} />
                        </Field>
                    </FormRow>

                    <div className="grid gap-2">
                        <Label htmlFor="opening-description">What the role is</Label>
                        <textarea
                            id="opening-description"
                            className="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save opening' : 'Add opening'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
