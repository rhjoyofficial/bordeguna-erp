import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogLeadSource {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    note: string | null;
}

const EMPTY = { code: '', name: '', is_active: true as boolean, note: '' };

/**
 * Add a lead source or correct one in a popup (Joy, 14 Oct 2026: "click add
 * button pop up will add ... same when edit"). The form that sat open above
 * the sources table is gone; the table is the page.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. ⚠ The code is the frozen reporting key
 * (C-CRM-14), unique per business; the server refuses a second one.
 */
export function LeadSourceDialog({
    open,
    onOpenChange,
    source,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    source: DialogLeadSource | null;
}) {
    const editing = source !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new source, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = source ? { code: source.code, name: source.name, is_active: source.is_active, note: source.note ?? '' } : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, source]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (source) {
            put(route('crm.settings.sources.update', source.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('crm.settings.sources.store'), { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${source.name}` : 'Add a lead source'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Code" htmlFor="source_code" size="sm" error={errors.code} required>
                            <Input
                                id="source_code"
                                aria-required
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="META"
                            />
                        </Field>
                        <Field label="Name" htmlFor="source_name" size="md" error={errors.name} required>
                            <Input
                                id="source_name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Meta Ads"
                            />
                        </Field>
                        <Field label="Active" htmlFor="source_active" error={errors.is_active}>
                            <SearchSelect
                                fit
                                id="source_active"
                                value={data.is_active ? '1' : '0'}
                                onChange={(e) => setData('is_active', e.target.value === '1')}
                            >
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <div className="grid gap-2">
                        <Label htmlFor="source_note">Note</Label>
                        <textarea
                            id="source_note"
                            rows={2}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save source' : 'Add source'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
