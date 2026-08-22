import { FilterBar } from '@/components/data-table/filter-bar';
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

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Customer {
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
    credit_limit: string | null;
    price_group_id: number | null;
    is_system: boolean;
    is_active: boolean;
}

interface PriceGroup {
    id: number;
    code: string;
    name: string;
}

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
    credit_limit: '',
    price_group_id: '',
    note: '',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Customers', href: '/sales/customers' },
];

export default function CustomersIndex({
    customers,
    filters: initialFilters,
    types,
    priceGroups,
}: {
    customers: Paginated<Customer>;
    filters: { search: string; type: string; status: string };
    types: { value: string; label: string }[];
    priceGroups: PriceGroup[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/customers', initialFilters);

    const [editing, setEditing] = useState<Customer | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        // ⚠ `update()` and its PUT route existed from the first slice with
        // NOTHING calling them - the same gap suppliers and products both had.
        // A customer entered with a typo could not be corrected.
        if (editing) {
            put(route('sales.customers.update', editing.id), { onSuccess: () => cancel() });

            return;
        }

        post(route('sales.customers.store'), { onSuccess: () => reset() });
    };

    const edit = (customer: Customer) => {
        setEditing(customer);
        clearErrors();
        setData({
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
            credit_limit: customer.credit_limit ?? '',
            price_group_id: customer.price_group_id !== null ? String(customer.price_group_id) : '',
            note: customer.note ?? '',
        });
    };

    const cancel = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="flex flex-col gap-6 p-4">
                <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code</Label>
                        <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="DIST-001" />
                        <InputError message={errors.code} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="type">Type</Label>
                        <select id="type" className={selectClass} value={data.type} onChange={(e) => setData('type', e.target.value)}>
                            {types.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.type} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="phone">Phone</Label>
                        <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="payment_terms">Payment terms</Label>
                        <Input
                            id="payment_terms"
                            value={data.payment_terms}
                            onChange={(e) => setData('payment_terms', e.target.value)}
                            placeholder="Net 30"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="credit_limit">Credit limit</Label>
                        <Input id="credit_limit" value={data.credit_limit} onChange={(e) => setData('credit_limit', e.target.value)} />
                        <InputError message={errors.credit_limit} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="price_group_id">Price group</Label>
                        {/* ⚠ A select over real rows, never typed - free text here
                            once meant `Distributor` vs `distributor` silently
                            charged a distributor the retail price. Blank means
                            RETAIL, the default group. */}
                        <select
                            id="price_group_id"
                            className={selectClass}
                            value={data.price_group_id}
                            onChange={(e) => setData('price_group_id', e.target.value)}
                        >
                            <option value="">Default (RETAIL)</option>
                            {priceGroups.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.code}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.price_group_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="contact_name">Contact person</Label>
                        <Input id="contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                        <InputError message={errors.contact_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        <InputError message={errors.email} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="bin">BIN (optional)</Label>
                        <Input id="bin" value={data.bin} onChange={(e) => setData('bin', e.target.value)} />
                        <InputError message={errors.bin} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="tin">TIN (optional)</Label>
                        <Input id="tin" value={data.tin} onChange={(e) => setData('tin', e.target.value)} />
                        <InputError message={errors.tin} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="address">Address</Label>
                        <textarea
                            id="address"
                            className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        <InputError message={errors.address} />
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="note">Note</Label>
                        <textarea
                            id="note"
                            className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                        <InputError message={errors.note} />
                    </div>
                    <div className="flex items-end gap-2 md:col-span-4">
                        <Button type="submit" disabled={processing}>
                            {editing ? 'Save changes' : 'Add customer'}
                        </Button>
                        {editing && (
                            <>
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                                <span className="self-center text-sm text-muted-foreground">
                                    Editing {editing.name}
                                </span>
                            </>
                        )}
                    </div>
                </form>

                <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                    <Input
                        placeholder="Search name, code or phone"
                        value={filters.search}
                        onChange={(e) => setFilter('search', e.target.value)}
                        className="max-w-xs"
                    />
                    <select className={selectClass} value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                        <option value="">All types</option>
                        {types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </select>
                    <select className={selectClass} value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Customer</th>
                                <th className="p-3">Type</th>
                                <th className="p-3">Phone</th>
                                <th className="p-3">Terms</th>
                                <th className="p-3 text-right">Credit limit</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {customers.data.map((customer) => (
                                <tr key={customer.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">{customer.name}</div>
                                        {customer.code && <div className="text-xs text-muted-foreground">{customer.code}</div>}
                                    </td>
                                    <td className="p-3">
                                        <Badge variant="secondary">{customer.type}</Badge>
                                        {!customer.is_active && (
                                            <Badge variant="outline" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3">{customer.phone ?? '-'}</td>
                                    <td className="p-3">{customer.payment_terms ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{customer.credit_limit ?? '-'}</td>
                                    <td className="p-3 text-right">
                                        {/* ⚠ The walk-in row IS editable - a business may want to
                                            call it "Counter Sale" - but the controller refuses any
                                            change to its code or type, because Customer::walkIn()
                                            resolves the counter customer by code. */}
                                        <Button variant="ghost" size="sm" onClick={() => edit(customer)}>
                                            Edit
                                        </Button>
                                        {/* The walk-in row cannot be deactivated: every counter
                                            sale ever made points at it. */}
                                        {customer.is_active && !customer.is_system && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => router.delete(route('sales.customers.destroy', customer.id))}
                                            >
                                                Deactivate
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={customers} />
            </div>
        </AppLayout>
    );
}
