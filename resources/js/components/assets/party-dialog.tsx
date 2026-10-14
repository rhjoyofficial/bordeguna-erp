import { Field, FormRow } from '@/components/form/field';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogAssetParty {
    id: number;
    name: string;
    organisation: string | null;
    phone: string | null;
    kind: string;
    supplier_id: number | null;
    note: string | null;
    is_active: boolean;
}

interface Option {
    value: string;
    label: string;
}

const EMPTY = {
    name: '',
    organisation: '',
    phone: '',
    kind: 'repair_shop',
    supplier_id: '',
    // ⚠ Not drawn, only carried: the screen never had a box for it, and an edit
    // must send back what the party already has rather than clear it.
    note: '',
    is_active: true as boolean,
};

function fromParty(party: DialogAssetParty): typeof EMPTY {
    return {
        name: party.name,
        organisation: party.organisation ?? '',
        phone: party.phone ?? '',
        kind: party.kind,
        supplier_id: party.supplier_id ? String(party.supplier_id) : '',
        note: party.note ?? '',
        is_active: party.is_active,
    };
}

/**
 * Add an outside party that can hold a thing (a repair shop, a freelancer), or
 * correct one, in one popup (Joy, 14 Oct 2026: "click add button pop up will
 * add, same when edit"). Add was a form above the list and Edit replaced the
 * row; both are this dialog now.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn. Switched off, never deleted: hand-overs
 * point at a party.
 */
export function AssetPartyDialog({
    open,
    onOpenChange,
    party,
    kinds,
    suppliers,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    party: DialogAssetParty | null;
    kinds: Option[];
    suppliers: { id: number; name: string }[];
}) {
    const editing = party !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new party, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = party ? fromParty(party) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, party]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (party) {
            put(`/assets/parties/${party.id}`, { preserveScroll: true, onSuccess: close });

            return;
        }

        post('/assets/parties', { preserveScroll: true, onSuccess: close });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${party.name}` : 'Add an outside party'}</DialogTitle>
                    </DialogHeader>

                    <FormRow>
                        <Field label="Name" htmlFor="asset-party-name" size="md" error={errors.name} required>
                            <Input
                                id="asset-party-name"
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Rahim"
                            />
                        </Field>
                        <Field label="Shop or organisation" htmlFor="asset-party-organisation" size="md" error={errors.organisation}>
                            <Input
                                id="asset-party-organisation"
                                value={data.organisation}
                                onChange={(e) => setData('organisation', e.target.value)}
                                placeholder="Rahim Computer Care"
                            />
                        </Field>
                        <Field label="Phone" htmlFor="asset-party-phone" size="sm" error={errors.phone}>
                            <Input
                                id="asset-party-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="01XXXXXXXXX"
                            />
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="Kind" htmlFor="asset-party-kind" error={errors.kind} required>
                            <SearchableSelect fit id="asset-party-kind" value={data.kind} onChange={(v) => setData('kind', v)} options={kinds} />
                        </Field>
                        <Field label="Also a supplier" htmlFor="asset-party-supplier" error={errors.supplier_id}>
                            <SearchableSelect
                                fit
                                id="asset-party-supplier"
                                value={data.supplier_id}
                                onChange={(v) => setData('supplier_id', v)}
                                options={[{ value: '', label: 'No' }, ...suppliers.map((s) => ({ value: String(s.id), label: s.name }))]}
                            />
                        </Field>
                    </FormRow>

                    {editing && (
                        <div className="flex items-center gap-2">
                            <Checkbox id="asset-party-active" checked={data.is_active} onCheckedChange={(on) => setData('is_active', on === true)} />
                            <Label htmlFor="asset-party-active" className="font-normal">
                                In use
                            </Label>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? 'Save' : 'Add party'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
