import { RefundForm, RefundsTable, type RefundRow } from '@/components/purchasing/supplier-advance-refunds';
import { AppliedAdvancesTable, ApplyAdvancesForm, type AppliedAdvance, type AvailableAdvance } from '@/components/purchasing/supplier-advances';
import { SupplierCreditRefunds, type CreditRefundRow } from '@/components/purchasing/supplier-credit-refunds';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { showDate } from '@/lib/dates';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

interface OpenBill {
    id: number;
    bill_number: string;
    bill_date: string | null;
    purchase_order_id: number | null;
    outstanding: string;
    proposal: { key: string; amount: string }[];
}

/**
 * ⭐ A supplier's advances in one place (ruling P2, 18 Oct 2026): what the
 * supplier holds of ours, the bills it could settle (each with the default
 * proposal), what has been applied or refunded, and the refund form.
 *
 * ⚠ "On account (vouchers)" is the 1160 balance Payment vouchers left with no
 * advance document behind it; it is applied and refunded exactly like one.
 */
export default function SupplierAdvances({
    supplier,
    available,
    on_account,
    bills,
    applications,
    refunds,
    moneyAccounts,
    can_record,
    today,
    credit,
}: {
    supplier: { id: number; name: string };
    available: AvailableAdvance[];
    on_account: { balance: string; lots: { date: string; reference: string; open: string }[] };
    bills: OpenBill[];
    applications: AppliedAdvance[];
    refunds: RefundRow[];
    moneyAccounts: { id: number; name: string; balance?: string | null }[];
    can_record: boolean;
    today: string;
    /** ⭐ Ruling P5: what they owe us back on Accounts Payable, and its refunds. */
    credit?: { balance: string; refunds: CreditRefundRow[] };
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Purchases', href: '/purchasing/overview' },
        { title: 'Suppliers', href: '/purchasing/suppliers' },
        { title: supplier.name, href: `/purchasing/suppliers/${supplier.id}` },
        { title: 'Advances', href: `/purchasing/suppliers/${supplier.id}/advances` },
    ];
    const [billId, setBillId] = useState(bills[0] ? String(bills[0].id) : '');
    const bill = bills.find((row) => String(row.id) === billId);
    const totalOpen = available.reduce((sum, row) => sum + Number(row.open), 0);

    // The bill's proposal decides the order: its purchase order's advances first.
    const ordered = bill
        ? [...available].sort((a, b) => {
              const rank = (row: AvailableAdvance) => (bill.purchase_order_id !== null && row.purchase_order_id === bill.purchase_order_id ? 0 : 1);
              return rank(a) - rank(b) || a.date.localeCompare(b.date);
          })
        : available;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Advances: ${supplier.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Advances to {supplier.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {formatMoney(totalOpen)} of ours is with this supplier, to be used against bills or refunded. It is not part of what we
                            owe them.
                        </p>
                    </div>
                    {can_record && (
                        <Button asChild>
                            <Link href={`/purchasing/advances/new?supplier_id=${supplier.id}`}>Pay an advance</Link>
                        </Button>
                    )}
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Open advances</h2>
                    {available.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing open.</p>
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Advance</th>
                                        <th className="p-3">Paid on</th>
                                        <th className="p-3">Purchase order</th>
                                        <th className="p-3 text-right">Open</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {available.map((row) => (
                                        <tr key={row.key} className="border-t">
                                            <td className="p-3 font-medium">
                                                {row.id ? (
                                                    <Link href={`/purchasing/advances/${row.id}`} className="hover:underline">
                                                        {row.number}
                                                    </Link>
                                                ) : (
                                                    'On account (vouchers)'
                                                )}
                                            </td>
                                            <td className="p-3">{showDate(row.date)}</td>
                                            <td className="p-3">{row.po_number ?? '-'}</td>
                                            <td className="p-3 text-right tabular-nums">{formatMoney(row.open)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {on_account.lots.length > 0 && (
                        <p className="text-muted-foreground text-xs">
                            On account comes from:{' '}
                            {on_account.lots.map((lot) => `${lot.reference} (${showDate(lot.date)}, ${formatMoney(lot.open)})`).join(', ')}.
                        </p>
                    )}
                </section>

                {can_record && available.length > 0 && (
                    <section className="flex flex-col gap-3">
                        <h2 className="text-muted-foreground text-sm font-medium">Use against a bill</h2>
                        {bills.length === 0 ? (
                            <p className="text-muted-foreground text-sm">This supplier has no open bill.</p>
                        ) : (
                            <>
                                <div className="grid max-w-md gap-2">
                                    <Label htmlFor="bill_id">Bill</Label>
                                    <SearchSelect fit id="bill_id" value={billId} onChange={(e) => setBillId(e.target.value)}>
                                        {bills.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.bill_number} ({showDate(row.bill_date)}, owes {formatMoney(row.outstanding)})
                                            </option>
                                        ))}
                                    </SearchSelect>
                                </div>
                                {bill && (
                                    <ApplyAdvancesForm
                                        key={bill.id}
                                        billId={bill.id}
                                        billNumber={bill.bill_number}
                                        billDate={bill.bill_date}
                                        outstanding={bill.outstanding}
                                        available={ordered}
                                        proposal={bill.proposal}
                                        today={today}
                                        purchaseOrderId={bill.purchase_order_id}
                                    />
                                )}
                            </>
                        )}
                    </section>
                )}

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Applied to bills</h2>
                    <AppliedAdvancesTable applications={applications.map((a) => ({ ...a, can_reverse: can_record }))} showBill />
                </section>

                <section className="flex flex-col gap-2">
                    <h2 className="text-muted-foreground text-sm font-medium">Refunds</h2>
                    <RefundsTable refunds={refunds} canReverse={can_record} />
                    {can_record && available.length > 0 && (
                        <RefundForm
                            supplierId={supplier.id}
                            options={available.map((row) => ({ key: row.key, label: row.id ? row.number : 'On account (vouchers)', open: row.open }))}
                            moneyAccounts={moneyAccounts}
                            today={today}
                        />
                    )}
                </section>

                {credit && (
                    <SupplierCreditRefunds
                        supplier={supplier}
                        balance={credit.balance}
                        refunds={credit.refunds}
                        moneyAccounts={moneyAccounts}
                        canRecord={can_record}
                        today={today}
                    />
                )}
            </div>
        </AppLayout>
    );
}
