import { Field, FormRow } from '@/components/form/field';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';

export const COUNTERPARTY_KINDS = ['company', 'individual', 'bank', 'nbfi', 'government', 'other'] as const;

export type CounterpartyKind = (typeof COUNTERPARTY_KINDS)[number];

export const KIND_LABEL: Record<CounterpartyKind, TranslationKey> = {
    company: 'counterparties.kind.company',
    individual: 'counterparties.kind.individual',
    bank: 'counterparties.kind.bank',
    nbfi: 'counterparties.kind.nbfi',
    government: 'counterparties.kind.government',
    other: 'counterparties.kind.other',
};

export interface CounterpartyFields {
    legal_name: string;
    kind: CounterpartyKind;
    related_party: boolean;
    relationship: string;
    tin: string;
    bin: string;
    registration_no: string;
    phone: string;
    email: string;
    address: string;
    bank_details: string;
    notes: string;
    customer_id: string;
    supplier_id: string;
    employee_id: string;
}

export interface LinkOptions {
    customers: { id: number; label: string }[];
    suppliers: { id: number; label: string }[];
    employees: { id: number; label: string }[];
}

export const blankCounterparty = (name = ''): CounterpartyFields => ({
    legal_name: name,
    kind: 'company',
    related_party: false,
    relationship: '',
    tin: '',
    bin: '',
    registration_no: '',
    phone: '',
    email: '',
    address: '',
    bank_details: '',
    notes: '',
    customer_id: '',
    supplier_id: '',
    employee_id: '',
});

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Add or change a counterparty (loans plan v2, 14 Oct 2026). ONE dialog for
 * the Counterparties screen and for the "New counterparty" row inside a loan
 * line's picker, talking JSON to the same endpoint, so a voucher half filled in
 * underneath is never re-rendered (the LedgerQuickCreate rule).
 *
 * `quick` (from a picker) asks only what the line needs now: the name, the
 * kind, whether it is a related party and how, a phone and a TIN. The owner's
 * case (14 Oct 2026): his own company is created here as related and goes
 * straight onto 11752. Everything else waits for the Counterparties screen.
 *
 * ⚠ The server decides duplicates and our own businesses (SaveCounterparty);
 * its sentence is shown on the field it names.
 *
 * ⚠ Not a <form>: it opens from inside the voucher's form, and a nested form's
 * submit would submit the voucher too.
 */
