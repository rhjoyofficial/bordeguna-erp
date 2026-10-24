import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import { Field } from '@/components/form/field';
import InputError from '@/components/input-error';
import { PaymentTermsFields } from '@/components/purchasing/payment-terms-fields';
import { pickableSupplierTypes, supplierTypeLabel, type SupplierTypeOption } from '@/components/purchasing/supplier-types';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogSupplier {
    id: number;
    code: string | null;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    payment_terms: string | null;
    // ⭐ Ruling P6: structured terms and the warning-only credit limit.
    payment_terms_type?: string | null;
    payment_terms_days?: number | null;
    credit_limit?: string | null;
    /** ⭐ PR1: the usual delivery time in days, for a new order's expected date. */
    lead_time_days?: number | null;
    supplier_type: string | null;
    // ⚠ Only sent to users who may manage suppliers.
    bin?: string | null;
    tin?: string | null;
    bank_name?: string | null;
    bank_branch?: string | null;
    bank_account_name?: string | null;
    bank_account_number?: string | null;
    mfs_number?: string | null;
    notes?: string | null;
    is_active: boolean;
}

// ⚠⚠ Every field the import can set, the screen can set. A supplier created by
// hand must not be structurally different from an imported one.
const EMPTY = {
    code: '',
    name: '',
    contact_name: '',
    phone: '',
    email: '',
    address: '',
    payment_terms: '',
    payment_terms_type: '',
    payment_terms_days: '',
    credit_limit: '',
    lead_time_days: '',
    supplier_type: '',
    bin: '',
    tin: '',
    bank_name: '',
    bank_branch: '',
    bank_account_name: '',
    bank_account_number: '',
    mfs_number: '',
    notes: '',
};

function fromSupplier(supplier: DialogSupplier): typeof EMPTY {
    return {
        code: supplier.code ?? '',
        name: supplier.name,
        contact_name: supplier.contact_name ?? '',
        phone: supplier.phone ?? '',
        email: supplier.email ?? '',
        address: supplier.address ?? '',
        payment_terms: supplier.payment_terms ?? '',
        payment_terms_type: supplier.payment_terms_type ?? '',
        payment_terms_days:
            supplier.payment_terms_days === null || supplier.payment_terms_days === undefined ? '' : String(supplier.payment_terms_days),
        // Plain digits in the box, not the stored "50000.0000".
        credit_limit: supplier.credit_limit === null || supplier.credit_limit === undefined ? '' : String(Number(supplier.credit_limit)),
        lead_time_days: supplier.lead_time_days === null || supplier.lead_time_days === undefined ? '' : String(supplier.lead_time_days),
        supplier_type: supplier.supplier_type ?? '',
        bin: supplier.bin ?? '',
        tin: supplier.tin ?? '',
        bank_name: supplier.bank_name ?? '',
        bank_branch: supplier.bank_branch ?? '',
        bank_account_name: supplier.bank_account_name ?? '',
        bank_account_number: supplier.bank_account_number ?? '',
        mfs_number: supplier.mfs_number ?? '',
        notes: supplier.notes ?? '',
    };
}

/**
 * One popup to add a supplier or correct one (13 Oct 2026). The form that
 * used to sit open above the list, with sixteen fields, is gone; the list is
 * the page and the form appears only when somebody asks for it.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn.
 */
