import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { ReportHeading } from '@/components/report/report-shell';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm';

/**
 * The receipts behind one voucher, and the way to add another.
 *
 * ⚠⚠ "No receipt" IS THE MESSAGE. A voucher with nothing attached says so in
 * plain words rather than showing an empty space, because an empty space reads
 * as "nothing to see" when it actually means "this payment has no proof".
 */
function EvidenceCell({ voucher, canRecord }: { voucher: Voucher; canRecord: boolean }) {
    const [busy, setBusy] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setBusy(true);
        router.post(
            `/purchasing/expenses/${voucher.id}/evidence`,
            { file },
            { preserveScroll: true, forceFormData: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <div className="space-y-1">
            {voucher.evidence.length === 0 ? (
                <p className="text-destructive text-xs">No receipt</p>
            ) : (
                voucher.evidence.map((file) => (
                    <div key={file.id} className="flex items-center gap-2">
                        <a href={file.url} target="_blank" rel="noreferrer" className="text-xs underline">
                            {file.name}
                        </a>
                        {canRecord && (
                            <button
                                type="button"
                                className="text-muted-foreground hover:text-destructive text-xs"
                                onClick={() => router.delete(`/purchasing/expenses/${voucher.id}/evidence/${file.id}`, { preserveScroll: true })}
                            >
                                remove
                            </button>
                        )}
                    </div>
                ))
            )}

            {canRecord && (
                <label className="text-muted-foreground hover:text-foreground block cursor-pointer text-xs underline">
                    {busy ? 'Uploading…' : 'Attach receipt'}
                    <input
                        type="file"
                        className="hidden"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                </label>
            )}

            <a href={`/purchasing/expenses/${voucher.id}/print`} className="block text-xs underline">
                Print voucher
            </a>
        </div>
    );
}

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
    evidence: { id: number; name: string; uploaded_by: string | null; url: string }[];
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
    // ⚠ Held locally rather than pushed through a visit: these filter the FILE,
    // not the list on screen, so picking a date must not reload the page.
    const [exportFrom, setExportFrom] = useState('');
    const [exportTo, setExportTo] = useState('');

    const exportUrl = (() => {
        const params = new URLSearchParams();
        if (exportFrom) params.set('from', exportFrom);
        if (exportTo) params.set('to', exportTo);
        const query = params.toString();

        return `/purchasing/expenses/export${query ? `?${query}` : ''}`;
    })();

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
                <ReportHeading
                    title="Expenses"
                    description="Rent, fuel, utilities, repairs - money spent on running the business rather than on stock. Each one is paid at the moment it is recorded; a bill to be settled later is a supplier bill instead."
                />

                {canRecord && (
                    <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border flex flex-col gap-4 rounded-md border p-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="spent_on">Date</Label>
                                <Input id="spent_on" type="date" value={data.spent_on} onChange={(e) => setData('spent_on', e.target.value)} />
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
                                <SearchSelect
                                    fit
                                    id="money_account_id"
                                    value={data.money_account_id}
                                    onChange={(e) => setData('money_account_id', e.target.value)}
                                >
                                    <option value="">Choose an account</option>
                                    {moneyAccounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.name}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={errors.money_account_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="supplier_id">Supplier (optional)</Label>
                                <SearchSelect fit id="supplier_id" value={data.supplier_id} onChange={(e) => setData('supplier_id', e.target.value)}>
                                    <option value="">Not a supplier</option>
                                    {suppliers.map((supplier) => (
                                        <option key={supplier.id} value={supplier.id}>
                                            {supplier.name}
                                        </option>
                                    ))}
                                </SearchSelect>
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
                                    <SearchSelect
                                        fit
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
                                    </SearchSelect>
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
                            <span className="text-muted-foreground text-sm">
                                Total <span className="text-foreground font-medium tabular-nums">{total.toFixed(2)}</span>
                            </span>
                        </div>
                    </form>
                )}

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="export-from" className="text-muted-foreground text-xs">
                            Export from
                        </Label>
                        <Input id="export-from" type="date" value={exportFrom} onChange={(e) => setExportFrom(e.target.value)} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="export-to" className="text-muted-foreground text-xs">
                            to
                        </Label>
                        <Input id="export-to" type="date" value={exportTo} onChange={(e) => setExportTo(e.target.value)} />
                    </div>
                    {/* ⚠ A plain anchor, not a router visit: this is a file download,
                        and Inertia would try to render the CSV as a page. */}
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>Export to CSV</a>
                    </Button>
                    <p className="text-muted-foreground text-xs">
                        Leave both dates empty for everything. One row per expense line, so the amounts add up.
                    </p>
                </div>

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
                                <th className="p-3">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            {vouchers.data.map((voucher) => (
                                <tr key={voucher.id} className="border-t align-top">
                                    <td className="p-3 font-medium">{voucher.voucher_number}</td>
                                    <td className="p-3">{voucher.spent_on ?? '-'}</td>
                                    <td className="p-3">{voucher.payee}</td>
                                    <td className="p-3">{voucher.paid_from ?? '-'}</td>
                                    <td className="text-muted-foreground p-3">
                                        {voucher.lines.map((line) => (
                                            <div key={line.id}>
                                                {line.account ?? '-'}
                                                {line.description ? ` - ${line.description}` : ''} <span className="tabular-nums">{line.amount}</span>
                                            </div>
                                        ))}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{voucher.total}</td>
                                    <td className="p-3">
                                        <EvidenceCell voucher={voucher} canRecord={canRecord} />
                                    </td>
                                </tr>
                            ))}
                            {vouchers.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
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
