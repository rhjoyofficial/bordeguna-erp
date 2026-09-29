import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Paginated } from '@/types';

type BillRow = {
    id: number;
    bill_number: string;
    supplier: string | null;
    bill_date: string | null;
    due_date: string | null;
    total: string;
    outstanding: string;
    is_reversed: boolean;
};

type OrderOption = { id: number; po_number: string; supplier: string | null };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Bills', href: '/purchasing/bills' },
];

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2 });

export default function BillsIndex({
    bills,
    filters,
    can_record,
    billable_orders,
}: {
    bills: Paginated<BillRow>;
    filters: { search: string };
    can_record: boolean;
    billable_orders: OrderOption[];
}) {
    const [search, setSearch] = useState(filters.search ?? '');

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/purchasing/bills', { search }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Supplier bills" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Supplier bills</h1>
                    </div>

                    <form onSubmit={submitSearch} className="flex gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Bill number or supplier" className="w-64" />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </form>
                </div>

                {/* ⚠ D-SB-1: a bill is always raised against one purchase order,
                    so there is no blank "new bill" button anywhere. */}
                {can_record && (
                    <div className="rounded-lg border p-4">
                        <h2 className="mb-3 text-sm font-semibold">Orders with goods still to bill</h2>
                        {billable_orders.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nothing is waiting to be billed.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {billable_orders.map((order) => (
                                    <Button key={order.id} variant="outline" size="sm" asChild>
                                        <Link href={`/purchasing/bills/new/${order.id}`}>
                                            {order.po_number}
                                            <span className="text-muted-foreground ml-2">{order.supplier}</span>
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Bill</th>
                                <th className="p-3">Supplier</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Due</th>
                                <th className="p-3 text-right">Total</th>
                                <th className="p-3 text-right">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bills.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        No supplier bills yet.
                                    </td>
                                </tr>
                            )}
                            {bills.data.map((bill) => (
                                <tr key={bill.id} className="border-t">
                                    <td className="p-3">
                                        <Link href={`/purchasing/bills/${bill.id}`} className="font-medium underline">
                                            {bill.bill_number}
                                        </Link>
                                        {bill.is_reversed && (
                                            <span className="bg-destructive/10 text-destructive ml-2 rounded px-2 py-0.5 text-xs">Reversed</span>
                                        )}
                                    </td>
                                    <td className="p-3">{bill.supplier}</td>
                                    <td className="p-3">{bill.bill_date}</td>
                                    <td className="p-3">{bill.due_date ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{money(bill.total)}</td>
                                    <td className="p-3 text-right tabular-nums">{money(bill.outstanding)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
