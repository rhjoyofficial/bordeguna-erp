import { Field, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dhakaToday } from '@/lib/dates';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

// A type, not an interface: useForm wants an index signature, which an
// interface never has.
type ApplicantData = {
    job_opening_id: string;
    name: string;
    phone: string;
    email: string;
    source: string;
    expected_salary: string;
    note: string;
    applied_on: string;
    cv: File | null;
};

// ⚠ A function, not a constant: "today" is read each time the popup opens.
const blank = (): ApplicantData => ({
    job_opening_id: '',
    name: '',
    phone: '',
    email: '',
    source: '',
    expected_salary: '',
    note: '',
    applied_on: dhakaToday(),
    cv: null,
});

/**
 * Record somebody who applied, in a popup over the list (Joy, 14 Oct 2026:
 * "click add button pop up will add"). The form that sat open above the
 * applicants is gone; the applicants are the page.
 *
 * Add only, as before: an applicant moves on through Move, never through a
 * form. Same fields, same route, same server rules.
 *
 * ⚠⚠ Nothing here creates an employee. An applicant is a name, a way to reach
 * them and a CV; the employee record is made in People.
 */
export function ApplicantDialog({
    open,
    onOpenChange,
    openings,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    openings: { id: number; title: string }[];
}) {
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm<ApplicantData>(blank());

    // Blank fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = blank();
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('hrm.recruitment.applicants.store'), { forceFormData: true, preserveScroll: true, onSuccess: close });
    };

    // The server takes a phone OR an email (required_without), so each is
    // starred only while the other is blank.
    const noPhone = data.phone.trim() === '';
    const noEmail = data.email.trim() === '';

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Add an applicant</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="For which role" htmlFor="applicant-job_opening_id" error={errors.job_opening_id} required>
                            <SearchSelect
                                id="applicant-job_opening_id"
                                value={data.job_opening_id}
                                onChange={(e) => setData('job_opening_id', e.target.value)}
                            >
                                <option value="">Choose an opening</option>
                                {openings.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.title}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field label="Name" htmlFor="applicant-name" size="md" error={errors.name} required>
                            <Input id="applicant-name" aria-required value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Phone" htmlFor="applicant-phone" size="md" error={errors.phone} required={noEmail}>
                            <Input
                                id="applicant-phone"
                                aria-required={noEmail}
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="01712345678"
                            />
                        </Field>
                        <Field label="Email" htmlFor="applicant-email" size="lg" error={errors.email} required={noPhone}>
                            <Input
                                id="applicant-email"
                                aria-required={noPhone}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                        </Field>
                        <Field label="Where from" htmlFor="applicant-source" size="md" error={errors.source}>
                            <Input
                                id="applicant-source"
                                value={data.source}
                                onChange={(e) => setData('source', e.target.value)}
                                placeholder="Referral, Facebook, walk-in"
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Salary asked for" htmlFor="applicant-expected_salary" size="sm" error={errors.expected_salary}>
                            <Input
                                id="applicant-expected_salary"
                                inputMode="decimal"
                                value={data.expected_salary}
                                onChange={(e) => setData('expected_salary', e.target.value)}
                            />
                        </Field>
                        <Field label="Applied on" htmlFor="applicant-applied_on" error={errors.applied_on} required>
                            <Input
                                id="applicant-applied_on"
                                aria-required
                                type="date"
                                value={data.applied_on}
                                onChange={(e) => setData('applied_on', e.target.value)}
                            />
                        </Field>
                        <Field label="CV" htmlFor="applicant-cv" size="lg" error={errors.cv}>
                            <Input id="applicant-cv" type="file" onChange={(e) => setData('cv', e.target.files?.[0] ?? null)} />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Note" htmlFor="applicant-note" size="grow" error={errors.note}>
                            <Input id="applicant-note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Add applicant</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
