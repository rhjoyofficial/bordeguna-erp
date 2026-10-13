import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Thing {
    id: number;
    tag: string;
    name: string;
    quantity: number;
    status: string;
    return_url: string;
}

interface AccountRow {
    id: number;
    name: string;
    kind_label: string;
    provider: string | null;
    /** The home business when another business of the group shares it with us; then nothing can be changed here. */
    home: string | null;
    url: string;
    edit_url: string | null;
    role_label?: string;
    since?: string;
    method_label?: string;
}

interface LoginRow {
    id: number;
    label: string;
    kind_label: string;
    needs_review: boolean;
    last_rotated_at: string | null;
    owner: string;
    owner_url: string;
}

interface Sections {
    things: Thing[];
    responsible: AccountRow[];
    access: AccountRow[];
    two_factor: AccountRow[];
    logins: LoginRow[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Accounts hub', href: '/assets/accounts' },
    { title: 'Leaver check', href: '/assets/accounts/leaver-check' },
];

function Section({ title, count, hint, children }: { title: string; count: number; hint: string; children: React.ReactNode }) {
    return (
        <section className="grid gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-medium">
                    {title} <span className="text-muted-foreground text-sm tabular-nums">({count})</span>
                </h2>
                <p className="text-muted-foreground text-xs">{hint}</p>
            </div>
            {count === 0 ? <p className="text-muted-foreground rounded-lg border p-3 text-sm">Nothing.</p> : children}
        </section>
    );
}

function AccountLine({ row, action, actionLabel }: { row: AccountRow; action: string | null; actionLabel: string }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
            <div className="min-w-0">
                <Link href={row.url} className="font-medium underline-offset-2 hover:underline">
                    {row.name}
                </Link>
                <p className="text-muted-foreground text-xs">
                    {row.kind_label}
                    {row.provider && ` - ${row.provider}`}
                    {row.role_label && ` - ${row.role_label} since ${row.since}`}
                    {row.method_label && ` - ${row.method_label}`}
                    {row.home && ` - shared by ${row.home}`}
                </p>
            </div>
            {action ? (
                <Button size="sm" variant="outline" asChild>
                    <Link href={action}>{actionLabel}</Link>
                </Button>
            ) : (
                <span className="text-muted-foreground text-xs">Change it in {row.home}</span>
            )}
        </li>
    );
}

/**
 * The leaver check (13 Oct 2026 review, ruling 10): one employee, everything
 * they hold, run, use or guard, each with the link to the screen that acts.
 * Nothing is changed here.
 */
export default function LeaverCheck({
    employees,
    employee,
    sections,
}: {
    employees: { id: number; label: string }[];
    employee: { id: number; name: string; code: string; status: string } | null;
    sections: Sections | null;
}) {
    const pick = (value: string) => {
        router.get('/assets/accounts/leaver-check', value ? { employee: value } : {}, { preserveState: true, replace: true });
    };

    const total = sections
        ? sections.things.length + sections.responsible.length + sections.access.length + sections.two_factor.length + sections.logins.length
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leaver check" />
            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">Leaver check</h1>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        When someone leaves, or changes role: what they still hold, what they are responsible for, what they can sign in to, which
                        second factors they keep, and which passwords they could read. Act on each from its own screen.
                    </p>
                </div>

                <div className="grid max-w-md gap-2">
                    <SearchableSelect
                        value={employee ? String(employee.id) : ''}
                        onChange={pick}
                        options={[{ value: '', label: 'Choose an employee' }, ...employees.map((e) => ({ value: String(e.id), label: e.label }))]}
                        placeholder="Choose an employee"
                    />
                </div>

                {employee && sections && (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm">
                                <span className="font-medium">{employee.name}</span>{' '}
                                <span className="text-muted-foreground font-mono text-xs">{employee.code}</span>
                            </p>
                            {employee.status === 'separated' && <Badge variant="outline">Left</Badge>}
                            <Badge variant={total === 0 ? 'secondary' : 'outline'}>
                                {total === 0 ? 'Nothing outstanding' : `${total} to look at`}
                            </Badge>
                        </div>

                        <Section title="Things they hold" count={sections.things.length} hint="Return each with a hand-over.">
                            <ul className="grid gap-2">
                                {sections.things.map((thing) => (
                                    <li key={thing.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                        <div className="min-w-0">
                                            <Link href={`/assets/items/${thing.id}`} className="font-medium underline-offset-2 hover:underline">
                                                {thing.tag} {thing.name}
                                            </Link>
                                            <p className="text-muted-foreground text-xs">
                                                {thing.quantity > 1 && `${thing.quantity} held - `}
                                                {thing.status}
                                            </p>
                                        </div>
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={thing.return_url}>Return</Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </Section>

                        <Section
                            title="Accounts they are responsible for"
                            count={sections.responsible.length}
                            hint="Name somebody else as responsible on the account's form."
                        >
                            <ul className="grid gap-2">
                                {sections.responsible.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.edit_url} actionLabel="Change responsible" />
                                ))}
                            </ul>
                        </Section>

                        <Section
                            title="Accounts they can sign in to"
                            count={sections.access.length}
                            hint="Revoke on the account's page, then change the password at the provider."
                        >
                            <ul className="grid gap-2">
                                {sections.access.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.home ? null : row.url} actionLabel="Revoke" />
                                ))}
                            </ul>
                        </Section>

                        <Section
                            title="Second factors they keep"
                            count={sections.two_factor.length}
                            hint="Move the phone or the authenticator to somebody else, then record the new custodian."
                        >
                            <ul className="grid gap-2">
                                {sections.two_factor.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.edit_url} actionLabel="Change custodian" />
                                ))}
                            </ul>
                        </Section>

                        <Section
                            title="Logins they could reveal"
                            count={sections.logins.length}
                            hint="Of the things they hold and the accounts they use. Replace each at the device or provider, then record it."
                        >
                            <ul className="grid gap-2">
                                {sections.logins.map((login) => (
                                    <li key={login.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {login.label} <span className="text-muted-foreground text-xs font-normal">{login.kind_label}</span>
                                                {login.needs_review && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ml-2 border-amber-500 text-xs text-amber-700 dark:text-amber-400"
                                                    >
                                                        Needs review
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-muted-foreground text-xs">
                                                {login.owner}
                                                {login.last_rotated_at && ` - replaced ${login.last_rotated_at}`}
                                            </p>
                                        </div>
                                        <Button size="sm" variant="outline" asChild>
                                            <Link href={login.owner_url}>Open</Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