export function SupplierDialog({
    open,
    onOpenChange,
    supplier,
    supplierTypes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    supplier: DialogSupplier | null;
    /** The business's own types (17 Oct 2026); see supplier-types.ts. */
    supplierTypes: SupplierTypeOption[];
}) {
    const { t } = useTranslation();
    const editing = supplier !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults, transform } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new supplier, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = supplier ? fromSupplier(supplier) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, supplier]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    // ⭐ B13 (24 Oct 2026): a possible duplicate (same phone or name) comes back
    // as `_duplicate`; Save anyway sends the same form with the ack.
    const send = (ack: string | null) => {
        transform((form) => ({ ...form, duplicate_ack: ack }));

        if (supplier) {
            put(route('purchasing.suppliers.update', supplier.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('purchasing.suppliers.store'), { preserveScroll: true, onSuccess: close });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send(null);
    };

    const field = (
        name: keyof typeof EMPTY,
        label: string,
        extra?: { type?: string; placeholder?: string; required?: boolean; disabled?: boolean; hint?: string },
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`supplier-${name}`} required={extra?.required}>
                {label}
            </Label>
            <Input
                id={`supplier-${name}`}
                type={extra?.type}
                required={extra?.required}
                aria-required={extra?.required}
                placeholder={extra?.placeholder}
                disabled={extra?.disabled}
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
            />
            {extra?.hint && <p className="text-muted-foreground text-xs">{extra.hint}</p>}
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined}>
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${supplier.name}` : 'Add a supplier'}</DialogTitle>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {/* ⚠ Frozen once assigned: the import finds a supplier by its code. */}
                        {field('code', 'Code', {
                            placeholder: 'e.g. ACME',
                            required: true,
                            disabled: Boolean(supplier?.code),
                            hint: supplier?.code ? 'Permanent once set.' : undefined,
                        })}
                        {field('name', 'Name', { required: true })}
                        {field('contact_name', 'Contact person')}
                        {field('phone', 'Phone')}
                        {field('email', 'Email', { type: 'email' })}
                        {field('address', 'Address')}
                        {field('payment_terms', t('terms.note'), { placeholder: 'e.g. cheque on delivery', hint: t('terms.note_hint') })}
                        {/* Finance decision C4: picks the TDS / VDS rates offered when paying. */}
                        <div className="grid gap-2">
                            <Label htmlFor="supplier-supplier_type">Supplier type</Label>
                            <SearchSelect
                                fit
                                id="supplier-supplier_type"
                                value={data.supplier_type}
                                onChange={(e) => setData('supplier_type', e.target.value)}
                            >
                                <option value="">Not set</option>
                                {pickableSupplierTypes(supplierTypes, supplier?.supplier_type).map((type) => (
                                    <option key={type.key} value={type.key}>
                                        {supplierTypeLabel(type)}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.supplier_type} />
                        </div>
                    </div>

                    {/* ⭐ Ruling P6: what a bill's due date is worked out from, and a
                        credit limit that only ever warns. */}
                    <div className="flex flex-wrap items-start gap-3">
                        <PaymentTermsFields
                            idPrefix="supplier"
                            type={data.payment_terms_type}
                            days={data.payment_terms_days}
                            onType={(value) => setData('payment_terms_type', value)}
                            onDays={(value) => setData('payment_terms_days', value)}
                            typeError={errors.payment_terms_type}
                            daysError={errors.payment_terms_days}
                        />
                        <Field label={t('terms.credit_limit')} htmlFor="supplier-credit_limit" size="sm" error={errors.credit_limit}>
                            <Input
                                id="supplier-credit_limit"
                                inputMode="decimal"
                                value={data.credit_limit}
                                onChange={(e) => setData('credit_limit', e.target.value)}
                            />
                        </Field>
                        <Field label={t('pof.lead_time')} htmlFor="supplier-lead_time_days" size="xs" error={errors.lead_time_days}>
                            <Input
                                id="supplier-lead_time_days"
                                inputMode="numeric"
                                value={data.lead_time_days}
                                onChange={(e) => setData('lead_time_days', e.target.value)}
                            />
                        </Field>
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <p className="text-muted-foreground text-xs sm:col-span-2">Tax identity</p>
                        {field('bin', 'BIN (VAT)')}
                        {field('tin', 'TIN')}
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <p className="text-muted-foreground text-xs sm:col-span-2">How we pay them</p>
                        {field('bank_name', 'Bank')}
                        {field('bank_branch', 'Branch')}
                        {/* ⚠ Often NOT the trading name. Paying to the wrong one bounces a week later. */}
                        {field('bank_account_name', 'Account name')}
                        {field('bank_account_number', 'Account number')}
                        {field('mfs_number', 'bKash / Nagad number')}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="supplier-notes">Notes</Label>
                        <textarea
                            id="supplier-notes"
                            rows={2}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                            placeholder="Delivery days, who to ask for, packaging requirements…"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DuplicateWarning errors={errors as Record<string, string | undefined>} busy={processing} onSaveAnyway={send} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? `Save ${supplier.name}` : 'Add supplier'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
