import { type CustomerOption, type Lead, type PriceGroupOption, type ValueLabel } from '@/components/crm/lead-types';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * ⭐ Plan D6 (CRM slice 2e): the customer type a lead's type points to. A
 * distributor lead is a Distributor; a shop or a consumer is Retail (answer 8).
 * Both stay changeable - Shoykod picks Dealer or Distributor (answer 10).
 */
const TYPE_FOR_LEAD: Record<string, string> = { distributor: 'distributor', retailer: 'retail', customer: 'retail' };

/** The price group whose code matches a customer type, if the business has one. */
const groupFor = (type: string, groups: PriceGroupOption[]) => groups.find((g) => g.code.toUpperCase() === type.toUpperCase());

/**
 * ⚠⚠ CONVERSION IS THE FUNNEL'S ONLY EXIT. `ConvertLead` is the single writer
 * of `converted_customer_id`, so until this form existed a lead could never
 * become a customer through the UI at all.
 *
 * ⚠ Every rule - already converted, lost, supplier lead, duplicate code,
 * duplicate phone, system or inactive customer, credit on a distributor - lives
 * in the action and comes back as a validation error. The form only avoids
 * offering what the action would drop.
 *
 * The page mounts this with a `key` per lead, so opening another lead starts a
 * fresh form rather than carrying the last one's code across.
 */