export function CounterpartyDialog({
    open,
    onOpenChange,
    quick = false,
    counterpartyId,
    initial,
    linkOptions,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quick?: boolean;
    /** Set when editing; absent when adding. */
    counterpartyId?: number;
    initial?: CounterpartyFields;
    linkOptions?: LinkOptions | null;
    onSaved: (row: { id: number; label: string; related: boolean; url: string }) => void;
}) {
    const { t } = useTranslation();
    const [values, setValues] = useState<CounterpartyFields>(initial ?? blankCounterparty());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    // ⚠ Read when the dialog OPENS, never on every render: a caller passing a
    // fresh object each render would otherwise wipe what is being typed.
    const initialRef = useRef(initial);
    initialRef.current = initial;

    useEffect(() => {
        if (open) {
            setValues(initialRef.current ?? blankCounterparty());
            setErrors({});
        }
    }, [open]);

    const set = <K extends keyof CounterpartyFields>(key: K, value: CounterpartyFields[K]) =>
        setValues((previous) => ({ ...previous, [key]: value }));

    const ready = values.legal_name.trim() !== '' && (!values.related_party || values.relationship.trim() !== '');

    async function save() {
        setSaving(true);
        setErrors({});

        try {
            const response = await fetch(counterpartyId ? `/accounting/counterparties/${counterpartyId}` : '/accounting/counterparties', {
                method: counterpartyId ? 'PUT' : 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify(values),
            });

            if (response.status === 422) {
                const body = (await response.json()) as { errors: Record<string, string[]> };
                setErrors(Object.fromEntries(Object.entries(body.errors).map(([key, value]) => [key, value[0]])));

                return;
            }

            if (!response.ok) {
                setErrors({ legal_name: response.status === 403 ? t('counterparties.form.forbidden') : t('counterparties.form.failed') });

                return;
            }

            onSaved((await response.json()) as { id: number; label: string; related: boolean; url: string });
            onOpenChange(false);
        } catch {
            setErrors({ legal_name: t('counterparties.form.offline') });
        } finally {
            setSaving(false);
        }
    }

    const textarea = (key: 'address' | 'bank_details' | 'notes' | 'relationship', placeholder?: string) => (
        <textarea
            id={`counterparty_${key}`}
            rows={2}
            className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            value={values[key]}
            placeholder={placeholder}
            onChange={(e) => set(key, e.target.value)}
        />
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={quick ? 'sm:max-w-lg' : 'max-h-[90vh] overflow-y-auto sm:max-w-2xl'}>
                <DialogHeader>
                    <DialogTitle>{counterpartyId ? t('counterparties.form.edit_title') : t('counterparties.picker.new')}</DialogTitle>
                    <DialogDescription>{t('counterparties.form.internal_note')}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <FormRow>
                        <Field
                            label={t('counterparties.form.legal_name')}
                            htmlFor="counterparty_legal_name"
                            size="grow"
                            required
                            error={errors.legal_name}
                            hint={t('counterparties.form.legal_name_hint')}
                        >
                            <Input
                                id="counterparty_legal_name"
                                aria-required="true"
                                value={values.legal_name}
                                onChange={(e) => set('legal_name', e.target.value)}
                            />
                        </Field>
                        <Field label={t('counterparties.form.kind')} htmlFor="counterparty_kind" required error={errors.kind}>
                            <SearchSelect
                                id="counterparty_kind"
                                value={values.kind}
                                onChange={(e) => set('kind', e.target.value as CounterpartyKind)}
                            >
                                {COUNTERPARTY_KINDS.map((kind) => (
                                    <option key={kind} value={kind}>
                                        {t(KIND_LABEL[kind])}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormRow>

                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="counterparty_related"
                            checked={values.related_party}
                            onCheckedChange={(checked) => set('related_party', checked === true)}
                        />
                        <Label htmlFor="counterparty_related" className="leading-snug font-normal">
                            {t('counterparties.form.related')}
                        </Label>
                    </div>
                    <InputError message={errors.related_party} />

                    {values.related_party && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="counterparty_relationship" required>
                                {t('counterparties.form.relationship')}
                            </Label>
                            {textarea('relationship', t('counterparties.form.relationship_placeholder'))}
                            <InputError message={errors.relationship} />
                        </div>
                    )}

                    <FormRow>
                        <Field label={t('counterparties.form.phone')} htmlFor="counterparty_phone" size="md" error={errors.phone}>
                            <Input id="counterparty_phone" value={values.phone} onChange={(e) => set('phone', e.target.value)} />
                        </Field>
                        <Field label={t('counterparties.form.tin')} htmlFor="counterparty_tin" size="md" error={errors.tin}>
                            <Input id="counterparty_tin" value={values.tin} onChange={(e) => set('tin', e.target.value)} />
                        </Field>
                        {!quick && (
                            <>
                                <Field label={t('counterparties.form.bin')} htmlFor="counterparty_bin" size="md" error={errors.bin}>
                                    <Input id="counterparty_bin" value={values.bin} onChange={(e) => set('bin', e.target.value)} />
                                </Field>
                                <Field
                                    label={t('counterparties.form.registration_no')}
                                    htmlFor="counterparty_registration_no"
                                    size="md"
                                    error={errors.registration_no}
                                >
                                    <Input
                                        id="counterparty_registration_no"
                                        value={values.registration_no}
                                        onChange={(e) => set('registration_no', e.target.value)}
                                    />
                                </Field>
                                <Field label={t('counterparties.form.email')} htmlFor="counterparty_email" size="lg" error={errors.email}>
                                    <Input id="counterparty_email" type="email" value={values.email} onChange={(e) => set('email', e.target.value)} />
                                </Field>
                            </>
                        )}
                    </FormRow>

                    {!quick && (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="counterparty_address">{t('counterparties.form.address')}</Label>
                                {textarea('address')}
                                <InputError message={errors.address} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="counterparty_bank_details">{t('counterparties.form.bank_details')}</Label>
                                {textarea('bank_details', t('counterparties.form.bank_details_placeholder'))}
                                <InputError message={errors.bank_details} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="counterparty_notes">{t('counterparties.form.notes')}</Label>
                                {textarea('notes')}
                                <InputError message={errors.notes} />
                            </div>

                            {linkOptions && (
                                <div className="grid gap-2">
                                    <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                                        {t('counterparties.form.links')}
                                    </p>
                                    <FormRow>
                                        <Field label={t('counterparties.form.customer')} htmlFor="counterparty_customer" error={errors.customer_id}>
                                            <SearchSelect
                                                id="counterparty_customer"
                                                value={values.customer_id}
                                                onChange={(e) => set('customer_id', e.target.value)}
                                            >
                                                <option value="">{t('counterparties.form.none')}</option>
                                                {linkOptions.customers.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        </Field>
                                        <Field label={t('counterparties.form.supplier')} htmlFor="counterparty_supplier" error={errors.supplier_id}>
                                            <SearchSelect
                                                id="counterparty_supplier"
                                                value={values.supplier_id}
                                                onChange={(e) => set('supplier_id', e.target.value)}
                                            >
                                                <option value="">{t('counterparties.form.none')}</option>
                                                {linkOptions.suppliers.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        </Field>
                                        <Field label={t('counterparties.form.employee')} htmlFor="counterparty_employee" error={errors.employee_id}>
                                            <SearchSelect
                                                id="counterparty_employee"
                                                value={values.employee_id}
                                                onChange={(e) => set('employee_id', e.target.value)}
                                            >
                                                <option value="">{t('counterparties.form.none')}</option>
                                                {linkOptions.employees.map((option) => (
                                                    <option key={option.id} value={option.id}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </SearchSelect>
                                        </Field>
                                    </FormRow>
                                </div>
                            )}
                        </>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        {t('counterparties.form.cancel')}
                    </Button>
                    <Button type="button" disabled={saving || !ready} onClick={save}>
                        {saving ? t('counterparties.form.saving') : t('counterparties.form.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
