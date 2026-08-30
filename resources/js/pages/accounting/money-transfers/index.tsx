import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Transfer {
    id: number;
    transfer_number: string;
    transferred_on: string | null;
    from: string | null;
    to: string | null;
    amount: string;
    fee: string;
    total_withdrawn: string;
    fee_account: string | null;
    reference: string | null;
    note: string | null;
}

interface AccountOption {
    id: number;
    name: string;
    type: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/money-transfers' },
    { title: 'Money transfer', href: '/accounting/money-transfers' },
];

const today = () => new Date().toISOString().slice(0, 10);

export default function MoneyTransfersIndex({
    transfers,
    accounts,
    fee_accounts,
}: {
    transfers: Paginated<Transfer>;
    accounts: AccountOption[];
    fee_accounts: { id: number; label: string }[];
}) {
    const { data, setData, post, errors, processing, reset } = useForm({
        from_money_account_id: '',
        to_money_account_id: '',
        amount: '',
        transferred_on: today(),
        fee: '',
        fee_account_id: '',
        reference: '',
        note: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('accounting.money-transfers.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    // Shown live so nobody has to work out that the fee comes off the source too.
    const moved = Number(data.amount) || 0;
    const charge = Number(data.fee) || 0;
    const withdrawn = moved + charge;
    const chargeable = charge > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Money transfer" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-medium">Money transfer</h1>
                    <p className="text-muted-foreground text-sm">
                        Moving money between your own accounts - bank to the till, bKash to the bank. Nothing is earned or spent here except the fee,
                        so the business is exactly as rich afterwards, minus what the move cost.
                    </p>
                </div>

                <form onSubmit={submit} className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="transferred_on">Date</Label>
                        <Input
                            id="transferred_on"
                            type="date"
                            value={data.transferred_on}
                            onChange={(e) => setData('transferred_on', e.target.value)}
                        />
                        <InputError message={errors.transferred_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="from_money_account_id">From</Label>
                        <SearchSelect
                            id="from_money_account_id"
                            value={data.from_money_account_id}
                            onChange={(e) => setData('from_money_account_id', e.target.value)}
                        >
                            <option value="">Choose an account</option>
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.from_money_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="to_money_account_id">To</Label>
                        <SearchSelect
                            id="to_money_account_id"
                            value={data.to_money_account_id}
                            onChange={(e) => setData('to_money_account_id', e.target.value)}
                        >
                            <option value="">Choose an account</option>
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.to_money_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="amount">Amount that arrives</Label>
                        <Input id="amount" inputMode="decimal" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                        <InputError message={errors.amount} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="fee">Fee (optional)</Label>
                        <Input id="fee" inputMode="decimal" value={data.fee} onChange={(e) => setData('fee', e.target.value)} />
                        <InputError message={errors.fee} />
                        {/* ⚠ The reason this field exists at all. */}
                        <p className="text-muted-foreground text-xs">A bKash cash-out costs about 1.85%. Leave blank if the move was free.</p>
                    </div>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="fee_account_id">Charge the fee to</Label>
                        <SearchSelect
                            id="fee_account_id"
                            value={data.fee_account_id}
                            onChange={(e) => setData('fee_account_id', e.target.value)}
                            disabled={!chargeable}
                        >
                            <option value="">{chargeable ? 'Choose an expense account' : 'No fee to charge'}</option>
                            {fee_accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.fee_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reference">Reference</Label>
                        <Input
                            id="reference"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                            placeholder="Slip or transaction no."
                        />
                        <InputError message={errors.reference} />
                    </div>

                    <div className="grid gap-2 md:col-span-4">
                        <Label htmlFor="note">Note</Label>
                        <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>

                    {/* ⚠⚠ The one thing people get wrong: the source loses amount PLUS
                        fee, not amount. Stated on screen rather than left to be
                        discovered when the till is counted. */}
                    {withdrawn > 0 && (
                        <div className="bg-muted/50 rounded-md p-3 text-sm md:col-span-4">
                            Leaves the source: <span className="font-medium tabular-nums">{withdrawn.toFixed(2)}</span>
                            {chargeable && (
                                <>
                                    {' '}
                                    <span className="text-muted-foreground">
                                        ({moved.toFixed(2)} arrives, {charge.toFixed(2)} fee)
                                    </span>
                                </>
                            )}
                        </div>
                    )}

                    <div className="md:col-span-4">
                        <Button type="submit" disabled={processing}>
                            Record transfer
                        </Button>
                    </div>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Transfer</th>
                                <th className="p-3">Date</th>
                                <th className="p-3">From</th>
                                <th className="p-3">To</th>
                                <th className="p-3 text-right">Arrived</th>
                                <th className="p-3 text-right">Fee</th>
                                <th className="p-3 text-right">Left the source</th>
                                <th className="p-3">Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            {transfers.data.length === 0 && (
                                <tr>
                                    <td className="text-muted-foreground p-3" colSpan={8}>
                                        No transfers recorded yet.
                                    </td>
                                </tr>
                            )}
                            {transfers.data.map((t) => (
                                <tr key={t.id} className="border-t">
                                    <td className="p-3 font-medium">{t.transfer_number}</td>
                                    <td className="p-3">{t.transferred_on ?? '-'}</td>
                                    <td className="p-3">{t.from ?? '-'}</td>
                                    <td className="p-3">{t.to ?? '-'}</td>
                                    <td className="p-3 text-right tabular-nums">{t.amount}</td>
                                    <td className="p-3 text-right tabular-nums">
                                        {Number(t.fee) > 0 ? t.fee : '-'}
                                        {t.fee_account && <div className="text-muted-foreground text-xs">{t.fee_account}</div>}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">{t.total_withdrawn}</td>
                                    <td className="p-3">{t.reference ?? '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={transfers} />
            </div>
        </AppLayout>
    );
}
