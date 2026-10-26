import InputError from '@/components/input-error';
import { AmountBox, DateBox, Field } from '@/components/sales/advance-dialogs';
import { CustomerLookup } from '@/components/sales/customer-lookup';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dmy } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { type FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useRef, useState, type ReactNode } from 'react';

type Named = { id: number; name: string; phone?: string | null };

export type SampleOptions = {
    customers: Named[];
    leads: Named[];
    contacts: Named[];
    employees: Named[];
    warehouses: { id: number; name: string }[];
    products: { id: number; name: string; type: string; unit: string | null }[];
    batches: {
        id: number;
        product_id: number;
        warehouse_id: number;
        batch_number: string;
        quantity: string;
        expiry_date: string | null;
        is_sample: boolean;
    }[];
    moneyAccounts: { id: number; name: string }[];
    couriers: { id: number; name: string }[];
    districts: string[];
};

/**
 * ⭐ Samples (Joy, 25 Oct 2026): the two popups of the Samples screen, each a
 * popup with the date first (Joy: add in a POPUP, never a form above a list).
 * ⚠ No <form>: Enter never submits; saving happens only by clicking Save.
 * `startOpen` opens Give sample by itself when the product page's "Give as
 * sample" button brought the person here.
 */
function SamplePopup({
    trigger,
    title,
    description,
    url,
    data,
    fields,
    ready,
    startOpen = false,
    onDone,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    url: string;
    data: Record<string, FormDataConvertible>;
    fields: ReactNode;
    ready: boolean;
    startOpen?: boolean;
    onDone?: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(startOpen);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const send = () => {
        setBusy(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setErrors({});
                onDone?.();
            },
            onError: (problems) => setErrors(problems),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setErrors({});
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 text-sm">
                    {fields}
                    {Object.entries(errors).map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('smp.cancel')}
                    </Button>
                    <Button type="button" disabled={!ready || busy} onClick={send}>
                        {busy ? t('smp.working') : t('smp.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type Line = { product_id: string; stock_batch_id: string; quantity: string };

const RECIPIENT_KINDS = ['customer', 'lead', 'contact', 'employee', 'new'] as const;
type RecipientKind = (typeof RECIPIENT_KINDS)[number];

export function GiveSampleDialog({
    options,
    purposes,
    methods,
    today,
    presetProduct,
}: {
    options: SampleOptions;
    purposes: Record<string, string>;
    methods: Record<string, string>;
    today: string;
    presetProduct: number | null;
}) {
    const { t } = useTranslation();
    const firstWarehouse = options.warehouses.length === 1 ? String(options.warehouses[0].id) : '';
    const empty = {
        given_on: today,
        recipient_kind: 'customer' as RecipientKind,
        recipient_id: '',
        recipient_name: '',
        recipient_phone: '',
        purpose: 'tasting',
        purpose_note: '',
        warehouse_id: firstWarehouse,
        lines: [{ product_id: presetProduct ? String(presetProduct) : '', stock_batch_id: '', quantity: '1' }] as Line[],
        delivery_method: 'hand',
        delivery_charge: '',
        pay: 'account' as 'account' | 'courier',
        delivery_money_account_id: '',
        courier_account_id: '',
        delivery_district: '',
        delivery_zone: '',
        feedback_on: '',
        note: '',
    };
    const [data, setData] = useState(empty);
    const [zoneNote, setZoneNote] = useState<string | null>(null);
    // ⭐ 26 Oct 2026: who the sample was going to before the lookup picked a customer.
    const beforeLookup = useRef<Pick<typeof empty, 'recipient_kind' | 'delivery_district'> | null>(null);
    const set =
        <K extends keyof typeof empty>(key: K) =>
        (value: (typeof empty)[K]) =>
            setData((current) => ({ ...current, [key]: value }));
    const setLine = (index: number, patch: Partial<Line>) =>
        setData((current) => ({ ...current, lines: current.lines.map((line, i) => (i === index ? { ...line, ...patch } : line)) }));

    const people: Record<Exclude<RecipientKind, 'new'>, Named[]> = {
        customer: options.customers,
        lead: options.leads,
        contact: options.contacts,
        employee: options.employees,
    };

    const charged = Number(data.delivery_charge) > 0;
    const owedToCourier = data.delivery_method === 'courier' && data.pay === 'courier';
    const ready =
        data.given_on !== '' &&
        data.warehouse_id !== '' &&
        (data.recipient_kind === 'new' ? data.recipient_name.trim() !== '' && data.recipient_phone.trim() !== '' : data.recipient_id !== '') &&
        (data.purpose !== 'other' || data.purpose_note.trim() !== '') &&
        data.lines.length > 0 &&
        data.lines.every((line) => line.product_id !== '' && Number(line.quantity) > 0) &&
        (!charged || (owedToCourier ? data.courier_account_id !== '' : data.delivery_money_account_id !== ''));

    const zone = async () => {
        if (data.delivery_district === '') return;
        const response = await fetch(`/sales/samples/zone?district=${encodeURIComponent(data.delivery_district)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const found = (await response.json()) as { charge: string; zone: string } | null;
        if (found) {
            setData((current) => ({ ...current, delivery_charge: found.charge, delivery_zone: found.zone }));
            setZoneNote(t('smp.zone_found', { zone: found.zone, charge: found.charge }));
        }
    };

    const payload = {
        ...data,
        lines: data.lines,
        delivery_money_account_id: charged && !owedToCourier ? data.delivery_money_account_id : '',
        courier_account_id: charged && owedToCourier ? data.courier_account_id : '',
    };

    return (
        <SamplePopup
            trigger={<Button size="sm">{t('smp.give')}</Button>}
            title={t('smp.give_title')}
            description={t('smp.give_text')}
            url="/sales/samples"
            data={payload}
            ready={ready}
            startOpen={presetProduct !== null}
            onDone={() => {
                setData(empty);
                setZoneNote(null);
            }}
            fields={
                <>
                    <Field id="sample-given-on" label={t('smp.date')} required>
                        <DateBox id="sample-given-on" value={data.given_on} max={today} onChange={set('given_on')} />
                    </Field>

                    <div className="flex flex-wrap items-end gap-3">
                        <Field id="sample-recipient-kind" label={t('smp.to')} required>
                            <SearchSelect
                                id="sample-recipient-kind"
                                value={data.recipient_kind}
                                onChange={(e) =>
                                    setData((current) => ({ ...current, recipient_kind: e.target.value as RecipientKind, recipient_id: '' }))
                                }
                            >
                                {RECIPIENT_KINDS.map((kind) => (
                                    <option key={kind} value={kind}>
                                        {t(`smp.kind_${kind}`)}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {data.recipient_kind === 'new' ? (
                            <>
                                <Field id="sample-new-name" label={t('smp.name')} required>
                                    <Input
                                        id="sample-new-name"
                                        className="w-56"
                                        value={data.recipient_name}
                                        onChange={(e) => set('recipient_name')(e.target.value)}
                                    />
                                </Field>
                                <Field id="sample-new-phone" label={t('smp.phone')} required>
                                    <Input
                                        id="sample-new-phone"
                                        inputMode="tel"
                                        className="w-44"
                                        value={data.recipient_phone}
                                        onChange={(e) => set('recipient_phone')(e.target.value)}
                                    />
                                </Field>
                            </>
                        ) : (
                            <Field id="sample-recipient" label={t('smp.who')} required>
                                <SearchSelect id="sample-recipient" value={data.recipient_id} onChange={(e) => set('recipient_id')(e.target.value)}>
                                    <option value="">{t('smp.choose_person')}</option>
                                    {people[data.recipient_kind].map((person) => (
                                        <option key={person.id} value={person.id}>
                                            {person.name}
                                            {person.phone ? ` (${person.phone})` : ''}
                                        </option>
                                    ))}
                                </SearchSelect>
                            </Field>
                        )}
                    </div>
                    {data.recipient_kind === 'new' && <p className="text-muted-foreground text-xs">{t('smp.new_hint')}</p>}
                    {/* ⭐ 26 Oct 2026 (Joy): a phone or a name finds the customer, also
                        when it was about to be typed in as somebody new. */}
                    {(data.recipient_kind === 'customer' || data.recipient_kind === 'new') && (
                        <CustomerLookup
                            id="sample-customer-lookup"
                            selectedId={data.recipient_kind === 'customer' ? data.recipient_id : ''}
                            onPick={(customer) => {
                                beforeLookup.current = { recipient_kind: data.recipient_kind, delivery_district: data.delivery_district };
                                setData((current) => ({
                                    ...current,
                                    recipient_kind: 'customer',
                                    recipient_id: String(customer.id),
                                    delivery_district: current.delivery_district || (customer.saved_place?.district ?? ''),
                                }));
                            }}
                            onUndo={(previousId) =>
                                setData((current) => ({
                                    ...current,
                                    ...(beforeLookup.current ?? {}),
                                    recipient_id: previousId,
                                }))
                            }
                        />
                    )}

                    <div className="flex flex-wrap items-end gap-3">
                        <Field id="sample-purpose" label={t('smp.purpose')} required>
                            <SearchSelect id="sample-purpose" value={data.purpose} onChange={(e) => set('purpose')(e.target.value)}>
                                {Object.keys(purposes).map((key) => (
                                    <option key={key} value={key}>
                                        {t(`smp.purpose_${key}` as 'smp.purpose_tasting')}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        <Field id="sample-purpose-note" label={t('smp.purpose_note')} required={data.purpose === 'other'}>
                            <Input
                                id="sample-purpose-note"
                                className="w-64"
                                value={data.purpose_note}
                                onChange={(e) => set('purpose_note')(e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field id="sample-warehouse" label={t('smp.warehouse')} required>
                        <SearchSelect id="sample-warehouse" value={data.warehouse_id} onChange={(e) => set('warehouse_id')(e.target.value)}>
                            <option value="">{t('smp.choose_warehouse')}</option>
                            {options.warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>

                    <div className="grid gap-2">
                        <p className="font-medium">{t('smp.items')}</p>
                        {data.lines.map((line, index) => {
                            const batches = options.batches.filter(
                                (b) => String(b.product_id) === line.product_id && String(b.warehouse_id) === data.warehouse_id,
                            );
                            const unit = options.products.find((p) => String(p.id) === line.product_id)?.unit;

                            return (
                                <div key={index} className="flex flex-wrap items-end gap-2">
                                    <Field id={`sample-line-product-${index}`} label={t('smp.product')} required>
                                        <SearchSelect
                                            id={`sample-line-product-${index}`}
                                            value={line.product_id}
                                            onChange={(e) => setLine(index, { product_id: e.target.value, stock_batch_id: '' })}
                                        >
                                            <option value="">{t('smp.choose_product')}</option>
                                            {options.products.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </Field>
                                    <Field id={`sample-line-batch-${index}`} label={t('smp.batch')}>
                                        <SearchSelect
                                            id={`sample-line-batch-${index}`}
                                            value={line.stock_batch_id}
                                            onChange={(e) => setLine(index, { stock_batch_id: e.target.value })}
                                        >
                                            <option value="">{t('smp.fefo')}</option>
                                            {batches.map((b) => (
                                                <option key={b.id} value={b.id}>
                                                    {b.is_sample ? `${t('smp.sample_lot')} ` : ''}
                                                    {b.batch_number} · {b.quantity}
                                                    {unit ? ` ${unit}` : ''}
                                                    {b.expiry_date ? ` · ${t('smp.expires')} ${dmy(b.expiry_date)}` : ''}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </Field>
                                    <Field id={`sample-line-qty-${index}`} label={t('smp.quantity')} required>
                                        <AmountBox
                                            id={`sample-line-qty-${index}`}
                                            value={line.quantity}
                                            onChange={(value) => setLine(index, { quantity: value })}
                                        />
                                    </Field>
                                    {data.lines.length > 1 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setData((current) => ({ ...current, lines: current.lines.filter((_, i) => i !== index) }))}
                                        >
                                            {t('smp.remove')}
                                        </Button>
                                    )}
                                </div>
                            );
                        })}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setData((current) => ({
                                        ...current,
                                        lines: [...current.lines, { product_id: '', stock_batch_id: '', quantity: '1' }],
                                    }))
                                }
                            >
                                {t('smp.add_item')}
                            </Button>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-end gap-3">
                        <Field id="sample-method" label={t('smp.delivery')} required>
                            <SearchSelect id="sample-method" value={data.delivery_method} onChange={(e) => set('delivery_method')(e.target.value)}>
                                {Object.keys(methods).map((key) => (
                                    <option key={key} value={key}>
                                        {t(`smp.method_${key}` as 'smp.method_hand')}
                                    </option>
                                ))}
                            </SearchSelect>
                        </Field>
                        {data.delivery_method === 'courier' && (
                            <>
                                <Field id="sample-district" label={t('smp.district')}>
                                    <SearchSelect
                                        id="sample-district"
                                        value={data.delivery_district}
                                        onChange={(e) => set('delivery_district')(e.target.value)}
                                    >
                                        <option value="">{t('smp.choose_district')}</option>
                                        {options.districts.map((d) => (
                                            <option key={d} value={d}>
                                                {d}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                                <Button type="button" size="sm" variant="outline" disabled={data.delivery_district === ''} onClick={zone}>
                                    {t('smp.use_zone')}
                                </Button>
                            </>
                        )}
                        <Field id="sample-charge" label={t('smp.charge')}>
                            <AmountBox id="sample-charge" value={data.delivery_charge} onChange={set('delivery_charge')} />
                        </Field>
                    </div>
                    {zoneNote && <p className="text-muted-foreground text-xs">{zoneNote}</p>}

                    {charged && (
                        <div className="flex flex-wrap items-end gap-3">
                            {data.delivery_method === 'courier' && (
                                <Field id="sample-pay" label={t('smp.pay')}>
                                    <SearchSelect
                                        id="sample-pay"
                                        value={data.pay}
                                        onChange={(e) => set('pay')(e.target.value as 'account' | 'courier')}
                                    >
                                        <option value="account">{t('smp.pay_account')}</option>
                                        <option value="courier">{t('smp.pay_courier')}</option>
                                    </SearchSelect>
                                </Field>
                            )}
                            {owedToCourier ? (
                                <Field id="sample-courier" label={t('smp.courier')} required>
                                    <SearchSelect
                                        id="sample-courier"
                                        value={data.courier_account_id}
                                        onChange={(e) => set('courier_account_id')(e.target.value)}
                                    >
                                        <option value="">{t('smp.choose_courier')}</option>
                                        {options.couriers.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                            ) : (
                                <Field id="sample-paid-from" label={t('smp.paid_from')} required>
                                    <SearchSelect
                                        id="sample-paid-from"
                                        value={data.delivery_money_account_id}
                                        onChange={(e) => set('delivery_money_account_id')(e.target.value)}
                                    >
                                        <option value="">{t('smp.choose_account')}</option>
                                        {options.moneyAccounts.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.name}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                            )}
                        </div>
                    )}
                    <p className="text-muted-foreground text-xs">{t('smp.no_money')}</p>

                    <Field id="sample-feedback" label={t('smp.feedback')}>
                        <DateBox id="sample-feedback" value={data.feedback_on} max="9999-12-31" onChange={set('feedback_on')} />
                    </Field>
                    <p className="text-muted-foreground -mt-2 text-xs">{t('smp.feedback_hint')}</p>

                    <Field id="sample-note" label={t('smp.note')}>
                        <textarea
                            id="sample-note"
                            value={data.note}
                            onChange={(e) => set('note')(e.target.value)}
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        />
                    </Field>
                </>
            }
        />
    );
}

type MakeInput = { stock_batch_id: string; quantity: string };

export function MakeSampleDialog({ options, today }: { options: SampleOptions; today: string }) {
    const { t } = useTranslation();
    const empty = {
        made_on: today,
        warehouse_id: options.warehouses.length === 1 ? String(options.warehouses[0].id) : '',
        product_id: '',
        quantity: '',
        expiry_date: '',
        inputs: [{ stock_batch_id: '', quantity: '' }] as MakeInput[],
        note: '',
    };
    const [data, setData] = useState(empty);
    const set =
        <K extends keyof typeof empty>(key: K) =>
        (value: (typeof empty)[K]) =>
            setData((current) => ({ ...current, [key]: value }));
    const setInput = (index: number, patch: Partial<MakeInput>) =>
        setData((current) => ({ ...current, inputs: current.inputs.map((input, i) => (i === index ? { ...input, ...patch } : input)) }));
    const productName = (id: number) => options.products.find((p) => p.id === id)?.name ?? '';
    const batches = options.batches.filter((b) => String(b.warehouse_id) === data.warehouse_id && String(b.product_id) !== data.product_id);
    const ready =
        data.made_on !== '' &&
        data.warehouse_id !== '' &&
        data.product_id !== '' &&
        Number(data.quantity) > 0 &&
        data.inputs.length > 0 &&
        data.inputs.every((input) => input.stock_batch_id !== '' && Number(input.quantity) > 0);

    return (
        <SamplePopup
            trigger={
                <Button size="sm" variant="outline">
                    {t('smp.make')}
                </Button>
            }
            title={t('smp.make_title')}
            description={t('smp.make_text')}
            url="/sales/samples/make"
            data={data}
            ready={ready}
            onDone={() => setData(empty)}
            fields={
                <>
                    <Field id="make-on" label={t('smp.date')} required>
                        <DateBox id="make-on" value={data.made_on} max={today} onChange={set('made_on')} />
                    </Field>
                    <Field id="make-warehouse" label={t('smp.warehouse')} required>
                        <SearchSelect id="make-warehouse" value={data.warehouse_id} onChange={(e) => set('warehouse_id')(e.target.value)}>
                            <option value="">{t('smp.choose_warehouse')}</option>
                            {options.warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </SearchSelect>
                    </Field>
                    <div className="flex flex-wrap items-end gap-3">
                        <Field id="make-product" label={t('smp.make_output')} required>
                            <SearchSelect id="make-product" value={data.product_id} onChange={(e) => set('product_id')(e.target.value)}>
                                <option value="">{t('smp.choose_product')}</option>
                                {options.products
                                    .filter((p) => p.type !== 'raw_material')
                                    .map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                            </SearchSelect>
                        </Field>
                        <Field id="make-qty" label={t('smp.quantity')} required>
                            <AmountBox id="make-qty" value={data.quantity} onChange={set('quantity')} />
                        </Field>
                        <Field id="make-expiry" label={t('smp.expiry')}>
                            <DateBox id="make-expiry" value={data.expiry_date} max="9999-12-31" onChange={set('expiry_date')} />
                        </Field>
                    </div>
                    <p className="text-muted-foreground text-xs">{t('smp.make_output_hint')}</p>

                    <div className="grid gap-2">
                        <p className="font-medium">{t('smp.make_inputs')}</p>
                        {data.inputs.map((input, index) => (
                            <div key={index} className="flex flex-wrap items-end gap-2">
                                <Field id={`make-input-${index}`} label={t('smp.batch')} required>
                                    <SearchSelect
                                        id={`make-input-${index}`}
                                        value={input.stock_batch_id}
                                        onChange={(e) => setInput(index, { stock_batch_id: e.target.value })}
                                    >
                                        <option value="">{t('smp.choose_batch')}</option>
                                        {batches.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {productName(b.product_id)} · {b.batch_number} · {b.quantity}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </Field>
                                <Field id={`make-input-qty-${index}`} label={t('smp.quantity')} required>
                                    <AmountBox
                                        id={`make-input-qty-${index}`}
                                        value={input.quantity}
                                        onChange={(value) => setInput(index, { quantity: value })}
                                    />
                                </Field>
                                {data.inputs.length > 1 && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setData((current) => ({ ...current, inputs: current.inputs.filter((_, i) => i !== index) }))}
                                    >
                                        {t('smp.remove')}
                                    </Button>
                                )}
                            </div>
                        ))}
                        <div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setData((current) => ({ ...current, inputs: [...current.inputs, { stock_batch_id: '', quantity: '' }] }))
                                }
                            >
                                {t('smp.add_input')}
                            </Button>
                        </div>
                    </div>
                    <Field id="make-note" label={t('smp.note')}>
                        <textarea
                            id="make-note"
                            value={data.note}
                            onChange={(e) => set('note')(e.target.value)}
                            className="border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        />
                    </Field>
                </>
            }
        />
    );
}
