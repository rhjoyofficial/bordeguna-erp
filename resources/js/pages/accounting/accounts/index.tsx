import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    normal_balance: 'debit' | 'credit';
    parent_id: number | null;
    is_postable: boolean;
    is_active: boolean;
    is_system: boolean;
    note: string | null;
}

interface AccountType {
    value: string;
    label: string;
    normal_balance: 'debit' | 'credit';
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounting', href: '/accounting/accounts' },
    { title: 'Chart of accounts', href: '/accounting/accounts' },
];

/**
 * Depth is derived from the parent chain rather than stored. A `depth` column
 * would be a second fact about the same relationship, and the two disagree the
 * first time somebody re-parents an account.
 */
function depthOf(account: Account, byId: Map<number, Account>): number {
    let depth = 0;
    let parent = account.parent_id === null ? undefined : byId.get(account.parent_id);

    while (parent && depth < 50) {
        depth += 1;
        parent = parent.parent_id === null ? undefined : byId.get(parent.parent_id);
    }

    return depth;
}

const EMPTY = { code: '', name: '', type: 'asset', parent_id: '', is_postable: true, is_active: true, note: '' };

export default function ChartOfAccounts({ accounts, types }: { accounts: Account[]; types: AccountType[] }) {
    const { canManageChartOfAccounts } = usePage<SharedData>().props;
    const [editing, setEditing] = useState<Account | null>(null);

    const { data, setData, post, put, errors, processing, reset, clearErrors } = useForm(EMPTY);

    const byId = useMemo(() => new Map(accounts.map((account) => [account.id, account])), [accounts]);

    // Only accounts of the same type can be a parent, because a child must match
    // its parent's type. Offering the rest and rejecting the choice afterwards
    // teaches the rule one failed save at a time.
    const parentOptions = useMemo(() => accounts.filter((a) => a.type === data.type && a.id !== editing?.id), [accounts, data.type, editing]);

    const startEdit = (account: Account) => {
        clearErrors();
        setEditing(account);
        setData({
            code: account.code,
            name: account.name,
            type: account.type,
            parent_id: account.parent_id === null ? '' : String(account.parent_id),
            is_postable: account.is_postable,
            is_active: account.is_active,
            note: account.note ?? '',
        });
    };

    const cancelEdit = () => {
        setEditing(null);
        clearErrors();
        reset();
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('accounting.accounts.update', editing.id), { onSuccess: cancelEdit });
            return;
        }

        post(route('accounting.accounts.store'), { onSuccess: () => reset() });
    };

    const destroy = (account: Account) => {
        router.delete(route('accounting.accounts.destroy', account.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chart of accounts" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Chart of accounts</h1>
                    <p className="text-sm text-muted-foreground">
                        One chart, shared by every business. Balances are kept separately per business and come from the ledger, never from
                        this screen.
                    </p>
                </div>

                {canManageChartOfAccounts && (
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-md border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <div className="grid gap-2">
                            <Label htmlFor="code">Code</Label>
                            <Input id="code" value={data.code} onChange={(e) => setData('code', e.target.value)} className="w-24" placeholder="6320" />
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-64" placeholder="Security services" />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="type">Type</Label>
                            <select
                                id="type"
                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                value={data.type}
                                onChange={(e) => setData({ ...data, type: e.target.value, parent_id: '' })}
                            >
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="parent_id">Sits under</Label>
                            <select
                                id="parent_id"
                                className="h-10 rounded-md border border-input bg-background px-3 text-sm"
                                value={data.parent_id}
                                onChange={(e) => setData('parent_id', e.target.value)}
                            >
                                <option value="">Top level</option>
                                {parentOptions.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.code} - {account.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.parent_id} />
                        </div>
                        <label className="flex h-10 items-center gap-2 text-sm">
                            <input type="checkbox" checked={data.is_postable} onChange={(e) => setData('is_postable', e.target.checked)} />
                            Can be posted to
                        </label>
                        {editing && (
                            <label className="flex h-10 items-center gap-2 text-sm">
                                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                                Active
                            </label>
                        )}
                        <Button disabled={processing}>{editing ? `Save ${editing.code}` : 'Add account'}</Button>
                        {editing && (
                            <Button type="button" variant="ghost" onClick={cancelEdit}>
                                Cancel
                            </Button>
                        )}
                    </form>
                )}

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-sidebar-border/70 text-left text-muted-foreground dark:border-sidebar-border">
                            <th className="py-2">Code</th>
                            <th className="py-2">Account</th>
                            <th className="py-2">Type</th>
                            <th className="py-2">Normal balance</th>
                            <th className="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {accounts.map((account) => {
                            const depth = depthOf(account, byId);

                            return (
                                <tr key={account.id} className="border-b border-sidebar-border/70 dark:border-sidebar-border">
                                    <td className="py-2 font-mono text-muted-foreground">{account.code}</td>
                                    <td className="py-2" style={{ paddingLeft: `${depth * 1.5}rem` }}>
                                        <span className={account.is_postable ? '' : 'font-semibold'}>{account.name}</span>
                                        {!account.is_postable && (
                                            <span className="ml-2 text-xs text-muted-foreground">group</span>
                                        )}
                                        {!account.is_active && (
                                            <Badge variant="destructive" className="ml-2">
                                                Inactive
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="py-2 text-muted-foreground">{account.type_label}</td>
                                    <td className="py-2 text-muted-foreground">{account.normal_balance === 'debit' ? 'Debit' : 'Credit'}</td>
                                    <td className="py-2 text-right">
                                        {canManageChartOfAccounts && (
                                            <>
                                                <Button variant="ghost" size="sm" onClick={() => startEdit(account)}>
                                                    Edit
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={account.is_system}
                                                    onClick={() => destroy(account)}
                                                    title={
                                                        account.is_system
                                                            ? 'The system posts to this account. Deactivate it instead of deleting it.'
                                                            : undefined
                                                    }
                                                >
                                                    Delete
                                                </Button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                        {accounts.length === 0 && (
                            <tr>
                                <td colSpan={5} className="py-4 text-center text-muted-foreground">
                                    No accounts yet.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
