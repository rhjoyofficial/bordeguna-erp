import { type Lead, type SupplierOption } from '@/components/crm/lead-types';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

/** What the supplier profile said, as a starting note for the supplier record. */
const profileNote = (lead: Lead) =>
    [
        lead.profile?.supplier_type,
        lead.profile?.supply_categories ? `Supplies ${lead.profile.supply_categories}` : null,
        lead.profile?.origin_district ? `Goods from ${lead.profile.origin_district}` : null,
    ]
        .filter(Boolean)
        .join('. ');

/**
 * ⭐ Plan D12 (CRM slice 2e): a supplier lead becomes a supplier, or is linked
 * to one we already buy from. Answer 13: only a code and a name are required,
 * because suppliers are often informal.
 *
 * ⚠ Every rule - supplier leads only, converted once, not while lost, duplicate
 * code, inactive supplier - lives in ConvertLeadToSupplier. No bank details
 * here: those are Purchasing's, on the supplier screen.
 */
export function LeadConvertSupplierForm({ lead, suppliers, onDone }: { lead: Lead; suppliers: SupplierOption[]; onDone: () => void }) {
    const [mode, setMode] = useState<'existing' | 'new'>('new');

    const form = useForm({
        supplier_id: '',
        code: '',
        name: lead.name ?? '',
        contact_name: lead.contact_name ?? '',
        phone: lead.phone ?? '',
        email: lead.email ?? '',
        address: lead.full_address ?? '',
        payment_terms: '',
        bin: '',
        tin: '',
        notes: profileNote(lead),
        trade_licence: [] as File[],
        nid: [] as File[],
    });

    const hasFiles = form.data.trade_licence.length > 0 || form.data.nid.length > 0;
    form.transform(({ trade_licence, nid, ...rest }) => (hasFiles ? { ...rest, documents: { trade_licence, nid } } : rest));

    const switchMode = (next: 'existing' | 'new') => {
        setMode(next);
        form.clearErrors();
        form.setData('supplier_id', '');
    };

    const errors = form.errors as Record<string, string | undefined>;
    const documentError = (kind: string) => Object.entries(errors).find(([key]) => key.startsWith(`documents.${kind}`))?.[1];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('crm.leads.convert', lead.id), { preserveScroll: true, forceFormData: hasFiles, onSuccess: () => onDone() });
    };

    const field = (key: 'code' | 'name' | 'contact_name' | 'phone' | 'email' | 'payment_terms' | 'bin' | 'tin', label: string, required = false) => (
        <div className="grid gap-2">
            <Label htmlFor={`supplier_${key}`} required={required}>
                {label}
            </Label>
            <Input id={`supplier_${key}`} aria-required={required} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div className="sm:col-span-2 lg:col-span-4">
                <h2 className="font-medium">Convert {lead.name ?? `lead #${lead.id}`} to a supplier</h2>
            </div>

            <div className="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="button" size="sm" variant={mode === 'new' ? 'default' : 'outline'} onClick={() => switchMode('new')}>
                    Create a new supplier
                </Button>
                <Button type="button" size="sm" variant={mode === 'existing' ? 'default' : 'outline'} onClick={() => switchMode('existing')}>
                    We already buy from them
                </Button>
            </div>

            {mode === 'existing' ? (
                <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                    <Label htmlFor="convert_supplier_id" required>
                        Existing supplier
                    </Label>
                    <SearchSelect
                        id="convert_supplier_id"
                        value={form.data.supplier_id}
                        onChange={(e) => form.setData('supplier_id', e.target.value)}
                    >
                        <option value="">Choose a supplier</option>
                        {suppliers.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.code ? `${s.code} - ` : ''}
                                {s.name}
                                {s.phone ? ` (${s.phone})` : ''}
                            </option>
                        ))}
                    </SearchSelect>
                    <InputError message={errors.supplier_id} />
                </div>
            ) : (
                <>
                    {field('code', 'Supplier code', true)}
                    {field('name', 'Name', true)}
                    {field('contact_name', 'Proprietor / contact')}
                    {field('phone', 'Phone')}
                    {field('email', 'Email')}
                    {field('payment_terms', 'Payment terms (optional)')}
                    {field('bin', 'BIN (optional)')}
                    {field('tin', 'TIN (optional)')}
                    <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="supplier_address">Address</Label>
                        <Input id="supplier_address" value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                        <InputError message={errors.address} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                        <Label htmlFor="supplier_notes">Notes</Label>
                        <Input id="supplier_notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        <InputError message={errors.notes} />
                    </div>
                </>
            )}

            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="supplier_trade_licence">Trade licence scan (optional)</Label>
                <input
                    id="supplier_trade_licence"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    multiple
                    className="w-full min-w-0 text-sm"
                    onChange={(e) => form.setData('trade_licence', Array.from(e.target.files ?? []))}
                />
                <InputError message={documentError('trade_licence')} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="supplier_nid">NID scan (optional)</Label>
                <input
                    id="supplier_nid"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    multiple
                    className="w-full min-w-0 text-sm"
                    onChange={(e) => form.setData('nid', Array.from(e.target.files ?? []))}
                />
                <InputError message={documentError('nid')} />
            </div>

            <div className="sm:col-span-2 lg:col-span-4">
                <InputError message={errors.lead} />
            </div>

            <div className="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                <Button type="submit" disabled={form.processing}>
                    Convert to supplier
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
