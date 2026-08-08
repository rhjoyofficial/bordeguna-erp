import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const selectClass = 'h-10 w-full rounded-md border border-input bg-background px-3 text-sm';

interface SupplierRow {
    id: number;
    name: string;
    outstanding: string;
}

interface BillRow {
    id: number;
    bill_number: string;
    bill_date: string | null;
    due_date: string | null;
    total: string;
    outstanding: string;
}

interface MoneyAccountRow {
    id: number;
    name: string;
    type: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Payments', href: '/purchasing/payments' },
    { title: 'New', href: '/purchasing/payments/new' },
];

/** Money in this form is paisa. Two decimal places, added as integers. */
const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);
const toTaka = (paisa: number): string => (paisa / 100).toFixed(2);

export default function PaymentCreate({
    suppliers,
    selectedSupplierId,
    bills,
    moneyAccounts,
    today,
}: {
    suppliers: SupplierRow[];
    selectedSupplierId: number | null;
    bills: BillRow[];
    moneyAccounts: MoneyAccountRow[];
    today: string;
}) {
    const { data, setData } = useForm({
        supplier_id: selectedSupplierId ?? '',
        money_account_id: '',
        paid_on: today,
        reference: '',
        note: '',
        // bill id -> amount typed by the clerk
        amounts: {} as Record<number, string>,
    });

    const [processing, setProcessing] = useState(false);

    // ⚠ Server errors come from the SHARED page props, not from useForm. The
    // submission is a router.post of a reshaped payload - allocations are built
    // at submit time from `amounts` - so useForm never sees the keys the server
    // validates, and `allocations.0.amount` could not be typed against it.
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    // Choosing a supplier reloads the page so the server decides which bills
    // are open. Working that out in the browser would be a second copy of the
    // rule, and the outstanding figure is derived from allocations server-side.
    const chooseSupplier = (id: string) => {
        setData('supplier_id', id);
        router.get('/purchasing/payments/new', id ? { supplier_id: id } : {}, {
            preserveState: true,
            replace: true,
        });
    };

    const total = useMemo(
        () => bills.reduce((sum, bill) => sum + toPaisa(data.amounts[bill.id] ?? '0'), 0),
        [bills, data.amounts],
    );

    // ⚠ The server refuses overpayment and is the authority. This only stops the
    // clerk submitting something it already knows will be rejected.
    const overpaid = useMemo(
        () => bills.filter((bill) => toPaisa(data.amounts[bill.id] ?? '0') > toPaisa(bill.outstanding)),
        [bills, data.amounts],
    );

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const allocations = bills
            .map((bill) => ({ supplier_bill_id: bill.id, amount: data.amounts[bill.id] ?? '0' }))
            .filter((row) => toPaisa(row.amount) > 0);

        router.post(
            '/purchasing/payments',
            {
                supplier_id: data.supplier_id,
                money_account_id: data.money_account_id,
                paid_on: data.paid_on,
                reference: data.reference,
                note: data.note,
                allocations,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const payAllOf = (bill: BillRow) => setData('amounts', { ...data.amounts, [bill.id]: bill.outstanding });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Pay a supplier" />
            <form onSubmit={submit} className="flex flex-col gap-6 p-4">
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id">Supplier</Label>
                        <select
                            id="supplier_id"
                            className={selectClass}
                            value={String(data.supplier_id)}
                            onChange={(e) => chooseSupplier(e.target.value)}
                        >
                            <option value="">Select a supplier</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name} — {supplier.outstanding} owed
                                </option>
                            ))}
                        </select>
                        {suppliers.length === 0 && (
                            <p className="text-sm text-muted-foreground">No supplier is owed anything right now.</p>
                        )}
                        {errors.supplier_id && <p className="text-sm text-destructive">{errors.supplier_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id">Paid from</Label>
                        <select
                            id="money_account_id"
                            className={selectClass}
                            value={String(data.money_account_id)}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">Select an account</option>
                            {moneyAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </select>
                        {errors.money_account_id && <p className="text-sm text-destructive">{errors.money_account_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="paid_on">Payment date</Label>
                        <Input
                            id="paid_on"
                            type="date"
                            value={data.paid_on}
                            onChange={(e) => setData('paid_on', e.target.value)}
                        />
                        {errors.paid_on && <p className="text-sm text-destructive">{errors.paid_on}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={data.reference}
                            placeholder="Cheque number, bKash trx id, slip number"
                            onChange={(e) => setData('reference', e.target.value)}
                        />
                        {errors.reference && <p className="text-sm text-destructive">{errors.reference}</p>}
                    </div>
                </div>

                {data.supplier_id !== '' && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Bill</th>
                                    <th className="p-3">Date</th>
                                    <th className="p-3">Due</th>
                                    <th className="p-3 text-right">Total</th>
                                    <th className="p-3 text-right">Outstanding</th>
                                    <th className="p-3 text-right">Paying now</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bills.map((bill, index) => {
                                    const typed = toPaisa(data.amounts[bill.id] ?? '0');
                                    const isOver = typed > toPaisa(bill.outstanding);

                                    return (
                                        <tr key={bill.id} className="border-t">
                                            <td className="p-3 font-medium">{bill.bill_number}</td>
                                            <td className="p-3">{bill.bill_date ?? '-'}</td>
                                            <td className="p-3">{bill.due_date ?? '-'}</td>
                                            <td className="p-3 text-right tabular-nums">{bill.total}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                <button
                                                    type="button"
                                                    className="tabular-nums hover:underline"
                                                    onClick={() => payAllOf(bill)}
                                                    title="Pay all of this bill"
                                                >
                                                    {bill.outstanding}
                                                </button>
                                            </td>
                                            <td className="p-3 text-right">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    className={`ml-auto max-w-32 text-right tabular-nums ${isOver ? 'border-destructive' : ''}`}
                                                    value={data.amounts[bill.id] ?? ''}
                                                    onChange={(e) =>
                                                        setData('amounts', { ...data.amounts, [bill.id]: e.target.value })
                                                    }
                                                />
                                                {isOver && (
                                                    <p className="mt-1 text-xs text-destructive">
                                                        Only {bill.outstanding} is outstanding.
                                                    </p>
                                                )}
                                                {errors[`allocations.${index}.amount`] && (
                                                    <p className="mt-1 text-xs text-destructive">
                                                        {errors[`allocations.${index}.amount`]}
                                                    </p>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {bills.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                            This supplier has no open bills.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {bills.length > 0 && (
                                <tfoot>
                                    <tr className="border-t bg-muted/30 font-medium">
                                        <td className="p-3" colSpan={5}>
                                            Total payment
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{toTaka(total)}</td>
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                )}

                {errors.allocations && <p className="text-sm text-destructive">{errors.allocations}</p>}

                <div className="grid gap-2">
                    <Label htmlFor="note">Note</Label>
                    <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing || total <= 0 || overpaid.length > 0}>
                        Record payment of {toTaka(total)}
                    </Button>
                    {overpaid.length > 0 && (
                        <span className="text-sm text-destructive">
                            One or more lines pay more than is outstanding.
                        </span>
                    )}
                </div>
            </form>
        </AppLayout>
    );
}
