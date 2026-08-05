import { FilterBar, FilterField } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Supplier {
    id: number;
    code: string | null;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    payment_terms: string | null;
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
    bin: '',
    tin: '',
    bank_name: '',
    bank_branch: '',
    bank_account_name: '',
    bank_account_number: '',
    mfs_number: '',
    notes: '',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Suppliers', href: '/purchasing/suppliers' },
];

export default function SuppliersIndex({
    suppliers,
    filters: initialFilters,
    mayManage,
}: {
    suppliers: Paginated<Supplier>;
    filters: { search: string; status: string };
    mayManage: boolean;
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/suppliers', initialFilters);
    const [editing, setEditing] = useState<Supplier | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    const startEdit = (supplier: Supplier) => {
        clearErrors();
        setEditing(supplier);
        setData({
            code: supplier.code ?? '',
            name: supplier.name,
            contact_name: supplier.contact_name ?? '',
            phone: supplier.phone ?? '',
            email: supplier.email ?? '',
            address: supplier.address ?? '',
            payment_terms: supplier.payment_terms ?? '',
            bin: supplier.bin ?? '',
            tin: supplier.tin ?? '',
            bank_name: supplier.bank_name ?? '',
            bank_branch: supplier.bank_branch ?? '',
            bank_account_name: supplier.bank_account_name ?? '',
            bank_account_number: supplier.bank_account_number ?? '',
            mfs_number: supplier.mfs_number ?? '',
            notes: supplier.notes ?? '',
        });
    };

    const cancel = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('purchasing.suppliers.update', editing.id), { onSuccess: cancel });
            return;
        }

        post(route('purchasing.suppliers.store'), { onSuccess: () => reset() });
    };

    const destroy = (supplier: Supplier) => {
        router.delete(route('purchasing.suppliers.destroy', supplier.id));
    };

    const field = (name: keyof typeof EMPTY, label: string, extra?: { type?: string; placeholder?: string }) => (
        <div className="grid gap-2">
            <Label htmlFor={name}>{label}</Label>
            <Input id={name} type={extra?.type} placeholder={extra?.placeholder} value={data[name]} onChange={(e) => setData(name, e.target.value)} />
            <InputError message={errors[name]} />
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Suppliers" />
            <div className="flex flex-col gap-6 p-4">
                {mayManage && (
                    <form onSubmit={submit} className="flex flex-col gap-4 rounded-lg border p-4">
                        <p className="text-sm font-medium">{editing ? `Editing ${editing.name}` : 'Add a supplier'}</p>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {field('code', 'Code *', { placeholder: 'e.g. ACME' })}
                            {field('name', 'Name *')}
                            {field('contact_name', 'Contact person')}
                            {field('phone', 'Phone')}
                            {field('email', 'Email', { type: 'email' })}
                            {field('address', 'Address')}
                            {field('payment_terms', 'Payment terms', { placeholder: 'e.g. Net 30' })}
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <p className="text-muted-foreground col-span-2 text-xs sm:col-span-4">Tax identity</p>
                            {field('bin', 'BIN (VAT)')}
                            {field('tin', 'TIN')}
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <p className="text-muted-foreground col-span-2 text-xs sm:col-span-4">How we pay them</p>
                            {field('bank_name', 'Bank')}
                            {field('bank_branch', 'Branch')}
                            {/* ⚠ Often NOT the trading name. Paying to the wrong one bounces a week later. */}
                            {field('bank_account_name', 'Account name')}
                            {field('bank_account_number', 'Account number')}
                            {field('mfs_number', 'bKash / Nagad number')}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <textarea
                                id="notes"
                                rows={2}
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                placeholder="Delivery days, who to ask for, packaging requirements…"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                            <InputError message={errors.notes} />
                        </div>

                        <div className="flex gap-2">
                            <Button disabled={processing}>{editing ? `Save ${editing.name}` : 'Add supplier'}</Button>
                            {editing && (
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </form>
                )}

                <FilterBar onClear={resetFilters} showClear={hasActiveFilters}>
                    <FilterField label="Search" htmlFor="filter-search" className="min-w-56 flex-1">
                        <Input
                            id="filter-search"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            placeholder="Search code, name, contact or email…"
                        />
                    </FilterField>
                    <FilterField label="Status" htmlFor="filter-status">
                        <select
                            id="filter-status"
                            className={selectClass}
                            value={filters.status}
                            onChange={(e) => setFilter('status', e.target.value)}
                        >
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </FilterField>
                </FilterBar>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-b text-left">
                            <th className="py-2">Code</th>
                            <th className="py-2">Name</th>
                            <th className="py-2">Contact</th>
                            <th className="py-2">Phone</th>
                            <th className="py-2">Email</th>
                            <th className="py-2">Terms</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {suppliers.data.map((supplier) => (
                            <tr key={supplier.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                <td className="py-2 font-medium">{supplier.code ?? '—'}</td>
                                <td className="py-2">
                                    {supplier.name}
                                    {!supplier.is_active && (
                                        <Badge variant="secondary" className="ml-2">
                                            Inactive
                                        </Badge>
                                    )}
                                </td>
                                <td className="text-muted-foreground py-2">{supplier.contact_name ?? '—'}</td>
                                <td className="text-muted-foreground py-2">{supplier.phone ?? '—'}</td>
                                <td className="text-muted-foreground py-2">{supplier.email ?? '—'}</td>
                                <td className="text-muted-foreground py-2">{supplier.payment_terms ?? '—'}</td>
                                <td className="py-2 text-right">
                                    {mayManage && (
                                        <Button variant="ghost" size="sm" onClick={() => startEdit(supplier)}>
                                            Edit
                                        </Button>
                                    )}
                                    {mayManage && supplier.is_active && (
                                        <Button variant="ghost" size="sm" onClick={() => destroy(supplier)}>
                                            Deactivate
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {suppliers.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                    {hasActiveFilters ? 'No suppliers match these filters.' : 'No suppliers yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>

                <Pagination meta={suppliers} />
            </div>
        </AppLayout>
    );
}
