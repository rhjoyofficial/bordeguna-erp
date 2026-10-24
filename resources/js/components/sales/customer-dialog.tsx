import { DuplicateWarning } from '@/components/accounting/duplicate-warning';
import { Field, FormGroup, FormRow } from '@/components/form/field';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { plainNumber } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

export interface DialogCustomer {
    id: number;
    code: string | null;
    name: string;
    type: string;
    // ⚠ These four were in the form's state and posted as EMPTY STRINGS on every
    // save, with no input ever rendered - so the database column existed, the
    // controller validated it, and nothing could ever put a value in it. Same
    // failure as products.barcode. They are here because the edit form has to
    // preselect what the customer already is.
    contact_name: string | null;
    email: string | null;
    bin: string | null;
    tin: string | null;
    address: string | null;
    note: string | null;
    phone: string | null;
    payment_terms: string | null;
    credit_days: number | null;
    credit_limit: string | null;
    price_group_id: number | null;
    is_system: boolean;
}

export interface DialogPriceGroup {
    id: number;
    code: string;
    name: string;
}

/* What "no group" means for each type (mirrors CustomerType::defaultPriceGroupCode):
   consumers pay RETAIL, the MRP group; a shop is priced in SHOP and never at MRP. */
const defaultGroupFor = (type: string) => (type === 'walk_in' || type === 'retail' ? 'RETAIL' : type.toUpperCase());

const EMPTY = {
    name: '',
    code: '',
    type: 'retail',
    contact_name: '',
    phone: '',
    email: '',
    bin: '',
    tin: '',
    address: '',
    payment_terms: '',
    credit_days: '',
    credit_limit: '',
    price_group_id: '',
    note: '',
};

function fromCustomer(customer: DialogCustomer): typeof EMPTY {
    return {
        name: customer.name,
        code: customer.code ?? '',
        type: customer.type,
        contact_name: customer.contact_name ?? '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
        bin: customer.bin ?? '',
        tin: customer.tin ?? '',
        address: customer.address ?? '',
        payment_terms: customer.payment_terms ?? '',
        credit_days: customer.credit_days === null ? '' : String(customer.credit_days),
        // "20000.0000" as stored reads "20000" in the box (practice run F27).
        credit_limit: plainNumber(customer.credit_limit),
        price_group_id: customer.price_group_id !== null ? String(customer.price_group_id) : '',
        note: customer.note ?? '',
    };
}

/**
 * One popup to add a customer or correct one (Joy, 14 Oct 2026: "click add
 * button pop up will add ... same when edit"). The fourteen-field form that
 * sat open above the list, drawn even for people who could not save it, is
 * gone; the list is the page and the form appears only when somebody asks.
 *
 * Same fields, same routes, same server rules as before: the dialog only
 * changes where the form is drawn.
 */
