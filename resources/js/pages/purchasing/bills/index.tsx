import { Chip, Pill, type Tone } from '@/components/assets/assets-ui';
import { Pagination } from '@/components/data-table/pagination';
import { PendingApprovalsNotice } from '@/components/purchasing/purchase-approval-decision';
import { ListFooter, MoneyStrip, type MoneyStripData, SearchField } from '@/components/purchasing/purchases-ui';
import { SupplierBillPicker } from '@/components/purchasing/supplier-bill-picker';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { cn, formatMoney } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

type State = 'open' | 'part_paid' | 'overdue' | 'paid' | 'reversed';

type BillRow = {
    id: number;
    bill_number: string;
    supplier: string | null;
    supplier_id: number;
    purchase_order: { id: number; po_number: string } | null;
    bill_date: string | null;
    due_date: string | null;
    /** Days until the due date; negative once it has passed; null without one. */
    due_in_days: number | null;
    total: string;
    outstanding: string;
    is_reversed: boolean;
    overdue_days: number;
    state: State;
};

type OrderOption = { id: number; po_number: string; supplier: string | null };

type Counts = { all: number; open: number; overdue: number; due_week: number; paid: number; reversed: number };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Bills & payments', href: '/purchasing/bills' },
];

const STATE: Record<State, { label: string; tone: Tone }> = {
    open: { label: 'Open', tone: 'warning' },
    part_paid: { label: 'Part paid', tone: 'warning' },
    overdue: { label: 'Overdue', tone: 'danger' },
    paid: { label: 'Paid', tone: 'ok' },
    reversed: { label: 'Reversed', tone: 'info' },
};

const CHIPS: { key: keyof Counts; label: string; tone?: Tone }[] = [
    { key: 'all', label: 'All' },
    { key: 'open', label: 'Open' },
    { key: 'overdue', label: 'Overdue', tone: 'danger' },
    { key: 'due_week', label: 'Due in 7 days', tone: 'warning' },
    { key: 'paid', label: 'Paid' },
    { key: 'reversed', label: 'Reversed' },
];

