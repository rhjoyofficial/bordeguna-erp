import { BusyButton } from '@/components/busy-button';
import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type Line = {
    id: number;
    spent_on: string | null;
    amount: string;
    description: string | null;
    category: string | null;
    category_id: number | null;
    is_declined: boolean;
    declined_reason: string | null;
    receipts: { id: number; name: string; url: string }[];
};

type Claim = {
    id: number;
    claim_number: string;
    spent_on: string | null;
    amount: string;
    category: string | null;
    purpose: string;
    state: string;
    is_draft: boolean;
    was_sent_back: boolean;
    sent_back_note: string | null;
    is_settled: boolean;
    is_approved: boolean;
    can_decide: boolean;
    settlement_method: string | null;
    settled_by: string | null;
    expense_account: string | null;
    is_booked: boolean;
    employee: string | null;
    employee_code: string | null;
    lines: Line[];
    receipts: { id: number; name: string; url: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Expense claims', href: '/hrm/expense-claims' }];

const money = (value: string | number) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * The spends on one claim, which is what the approver is actually deciding on.
 *
 * ⚠⚠ A TOTAL IS NOT A DECISION. A market trip approved from a single figure is
 * a figure nobody checked; the rickshaw, the courier and the tea have to be
 * visible separately, because that is what the receipts in the approver's hand
 * look like.
 */
function Lines({ claim }: { claim: Claim }) {
    if (claim.lines.length === 0) {
        return <span className="text-muted-foreground text-xs">No spends on this claim.</span>;
    }

    return (
        <ul className="space-y-1">
            {claim.lines.map((line) => (
                <li key={line.id} className={line.is_declined ? 'text-muted-foreground line-through' : ''}>
                    <span className="tabular-nums">{money(line.amount)}</span> {line.category ?? 'Uncategorised'}
                    {line.description ? `: ${line.description}` : ''}
                    <span className="text-muted-foreground text-xs"> {line.spent_on}</span>
                    {line.receipts.length === 0 ? (
                        <span className="text-destructive ml-1 text-xs">no receipt</span>
                    ) : (
                        line.receipts.map((file) => (
                            <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="ml-1 text-xs underline">
                                receipt
                            </a>
                        ))
                    )}
                    {line.is_declined && <span className="block text-xs text-amber-700 no-underline">Declined: {line.declined_reason}</span>}
                </li>
            ))}
        </ul>
    );
}

/**
 * Approve, hand back, or refuse.
 *
 * ⚠⚠ THE THIRD OUTCOME IS THE NEW ONE (Joy, 9 Sep 2026). A claim with one wrong
 * figure had to be rejected and typed again from nothing. Sending it back keeps
 * the claim, its number and its decision history, and tells the employee what to
 * change.
 *
 * ⚠ Declining a line is not the same as sending the claim back. Declining says
 * "the rest is fine, this one is not" and lets the good lines post and be paid
 * today; sending back says "I cannot judge this yet, fix it and ask again".
 */
function Decide({ claim }: { claim: Claim }) {
    const [declined, setDeclined] = useState<Record<number, string>>({});

    const toggle = (lineId: number, on: boolean) =>
        setDeclined((current) => {
            const next = { ...current };
            if (on) {
                next[lineId] = '';
            } else {
                delete next[lineId];
            }
            return next;
        });

    const entries = Object.entries(declined);
    const allDeclined = entries.length > 0 && entries.length >= claim.lines.length;
    const missingReason = entries.some(([, reason]) => reason.trim() === '');

    const approve = () =>
        router.post(
            `/hrm/expense-claims/${claim.id}/approve`,
            { declines: entries.map(([id, reason]) => ({ id: Number(id), reason })) },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-2">
            {claim.lines.length > 1 && (
                <div className="space-y-1">
                    <Label className="text-muted-foreground text-xs">Decline any spend you will not pay</Label>
                    {claim.lines.map((line) => (
                        <div key={line.id} className="space-y-1">
                            <label className="flex items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    checked={declined[line.id] !== undefined}
                                    onChange={(e) => toggle(line.id, e.target.checked)}
                                />
                                <span className="tabular-nums">{money(line.amount)}</span>
                                <span>{line.category ?? 'Uncategorised'}</span>
                            </label>
                            {declined[line.id] !== undefined && (
                                <Input
                                    className="w-full text-xs"
                                    placeholder="Why this one is refused"
                                    value={declined[line.id]}
                                    onChange={(e) => setDeclined((current) => ({ ...current, [line.id]: e.target.value }))}
                                />
                            )}
                        </div>
                    ))}
                </div>
            )}

            {allDeclined && (
                <p className="text-destructive text-xs">Declining every spend is a rejection. Use Reject, or send the claim back for correction.</p>
            )}

            <div className="flex flex-wrap gap-2">
                <BusyButton size="sm" busyLabel="Approving…" disabled={allDeclined || missingReason} onClick={approve}>
                    Approve
                </BusyButton>

                <ConfirmDialog
                    trigger={
                        <Button size="sm" variant="outline">
                            Send back
                        </Button>
                    }
                    title={`Send ${claim.claim_number} back for correction`}
                    description="It returns to the employee as a draft, keeps its number, and they can fix it and send it again."
                    confirmLabel="Send back"
                    withNote
                    noteRequired
                    noteLabel="What needs correcting"
                    notePlaceholder="The courier amount looks like 350 rather than 550…"
                    onConfirm={(note) => router.post(`/hrm/expense-claims/${claim.id}/send-back`, { note }, { preserveScroll: true })}
                />

                <ConfirmDialog
                    trigger={
                        <Button size="sm" variant="ghost">
                            Reject
                        </Button>
                    }
                    title={`Reject ${claim.claim_number}`}
                    description="Final. Nothing moves, and the employee cannot resubmit this claim."
                    confirmLabel="Reject"
                    destructive
                    withNote
                    noteLabel="Reason for rejecting (optional)"
                    onConfirm={(note) => router.post(`/hrm/expense-claims/${claim.id}/reject`, { note }, { preserveScroll: true })}
                />
            </div>
        </div>
    );
}

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
            <div className="space-y-1">
                <div className="text-muted-foreground text-xs">
                    {claim.settlement_method === 'payroll' ? 'Added to salary' : 'Paid'}
                    {claim.expense_account ? ` · ${claim.expense_account}` : ''}
                    {claim.settled_by ? ` · by ${claim.settled_by}` : ''}
                </div>
                {/* ⭐ Until this existed a claim paid from a booked position produced
                    no printable document at all: cash left the till with nothing to
                    file and nothing for the recipient to sign. */}
                <a href={`/hrm/expense-claims/${claim.id}/print`} className="text-xs underline">
                    Print voucher
                </a>
            </div>
        );
    }

    if (claim.is_draft) {
        return (
            <div className="space-y-1">
                <span className="text-muted-foreground text-xs">Sent back. Waiting on the employee to correct it.</span>
                {claim.sent_back_note && <p className="text-xs text-amber-700">{claim.sent_back_note}</p>}
            </div>
        );
    }

    if (!claim.is_approved) {
        return claim.can_decide ? <Decide claim={claim} /> : <span className="text-muted-foreground text-xs">Waiting for approval</span>;
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

    // ⚠⚠ A category claim was EXPENSED AT APPROVAL, so there is no expense
    // head left to pick - the cost is already in the books and only the payable
    // is outstanding. Both routes clear that payable: paying now moves it to
    // cash, adding it to salary moves it to wages.
    if (claim.is_booked) {
        return (
            <div className="space-y-2">
                <p className="text-muted-foreground text-xs">
                    Already booked at approval{claim.expense_account ? ` to ${claim.expense_account}` : ''}. Paying clears the payable.
                </p>
                <div className="grid gap-1">
                    <Label className="text-muted-foreground text-xs">Pay from</Label>
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
                <BusyButton size="sm" busyLabel="Paying…" disabled={account === ''} onClick={() => settle('payment')}>
                    Pay now
                </BusyButton>

                {/* ⭐ Joy, 7 Sep: every claim can go either way. Payroll posts this
                    against the payable rather than an expense account, so the cost
                    is not counted twice. */}
                <div className="grid gap-1 border-t pt-2">
                    <Label className="text-muted-foreground text-xs">Or add to salary of</Label>
                    <div className="w-56">
                        <SearchSelect value={period} onChange={(e) => setPeriod(e.target.value)}>
                            <option value="">Pick a payroll month</option>
                            {payrollPeriods.map((p) => (
                                <option key={p.id} value={String(p.id)}>
                                    {p.label}
                                </option>
                            ))}
                        </SearchSelect>
                    </div>
                    <BusyButton size="sm" variant="outline" busyLabel="Adding…" disabled={period === ''} onClick={() => settle('payroll')}>
                        Add to salary
                    </BusyButton>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className="grid gap-1">
                <Label className="text-muted-foreground text-xs">Charge to</Label>
                <div className="w-56">
                    <SearchSelect
                        newLedger={{ types: ['expense'], reload: ['expenseAccounts'] }}
                        value={head}
                        onChange={(e) => setHead(e.target.value)}
                    >
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
                        Approved and not yet paid: <span className="font-medium tabular-nums">BDT {money(owed)}</span>
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
                                <th className="p-3">Spends</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">What happens next</th>
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
                                    <td className="p-3 font-medium">
                                        {claim.claim_number}
                                        <span className="text-muted-foreground block text-xs font-normal">{claim.state}</span>
                                    </td>
                                    <td className="p-3">
                                        {claim.employee}
                                        <span className="text-muted-foreground block text-xs">{claim.employee_code}</span>
                                    </td>
                                    <td className="p-3">{claim.spent_on ?? '-'}</td>
                                    <td className="p-3">{claim.purpose}</td>
                                    <td className="p-3">
                                        <Lines claim={claim} />
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{money(claim.amount)}</td>
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
