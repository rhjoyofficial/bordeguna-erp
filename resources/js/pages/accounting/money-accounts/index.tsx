import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface MoneyAccount {
    id: number;
    name: string;
    type: string;
    type_label: string;
    account_number: string | null;
    bank_name: string | null;
    branch: string | null;
    routing_number: string | null;
    provider: string | null;
    note: string | null;
    is_active: boolean;
    account_id: number | null;
    ledger_account: string | null;
    is_unmapped: boolean;
}

interface Option {
    value?: string;
    id?: number;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/journals' },
    { title: 'Bank & cash', href: '/accounting/money-accounts' },
];

const EMPTY = {
    name: '',
    type: 'bank',
    account_number: '',
    bank_name: '',
    branch: '',
    routing_number: '',
    provider: '',
    account_id: '',
    is_active: true,
    note: '',
};

export default function MoneyAccountsIndex({
    accounts,
    types,
    ledgerAccounts,
    unmappedCount,
}: {
    accounts: MoneyAccount[];
    types: Option[];
    ledgerAccounts: Option[];
    unmappedCount: number;
}) {
    const { canManageChartOfAccounts } = usePage<SharedData>().props;
    const [editing, setEditing] = useState<MoneyAccount | null>(null);
    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    const startEdit = (account: MoneyAccount) => {
        clearErrors();
        setEditing(account);
        setData({
            name: account.name,
            type: account.type,
            account_number: account.account_number ?? '',
            bank_name: account.bank_name ?? '',
            branch: account.branch ?? '',
            routing_number: account.routing_number ?? '',
            provider: account.provider ?? '',
            account_id: account.account_id === null ? '' : String(account.account_id),
            is_active: account.is_active,
            note: account.note ?? '',
        });
    };

    const cancel = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('accounting.money-accounts.update', editing.id), { onSuccess: cancel });
            return;
        }

        post(route('accounting.money-accounts.store'), { onSuccess: () => reset() });
    };

    const isCash = data.type === 'cash';
    const isMfs = data.type === 'mfs';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank & cash" />
            <div className="flex flex-col gap-6 p-4">
                {/*
                  ⚠⚠ An unmapped money account does not fail here. It fails at the
                  till, when somebody pays a supplier or takes a customer receipt,
                  with an error naming an account nobody remembers creating. The
                  account is deliberately allowed to exist before its mapping is
                  decided - so the unfinished state is made loud instead.
                */}
                {unmappedCount > 0 && (
                    <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm">
                        <p className="font-medium">
                            {unmappedCount} account{unmappedCount === 1 ? '' : 's'} {unmappedCount === 1 ? 'has' : 'have'} no
                            ledger account.
                        </p>
                        <p className="text-muted-foreground">
                            Payments and customer receipts using {unmappedCount === 1 ? 'it' : 'them'} will be refused when
                            somebody tries to record one. Set the ledger account below to finish the setup.
                        </p>
                    </div>
                )}
                <div>
                    <h1 className="text-lg font-semibold">Bank &amp; cash accounts</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Where money actually sits. These record which account paid or received, not how much is in it: the balance
                        comes from the ledger, so nothing here is ever typed as a number.
                    </p>
                </div>

                {canManageChartOfAccounts && (
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-md border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-56"
                                placeholder="e.g. BRAC Bank - Current"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="type">Kind</Label>
                            <select
                                id="type"
                                className="h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {!isCash && (
                            <div className="grid gap-2">
                                <Label htmlFor="account_number">{isMfs ? 'Wallet number' : 'Account number'}</Label>
                                <Input
                                    id="account_number"
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
                                    className="w-52"
                                />
                                <InputError message={errors.account_number} />
                            </div>
                        )}

                        {isMfs && (
                            <div className="grid gap-2">
                                <Label htmlFor="provider">Provider</Label>
                                <Input
                                    id="provider"
                                    value={data.provider}
                                    onChange={(e) => setData('provider', e.target.value)}
                                    className="w-36"
                                    placeholder="bKash"
                                />
                            </div>
                        )}

                        {data.type === 'bank' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="bank_name">Bank</Label>
                                    <Input id="bank_name" value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} className="w-44" />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="branch">Branch</Label>
                                    <Input id="branch" value={data.branch} onChange={(e) => setData('branch', e.target.value)} className="w-40" />
                                </div>
                            </>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="account_id">Reports under</Label>
                            <select
                                id="account_id"
                                className="h-10 w-full min-w-0 rounded-md border border-input bg-background px-3 text-sm"
                                value={data.account_id}
                                onChange={(e) => setData('account_id', e.target.value)}
                            >
                                <option value="">Not decided yet</option>
                                {ledgerAccounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.account_id} />
                        </div>

                        {editing && (
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                                Active
                            </label>
                        )}

                        <Button disabled={processing}>{editing ? `Save ${editing.name}` : 'Add account'}</Button>
                        {editing && (
                            <Button type="button" variant="ghost" onClick={cancel}>
                                Cancel
                            </Button>
                        )}
                    </form>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead>
                            <tr className="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border">
                                <th className="py-2">Name</th>
                                <th className="py-2">Kind</th>
                                <th className="py-2">Details</th>
                                <th className="py-2">Reports under</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr key={account.id} className="border-b border-sidebar-border/70 dark:border-sidebar-border">
                                    <td className="py-2">
                                        {account.name}
                                        {!account.is_active && (
                                            <Badge variant="destructive" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 text-muted-foreground">{account.type_label}</td>
                                    <td className="py-2 text-muted-foreground">
                                        {[account.bank_name, account.branch, account.provider, account.account_number].filter(Boolean).join(' · ')}
                                    </td>
                                    <td className="py-2 text-muted-foreground">
                                        {account.ledger_account ?? (
                                            /* ⚠⚠ Not a cosmetic gap. A payment from an unmapped
                                               account fails at the till with MappingNotFound, long
                                               after whoever created it has left this screen. */
                                            <span className="font-medium text-destructive">
                                                Not set - payments and receipts from this account will fail
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {canManageChartOfAccounts && (
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(account)}>
                                                Edit
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {accounts.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="py-6 text-center text-muted-foreground">
                                        No bank or cash accounts yet. Opening balances need these first.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <p className="max-w-2xl text-sm text-muted-foreground">
                    An account that has been used to move money is deactivated rather than removed, so a payment made in August can
                    still be named in December.
                </p>
            </div>
        </AppLayout>
    );
}
