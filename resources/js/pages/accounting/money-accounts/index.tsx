import InputError from '@/components/input-error';
import { SearchSelect } from '@/components/search-select';
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
    courierLedgerAccountId,
    gatewayLedgerAccountId,
}: {
    accounts: MoneyAccount[];
    types: Option[];
    ledgerAccounts: Option[];
    unmappedCount: number;
    courierLedgerAccountId: number | null;
    gatewayLedgerAccountId: number | null;
}) {
    const { canManageMoneyAccounts } = usePage<SharedData>().props;
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
    const isCourier = data.type === 'courier';
    const isGateway = data.type === 'gateway';

    // ⚠ A courier's account can only report under 1145 Receivable from Couriers,
    // and a payment gateway's under 1146, so choosing the kind chooses the ledger
    // account too.
    const chooseType = (type: string) => {
        const forced = type === 'courier' ? courierLedgerAccountId : type === 'gateway' ? gatewayLedgerAccountId : null;

        setData((current) => ({
            ...current,
            type,
            account_id: forced !== null ? String(forced) : current.account_id,
        }));
    };

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
                    <div className="border-destructive/50 bg-destructive/10 rounded-md border p-3 text-sm">
                        <p className="font-medium">
                            {unmappedCount} account{unmappedCount === 1 ? '' : 's'} {unmappedCount === 1 ? 'has' : 'have'} no ledger account.
                        </p>
                        <p className="text-muted-foreground">
                            Payments and customer receipts using {unmappedCount === 1 ? 'it' : 'them'} will be refused when somebody tries to record
                            one. Set the ledger account below to finish the setup.
                        </p>
                    </div>
                )}
                <div>
                    <h1 className="text-lg font-semibold">Bank &amp; cash accounts</h1>
                </div>

                {canManageMoneyAccounts && (
                    <form
                        onSubmit={submit}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-end gap-3 rounded-md border p-4"
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="name" required>
                                Name
                            </Label>
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
                            <Label htmlFor="type" required>
                                Kind
                            </Label>
                            <SearchSelect fit id="type" value={data.type} onChange={(e) => chooseType(e.target.value)}>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </SearchSelect>
                            <InputError message={errors.type} />
                        </div>

                        {!isCash && (
                            <div className="grid gap-2">
                                <Label htmlFor="account_number">
                                    {isMfs ? 'Wallet number' : isCourier || isGateway ? 'Merchant account' : 'Account number'}
                                </Label>
                                <Input
                                    id="account_number"
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
                                    className="w-52"
                                />
                                <InputError message={errors.account_number} />
                            </div>
                        )}

                        {(isMfs || isCourier || isGateway) && (
                            <div className="grid gap-2">
                                <Label htmlFor="provider">{isCourier ? 'Courier' : isGateway ? 'Gateway' : 'Provider'}</Label>
                                <Input
                                    id="provider"
                                    value={data.provider}
                                    onChange={(e) => setData('provider', e.target.value)}
                                    className="w-36"
                                    placeholder={isCourier ? 'Pathao' : isGateway ? 'SSLCommerz' : 'bKash'}
                                />
                            </div>
                        )}

                        {data.type === 'bank' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="bank_name">Bank</Label>
                                    <Input
                                        id="bank_name"
                                        value={data.bank_name}
                                        onChange={(e) => setData('bank_name', e.target.value)}
                                        className="w-44"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="branch">Branch</Label>
                                    <Input id="branch" value={data.branch} onChange={(e) => setData('branch', e.target.value)} className="w-40" />
                                </div>
                            </>
                        )}

                        {/*
                          ⚠⚠ No ledger picker (21 Sep 2026). A cash box, bank account or
                          wallet gets a ledger head of its own, made by the system when it
                          is saved, so its balance is its own line on every report. A
                          courier's or gateway's always reports under 1145 / 1146.
                        */}
                        <div className="grid gap-2">
                            <span className="text-sm font-medium">Ledger</span>
                            <p className="text-muted-foreground flex h-10 max-w-72 items-center text-sm">
                                {isCourier || isGateway
                                    ? (ledgerAccounts.find((account) => String(account.id) === data.account_id)?.label ??
                                      (isCourier ? '1145 - Receivable from Couriers' : '1146 - Receivable from Payment Gateways'))
                                    : (editing?.ledger_account ?? 'Its own, created when you save')}
                            </p>
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
                            <tr className="border-sidebar-border/70 text-muted-foreground dark:border-sidebar-border border-b text-left">
                                <th className="py-2">Name</th>
                                <th className="py-2">Kind</th>
                                <th className="py-2">Details</th>
                                <th className="py-2">Reports under</th>
                                <th className="py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.map((account) => (
                                <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-b">
                                    <td className="py-2">
                                        {account.name}
                                        {!account.is_active && (
                                            <Badge variant="destructive" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground py-2">{account.type_label}</td>
                                    <td className="text-muted-foreground py-2">
                                        {[account.bank_name, account.branch, account.provider, account.account_number].filter(Boolean).join(' · ')}
                                    </td>
                                    <td className="text-muted-foreground py-2">
                                        {account.ledger_account ?? (
                                            /* ⚠⚠ Not a cosmetic gap. A payment from an unmapped
                                               account fails at the till with MappingNotFound, long
                                               after whoever created it has left this screen. */
                                            <span className="text-destructive font-medium">
                                                Not set - payments and receipts from this account will fail
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">
                                        {canManageMoneyAccounts && (
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(account)}>
                                                Edit
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {accounts.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                        No bank or cash accounts yet. Opening balances need these first.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
