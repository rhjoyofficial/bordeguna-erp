import { BusyButton } from '@/components/busy-button';
import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Claim = {
    id: number;
    claim_number: string;
    spent_on: string | null;
    amount: string;
    purpose: string;
    state: string;
    is_settled: boolean;
    is_approved: boolean;
    settlement_method: string | null;
    settled_by: string | null;
    expense_account: string | null;
    employee: string | null;
    employee_code: string | null;
    receipts: { id: number; name: string; url: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expense claims', href: '/hrm/expense-claims' }];

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Paying employees back for what they spent on the company.
 *
 * ⚠⚠ TWO ROUTES, CHOSEN PER CLAIM. Pay now takes money out of cash, bank or an
 * MFS wallet today and writes a payment voucher with the receipt attached; with
 * salary writes a payroll adjustment and nothing moves until the next run. The
 * choice is per claim because a 200 taka courier and a 15,000 taka repair do not
 * deserve the same treatment.
 */
function SettleRow({
    claim,
    moneyAccounts,
    expenseAccounts,
    payrollPeriods,
}: {
    claim: Claim;
    moneyAccounts: { id: number; name: string; type: string }[];
    expenseAccounts: { id: number; label: string }[];
    payrollPeriods: { id: number; label: string }[];
}) {
    const [head, setHead] = useState('');
    const [account, setAccount] = useState('');
    const [period, setPeriod] = useState('');

    if (claim.is_settled) {
        return (
            <div className="text-muted-foreground text-xs">
                {claim.settlement_method === 'payroll' ? 'Added to salary' : 'Paid'}
                {claim.expense_account ? ` · ${claim.expense_account}` : ''}
                {claim.settled_by ? ` · by ${claim.settled_by}` : ''}
            </div>
        );
    }

    if (!claim.is_approved) {
        return <span className="text-muted-foreground text-xs">Waiting for approval</span>;
    }

    const settle = (method: 'payment' | 'payroll') =>
        router.post(
            `/hrm/expense-claims/${claim.id}/settle`,
            {
                method,
                expense_account_id: head,
                money_account_id: method === 'payment' ? account : null,
                payroll_period_id: method === 'payroll' ? period : null,
            },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-2">
            <div className="grid gap-1">
                <Label className="text-muted-foreground text-xs">Charge to</Label>
                <div className="w-56">
                    <SearchSelect value={head} onChange={(e) => setHead(e.target.value)}>
                        <option value="">Pick an expense head</option>
                        {expenseAccounts.map((a) => (
                            <option key={a.id} value={String(a.id)}>
                                {a.label}
                            </option>
                        ))}
                    </SearchSelect>
                </div>
            </div>

            <div className="grid gap-1">
                <Label className="text-muted-foreground text-xs">Pay from (only for paying now)</Label>
                <div className="w-56">
                    <SearchSelect value={account} onChange={(e) => setAccount(e.target.value)}>
                        <option value="">Cash, bank or wallet</option>
                        {moneyAccounts.map((a) => (
                            <option key={a.id} value={String(a.id)}>
                                {a.name} ({a.type})
                            </option>
                        ))}
                    </SearchSelect>
                </div>
            </div>

            <div className="grid gap-1">
                <Label className="text-muted-foreground text-xs">Salary month (only for adding to salary)</Label>
                <div className="w-56">
                    <SearchSelect value={period} onChange={(e) => setPeriod(e.target.value)}>
                        <option value="">Which payroll month</option>
                        {payrollPeriods.map((p) => (
                            <option key={p.id} value={String(p.id)}>
                                {p.label}
                            </option>
                        ))}
                    </SearchSelect>
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <BusyButton size="sm" busyLabel="Paying…" disabled={head === '' || account === ''} onClick={() => settle('payment')}>
                    Pay now
                </BusyButton>
                <BusyButton size="sm" variant="outline" disabled={head === '' || period === ''} onClick={() => settle('payroll')}>
                    Add to salary
                </BusyButton>
            </div>
        </div>
    );
}

export default function ExpenseClaims({
    claims,
    moneyAccounts,
    expenseAccounts,
    payrollPeriods,
}: {
    claims: Claim[];
    moneyAccounts: { id: number; name: string; type: string }[];
    expenseAccounts: { id: number; label: string }[];
    payrollPeriods: { id: number; label: string }[];
}) {
    const owed = claims.filter((c) => !c.is_settled && c.is_approved).reduce((sum, c) => sum + Number(c.amount), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense claims" />

            <div className="space-y-6 p-4">
                <HeadingSmall
                    title="Employee expense claims"
                    description="What staff paid out of their own pocket, and how the company pays it back."
                />

                {owed > 0 && (
                    <p className="text-sm">
                        Approved and not yet paid: <span className="font-medium tabular-nums">BDT {money(String(owed))}</span>
                    </p>
                )}

                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full min-w-[60rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Claim</th>
                                <th className="p-3">Who</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">What for</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">Receipt</th>
                                <th className="p-3">Settlement</th>
                            </tr>
                        </thead>
                        <tbody>
                            {claims.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-3">
                                        Nobody has claimed anything yet.
                                    </td>
                                </tr>
                            )}
                            {claims.map((claim) => (
                                <tr key={claim.id} className="border-border border-t align-top">
                                    <td className="p-3 font-medium">{claim.claim_number}</td>
                                    <td className="p-3">
                                        {claim.employee}
                                        <span className="text-muted-foreground block text-xs">{claim.employee_code}</span>
                                    </td>
                                    <td className="p-3">{claim.spent_on ?? '-'}</td>
                                    <td className="p-3">{claim.purpose}</td>
                                    <td className="p-3 text-right tabular-nums">{money(claim.amount)}</td>
                                    <td className="p-3">
                                        {claim.receipts.length === 0 ? (
                                            <span className="text-destructive text-xs">None</span>
                                        ) : (
                                            claim.receipts.map((file) => (
                                                <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="block text-xs underline">
                                                    {file.name}
                                                </a>
                                            ))
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <SettleRow
                                            claim={claim}
                                            moneyAccounts={moneyAccounts}
                                            expenseAccounts={expenseAccounts}
                                            payrollPeriods={payrollPeriods}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