export function LeadConvertForm({
    lead,
    existingCustomerId,
    customers,
    customerTypes,
    priceGroups,
    creditTypes,
    onDone,
}: {
    lead: Lead;
    existingCustomerId: number | null;
    customers: CustomerOption[];
    customerTypes: ValueLabel[];
    priceGroups: PriceGroupOption[];
    creditTypes: string[];
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'existing' | 'new'>(existingCustomerId ? 'existing' : 'new');

    const startType = TYPE_FOR_LEAD[lead.lead_type] ?? 'retail';

    // The lead already knows who this is and where; only the permanent code is new.
    const form = useForm({
        customer_id: existingCustomerId ? String(existingCustomerId) : '',
        code: '',
        name: lead.name ?? '',
        type: startType,
        price_group_id: String(groupFor(startType, priceGroups)?.id ?? ''),
        contact_name: lead.contact_name ?? '',
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        address: lead.full_address ?? '',
        bin: '',
        tin: '',
        credit_limit: '',
        payment_terms: '',
        note: '',
    });

    const takesCredit = creditTypes.includes(form.data.type);

    /**
     * ⚠ Switching mode CLEARS the other side. A stale `customer_id` would send
     * the request down the "link to existing" branch while the user was looking
     * at a new-customer form.
     */
    const switchMode = (next: 'existing' | 'new') => {
        setMode(next);
        form.clearErrors();
        form.setData('customer_id', '');
    };

    /**
     * ⭐ D6: choosing a type moves the price group to the matching one. A
     * Distributor left on RETAIL is the easy mistake that charges the wrong
     * price; the group can still be changed afterwards. Credit fields are
     * emptied for a type that cannot carry them (D7), so nothing hidden is sent.
     */
    const chooseType = (type: string) => {
        const group = groupFor(type, priceGroups);
        form.setData((d) => ({
            ...d,
            type,
            price_group_id: group ? String(group.id) : d.price_group_id,
            ...(creditTypes.includes(type) ? {} : { credit_limit: '', payment_terms: '' }),
        }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('crm.leads.convert', lead.id), { preserveScroll: true, onSuccess: () => onDone() });
    };

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="sm:col-span-2 lg:col-span-4">
                <h2 className="font-medium">Convert {lead.name ?? `lead #${lead.id}`} to a customer</h2>
                <p className="text-muted-foreground text-sm">
                    The lead is kept, never deleted, and its history stays where it is. Nothing financial happens here - no invoice, no price, no
                    stock.
                </p>
            </div>

            <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="button" size="sm" variant={mode === 'new' ? 'default' : 'outline'} onClick={() => switchMode('new')}>
                    Create a new customer
                </Button>
                <Button type="button" size="sm" variant={mode === 'existing' ? 'default' : 'outline'} onClick={() => switchMode('existing')}>
                    They are already a customer
                </Button>
            </div>

            {mode === 'existing' ? (
                <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                    <Label htmlFor="convert_customer_id">Existing customer</Label>
                    <SearchSelect
                        id="convert_customer_id"
                        value={form.data.customer_id}
                        onChange={(e) => form.setData('customer_id', e.target.value)}
                    >
                        <option value="">Choose a customer</option>
                        {customers.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.code ? `${c.code} - ` : ''}
                                {c.name}
                                {c.phone ? ` (${c.phone})` : ''}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={form.errors.customer_id} />
                    <p className="text-muted-foreground text-xs">
                        Use this when the prospect turns out to be someone you already sell to. No second record is created.
                    </p>
                </div>
            ) : (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_code" required>
                            Customer code
                        </Label>
                        <Input id="convert_code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        <InputError message={form.errors.code} />
                        {/* ⚠ Deliberately typed, never generated. A machine-invented
                            identifier is one nobody recognises out loud. */}
                        <p className="text-muted-foreground text-xs">Permanent. Choose something people will say aloud.</p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_name" required>
                            Name
                        </Label>
                        <Input id="convert_name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_type">Type</Label>
                        <SearchSelect id="convert_type" value={form.data.type} onChange={(e) => chooseType(e.target.value)}>
                            {customerTypes
                                .filter((t) => t.value !== 'walk_in')
                                .map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                        </SearchSelect>
                        <InputError message={form.errors.type} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_price_group">Price group</Label>
                        <SearchSelect
                            id="convert_price_group"
                            value={form.data.price_group_id}
                            onChange={(e) => form.setData('price_group_id', e.target.value)}
                        >
                            {/* Null is RETAIL, the default group (D-PRC-2). */}
                            <option value="">Default (retail)</option>
                            {priceGroups.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name} ({g.code})
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={form.errors.price_group_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_contact_name">Proprietor / contact</Label>
                        <Input
                            id="convert_contact_name"
                            value={form.data.contact_name}
                            onChange={(e) => form.setData('contact_name', e.target.value)}
                        />
                        <InputError message={form.errors.contact_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_phone">Phone</Label>
                        <Input id="convert_phone" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        <InputError message={form.errors.phone} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="convert_email">Email</Label>
                        <Input id="convert_email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        <InputError message={form.errors.email} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="convert_address">Address</Label>
                        <Input id="convert_address" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        <InputError message={form.errors.address} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_bin">BIN (optional)</Label>
                        <Input id="convert_bin" value={form.data.bin} onChange={(e) => form.setData('bin', e.target.value)} />
                        <InputError message={form.errors.bin} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_tin">TIN (optional)</Label>
                        <Input id="convert_tin" value={form.data.tin} onChange={(e) => form.setData('tin', e.target.value)} />
                        <InputError message={form.errors.tin} />
                    </div>
                    {/* ⭐ D7: credit is for Retail and Corporate only. A dealer or
                        distributor pays in advance (answer 11). */}
                    {takesCredit ? (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="convert_credit_limit">Credit limit (optional)</Label>
                                <Input
                                    id="convert_credit_limit"
                                    inputMode="decimal"
                                    value={form.data.credit_limit}
                                    onChange={(e) => form.setData('credit_limit', e.target.value)}
                                />
                                <InputError message={form.errors.credit_limit} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="convert_payment_terms">Payment terms (optional)</Label>
                                <Input
                                    id="convert_payment_terms"
                                    placeholder="Weekly, settled on Thursday"
                                    value={form.data.payment_terms}
                                    onChange={(e) => form.setData('payment_terms', e.target.value)}
                                />
                                <InputError message={form.errors.payment_terms} />
                            </div>
                        </>
                    ) : (
                        <p className="text-muted-foreground text-xs sm:col-span-2">No credit: a dealer or distributor pays in advance.</p>
                    )}
                </>
            )}

            {/* The action reports "already converted", "marked lost" and
                "supplier lead" against the lead itself, not a field. */}
            <div className="sm:col-span-2 lg:col-span-4">
                <InputError message={(form.errors as Record<string, string>).lead} />
            </div>

            <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="submit" disabled={form.processing}>
                    Convert to customer
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
