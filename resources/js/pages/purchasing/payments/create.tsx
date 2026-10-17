import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
    /** F20: what is in it now, from the ledger. */
    balance?: string | null;
}

interface WithholdingRateRow {
    id: number;
    kind: 'tds' | 'vds';
    label: string;
    rate: number;
    supplier_type: string | null;
    from: string | null;
    to: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchases', href: '/purchasing/overview' },
    { title: 'Payments', href: '/purchasing/payments' },
    { title: 'New', href: '/purchasing/payments/new' },
];

/** Money in this form is paisa. Two decimal places, added as integers. */
const toPaisa = (value: string): number => Math.round((Number(value) || 0) * 100);

export default function PaymentCreate({
    suppliers,
    selectedSupplierId,
    bills,
    moneyAccounts,
    today,
    prefill,
    withholdingRates = [],
    supplierType = null,
}: {
    suppliers: SupplierRow[];
    selectedSupplierId: number | null;
    bills: BillRow[];
    moneyAccounts: MoneyAccountRow[];
    today: string;
    // Set when the form was opened from a bill's "Pay this bill" button.
    prefill?: { bill_id: number; amount: string } | null;
    withholdingRates?: WithholdingRateRow[];
    supplierType?: string | null;
}) {
    const { data, setData } = useForm({
        supplier_id: selectedSupplierId ?? '',
        money_account_id: '',
        paid_on: today,
        reference: '',
        note: '',
        tds_rate_id: '',
        vds_rate_id: '',
        // bill id -> amount typed by the clerk. ⚠ A prefill is only a typed
        // number: it can be changed or cleared, and the server re-checks it
        // against what is outstanding exactly as if the clerk had typed it.
        amounts: (prefill ? { [prefill.bill_id]: prefill.amount } : {}) as Record<number, string>,
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

    const total = useMemo(() => bills.reduce((sum, bill) => sum + toPaisa(data.amounts[bill.id] ?? '0'), 0), [bills, data.amounts]);

    // Finance decision C4: only the rates in force on the payment date that fit
    // this supplier's type. The server re-checks and computes the tax itself.
    const ratesFor = (kind: 'tds' | 'vds') =>
        withholdingRates.filter(
            (r) =>
                r.kind === kind &&
                (r.supplier_type === null || r.supplier_type === supplierType) &&
                (r.from === null || r.from <= data.paid_on) &&
                (r.to === null || r.to >= data.paid_on),
        );
    const heldBy = (id: string) => {
        const rate = withholdingRates.find((r) => String(r.id) === id);
        return rate ? Math.round((total * rate.rate) / 100) : 0;
    };
    const held = heldBy(data.tds_rate_id) + heldBy(data.vds_rate_id);

    // ⚠ The server refuses overpayment and is the authority. This only stops the
    // clerk submitting something it already knows will be rejected.
    const overpaid = useMemo(() => bills.filter((bill) => toPaisa(data.amounts[bill.id] ?? '0') > toPaisa(bill.outstanding)), [bills, data.amounts]);

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
                tds_rate_id: data.tds_rate_id || null,
                vds_rate_id: data.vds_rate_id || null,
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
                <div className="flex flex-wrap items-start gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="supplier_id" required>
                            Supplier
                        </Label>
                        <SearchSelect fit id="supplier_id" value={String(data.supplier_id)} onChange={(e) => chooseSupplier(e.target.value)}>
                            <option value="">Select a supplier</option>
                            {suppliers.map((supplier) => (
                                <option key={supplier.id} value={supplier.id}>
                                    {supplier.name} - {formatMoney(supplier.outstanding)} owed
                                </option>
                            ))}
                        </SearchSelect>
                        {suppliers.length === 0 && <p className="text-muted-foreground text-sm">No supplier is owed anything right now.</p>}
                        {errors.supplier_id && <p className="text-destructive text-sm">{errors.supplier_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="money_account_id" required>
                            Paid from
                        </Label>
                        <SearchSelect
                            fit
                            id="money_account_id"
                            value={String(data.money_account_id)}
                            onChange={(e) => setData('money_account_id', e.target.value)}
                        >
                            <option value="">Select an account</option>
                            {moneyAccounts.map((account) => (
                                <option
                                    key={account.id}
                                    value={account.id}
                                    data-hint={account.balance != null ? `Balance now ${formatMoney(account.balance)}` : undefined}
                                >
                                    {account.name}
                                </option>
                            ))}
                        </SearchSelect>
                        {errors.money_account_id && <p className="text-destructive text-sm">{errors.money_account_id}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="paid_on" required>
                            Payment date
                        </Label>
                        <Input
                            id="paid_on"
                            type="date"
                            required
                            aria-required
                            className="w-auto"
                            value={data.paid_on}
                            onChange={(e) => setData('paid_on', e.target.value)}
                        />
                        {errors.paid_on && <p className="text-destructive text-sm">{errors.paid_on}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            className="w-80"
                            value={data.reference}
                            placeholder="Cheque number, bKash trx id, slip number"
                            onChange={(e) => setData('reference', e.target.value)}
                        />
                        {errors.reference && <p className="text-destructive text-sm">{errors.reference}</p>}
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
                                    <th className="p-3 text-right">
                                        Paying now
                                        <span aria-hidden="true" className="text-destructive ml-0.5 font-semibold">
                                            *
                                        </span>
                                    </th>
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
                                            <td className="p-3 text-right tabular-nums">{formatMoney(bill.total)}</td>
                                            <td className="p-3 text-right tabular-nums">
                                                <button
                                                    type="button"
                                                    className="tabular-nums hover:underline"
                                                    onClick={() => payAllOf(bill)}
                                                    title="Pay all of this bill"
                                                >
                                                    {formatMoney(bill.outstanding)}
                                                </button>
                                            </td>
                                            <td className="p-3 text-right">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    className={`ml-auto max-w-32 text-right tabular-nums ${isOver ? 'border-destructive' : ''}`}
                                                    value={data.amounts[bill.id] ?? ''}
                                                    onChange={(e) => setData('amounts', { ...data.amounts, [bill.id]: e.target.value })}
                                                />
                                                {isOver && (
                                                    <p className="text-destructive mt-1 text-xs">
                                                        Only {formatMoney(bill.outstanding)} is outstanding.
                                                    </p>
                                                )}
                                                {errors[`allocations.${index}.amount`] && (
                                                    <p className="text-destructive mt-1 text-xs">{errors[`allocations.${index}.amount`]}</p>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {bills.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                            This supplier has no open bills.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                            {bills.length > 0 && (
                                <tfoot>
                                    <tr className="bg-muted/30 border-t font-medium">
                                        <td className="p-3" colSpan={5}>
                                            Total payment
                                        </td>
                                        <td className="p-3 text-right tabular-nums">{formatMoney(total / 100)}</td>
                                    </tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                )}

                {errors.allocations && <p className="text-destructive text-sm">{errors.allocations}</p>}

                {data.supplier_id !== '' && (ratesFor('tds').length > 0 || ratesFor('vds').length > 0) && (
                    <div className="flex flex-wrap items-start gap-4">
                        {(['tds', 'vds'] as const).map((kind) =>
                            ratesFor(kind).length === 0 ? null : (
                                <div key={kind} className="grid gap-2">
                                    <Label htmlFor={`${kind}_rate_id`}>{kind === 'tds' ? 'Hold back TDS' : 'Hold back VDS'}</Label>
                                    <SearchSelect
                                        fit
                                        id={`${kind}_rate_id`}
                                        value={data[`${kind}_rate_id`]}
                                        onChange={(e) => setData(`${kind}_rate_id`, e.target.value)}
                                    >
                                        <option value="">None</option>
                                        {ratesFor(kind).map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.label}
                                            </option>
                                        ))}
                                    </SearchSelect>
                                    {errors[`${kind}_rate_id`] && <p className="text-destructive text-sm">{errors[`${kind}_rate_id`]}</p>}
                                </div>
                            ),
                        )}
                        {held > 0 && (
                            <p className="text-muted-foreground self-end pb-2 text-sm">
                                Tax held back {formatMoney(held / 100)}. Leaves the account:{' '}
                                <span className="font-medium">{formatMoney((total - held) / 100)}</span>
                            </p>
                        )}
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="note">Note</Label>
                    <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing || total <= 0 || overpaid.length > 0}>
                        {held > 0
                            ? `Settle ${formatMoney(total / 100)}, pay ${formatMoney((total - held) / 100)}`
                            : `Record payment of ${formatMoney(total / 100)}`}
                    </Button>
                    {overpaid.length > 0 && <span className="text-destructive text-sm">One or more lines pay more than is outstanding.</span>}
                </div>
            </form>
        </AppLayout>
    );
}
