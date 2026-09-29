import { CustomerImportPanel } from '@/components/customer-import-panel';
import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Field, FormGroup, FormRow } from '@/components/form/field';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

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
    credit_days: number | null;
    credit_days_effective: number;
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Sales', href: '/sales/invoices' },
    { title: 'Customers', href: '/sales/customers' },
];

export default function CustomersIndex({
    customers,
    filters: initialFilters,
    types,
    priceGroups,
    mayManage,
    mayGrantCredit,
}: {
    customers: Paginated<Customer>;
    filters: { search: string; type: string; status: string };
    types: { value: string; label: string }[];
    priceGroups: PriceGroup[];
    mayManage: boolean;
    mayGrantCredit: boolean;
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
            credit_days: customer.credit_days === null ? '' : String(customer.credit_days),
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
                <ReportHeading title="Customers" />

                {mayManage && <CustomerImportPanel />}

                {/* ⚠ Thirteen fields in one equal four-column grid gave a code,
                    a phone and an address the same width (30 Aug 2026 audit).
                    Three rows now, each field as wide as what it holds. */}
                <form onSubmit={submit} className="flex flex-col gap-5 rounded-lg border p-4">
                    <FormGroup title="Identity">
                        <Field label="Name" htmlFor="name" size="grow" error={errors.name} required>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        </Field>
                        <Field label="Code" htmlFor="code" size="sm" error={errors.code}>
                            <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="DIST-001" />
                        </Field>
                        <Field label="Type" htmlFor="type" error={errors.type} required>
                            <SearchSelect id="type" value={data.type} onChange={(e) => setData('type', e.target.value)}>
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
                        <Field label="Price group" htmlFor="price_group_id" error={errors.price_group_id}>
                            <SearchSelect id="price_group_id" value={data.price_group_id} onChange={(e) => setData('price_group_id', e.target.value)}>
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
                        <Field label="Credit days" htmlFor="credit_days" size="sm" error={errors.credit_days}>
                            <Input
                                id="credit_days"
                                inputMode="numeric"
                                value={data.credit_days}
                                disabled={!mayGrantCredit}
                                title={mayGrantCredit ? undefined : 'Credit terms change through a Credit terms request.'}
                                onChange={(e) => setData('credit_days', e.target.value)}
                                placeholder={data.type === 'walk_in' ? '0' : '10'}
                            />
                        </Field>
                        <Field label="Terms note" htmlFor="payment_terms" size="sm">
                            <Input
                                id="payment_terms"
                                value={data.payment_terms}
                                onChange={(e) => setData('payment_terms', e.target.value)}
                                placeholder="e.g. cheque on delivery"
                            />
                        </Field>
                        <Field label="Credit limit" htmlFor="credit_limit" size="sm" error={errors.credit_limit}>
                            <Input
                                id="credit_limit"
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
                        <Field label="Contact person" htmlFor="contact_name" size="md" error={errors.contact_name}>
                            <Input id="contact_name" value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} />
                        </Field>
                        <Field label="Phone" htmlFor="phone" size="md">
                            <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                        </Field>
                        <Field label="Email" htmlFor="email" size="lg" error={errors.email}>
                            <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        </Field>
                        <Field label="BIN (optional)" htmlFor="bin" size="sm" error={errors.bin}>
                            <Input id="bin" value={data.bin} onChange={(e) => setData('bin', e.target.value)} />
                        </Field>
                        <Field label="TIN (optional)" htmlFor="tin" size="sm" error={errors.tin}>
                            <Input id="tin" value={data.tin} onChange={(e) => setData('tin', e.target.value)} />
                        </Field>
                    </FormGroup>

                    <FormRow>
                        <Field label="Address" htmlFor="address" size="grow" error={errors.address}>
                            <textarea
                                id="address"
                                className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                            />
                        </Field>
                        <Field label="Note" htmlFor="note" size="grow" error={errors.note}>
                            <textarea
                                id="note"
                                className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                                value={data.note}
                                onChange={(e) => setData('note', e.target.value)}
                            />
                        </Field>
                    </FormRow>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={processing}>
                            {editing ? 'Save changes' : 'Add customer'}
                        </Button>
                        {editing && (
                            <>
                                <Button type="button" variant="ghost" onClick={cancel}>
                                    Cancel
                                </Button>
                                <span className="text-muted-foreground text-sm">Editing {editing.name}</span>
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
                    <SearchSelect value={filters.type} onChange={(e) => setFilter('type', e.target.value)}>
                        <option value="">All types</option>
                        {types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </SearchSelect>
                    <SearchSelect value={filters.status} onChange={(e) => setFilter('status', e.target.value)}>
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </SearchSelect>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Customer</th>
                                <th className="p-3">Type</th>
                                <th className="p-3">Phone</th>
                                <th className="p-3">Credit days</th>
                                <th className="p-3 text-right">Credit limit</th>
                                <th className="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {customers.data.map((customer) => (
                                <tr key={customer.id} className="border-t">
                                    <td className="p-3">
                                        {/* C-360: the name opens the customer's own page -
                                            balance, open invoices, history in one place. */}
                                        <Link href={`/sales/customers/${customer.id}`} className="font-medium underline-offset-4 hover:underline">
                                            {customer.name}
                                        </Link>
                                        <div className="text-muted-foreground text-xs">
                                            {customer.code && <span>{customer.code} · </span>}
                                            {/* ⚠⚠ A converted lead's history had NO door on this
                                                side at all: every call, note and product interest
                                                recorded before they became a customer was
                                                unreachable the moment they did. The timeline door
                                                stays. */}
                                            <Link href={`/crm/customer/${customer.id}/timeline`} className="underline-offset-4 hover:underline">
                                                CRM timeline
                                            </Link>
                                        </div>
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
                                    <td className="p-3">
                                        {customer.credit_days_effective} days{customer.credit_days === null ? ' (default)' : ''}
                                    </td>
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
