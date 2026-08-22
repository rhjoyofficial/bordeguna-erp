import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

interface VoucherLine {
    id: number;
    account: string | null;
    amount: string;
    description: string | null;
}

interface Voucher {
    id: number;
    voucher_number: string;
    /** ⚠ A plain Y-m-d from the server. The raw `spent_on` date cast serialises
     *  in UTC and would render a Dhaka expense one day early. */
    spent_on: string | null;
    paid_from: string | null;
    payee: string;
    total: string;
    reference: string | null;
    note: string | null;
    lines: VoucherLine[];
}

interface Option {
    id: number;
    name?: string;
    label?: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Purchasing', href: '/purchasing/purchase-orders' },
    { title: 'Expenses', href: '/purchasing/expenses' },
];

const EMPTY_LINE = { account_id: '', amount: '', description: '' };

export default function ExpensesIndex({
    vouchers,
    canRecord,
    expenseAccounts,
    moneyAccounts,
    suppliers,
    today,
}: {
    vouchers: Paginated<Voucher>;
    canRecord: boolean;
    expenseAccounts: { id: number; label: string }[];
    moneyAccounts: { id: number; name: string; type: string }[];
    suppliers: { id: number; name: string }[];
    today: string;
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        money_account_id: '',
        spent_on: today,
        supplier_id: '',
        paid_to: '',
        reference: '',
        note: '',
        lines: [{ ...EMPTY_LINE }],
    });

    const setLine = (index: number, key: keyof typeof EMPTY_LINE, value: string) =>
        setData(
            'lines',
            data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line)),
        );

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('purchasing.expenses.store'), {
            onSuccess: () => reset(),
            preserveScroll: true,
        });
    };

    // Shown live so the person entering it can check it against the receipt in
    // their hand before anything is posted.
    const total = data.lines.reduce((sum, line) => sum + (Number.parseFloat(line.amount) || 0), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expenses" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Expenses</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Rent, fuel, utilities, repairs - money spent on running the business rather than on stock. Each one is paid
                        at the moment it is recorded; a bill to be settled later is a supplier bill instead.
                    </p>
                </div>

                {canRecord && (
                    <form onSubmit={submit} className="flex flex-col gap-4 rounded-md border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="spent_on">Date</Label>
                                <Input
                                    id="spent_on"
                                    type="date"
                                    value={data.spent_on}
                                    onChange={(e) => setData('spent_on', e.target.value)}
                                    className="w-44"
                                />
                                <InputError message={errors.spent_on} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="money_account_id">Paid from</Label>
                                {/*
                                  ⚠⚠ Only accounts that already have a ledger account appear
                                  here. An unmapped one is refused by the engine with an error
                                  naming an account nobody remembers creating, so it is not
                                  offered at all. The bank & cash screen warns about them.
                                */}
                                <select
                                    id="money_account_id"
                                    className={selectClass}
                                    value={data.money_account_id}
                                    onChange={(e) => setData('money_account_id', e.target.value)}
                                >
                                    <option value="">Choose an account</option>
                                    {moneyAccounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.money_account_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="supplier_id">Supplier (optional)</Label>
                                <select
                                    id="supplier_id"
                                    className={selectClass}
                                    value={data.supplier_id}
                                    onChange={(e) => setData('supplier_id', e.target.value)}
                                >
                                    <option value="">Not a supplier</option>
                                    {suppliers.map((supplier) => (
                                        <option key={supplier.id} value={supplier.id}>
                                            {supplier.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.supplier_id} />
                            </div>

                            <div className="grid gap-2">
                                {/* A landlord or an electricity board is not a supplier of
                                    goods and should not have to become one to be paid. */}
                                <Label htmlFor="paid_to">Paid to</Label>
                                <Input
                                    id="paid_to"
                                    value={data.paid_to}
                                    onChange={(e) => setData('paid_to', e.target.value)}
                                    className="w-52"
                                    placeholder="e.g. Adabor landlord"
                                />
                                <InputError message={errors.paid_to} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reference">Reference</Label>
                                <Input
                                    id="reference"
                                    value={data.reference}
                                    onChange={(e) => setData('reference', e.target.value)}
                                    className="w-40"
                                    placeholder="Receipt no."
                                />
                                <InputError message={errors.reference} />
                            </div>
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label>What it was spent on</Label>
                            {data.lines.map((line, index) => (
                                <div key={index} className="flex flex-wrap items-end gap-3">
                                    <select
                                        aria-label={`Expense account, line ${index + 1}`}
                                        className={`${selectClass} w-72`}
                                        value={line.account_id}
                                        onChange={(e) => setLine(index, 'account_id', e.target.value)}
                                    >
                                        <option value="">Choose an expense account</option>
                                        {expenseAccounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        aria-label={`Amount, line ${index + 1}`}
                                        value={line.amount}
                                        onChange={(e) => setLine(index, 'amount', e.target.value)}
                                        className="w-32 text-right"
                                        placeholder="0.00"
                                        inputMode="decimal"
                                    />
                                    <Input
                                        aria-label={`Description, line ${index + 1}`}
                                        value={line.description}
                                        onChange={(e) => setLine(index, 'description', e.target.value)}
                                        className="w-64"
                                        placeholder="Description (optional)"
                                    />
                                    {data.lines.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setData(
                                                    'lines',
                                                    data.lines.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                    <InputError message={errors[`lines.${index}.account_id` as keyof typeof errors]} />
                                    <InputError message={errors[`lines.${index}.amount` as keyof typeof errors]} />
                                    <InputError message={errors[`lines.${index}.account` as keyof typeof errors]} />
                                </div>
                            ))}
                            <InputError message={errors.lines} />
                            <div>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setData('lines', [...data.lines, { ...EMPTY_LINE }])}>
                                    Add another line
                                </Button>
                            </div>
                        </div>

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Record expense</Button>
                            <span className="text-sm text-muted-foreground">
                                Total <span className="font-medium tabular-nums text-foreground">{total.toFixed(2)}</span>
                            </span>
                        </div>
                    </form>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Voucher</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Paid to</th>
                                <th className="p-3">Paid from</th>
                                <th className="p-3">Spent on</th>
                                <th className="p-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.map((voucher) => (
                                <tr key={voucher.id} className="border-t align-top">
                                    <td className="p-3 font-medium">{voucher.voucher_number}</td>
                                    <td className="p-3">{voucher.spent_on ?? '-'}</td>
                                    <td className="p-3">{voucher.payee}</td>
                                    <td className="p-3">{voucher.paid_from ?? '-'}</td>
                                    <td className="p-3 text-muted-foreground">
                                        {voucher.lines.map((line) => (
                                            <div key={line.id}>
                                                {line.account ?? '-'}
                                                {line.description ? ` - ${line.description}` : ''}{' '}
                                                <span className="tabular-nums">{line.amount}</span>
                                            </div>
                                        ))}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{voucher.total}</td>
                                </tr>
                            ))}
                            {vouchers.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-6 text-center text-muted-foreground">
                                        No expenses recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={vouchers} />
            </div>
        </AppLayout>
    );
}
