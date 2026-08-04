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
import { FormEventHandler } from 'react';

const selectClass = 'h-10 rounded-md border border-input bg-background px-3 text-sm';

interface Customer {
    id: number;
    code: string | null;
    name: string;
    type: string;
    phone: string | null;
    payment_terms: string | null;
    credit_limit: string | null;
    price_group: string | null;
    is_system: boolean;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Customers', href: '/sales/customers' },
];

export default function CustomersIndex({
    customers,
    filters: initialFilters,
    types,
}: {
    customers: Paginated<Customer>;
    filters: { search: string; type: string; status: string };
    types: { value: string; label: string }[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/customers', initialFilters);

    const { data, setData, post, errors, processing, reset } = useForm({
        name: '',
        code: '',
        type: 'retail',
        contact_name: '',
        phone: '',
        email: '',
        address: '',
        payment_terms: '',
        credit_limit: '',
        price_group: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('sales.customers.store'), { onSuccess: () => reset() });
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
                        <Label htmlFor="price_group">Price group</Label>
                        <Input
                            id="price_group"
                            value={data.price_group}
                            onChange={(e) => setData('price_group', e.target.value)}
                            placeholder="dealer"
                        />
                    </div>
                    <div className="flex items-end">
                        <Button type="submit" disabled={processing}>
                            Add customer
                        </Button>
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
