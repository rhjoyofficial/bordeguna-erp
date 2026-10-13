import { LoginsCard, type LoginsCardData } from '@/components/assets/logins-card';
import { RenewalChip } from '@/components/assets/renewal-chip';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Account {
    id: number;
    kind: string;
    kind_label: string;
    name: string;
    provider: string | null;
    identifier: string | null;
    url: string | null;
    registered_as: string | null;
    responsible: string | null;
    two_factor_method: string;
    two_factor_method_label: string;
    two_factor_holder: string | null;
    renews_on: string | null;
    renewal_cycle_label: string;
    renewal_stage: string | null;
    renewal_tone: 'overdue' | 'soon' | null;
    /** Absent for a reader without assets.accounts.costs.view. */
    renewal_cost?: string | null;
    paid_from?: string | null;
    recovery_email: string | null;
    recovery_phone: string | null;
    status: string;
    status_label: string;
    note: string | null;
    recorded_by: string | null;
    recorded_at: string | null;
    home_business: string | null;
    /** The other businesses of the group this account also serves. */
    serves: string[];
}

interface AccessRow {
    id: number;
    employee: string | null;
    employee_code: string | null;
    role: string;
    role_label: string;
    granted_on: string;
    revoked_on: string | null;
    granted_by: string | null;
    note: string | null;
}

interface HistoryEntry {
    id: number;
    when: string | null;
    who: string | null;
    what: string;
    changes: { field: string; from: string | null; to: string | null }[];
}