export function CustomerDialog({
    open,
    onOpenChange,
    customer,
    types,
    priceGroups,
    mayGrantCredit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The customer being edited; null for a new one. */
    customer: DialogCustomer | null;
    types: { value: string; label: string }[];
    priceGroups: DialogPriceGroup[];
    mayGrantCredit: boolean;
}) {
    const editing = customer !== null;
    const { data, setData, post, put, errors, processing, reset, clearErrors, setDefaults, isDirty, transform } = useForm(EMPTY);

    // Fresh fields every time it opens: blank for a new customer, filled for an edit.
    useEffect(() => {
        if (!open) {
            return;
        }

        const next = customer ? fromCustomer(customer) : EMPTY;
        setDefaults(next);
        setData(next);
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, customer]);

    const close = () => {
        clearErrors();
        reset();
        onOpenChange(false);
    };

    // ⭐ B13 (24 Oct 2026): a possible duplicate (same phone or name) comes back
    // as `_duplicate`; Save anyway sends the same form with the ack.
    const send = (ack: string | null) => {
        transform((form) => ({ ...form, duplicate_ack: ack }));

        // ⚠ `update()` and its PUT route existed from the first slice with
        // NOTHING calling them - the same gap suppliers and products both had.
        // A customer entered with a typo could not be corrected.
        if (customer) {
            put(route('sales.customers.update', customer.id), { preserveScroll: true, onSuccess: close });

            return;
        }

        post(route('sales.customers.store'), { preserveScroll: true, onSuccess: close });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send(null);
    };

    // ⚠ The walk-in row's code and type are frozen by the controller, because
    // Customer::walkIn() resolves the counter customer by its code. The boxes
    // still post the stored values; they are only locked so nobody types a
    // change the server will refuse.
    const systemLocked = Boolean(customer?.is_system);
    const systemHint = systemLocked ? 'Part of the system: the counter finds this customer by it.' : undefined;

    return (
        <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl" aria-describedby={undefined} dirty={isDirty}>
                {/* ⚠ Thirteen fields in one equal four-column grid gave a code,
                    a phone and an address the same width (30 Aug 2026 audit).
                    Three rows, each field as wide as what it holds. */}
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{editing ? `Edit ${customer.name}` : 'Add a customer'}</DialogTitle>
                    </DialogHeader>

                    <FormGroup title="Identity">
                        <Field label="Name" htmlFor="customer-name" size="grow" error={errors.name} required>
                            <Input
                                id="customer-name"
                                className="max-w-md"
                                required
                                aria-required
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Code" htmlFor="customer-code" size="sm" error={errors.code} hint={systemHint}>
                            <Input
                                id="customer-code"
                                value={data.code}
                                disabled={systemLocked}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="DIST-001"
                            />
                        </Field>
                        <Field label="Type" htmlFor="customer-type" error={errors.type} required>
                            <SearchSelect
                                id="customer-type"
                                value={data.type}
                                disabled={systemLocked}
                                onChange={(e) => setData('type', e.target.value)}
                            >
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                    </FormGroup>

                    <FormGroup title="Commercial">
                        {/* ⚠ A select over real rows, never typed - free text here
                            once meant `Distributor` vs `distributor` silently
                            charged a distributor the retail price. Blank means
                            RETAIL, the default group. */}
                        <Field label="Price group" htmlFor="customer-price_group_id" error={errors.price_group_id}>
                            <SearchSelect
                                id="customer-price_group_id"
                                value={data.price_group_id}
                                onChange={(e) => setData('price_group_id', e.target.value)}
                            >
                                <option value="">Default ({defaultGroupFor(data.type)})</option>
                                {priceGroups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.code} - {g.name}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {/* ⭐ Days, not words (26 Sep 2026): an invoice falls due this many
                            days after its date. Blank means the default, 10. */}
                        <Field label="Credit days" htmlFor="customer-credit_days" size="sm" error={errors.credit_days}>
                            <Input
                                id="customer-credit_days"
                                inputMode="numeric"
                                value={data.credit_days}
                                disabled={!mayGrantCredit}
                                title={mayGrantCredit ? undefined : 'Credit terms change through a Credit terms request.'}
                                onChange={(e) => setData('credit_days', e.target.value)}
                                placeholder={data.type === 'walk_in' ? '0' : '10'}
                            />
                        </Field>
                        <Field label="Terms note" htmlFor="customer-payment_terms" size="sm" error={errors.payment_terms}>
                            <Input
                                id="customer-payment_terms"
                                value={data.payment_terms}
                                onChange={(e) => setData('payment_terms', e.target.value)}
                                placeholder="e.g. cheque on delivery"
                            />
                        </Field>
                        <Field label="Credit limit" htmlFor="customer-credit_limit" size="sm" error={errors.credit_limit}>
                            <Input
                                id="customer-credit_limit"
                                value={data.credit_limit}
                                disabled={!mayGrantCredit}
                                title={mayGrantCredit ? undefined : 'Credit terms change through a Credit terms request.'}
                                onChange={(e) => setData('credit_limit', e.target.value)}
                            />
                        </Field>
                        {!mayGrantCredit && (
                            <p className="text-muted-foreground w-full text-xs">
                                Credit limit and days are granted by the authority through a{' '}
                                <Link href="/sales/credit-terms" className="underline">
                                    Credit terms request
                                </Link>
                                .
                            </p>
                        )}
                    </FormGroup>

                    <FormGroup title="Contact">
                        <Field label="Contact person" htmlFor="customer-contact_name" size="md" error={errors.contact_name}>
                            <Input id="customer-contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                        </Field>
                        <Field label="Phone" htmlFor="customer-phone" size="md" error={errors.phone}>
                            <Input id="customer-phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        </Field>
                        <Field label="Email" htmlFor="customer-email" size="lg" error={errors.email}>
                            <Input id="customer-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        </Field>
                        <Field label="BIN (optional)" htmlFor="customer-bin" size="sm" error={errors.bin}>
                            <Input id="customer-bin" value={data.bin} onChange={(e) => setData('bin', e.target.value)} />
                        </Field>
                        <Field label="TIN (optional)" htmlFor="customer-tin" size="sm" error={errors.tin}>
                            <Input id="customer-tin" value={data.tin} onChange={(e) => setData('tin', e.target.value)} />
                        </Field>
                    </FormGroup>

                    <FormRow>
                        <Field label="Address" htmlFor="customer-address" size="grow" error={errors.address}>
                            <textarea
                                id="customer-address"
                                className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                            />
                        </Field>
                        <Field label="Note" htmlFor="customer-note" size="grow" error={errors.note}>
                            <textarea
                                id="customer-note"
                                className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                                value={data.note}
                                onChange={(e) => setData('note', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <DuplicateWarning errors={errors as Record<string, string | undefined>} busy={processing} onSaveAnyway={send} />
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>{editing ? `Save ${customer.name}` : 'Add customer'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