function shortDate(iso: string | null): string {
    if (!iso) return '-';

    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

/**
 * Supplier bills (Purchases redesign, 15 Oct 2026): the money strip, the
 * chips, a compact table, and a Pay button on every bill still owing that
 * opens the payment form already filled in. The tab row above is the
 * layout's; the strip's figures are the overview's (PurchasesPulse).
 */
export default function BillsIndex({
    bills,
    filters: initialFilters,
    counts,
    strip,
    can_record,
    can_pay,
    billable_orders,
    pending_approvals = 0,
    bill_suppliers = [],
}: {
    bills: Paginated<BillRow>;
    filters: { search: string; status: string };
    counts: Counts;
    strip: MoneyStripData;
    can_record: boolean;
    can_pay: boolean;
    billable_orders: OrderOption[];
    pending_approvals?: number;
    bill_suppliers?: { id: number; name: string }[];
}) {
    const { filters, setFilter } = useFilters('/purchasing/bills', initialFilters);
    const { canRecordSupplierPayment } = usePage().props as unknown as { canRecordSupplierPayment?: boolean };
    const mayPay = can_pay || Boolean(canRecordSupplierPayment);
    const active = (filters.status || 'all') as keyof Counts;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier bills" />

            <div className="flex flex-col gap-4 p-4">
                <PendingApprovalsNotice count={pending_approvals} what={pending_approvals === 1 ? 'bill is' : 'bills are'} />
                <div className="flex flex-wrap items-end justify-end gap-3">
                    <div className="flex flex-wrap gap-2">
                        {/* ⭐ Ruling P5 (19 Oct 2026): a bill for a supplier, across
                            their deliveries and orders, or for a service. */}
                        {can_record && bill_suppliers.length > 0 && <SupplierBillPicker suppliers={bill_suppliers} />}
                        {can_record && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        Record bill from order
                                        <ChevronDown className="size-3.5" aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
                                    <DropdownMenuLabel className="text-muted-foreground text-xs font-normal">
                                        {billable_orders.length === 0 ? 'Nothing is waiting to be billed' : 'Orders with goods still to bill'}
                                    </DropdownMenuLabel>
                                    {billable_orders.map((order) => (
                                        <DropdownMenuItem key={order.id} asChild>
                                            <Link href={`/purchasing/bills/new/${order.id}`}>
                                                <span className="font-medium">{order.po_number}</span>
                                                <span className="text-muted-foreground">{order.supplier}</span>
                                            </Link>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                        {mayPay && (
                            <Button size="sm" asChild>
                                <Link href="/purchasing/payments/new">Record payment</Link>
                            </Button>
                        )}
                    </div>
                </div>

                <MoneyStrip strip={strip} />

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div role="group" aria-label="Show bills" className="flex flex-wrap gap-1.5">
                            {CHIPS.map((chip) => (
                                <Chip
                                    key={chip.key}
                                    active={active === chip.key}
                                    count={counts[chip.key]}
                                    tone={counts[chip.key] > 0 ? chip.tone : undefined}
                                    onClick={() => setFilter('status', chip.key === 'all' ? '' : chip.key)}
                                >
                                    {chip.label}
                                </Chip>
                            ))}
                        </div>
                        <SearchField
                            value={filters.search}
                            onChange={(v) => setFilter('search', v)}
                            placeholder="Bill no. or supplier"
                            label="Search bills"
                        />
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-[13px] [&_tbody_td]:border-t [&_tbody_td]:px-3.5 [&_tbody_td]:py-2.5 [&_thead_th]:px-3.5 [&_thead_th]:py-2 [&_thead_th]:text-left [&_thead_th]:text-xs [&_thead_th]:font-medium [&_thead_th]:whitespace-nowrap">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th>Bill</th>
                                    <th>Supplier</th>
                                    <th>Order</th>
                                    <th>Due</th>
                                    <th className="text-right!">Total</th>
                                    <th className="text-right!">Outstanding</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {bills.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="text-muted-foreground p-6 text-center">
                                            {filters.search || filters.status ? 'No bill matches this.' : 'No supplier bills yet.'}
                                        </td>
                                    </tr>
                                )}
                                {bills.data.map((bill) => {
                                    const state = STATE[bill.state];
                                    const payable = mayPay && (bill.state === 'open' || bill.state === 'part_paid' || bill.state === 'overdue');

                                    return (
                                        <tr key={bill.id} className="hover:bg-muted/40">
                                            <td>
                                                <Link href={`/purchasing/bills/${bill.id}`} className="font-medium hover:underline">
                                                    {bill.bill_number}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">{shortDate(bill.bill_date)}</div>
                                            </td>
                                            <td>
                                                <Link href={`/purchasing/suppliers/${bill.supplier_id}`} className="hover:underline">
                                                    {bill.supplier ?? '-'}
                                                </Link>
                                            </td>
                                            <td>
                                                {bill.purchase_order ? (
                                                    <Link
                                                        href={`/purchasing/purchase-orders/${bill.purchase_order.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {bill.purchase_order.po_number}
                                                    </Link>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap">
                                                <span
                                                    className={cn(
                                                        bill.state === 'overdue' && 'text-destructive font-medium',
                                                        bill.state !== 'overdue' &&
                                                            bill.state !== 'paid' &&
                                                            bill.state !== 'reversed' &&
                                                            bill.due_in_days !== null &&
                                                            bill.due_in_days <= 7 &&
                                                            'font-medium text-amber-700 dark:text-amber-400',
                                                    )}
                                                >
                                                    {shortDate(bill.due_date)}
                                                </span>
                                            </td>
                                            <td className="text-right tabular-nums">{formatMoney(bill.total)}</td>
                                            <td className="text-right font-medium tabular-nums">{formatMoney(bill.outstanding)}</td>
                                            <td>
                                                <Pill tone={state.tone}>
                                                    {state.label}
                                                    {bill.state === 'overdue' && ` ${bill.overdue_days} d`}
                                                </Pill>
                                            </td>
                                            <td className="text-right">
                                                {payable && (
                                                    <Button variant="outline" size="sm" asChild>
                                                        <Link href={`/purchasing/payments/new?bill_id=${bill.id}`}>Pay</Link>
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <ListFooter count={bills.data.length} word="bill">
                        A bill is corrected by reversing it with a reason, never edited.
                    </ListFooter>
                </section>

                <Pagination meta={bills} />
            </div>
        </AppLayout>
    );
}
