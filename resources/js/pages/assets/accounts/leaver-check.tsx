import { Hint, Pill, Stat } from '@/components/assets/assets-ui';
import { SearchableSelect } from '@/components/searchable-select';
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
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Accounts & logins', href: '/assets/accounts' },
    { title: 'Leaver check', href: '/assets/accounts/leaver-check' },
];

function Section({ title, count, hint, children }: { title: string; count: number; hint: string; children: React.ReactNode }) {
    return (
        <section className="bg-card overflow-hidden rounded-xl border">
            <div className="flex items-center justify-between gap-2 px-4 pt-3.5 pb-2.5">
                <h2 className="text-[15px] font-semibold">
                    {title} <span className="text-muted-foreground text-sm font-normal tabular-nums">({count})</span>
                </h2>
                <Hint text={hint} />
            </div>
            {count === 0 ? <p className="text-muted-foreground border-t px-4 py-3 text-sm">Nothing.</p> : <ul>{children}</ul>}
        </section>
    );
}

function Row({ children, action }: { children: React.ReactNode; action: React.ReactNode }) {
    return (
        <li className="flex items-center gap-3 border-t px-4 py-2.5 text-[13px]">
            <div className="min-w-0 flex-1">{children}</div>
            {action}
        </li>
    );
}

function AccountLine({ row, action, actionLabel }: { row: AccountRow; action: string | null; actionLabel: string }) {
    return (
        <Row
            action={
                action ? (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={action}>{actionLabel}</Link>
                    </Button>
                ) : (
                    <span className="text-muted-foreground text-xs">Change it in {row.home}</span>
                )
            }
        >
            <Link href={row.url} className="font-medium underline-offset-2 hover:underline">
                {row.name}
            </Link>
            <p className="text-muted-foreground text-xs">
                {row.kind_label}
                {row.provider && ` · ${row.provider}`}
                {row.role_label && ` · ${row.role_label} since ${row.since}`}
                {row.method_label && ` · ${row.method_label}`}
                {row.home && ` · shared by ${row.home}`}
            </p>
        </Row>
    );
}

/**
 * The leaver check (13 Oct 2026 review, ruling 10): one employee, everything
 * they hold, run, use or guard, each with the link to the screen that acts.
 * Nothing is changed here.
 *
 * ⭐ Redesigned 15 Oct 2026 as the second tab of Accounts & logins: the
 * employee picker and the five counts in one bar, the five sections as cards
 * in a grid, the same action buttons.
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
            <div className="flex flex-col gap-4 p-4">
                <p className="text-muted-foreground max-w-3xl text-sm">
                    When someone leaves, or changes role: what they still hold, what they are responsible for, what they can sign in to, which second
                    factors they keep, and which passwords they could read. Act on each from its own screen.
                </p>

                <section className="bg-card flex flex-wrap items-center gap-x-6 gap-y-3 rounded-xl border px-5 py-4">
                    <label className="grid min-w-64 gap-1">
                        <span className="text-muted-foreground text-xs">Employee</span>
                        <SearchableSelect
                            value={employee ? String(employee.id) : ''}
                            onChange={pick}
                            options={[{ value: '', label: 'Choose an employee' }, ...employees.map((e) => ({ value: String(e.id), label: e.label }))]}
                            placeholder="Choose an employee"
                        />
                    </label>
                    {employee && sections && (
                        <>
                            <div className="flex items-center gap-2">
                                <span className="text-muted-foreground font-mono text-xs">{employee.code}</span>
                                {employee.status === 'separated' && <Pill tone="muted">Left</Pill>}
                                <Pill tone={total === 0 ? 'ok' : 'warning'}>{total === 0 ? 'Nothing outstanding' : `${total} to look at`}</Pill>
                            </div>
                            <div className="ml-auto flex flex-wrap gap-x-6 gap-y-2">
                                <Stat size="sm" label="Holds" value={sections.things.length} />
                                <Stat size="sm" label="Responsible for" value={sections.responsible.length} />
                                <Stat size="sm" label="Can sign in" value={sections.access.length} />
                                <Stat size="sm" label="Second factors" value={sections.two_factor.length} />
                                <Stat size="sm" label="Logins readable" value={sections.logins.length} />
                            </div>
                        </>
                    )}
                </section>

                {employee && sections && (
                    <>
                        <div className="grid gap-4 md:grid-cols-2">
                            <Section title="Things they hold" count={sections.things.length} hint="Return each with a hand-over.">
                                {sections.things.map((thing) => (
                                    <Row
                                        key={thing.id}
                                        action={
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={thing.return_url}>Return</Link>
                                            </Button>
                                        }
                                    >
                                        <Link href={`/assets/items/${thing.id}`} className="underline-offset-2 hover:underline">
                                            <span className="text-muted-foreground font-mono text-xs">{thing.tag}</span>{' '}
                                            <span className="font-medium">{thing.name}</span>
                                        </Link>
                                        <p className="text-muted-foreground text-xs">
                                            {thing.quantity > 1 && `${thing.quantity} held · `}
                                            {thing.status}
                                        </p>
                                    </Row>
                                ))}
                            </Section>

                            <Section
                                title="Accounts they are responsible for"
                                count={sections.responsible.length}
                                hint="Name somebody else as responsible on the account's form."
                            >
                                {sections.responsible.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.edit_url} actionLabel="Change responsible" />
                                ))}
                            </Section>

                            <Section
                                title="Accounts they can sign in to"
                                count={sections.access.length}
                                hint="Revoke on the account's page, then change the password at the provider."
                            >
                                {sections.access.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.home ? null : row.url} actionLabel="Revoke" />
                                ))}
                            </Section>

                            <Section
                                title="Second factors they keep"
                                count={sections.two_factor.length}
                                hint="Move the phone or the authenticator to somebody else, then record the new custodian."
                            >
                                {sections.two_factor.map((row) => (
                                    <AccountLine key={row.id} row={row} action={row.edit_url} actionLabel="Change custodian" />
                                ))}
                            </Section>

                            <Section
                                title="Logins they could reveal"
                                count={sections.logins.length}
                                hint="Of the things they hold and the accounts they use. Replace each at the device or provider, then record it."
                            >
                                {sections.logins.map((login) => (
                                    <Row
                                        key={login.id}
                                        action={
                                            <Button size="sm" variant="outline" asChild>
                                                <Link href={login.owner_url}>Open</Link>
                                            </Button>
                                        }
                                    >
                                        <p className="font-medium">
                                            {login.label} <span className="text-muted-foreground text-xs font-normal">{login.kind_label}</span>
                                            {login.needs_review && (
                                                <Pill tone="warning" className="ml-2">
                                                    Needs review
                                                </Pill>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {login.owner}
                                            {login.last_rotated_at && ` · replaced ${login.last_rotated_at}`}
                                        </p>
                                    </Row>
                                ))}
                            </Section>
                        </div>
                        <p className="text-muted-foreground text-xs">Nothing changes on this screen. Each button opens the screen that acts.</p>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
