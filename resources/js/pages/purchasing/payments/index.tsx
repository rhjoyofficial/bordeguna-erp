import { FilterBar } from '@/components/data-table/filter-bar';
import { Pagination } from '@/components/data-table/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface Payment {
    id: number;
    /** ⚠ A plain Y-m-d from the server. The raw `paid_on` cast serialises in UTC
     *  and would render a Dhaka payment one day early. */
    paid_on_date: string | null;
    /** Formatted taka, not the raw decimal(15,4). */
    amount_taka: string;
    reference: string | null;
    supplier: { id: number; name: string } | null;
    money_account: { id: number; name: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Payments', href: '/purchasing/payments' },
];

export default function PaymentsIndex({
    payments,
    filters: initialFilters,
    suppliers,
}: {
    payments: Paginated<Payment>;
    filters: { search: string; supplier_id: string };
    suppliers: { id: number; name: string }[];
}) {
    const { filters, setFilter, reset: resetFilters, hasActiveFilters } = useFilters('/purchasing/payments', initialFilters);

    // ⚠⚠ A NAMED shared prop, which is this app's convention. The first version
    // read `auth.permissions` and looked perfectly reasonable - there is no such
    // prop, so it was always undefined and the button silently never rendered.
    // Every HTTP test still passed, because none of them asserted on the button.
    // Found by opening the page in a browser.
    const { canRecordSupplierPayment } = usePage().props as unknown as { canRecordSupplierPayment?: boolean };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier payments" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <FilterBar showClear={hasActiveFilters} onClear={resetFilters}>
                        <Input
                            placeholder="Search reference or supplier"
                            value={filters.search}
                            onChange={(e) => setFilter('search', e.target.value)}
                            className="max-w-xs"
                        />
                        <select
                            className={selectClass}
                            value={filters.supplier_id}
                            onChange={(e) => setFilter('supplier_id', e.target.value)}
                        >
                            <option value="">All suppliers</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name}
                                </option>
                            ))}
                        </select>
                    </FilterBar>

                    {canRecordSupplierPayment && (
                        <Button asChild>
                            <Link href="/purchasing/payments/new">Pay a supplier</Link>
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Date</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Paid from</th>
                                <th className="p-3">Reference</th>
                                <th className="p-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.data.map((payment) => (
                                <tr key={payment.id} className="border-t">
                                    <td className="p-3">
                                        <Link href={`/purchasing/payments/${payment.id}`} className="font-medium hover:underline">
                                            {payment.paid_on_date ?? '-'}
                                        </Link>
                                    </td>
                                    <td className="p-3">{payment.supplier?.name ?? '-'}</td>
                                    <td className="p-3">{payment.money_account?.name ?? '-'}</td>
                                    <td className="p-3">{payment.reference ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{payment.amount_taka}</td>
                                </tr>
                            ))}
                            {payments.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        No supplier payments yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={payments} />
            </div>
        </AppLayout>
    );
}
