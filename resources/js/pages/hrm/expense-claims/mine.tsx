import HeadingSmall from '@/components/heading-small';
import SelfServiceTabs from '@/components/self-service-tabs';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

type Claim = {
    id: number;
    claim_number: string;
    spent_on: string | null;
    amount: string;
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
export default function MyExpenseClaims({ claims, today }: { claims: Claim[]; today: string }) {
    const form = useForm<{ spent_on: string; amount: string; purpose: string; receipt: File | null }>({
        spent_on: today,
        amount: '',
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

                <form onSubmit={submit} className="border-border grid gap-4 rounded-md border p-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="spent_on">When you spent it</Label>
                        <Input
                            id="spent_on"
                            type="date"
                            max={today}
                            value={form.data.spent_on}
                            onChange={(e) => form.setData('spent_on', e.target.value)}
                        />
                        {form.errors.spent_on && <p className="text-destructive text-sm">{form.errors.spent_on}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="amount">How much (BDT)</Label>
                        <Input
                            id="amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            value={form.data.amount}
                            onChange={(e) => form.setData('amount', e.target.value)}
                        />
                        {form.errors.amount && <p className="text-destructive text-sm">{form.errors.amount}</p>}
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="purpose">What it was for</Label>
                        <Input
                            id="purpose"
                            value={form.data.purpose}
                            placeholder="Courier to Savar for the Rahman order"
                            onChange={(e) => form.setData('purpose', e.target.value)}
                        />
                        {form.errors.purpose && <p className="text-destructive text-sm">{form.errors.purpose}</p>}
                    </div>

                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="receipt">Receipt</Label>
                        <Input
                            id="receipt"
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)}
                        />
                        <p className="text-muted-foreground text-xs">A photo from your phone is fine. Without it the claim cannot be approved.</p>
                        {form.errors.receipt && <p className="text-destructive text-sm">{form.errors.receipt}</p>}
                    </div>

                    <div className="sm:col-span-2">
                        <Button disabled={form.processing}>Send for approval</Button>
                    </div>
                </form>

                <div className="border-border overflow-x-auto rounded-md border">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Claim</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">What for</th>
                                <th className="p-3 text-right">Amount</th>
                                <th className="p-3">Receipt</th>
                                <th className="p-3">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            {claims.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-3">
                                        You have not claimed anything yet.
                                    </td>
                                </tr>
                            )}
                            {claims.map((claim) => (
                                <tr key={claim.id} className="border-border border-t">
                                    <td className="p-3 font-medium">{claim.claim_number}</td>
                                    <td className="p-3">{claim.spent_on ?? '-'}</td>
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
