import { CustomerImportPanel } from '@/components/customer-import-panel';
import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { ReportHeading } from '@/components/report/report-shell';
import { CustomerDialog, type DialogCustomer, type DialogPriceGroup } from '@/components/sales/customer-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

// The dialog carries the fields the form edits; the list adds what it shows.
interface Customer extends DialogCustomer {
    credit_days_effective: number;
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
    priceGroups,
    mayManage,
    mayGrantCredit,
}: {
    customers: Paginated<Customer>;
    filters: { search: string; type: string; status: string };
    types: { value: string; label: string }[];
    priceGroups: DialogPriceGroup[];
    mayManage: boolean;
    mayGrantCredit: boolean;
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/sales/customers', initialFilters);

    // ⚠ Add and edit happen in a popup (Joy, 14 Oct 2026: "click add button
    // pop up will add ... same when edit"). `undefined` = closed, `null` = a new one.
    const [dialog, setDialog] = useState<Customer | null | undefined>(undefined);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="flex flex-col gap-6 p-4">
                <ReportHeading title="Customers" actions={mayManage && <Button onClick={() => setDialog(null)}>Add customer</Button>} />

                {mayManage && <CustomerImportPanel />}

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
                                        {mayManage && (
                                            <Button variant="ghost" size="sm" onClick={() => setDialog(customer)}>
                                                Edit
                                            </Button>
                                        )}
                                        {/* The walk-in row cannot be deactivated: every counter
                                            sale ever made points at it. ⚠ Behind the same
                                            permission as Edit (14 Oct 2026): the route refuses
                                            anyone else, so the button only taught a 403. */}
                                        {mayManage && customer.is_active && !customer.is_system && (
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

                {mayManage && (
                    <CustomerDialog
                        open={dialog !== undefined}
                        onOpenChange={(open) => !open && setDialog(undefined)}
                        customer={dialog ?? null}
                        types={types}
                        priceGroups={priceGroups}
                        mayGrantCredit={mayGrantCredit}
                    />
                )}
            </div>
        </AppLayout>
    );
}
