import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface AccountOption {
    id: number;
    label: string;
    business: string | null;
}

interface Row {
    id: number;
    date: string | null;
    direction: 'in' | 'out';
    from: string;
    to: string;
    amount: string;
    note: string | null;
    by: string | null;
}

interface Props {
    fromAccounts: AccountOption[];
    toAccounts: AccountOption[];
    transfers: Row[];
    today: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inter-business transfers', href: '/accounting/inter-business' }];

export default function InterBusinessTransfers({ fromAccounts, toAccounts, transfers, today }: Props) {
    const form = useForm({
        from_money_account_id: '',
        to_money_account_id: '',
        amount: '',
        transferred_on: today,
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('accounting.inter-business.store'), {
            preserveScroll: true,
            onSuccess: () => form.setData({ ...form.data, amount: '', note: '' }),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inter-business transfers" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Inter-business transfers</h1>
                    <p className="text-muted-foreground text-sm">
                        Money sent to another business of the group. Booked in both: this business is owed it back, the other owes it.
                    </p>
                </div>

                {toAccounts.length === 0 ? (
                    <p className="text-muted-foreground text-sm">You do not work in any other business, so there is nowhere to send money.</p>
                ) : (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border bg-card flex flex-col gap-4 rounded-xl border p-4"
                    >
                        <div className="flex flex-wrap items-start gap-4">
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="from_money_account_id" required>
                                    From (this business)
                                </Label>
                                <SearchSelect
                                    fit
                                    id="from_money_account_id"
                                    value={form.data.from_money_account_id}
                                    onChange={(e) => form.setData('from_money_account_id', e.target.value)}
                                >
                                    <option value="">Choose</option>
                                    {fromAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={form.errors.from_money_account_id} />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="to_money_account_id" required>
                                    To (other business)
                                </Label>
                                <SearchSelect
                                    fit
                                    id="to_money_account_id"
                                    value={form.data.to_money_account_id}
                                    onChange={(e) => form.setData('to_money_account_id', e.target.value)}
                                >
                                    <option value="">Choose</option>
                                    {toAccounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.business} · {a.label}
                                        </option>
                                    ))}
                                </SearchSelect>
                                <InputError message={form.errors.to_money_account_id} />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="amount" required>
                                    Amount
                                </Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-36 text-right"
                                    value={form.data.amount}
                                    onChange={(e) => form.setData('amount', e.target.value)}
                                />
                                <InputError message={form.errors.amount} />
                            </div>
                            <div className="flex flex-col gap-1">
                                <Label htmlFor="transferred_on" required>
                                    Date
                                </Label>
                                <Input
                                    id="transferred_on"
                                    type="date"
                                    className="w-auto"
                                    value={form.data.transferred_on}
                                    onChange={(e) => form.setData('transferred_on', e.target.value)}
                                />
                                <InputError message={form.errors.transferred_on} />
                            </div>
                        </div>
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="note">Note</Label>
                            <Input id="note" className="max-w-xl" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            <InputError message={form.errors.note} />
                        </div>
                        <div>
                            <Button disabled={form.processing}>Book transfer</Button>
                        </div>
                    </form>
                )}

                <div className="border-sidebar-border/70 dark:border-sidebar-border bg-card overflow-x-auto rounded-xl border p-4">
                    {transfers.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No transfers yet.</p>
                    ) : (
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">Date</th>
                                    <th className="py-2 pr-3">Direction</th>
                                    <th className="py-2 pr-3">From</th>
                                    <th className="py-2 pr-3">To</th>
                                    <th className="py-2 pr-3 text-right">Amount</th>
                                    <th className="py-2">Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {transfers.map((row) => (
                                    <tr key={row.id} className="border-b align-top">
                                        <td className="py-2 pr-3">{row.date}</td>
                                        <td className="py-2 pr-3">{row.direction === 'out' ? 'Sent' : 'Received'}</td>
                                        <td className="py-2 pr-3">{row.from}</td>
                                        <td className="py-2 pr-3">{row.to}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{row.amount}</td>
                                        <td className="text-muted-foreground py-2">{row.note}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
