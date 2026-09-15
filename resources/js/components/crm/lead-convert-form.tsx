import { type CustomerOption, type Lead, type ValueLabel } from '@/components/crm/lead-types';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/**
 * ⚠⚠ CONVERSION IS THE FUNNEL'S ONLY EXIT. `ConvertLead` is the single writer
 * of `converted_customer_id`, so until this form existed a lead could never
 * become a customer through the UI at all.
 *
 * ⚠ Every rule - already converted, lost, duplicate code, duplicate phone,
 * system or inactive customer - lives in the action and comes back as a
 * validation error. Nothing is re-checked here, because a second set of rules
 * would be a second opinion.
 *
 * The page mounts this with a `key` per lead, so opening another lead starts a
 * fresh form rather than carrying the last one's code across.
 */
export function LeadConvertForm({
    lead,
    existingCustomerId,
    customers,
    customerTypes,
    onDone,
}: {
    lead: Lead;
    existingCustomerId: number | null;
    customers: CustomerOption[];
    customerTypes: ValueLabel[];
    onDone: () => void;
}) {
    const [mode, setMode] = useState<'existing' | 'new'>(existingCustomerId ? 'existing' : 'new');

    // The lead already knows who this is; only the permanent code is new.
    const form = useForm({
        customer_id: existingCustomerId ? String(existingCustomerId) : '',
        code: '',
        name: lead.name ?? '',
        type: '',
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        address: lead.address ?? '',
        note: '',
    });

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
                        <Label htmlFor="convert_code">Customer code</Label>
                        <Input id="convert_code" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        <InputError message={form.errors.code} />
                        {/* ⚠ Deliberately typed, never generated. A machine-invented
                            identifier is one nobody recognises out loud. */}
                        <p className="text-muted-foreground text-xs">Permanent. Choose something people will say aloud.</p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_name">Name</Label>
                        <Input id="convert_name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="convert_type">Type</Label>
                        <SearchSelect id="convert_type" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            {/* Blank keeps the model's own default rather than
                                asserting a price group nobody chose. */}
                            <option value="">Use the default</option>
                            {customerTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={form.errors.type} />
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
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="convert_address">Address</Label>
                        <Input id="convert_address" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        <InputError message={form.errors.address} />
                    </div>
                </>
            )}

            {/* The action reports "already converted" and "marked lost" against
                the lead itself, which is not a field on this form. */}
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
