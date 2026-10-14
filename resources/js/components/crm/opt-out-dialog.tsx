import { Field, FormRow } from '@/components/form/field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

const EMPTY = { address: '', reason: '' };

/**
 * Put a number or an address on the do-not-contact list in a popup (Joy,
 * 14 Oct 2026: "click add button pop up will add"). The two boxes that sat
 * open above the list are gone; the list is the section.
 *
 * Same fields, same route, same server rules as before: the dialog only
 * changes where the form is drawn. The server still normalises what is typed,
 * so however a number was written it matches the one a send would use.
 *
 * ⚠ Only the opt-out moved. The compose form above it stays a page form on
 * purpose: preview, cap and approval are its money brakes.
 */
export function OptOutDialog({ open, onOpenChange, channel }: { open: boolean; onOpenChange: (open: boolean) => void; channel: string }) {
    const { t } = useTranslation();
    const isSms = channel === 'sms';
    const { data, setData, post, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens.
    useEffect(() => {
        if (!open) {
            return;
        }

        setDefaults(EMPTY);
        setData(EMPTY);
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
        post(route('crm.messages.opt-out', channel), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{t(isSms ? 'campaigns.optout.add_number' : 'campaigns.optout.add_address')}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label={isSms ? 'Number' : 'Email'} htmlFor="opt_address" size={isSms ? 'md' : 'lg'} error={errors.address} required>
                            <Input id="opt_address" aria-required value={data.address} onChange={(e) => setData('address', e.target.value)} />
                        </Field>
                        <Field label="Why" htmlFor="opt_reason" size="grow" error={errors.reason}>
                            <Input id="opt_reason" value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        </Field>
                    </FormRow>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            {t('campaigns.optout.cancel')}
                        </Button>
                        <Button disabled={processing}>Add</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
