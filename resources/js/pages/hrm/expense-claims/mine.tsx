import HeadingSmall from '@/components/heading-small';
import SelfServiceTabs from '@/components/self-service-tabs';
import { Button } from '@/components/ui/button';
import { Field, FormRow } from '@/components/form/field';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Claim = {
    id: number;
    claim_number: string;
    spent_on: string | null;
    amount: string;
    category: string | null;
    purpose: string;
    state: string;
    is_settled: boolean;
    settlement_method: string | null;
    settled_by: string | null;
    receipts: { id: number; name: string; url: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My expenses', href: '/hrm/my-expenses' }];

const money = (value: string) => Number(value).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * What I spent on the company, and whether I have been paid back.
 *
 * ⚠ The receipt is part of the form, not a step afterwards. A claim with no
 * proof cannot be approved by anybody honestly, so it cannot be submitted
 * either.
 */
export default function MyExpenseClaims({
    categories = [],
    claims,
    today,
}: {
    categories: { id: number; name: string }[];
    claims: Claim[];
    today: string;
}) {
    const form = useForm<{ spent_on: string; amount: string; expense_category_id: string; purpose: string; receipt: File | null }>({
        spent_on: today,
        amount: '',
        expense_category_id: '',
        purpose: '',
        receipt: null,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hrm/my-expenses', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
    };

    const outstanding = claims.filter((claim) => !claim.is_settled).reduce((sum, claim) => sum + Number(claim.amount), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My expenses" />

            <div className="space-y-6 p-4">
                <SelfServiceTabs active="/hrm/my-expenses" />

                <HeadingSmall
                    title="Money I spent for the office"
                    description="Claim back what you paid out of your own pocket. Attach the receipt and it goes to your manager."
                />

                {outstanding > 0 && (
                    <p className="text-sm">
                        Waiting to be paid back: <span className="font-medium tabular-nums">BDT {money(String(outstanding))}</span>
                    </p>
                )}

                {/* ⚠⚠ TWO ROWS, Joy's own layout (3 Sep 2026): when / how much /
                    category, then what it was for / receipt. Field+FormRow, NOT a
                    grid: a grid gives a date and an amount a full half-screen each,
                    which is root cause B of the 30 Aug audit all over again. */}
                <form onSubmit={submit} className="border-border space-y-4 rounded-md border p-4">
                    <FormRow>
                        <Field label="When you spent it" htmlFor="spent_on" error={form.errors.spent_on}>
                            <Input
                                id="spent_on"
                                type="date"
                                max={today}
                                value={form.data.spent_on}
                                onChange={(e) => form.setData('spent_on', e.target.value)}
                            />
                        </Field>

                        <Field label="How much (BDT)" htmlFor="amount" size="sm" error={form.errors.amount}>
                            <Input
                                id="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                className="text-right tabular-nums"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                        </Field>

                        <Field label="Expense category" htmlFor="expense_category_id" error={form.errors.expense_category_id}>
                            <select
                                id="expense_category_id"
                                className="border-input bg-background h-10 rounded-md border px-3 py-2 text-sm"
                                value={form.data.expense_category_id}
                                onChange={(e) => form.setData('expense_category_id', e.target.value)}
                            >
                                <option value="">Choose the category…</option>
                                {categories.map((category: { id: number; name: string }) => (
                                    <option key={category.id} value={String(category.id)}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </FormRow>

                    <FormRow>
                        <Field label="What it was for" htmlFor="purpose" size="grow" error={form.errors.purpose}>
                            <Input
                                id="purpose"
                                value={form.data.purpose}
                                placeholder="Courier to Savar for the Rahman order"
                                onChange={(e) => form.setData('purpose', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="Receipt"
                            htmlFor="receipt"
                            size="lg"
                            error={form.errors.receipt}
                            hint="A photo from your phone is fine. Without it the claim cannot be approved."
                        >
                            <Input
                                id="receipt"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.webp"
                                onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)}
                            />
                        </Field>
                    </FormRow>

                    <Button disabled={form.processing}>Send for approval</Button>
                </form>

                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Claim</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">Category</th>
                                <th className="p-3">What for</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">Receipt</th>
                                <th className="p-3">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            {claims.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground p-3">
                                        You have not claimed anything yet.
                                    </td>
                                </tr>
                            )}
                            {claims.map((claim) => (
                                <tr key={claim.id} className="border-border border-t">
                                    <td className="p-3 font-medium">{claim.claim_number}</td>
                                    <td className="p-3">{claim.spent_on ?? '-'}</td>
                                    <td className="p-3">{claim.category ?? '-'}</td>
                                    <td className="p-3">{claim.purpose}</td>
                                    <td className="p-3 text-right tabular-nums">{money(claim.amount)}</td>
                                    <td className="p-3">
                                        {claim.receipts.map((file) => (
                                            <a key={file.id} href={file.url} target="_blank" rel="noreferrer" className="block text-xs underline">
                                                {file.name}
                                            </a>
                                        ))}
                                    </td>
                                    <td className="p-3">
                                        {claim.state}
                                        {claim.settled_by && <span className="text-muted-foreground block text-xs">by {claim.settled_by}</span>}
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