interface Option {
    value: string;
    label: string;
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

function GrantDialog({
    account,
    grantable,
    roles,
    today,
    open,
    onClose,
}: {
    account: Account;
    grantable: { id: number; label: string }[];
    roles: Option[];
    today: string;
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, errors, processing, reset } = useForm({ employee_id: '', role: 'user', granted_on: today, note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/accounts/${account.id}/access`, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Grant access to {account.name}</DialogTitle>
                    <DialogDescription>Recorded with a date, so it can be revoked with one.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label required>Employee</Label>
                        <SearchableSelect
                            value={data.employee_id}
                            onChange={(v) => setData('employee_id', v)}
                            options={grantable.map((e) => ({ value: String(e.id), label: e.label }))}
                            placeholder="Choose an employee"
                        />
                        <InputError message={errors.employee_id} />
                    </div>
                    <div className="grid gap-1">
                        <Label required>Role</Label>
                        <SearchableSelect value={data.role} onChange={(v) => setData('role', v)} options={roles} />
                        <InputError message={errors.role} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="granted_on" required>
                            Granted on
                        </Label>
                        <Input
                            id="granted_on"
                            type="date"
                            value={data.granted_on}
                            onChange={(e) => setData('granted_on', e.target.value)}
                            aria-required
                        />
                        <InputError message={errors.granted_on} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="grant_note">Note</Label>
                        <Input id="grant_note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !data.employee_id}>Grant</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RevokeDialog({ account, row, today, onClose }: { account: Account; row: AccessRow | null; today: string; onClose: () => void }) {
    const { data, setData, post, errors, processing } = useForm({ revoked_on: today });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (!row) {
            return;
        }

        post(`/assets/accounts/${account.id}/access/${row.id}/revoke`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={row !== null} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revoke {row?.employee}'s access?</DialogTitle>
                    <DialogDescription>The row stays, with the date it ended. Change the password at the provider too.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="revoked_on" required>
                            Revoked on
                        </Label>
                        <Input
                            id="revoked_on"
                            type="date"
                            value={data.revoked_on}
                            onChange={(e) => setData('revoked_on', e.target.value)}
                            aria-required
                        />
                        <InputError message={errors.revoked_on ?? (errors as Record<string, string | undefined>).access} />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" disabled={processing}>
                            Revoke
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RenewedDialog({ account, open, canViewCosts, onClose }: { account: Account; open: boolean; canViewCosts: boolean; onClose: () => void }) {
    const { data, setData, post, errors, processing } = useForm({ next_renews_on: '', renewal_cost: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/accounts/${account.id}/renewed`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Mark {account.name} renewed</DialogTitle>
                    <DialogDescription>Say when it renews next. The reminders start again from that date.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="next_renews_on" required>
                            Next renewal
                        </Label>
                        <Input
                            id="next_renews_on"
                            type="date"
                            value={data.next_renews_on}
                            onChange={(e) => setData('next_renews_on', e.target.value)}
                            aria-required
                            required
                        />
                        <InputError message={errors.next_renews_on} />
                    </div>
                    {/* ⚠ The cost is not every manager's to see or set: absent without costs.view. */}
                    {canViewCosts && (
                        <div className="grid gap-1">
                            <Label htmlFor="renewed_cost">Cost paid (Tk), if it changed</Label>
                            <Input
                                id="renewed_cost"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.renewal_cost}
                                onChange={(e) => setData('renewal_cost', e.target.value)}
                                placeholder={account.renewal_cost ?? ''}
                            />
                            <InputError message={errors.renewal_cost} />
                        </div>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button disabled={processing || !data.next_renews_on}>Mark renewed</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * One account the business owns (slice 4): its facts, who has access, its
 * logins, its renewal, and what has changed about it.
 */
export default function CompanyAccountShow({
    account,
    access,
    grantable,
    roles,
    credentials,
    history,
    is_home,
    can_manage,
    can_manage_access,
    can_view_costs,
    today,
}: {
    account: Account;
    access: AccessRow[];
    grantable: { id: number; label: string }[];
    roles: Option[];
    credentials: LoginsCardData | null;
    history: HistoryEntry[];
    is_home: boolean;
    can_manage: boolean;
    can_manage_access: boolean;
    can_view_costs: boolean;
    today: string;
}) {
    const [granting, setGranting] = useState(false);
    const [revoking, setRevoking] = useState<AccessRow | null>(null);
    const [renewing, setRenewing] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Accounts hub', href: '/assets/accounts' },
        { title: account.name, href: `/assets/accounts/${account.id}` },
    ];

    const current = access.filter((row) => row.revoked_on === null);
    const past = access.filter((row) => row.revoked_on !== null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={account.name} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-lg font-semibold break-words">{account.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {account.kind_label}
                            {account.provider && ` - ${account.provider}`}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant={account.status === 'active' ? 'secondary' : account.status === 'closed' ? 'destructive' : 'outline'}>
                                {account.status_label}
                            </Badge>
                            {!is_home && <Badge variant="outline">Shared by {account.home_business}</Badge>}
                            {is_home && account.serves.length > 0 && <Badge variant="outline">Also serves {account.serves.join(', ')}</Badge>}
                        </div>
                        {!is_home && (
                            <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                                Recorded by {account.home_business}. It is read-only here, and its logins can be revealed only there.
                            </p>
                        )}
                    </div>
                    {can_manage && (
                        <div className="flex flex-wrap gap-2">
                            <Button asChild>
                                <Link href={`/assets/accounts/${account.id}/edit`}>Edit</Link>
                            </Button>
                        </div>
                    )}
                </div>

                <dl className="grid content-start gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Fact label="Identifier">{account.identifier ?? '-'}</Fact>
                    <Fact label="URL">
                        {account.url ? (
                            <a href={account.url} target="_blank" rel="noreferrer" className="underline underline-offset-2">
                                {account.url}
                            </a>
                        ) : (
                            '-'
                        )}
                    </Fact>
                    <Fact label="Responsible (inside the company)">
                        {account.responsible ?? <span className="text-amber-600 dark:text-amber-500">Nobody yet</span>}
                    </Fact>
                    <Fact label="Registered at the provider as">
                        {account.registered_as ?? <span className="text-muted-foreground">The company's own</span>}
                    </Fact>
                    <Fact label="Second factor">
                        {account.two_factor_method_label}
                        {account.two_factor_holder && ` - kept by ${account.two_factor_holder}`}
                    </Fact>
                    <Fact label="Recovery email">{account.recovery_email ?? '-'}</Fact>
                    <Fact label="Recovery phone">{account.recovery_phone ?? '-'}</Fact>
                    <Fact label="Recorded">
                        {account.recorded_at}
                        {account.recorded_by && ` by ${account.recorded_by}`}
                    </Fact>
                    {account.note && (
                        <div className="sm:col-span-2 lg:col-span-3">
                            <Fact label="Note">
                                <span className="whitespace-pre-line">{account.note}</span>
                            </Fact>
                        </div>
                    )}
                </dl>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">Renewal</h2>
                        {can_manage && account.status !== 'closed' && (
                            <Button size="sm" variant="outline" onClick={() => setRenewing(true)}>
                                Mark renewed
                            </Button>
                        )}
                    </div>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Fact label="Renews on">
                            <RenewalChip renews_on={account.renews_on} tone={account.renewal_tone} />
                            <span className="text-muted-foreground ml-2 text-xs">{account.renewal_cycle_label}</span>
                        </Fact>
                        {can_view_costs && <Fact label="Cost">{account.renewal_cost ? `Tk ${account.renewal_cost}` : '-'}</Fact>}
                        {can_view_costs && <Fact label="Paid from">{account.paid_from ?? '-'}</Fact>}
                    </dl>
                </section>

                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">Access</h2>
                        {can_manage_access && (
                            <Button size="sm" variant="outline" onClick={() => setGranting(true)} disabled={grantable.length === 0}>
                                Grant access
                            </Button>
                        )}
                    </div>
                    {current.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nobody has access on record.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {current.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {row.employee} <span className="text-muted-foreground font-mono text-xs">{row.employee_code}</span>
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {row.role_label} since {row.granted_on}
                                            {row.granted_by && ` (by ${row.granted_by})`}
                                            {row.note && ` - ${row.note}`}
                                        </p>
                                    </div>
                                    {can_manage_access && (
                                        <Button size="sm" variant="ghost" onClick={() => setRevoking(row)}>
                                            Revoke
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {past.length > 0 && (
                        <details className="text-sm">
                            <summary className="text-muted-foreground cursor-pointer">{past.length} revoked</summary>
                            <ul className="text-muted-foreground mt-2 grid gap-1 text-xs">
                                {past.map((row) => (
                                    <li key={row.id}>
                                        {row.employee} ({row.employee_code}): {row.role_label} {row.granted_on} to {row.revoked_on}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>

                <LoginsCard data={credentials} storeUrl={`/assets/accounts/${account.id}/credentials`} />

                <section className="grid gap-2">
                    <h2 className="font-medium">What has changed</h2>
                    {history.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing yet.</p>
                    ) : (
                        <ul className="grid gap-2 text-sm">
                            {history.map((entry) => (
                                <li key={entry.id} className="grid gap-0.5">
                                    <p>
                                        <span className="text-muted-foreground">{entry.when}</span> {entry.who ?? 'The system'}: {entry.what}
                                    </p>
                                    {entry.changes.map((change) => (
                                        <p key={change.field} className="text-muted-foreground pl-3 text-xs">
                                            {change.field}: {change.from ?? '-'} to {change.to ?? '-'}
                                        </p>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {can_manage_access && (
                <>
                    <GrantDialog
                        account={account}
                        grantable={grantable}
                        roles={roles}
                        today={today}
                        open={granting}
                        onClose={() => setGranting(false)}
                    />
                    <RevokeDialog account={account} row={revoking} today={today} onClose={() => setRevoking(null)} />
                </>
            )}
            {can_manage && <RenewedDialog account={account} open={renewing} canViewCosts={can_view_costs} onClose={() => setRenewing(false)} />}
        </AppLayout>
    );
}
