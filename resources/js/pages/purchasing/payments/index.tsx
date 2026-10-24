import { Chip, Pill } from '@/components/assets/assets-ui';
import { Pagination } from '@/components/data-table/pagination';
import { PendingApprovalsNotice } from '@/components/purchasing/purchase-approval-decision';
import { ListFooter, MoneyStrip, type MoneyStripData, SearchField } from '@/components/purchasing/purchases-ui';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

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
    /** B2 (R57): every account a split payment came from. */
    paid_from?: string | null;
    /** PUR-003 (14 Oct 2026): set once the payment is reversed. */
    reversed_at: string | null;
}

type Filters = { search: string; supplier_id: string; from: string; to: string; state: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
    { title: 'Payments', href: '/purchasing/payments' },
];

function shortDate(iso: string | null): string {
    if (!iso) return '-';

    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function monthRange(today: string, monthsBack: number): { from: string; to: string } {
    const day = new Date(today + 'T00:00:00');
    const first = new Date(day.getFullYear(), day.getMonth() - monthsBack, 1);
    const last = new Date(day.getFullYear(), day.getMonth() - monthsBack + 1, 0);
    const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    return { from: iso(first), to: iso(last) };
}

/**
 * Supplier payments (Purchases redesign, 15 Oct 2026): the money strip, the
 * chips (this month, last month, reversed), a compact table. "Tax rates" is
 * a tab under Suppliers now, not a button here.
 */
export default function PaymentsIndex({
    payments,
    filters: initialFilters,
    counts,
    strip,
    today,
    suppliers,
    pending_approvals = 0,
}: {
    payments: Paginated<Payment>;
    filters: Filters;
    counts: { all: number; reversed: number };
    strip: MoneyStripData;
    today: string;
    suppliers: { id: number; name: string }[];
    pending_approvals?: number;
}) {
    const { filters, setFilter, setMany, reset, hasActiveFilters } = useFilters('/purchasing/payments', initialFilters);

    // ⚠⚠ A NAMED shared prop, which is this app's convention. The first version
    // read `auth.permissions` and looked perfectly reasonable - there is no such
    // prop, so it was always undefined and the button silently never rendered.
    const { canRecordSupplierPayment } = usePage().props as unknown as { canRecordSupplierPayment?: boolean };

    const thisMonth = monthRange(today, 0);
    const lastMonth = monthRange(today, 1);
    const period =
        filters.from === thisMonth.from && filters.to === thisMonth.to
            ? 'month'
            : filters.from === lastMonth.from && filters.to === lastMonth.to
              ? 'last'
              : '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier payments" />
            <div className="flex flex-col gap-4 p-4">
                <PendingApprovalsNotice count={pending_approvals} what={pending_approvals === 1 ? 'payment is' : 'payments are'} />
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">Money that left the business for a supplier, against the bills it settled.</p>
                    {canRecordSupplierPayment && (
                        <Button size="sm" asChild>
                            <Link href="/purchasing/payments/new">Record payment</Link>
                        </Button>
                    )}
                </div>

                <MoneyStrip strip={strip} />

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div role="group" aria-label="Show payments" className="flex flex-wrap gap-1.5">
                            <Chip active={!hasActiveFilters} count={counts.all} onClick={reset}>
                                All
                            </Chip>
                            <Chip active={period === 'month' && filters.state === ''} onClick={() => setMany({ ...thisMonth, state: '' })}>
                                This month
                            </Chip>
                            <Chip active={period === 'last' && filters.state === ''} onClick={() => setMany({ ...lastMonth, state: '' })}>
                                Last month
                            </Chip>
                            <Chip
                                active={filters.state === 'reversed'}
                                count={counts.reversed}
                                onClick={() => setMany({ from: '', to: '', state: 'reversed' })}
                            >
                                Reversed
                            </Chip>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <SearchSelect
                                value={filters.supplier_id}
                                onChange={(e) => setFilter('supplier_id', e.target.value)}
                                aria-label="Supplier"
                            >
                                <option value="">All suppliers</option>
                                {suppliers.map((supplier) => (
                                    <option key={supplier.id} value={supplier.id}>
                                        {supplier.name}
                                    </option>
                                ))}
                            </SearchSelect>
                            <SearchField
                                value={filters.search}
                                onChange={(v) => setFilter('search', v)}
                                placeholder="Reference or supplier"
                                label="Search payments"
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>Paid on</th>
                                    <th>Supplier</th>
                                    <th>Paid from</th>
                                    <th>Reference</th>
                                    <th className="text-right!">Amount</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {payments.data.map((payment) => (
                                    <tr key={payment.id} className="hover:bg-muted/40">
                                        <td className="whitespace-nowrap">
                                            <Link href={`/purchasing/payments/${payment.id}`} className="font-medium hover:underline">
                                                {shortDate(payment.paid_on_date)}
                                            </Link>
                                        </td>
                                        <td>
                                            {payment.supplier ? (
                                                <Link href={`/purchasing/suppliers/${payment.supplier.id}`} className="hover:underline">
                                                    {payment.supplier.name}
                                                </Link>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td>{payment.paid_from ?? payment.money_account?.name ?? '-'}</td>
                                        <td className="text-muted-foreground">{payment.reference ?? '-'}</td>
                                        <td className="text-right font-medium tabular-nums">{payment.amount_taka}</td>
                                        <td>{payment.reversed_at ? <Pill tone="info">Reversed</Pill> : <Pill tone="ok">Posted</Pill>}</td>
                                    </tr>
                                ))}
                                {payments.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                            {hasActiveFilters ? 'No payment matches this.' : 'No supplier payments yet.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <ListFooter count={payments.data.length} word="payment">
                        A payment is corrected by reversing it with a reason, never edited. Paying more than a bill owes is refused.
                    </ListFooter>
                </section>

                <Pagination meta={payments} />
            </div>
        </AppLayout>
    );
}
