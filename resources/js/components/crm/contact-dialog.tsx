import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogContact {
    id: number;
    name: string;
    company: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    source: string | null;
    note: string | null;
    is_active: boolean;
}

const EMPTY = {
    name: '',
    company: '',
    phone: '',
    email: '',
    address: '',
    source: '',
    note: '',
    is_active: true as boolean,
};

function fromContact(contact: DialogContact): typeof EMPTY {
    return {
        name: contact.name,
        company: contact.company ?? '',
        phone: contact.phone ?? '',
        email: contact.email ?? '',
        address: contact.address ?? '',
        source: contact.source ?? '',
        note: contact.note ?? '',
        is_active: contact.is_active,
    };
}

/**
 * One popup to add a person to the phone book or correct one (Joy, 14 Oct
 * 2026: "click add button pop up will add ... same when edit"). The form that
 * sat open above the list is gone; the list is the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. A contact that mirrors a lead or customer
 * is never offered here (the server refuses it too).
 */
export function ContactDialog({
    open,
    onOpenChange,
    contact,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    contact: DialogContact | null;
}) {
    const editing = contact !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults, transform } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new person, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = contact ? fromContact(contact) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, contact]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    // ⭐ B13 (24 Oct 2026): a possible duplicate (same phone or name) comes back
    // as `_duplicate`; Save anyway sends the same form with the ack.
    const send = (ack: string | null) => {
        transform((form) => ({ ...form, duplicate_ack: ack }));

        if (contact) {
            put(route('crm.contacts.update', contact.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('crm.contacts.store'), { preserveScroll: true, onSuccess: close });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send(null);
    };

    // ⚠ Phone and email: one of the two is required, never both. The star
    // follows whichever box is still the only way to reach the person.
    const noPhone = !data.phone.trim();
    const noEmail = !data.email.trim();

    const field = (
        name: 'name' | 'company' | 'phone' | 'email' | 'address' | 'source',
        label: string,
        extra?: { type?: string; placeholder?: string; required?: boolean; hint?: string },
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={`contact-${name}`} required={extra?.required}>
                {label}
            </Label>
            <Input
                id={`contact-${name}`}
                type={extra?.type}
                aria-required={extra?.required}
                placeholder={extra?.placeholder}
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
            />
            {extra?.hint && <p className="text-muted-foreground text-xs">{extra.hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${contact.name}` : 'Add a contact'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {field('name', 'Name', { required: true })}
                        {field('company', 'Company')}
                        {field('phone', 'Phone', {
                            required: noEmail,
                            placeholder: '01712345678',
                            hint: noPhone && noEmail ? 'A phone or an email, one is enough.' : undefined,
                        })}
                        {field('email', 'Email', { type: 'email', required: noPhone })}
                        {field('address', 'Address')}
                        {field('source', 'Where from', { placeholder: 'Trade fair 2026' })}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="contact-note">Note</Label>
                        <textarea
                            id="contact-note"
                            rows={2}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    <DuplicateWarning errors={errors as Record<string, string | undefined>} busy={processing} onSaveAnyway={send} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save contact' : 'Add contact'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
