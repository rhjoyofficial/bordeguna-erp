import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Option {
    value: string;
    label: string;
}

interface EditingAccount {
    id: number;
    kind: string;
    name: string;
    provider: string | null;
    identifier: string | null;
    url: string | null;
    owner_employee_id: number | null;
    two_factor_employee_id: number | null;
    renews_on: string | null;
    renewal_cost: string | null;
    renewal_money_account_id: number | null;
    recovery_email: string | null;
    recovery_phone: string | null;
    status: string;
    note: string | null;
}

const textareaClass = 'border-input bg-background min-h-20 w-full rounded-md border px-3 py-2 text-sm';

/**
 * Record an account the business owns, or correct what the hub says about it
 * (slice 4). Its logins are added on the account's own page, never here.
 */
export default function CompanyAccountForm({
    account,
    kinds,
    statuses,
    employees,
    money_accounts,
}: {
    account: EditingAccount | null;
    kinds: Option[];
    statuses: Option[];
    employees: { id: number; label: string }[];
    money_accounts: { id: number; name: string }[];
}) {
    const editing = account !== null;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounts hub', href: '/assets/accounts' },
        editing ? { title: account.name, href: `/assets/accounts/${account.id}` } : { title: 'New account', href: '/assets/accounts/create' },
    ];

    const { data, setData, post, put, errors, processing } = useForm({
        kind: account?.kind ?? 'email',
        name: account?.name ?? '',
        provider: account?.provider ?? '',
        identifier: account?.identifier ?? '',
        url: account?.url ?? '',
        owner_employee_id: account?.owner_employee_id ? String(account.owner_employee_id) : '',
        two_factor_employee_id: account?.two_factor_employee_id ? String(account.two_factor_employee_id) : '',
        renews_on: account?.renews_on ?? '',
        renewal_cost: account?.renewal_cost ?? '',
        renewal_money_account_id: account?.renewal_money_account_id ? String(account.renewal_money_account_id) : '',
        recovery_email: account?.recovery_email ?? '',
        recovery_phone: account?.recovery_phone ?? '',
        status: account?.status ?? 'active',
        note: account?.note ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (editing) {
            put(`/assets/accounts/${account.id}`);
        } else {
            post('/assets/accounts');
        }
    };

    const people = [{ value: '', label: 'Nobody yet' }, ...employees.map((e) => ({ value: String(e.id), label: e.label }))];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={editing ? `Edit ${account.name}` : 'New account'} />
            <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">{editing ? `Edit ${account.name}` : 'New account'}</h1>
                    <p className="text-muted-foreground text-sm">Passwords are not entered here: add them as logins on the account's page.</p>
                </div>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label required>Kind</Label>
                        <SearchableSelect value={data.kind} onChange={(v) => setData('kind', v)} options={kinds} />
                        <InputError message={errors.kind} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="name" required>
                            Name
                        </Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Support mailbox"
                            aria-required
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="provider">Provider</Label>
                        <Input
                            id="provider"
                            value={data.provider}
                            onChange={(e) => setData('provider', e.target.value)}
                            placeholder="Google, Hostinger, Meta, Pathao"
                        />
                        <InputError message={errors.provider} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="identifier">Identifier</Label>
                        <Input
                            id="identifier"
                            value={data.identifier}
                            onChange={(e) => setData('identifier', e.target.value)}
                            placeholder="The address, domain, page id or account number"
                        />
                        <InputError message={errors.identifier} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="url">URL</Label>
                        <Input id="url" value={data.url} onChange={(e) => setData('url', e.target.value)} placeholder="https://..." />
                        <InputError message={errors.url} />
                    </div>
                </section>

                <section className="grid gap-4">
                    <h2 className="font-medium">People</h2>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label>Owner (responsible)</Label>
                            <SearchableSelect value={data.owner_employee_id} onChange={(v) => setData('owner_employee_id', v)} options={people} />
                            <InputError message={errors.owner_employee_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Who holds the 2FA device</Label>
                            <SearchableSelect
                                value={data.two_factor_employee_id}
                                onChange={(v) => setData('two_factor_employee_id', v)}
                                options={people}
                            />
                            <InputError message={errors.two_factor_employee_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="recovery_email">Recovery email</Label>
                            <Input
                                id="recovery_email"
                                type="email"
                                value={data.recovery_email}
                                onChange={(e) => setData('recovery_email', e.target.value)}
                            />
                            <InputError message={errors.recovery_email} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="recovery_phone">Recovery phone</Label>
                            <Input
                                id="recovery_phone"
                                value={data.recovery_phone}
                                onChange={(e) => setData('recovery_phone', e.target.value)}
                                placeholder="01XXXXXXXXX"
                            />
                            <InputError message={errors.recovery_phone} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Who may use the account is granted on its page, with a date, so it can be revoked with one too.
                    </p>
                </section>

                <section className="grid gap-4">
                    <h2 className="font-medium">Renewal</h2>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="renews_on">Renews on</Label>
                            <Input id="renews_on" type="date" value={data.renews_on} onChange={(e) => setData('renews_on', e.target.value)} />
                            <InputError message={errors.renews_on} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="renewal_cost">Cost (Tk)</Label>
                            <Input
                                id="renewal_cost"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.renewal_cost}
                                onChange={(e) => setData('renewal_cost', e.target.value)}
                            />
                            <InputError message={errors.renewal_cost} />
                        </div>
                        <div className="grid gap-2">
                            <Label>Paid from</Label>
                            <SearchableSelect
                                value={data.renewal_money_account_id}
                                onChange={(v) => setData('renewal_money_account_id', v)}
                                options={[{ value: '', label: 'Not set' }, ...money_accounts.map((m) => ({ value: String(m.id), label: m.name }))]}
                            />
                            <InputError message={errors.renewal_money_account_id} />
                        </div>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        The owner and the hub's managers are reminded 30 days before, 7 days before, and once overdue.
                    </p>
                </section>

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label required>Status</Label>
                        <SearchableSelect value={data.status} onChange={(v) => setData('status', v)} options={statuses} />
                        <InputError message={errors.status} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="note">Note</Label>
                        <textarea id="note" className={textareaClass} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button disabled={processing}>{editing ? 'Save' : 'Record account'}</Button>
                    <Button type="button" variant="ghost" asChild>
                        <Link href={editing ? `/assets/accounts/${account.id}` : '/assets/accounts'}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
