import { CompactTable, daysWord, Meter, Panel, PermissionNote, Pill, Stat, type Tone } from '@/components/assets/assets-ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

interface Linked {
    count: number;
    url: string;
}

interface WhereRow {
    label: string;
    count: number;
    url: string;
    muted: boolean;
}

interface Things {
    count: number;
    pieces: number;
    statuses: { value: string; label: string; count: number; url: string }[];
    damaged_or_poor: Linked;
    idle: Linked;
    where: { people: WhereRow[]; places: WhereRow[]; categories: WhereRow[] };
    outside: {
        total: number;
        rows: { id: number; tag: string; name: string; with: string; expected_back_on: string | null; days_late: number | null; url: string }[];
    };
    maintenance: { due: number; open: number; ending: number; lines: { on: string; text: string; days: number; url: string }[] };
    counts: {
        open: { id: number; number: string; place: string; lines: number; marked: number; started_on: string | null; url: string } | null;
        last_closed: { id: number; number: string; place: string; lines: number; closed_on: string | null; gaps: number; url: string } | null;
        gaps: number;
    };
}

interface Accounts {
    on_record: number;
    renewing_soon: number;
    overdue: number;
    shared: number;
    by_kind: { value: string; label: string; count: number }[];
    soon: { id: number; name: string; kind_label: string; renews_on: string; tone: 'overdue' | 'soon' | null; url: string }[];
}

interface Books {
    cost: string;
    written_off: string;
    book_value: string;
    percent_left: number;
    monthly: string;
    to_write_down: number;
    last_run: string | null;
    assets: number;
}

interface Need {
    key: string;
    title: string;
    hint: string;
    count: number;
    tone: Tone;
    url: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Assets', href: '/assets/overview' }];

const STATUS_BAR: Record<string, string> = {
    in_use: 'bg-primary',
    in_store: 'bg-primary/40',
    under_repair: 'bg-amber-500',
    with_outside_party: 'bg-sky-500',
    missing: 'bg-destructive',
};

const NEED_BADGE: Record<Tone, string> = {
    danger: 'bg-destructive/10 text-destructive',
    warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300',
    info: 'bg-sky-500/15 text-sky-800 dark:text-sky-300',
    muted: 'bg-muted text-muted-foreground',
    ok: 'bg-primary/10 text-primary',
};

function longDate(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function shortDate(iso: string | null): string {
    if (!iso) return '-';

    return new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

/**
 * The Assets overview (module redesign, 15 Oct 2026): what we hold, what
 * needs somebody, where it all is, and the accounts and the books for those
 * who may see them. Each number opens the list behind it, filtered where the
 * list has the filter. A card the person may not see is simply absent.
 */
export default function AssetsOverview({
    today,
    can,
    needs,
    things,
    accounts,
    books,
}: {
    today: string;
    can: { things: boolean; accounts: boolean; books: boolean; manage_things: boolean };
    needs: Need[];
    things: Things | null;
    accounts: Accounts | null;
    books: Books | null;
}) {
    const [view, setView] = useState<'people' | 'places' | 'categories'>('people');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assets" />
            <div className="flex flex-col gap-5 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-muted-foreground text-xs">{longDate(today)}</p>
                        <h1 className="text-2xl font-semibold tracking-tight">Assets</h1>
                    </div>
                    {can.manage_things && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/counts">Start a stock-take</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/maintenance/create">Record work or a fault</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=issue">Hand to someone</Link>
                            </Button>
                            <Button size="sm" asChild>
                                <Link href="/assets/items?add=1">Add a thing</Link>
                            </Button>
                        </div>
                    )}
                </div>

                {things && <Holdings things={things} />}

                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                    {(things || accounts) && (
                        <Panel
                            title="Needs you"
                            aside={
                                <span className="text-muted-foreground text-xs">
                                    {needs.length === 0 ? 'Nothing waiting' : `${needs.length} ${needs.length === 1 ? 'item' : 'items'}`}
                                </span>
                            }
                            flush
                        >
                            {needs.length === 0 ? (
                                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing overdue, on the way, due or unsettled.</p>
                            ) : (
                                <ul>
                                    {needs.map((need) => (
                                        <li key={need.key}>
                                            <Link href={need.url} className="hover:bg-muted/50 flex items-center gap-3 border-t px-4 py-2.5">
                                                <span
                                                    className={cn(
                                                        'grid size-8 shrink-0 place-items-center rounded-lg text-[13px] font-semibold tabular-nums',
                                                        NEED_BADGE[need.tone],
                                                    )}
                                                >
                                                    {need.count}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-[13.5px] font-medium">{need.title}</span>
                                                    <span className="text-muted-foreground block text-xs">{need.hint}</span>
                                                </span>
                                                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}

                    {things && (
                        <Panel
                            title="Where it all is"
                            aside={
                                <div role="group" aria-label="Group by" className="bg-muted flex gap-0.5 rounded-lg p-0.5">
                                    {(
                                        [
                                            ['people', 'People'],
                                            ['places', 'Places'],
                                            ['categories', 'Categories'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={view === key}
                                            onClick={() => setView(key)}
                                            className={cn(
                                                'h-7 rounded-md px-2.5 text-xs transition-colors',
                                                view === key
                                                    ? 'bg-card text-foreground font-medium shadow-xs'
                                                    : 'text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            }
                        >
                            <WhereList rows={things.where[view]} view={view} />
                        </Panel>
                    )}

                    {things && (
                        <Panel
                            title="Out with outside parties"
                            aside={
                                <Link href="/assets/items?status=with_outside_party" className="text-primary text-xs hover:underline">
                                    All {things.outside.total}
                                </Link>
                            }
                            flush
                        >
                            {things.outside.rows.length === 0 ? (
                                <p className="text-muted-foreground px-4 pb-4 text-sm">Nothing is out.</p>
                            ) : (
                                <CompactTable minWidth="24rem">
                                    <thead className="text-muted-foreground">
                                        <tr>
                                            <th>Thing</th>
                                            <th>With</th>
                                            <th>Expected back</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {things.outside.rows.map((row) => (
                                            <tr key={row.id}>
                                                <td>
                                                    <Link href={row.url} className="font-medium hover:underline">
                                                        {row.name}
                                                    </Link>
                                                    <div className="text-muted-foreground font-mono text-xs">{row.tag}</div>
                                                </td>
                                                <td>{row.with}</td>
                                                <td className="whitespace-nowrap tabular-nums">
                                                    {row.expected_back_on === null ? (
                                                        <span className="text-muted-foreground">No date</span>
                                                    ) : row.days_late ? (
                                                        <>
                                                            <span className="text-destructive font-medium">{shortDate(row.expected_back_on)}</span>{' '}
                                                            <span className="text-muted-foreground text-xs">
                                                                {row.days_late} {row.days_late === 1 ? 'day' : 'days'} late
                                                            </span>
                                                        </>
                                                    ) : (
                                                        shortDate(row.expected_back_on)
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </CompactTable>
                            )}
                        </Panel>
                    )}

                    {things && (
                        <Panel
                            title="Maintenance"
                            aside={
                                <Link href="/assets/maintenance" className="text-primary text-xs hover:underline">
                                    Open maintenance
                                </Link>
                            }
                        >
                            <div className="mt-3 flex flex-wrap gap-7">
                                <Stat
                                    label="Due"
                                    value={things.maintenance.due}
                                    tone={things.maintenance.due > 0 ? 'warning' : 'ok'}
                                    href="/assets/maintenance"
                                />
                                <Stat label="Open jobs" value={things.maintenance.open} href="/assets/maintenance" />
                                <Stat
                                    label="Cover ending"
                                    value={things.maintenance.ending}
                                    hint="Warranty or service contract ending within 30 days."
                                    href="/assets/maintenance"
                                />
                            </div>
                            {things.maintenance.lines.length > 0 && (
                                <ul className="mt-3 border-t">
                                    {things.maintenance.lines.map((line, index) => (
                                        <li key={index} className="flex items-center gap-3 border-b py-2 text-[13px] last:border-b-0">
                                            <span className="text-muted-foreground w-14 shrink-0 tabular-nums">{shortDate(line.on)}</span>
                                            <Link href={line.url} className="min-w-0 flex-1 truncate hover:underline">
                                                {line.text}
                                            </Link>
                                            <span
                                                className={cn(
                                                    'shrink-0 text-xs',
                                                    line.days < 0 ? 'font-medium text-amber-700 dark:text-amber-400' : 'text-muted-foreground',
                                                )}
                                            >
                                                {daysWord(line.days)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}

                    {things && (
                        <Panel
                            title="Stock-takes"
                            aside={
                                <Link href="/assets/counts" className="text-primary text-xs hover:underline">
                                    All counts
                                </Link>
                            }
                        >
                            {things.counts.open ? (
                                <div className="mt-3">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <div>
                                            <Link href={things.counts.open.url} className="text-[13.5px] font-medium hover:underline">
                                                {things.counts.open.place}
                                            </Link>{' '}
                                            <span className="text-muted-foreground font-mono text-xs">{things.counts.open.number}</span>
                                        </div>
                                        <Pill tone="ok">Open</Pill>
                                    </div>
                                    <Meter
                                        className="mt-2 h-2"
                                        label={`${things.counts.open.marked} of ${things.counts.open.lines} marked`}
                                        segments={[
                                            {
                                                share: things.counts.open.lines ? (things.counts.open.marked / things.counts.open.lines) * 100 : 0,
                                                className: 'bg-primary',
                                            },
                                        ]}
                                    />
                                    <p className="text-muted-foreground mt-1.5 text-xs">
                                        <span className="tabular-nums">
                                            {things.counts.open.marked} of {things.counts.open.lines}
                                        </span>{' '}
                                        marked, {things.counts.open.lines - things.counts.open.marked} still to mark
                                        {things.counts.open.started_on && <> · started {shortDate(things.counts.open.started_on)}</>}
                                    </p>
                                </div>
                            ) : (
                                <p className="text-muted-foreground mt-3 text-sm">No count open.</p>
                            )}
                            {things.counts.last_closed && (
                                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t pt-3">
                                    <div>
                                        <Link href={things.counts.last_closed.url} className="text-[13.5px] font-medium hover:underline">
                                            {things.counts.last_closed.place}
                                        </Link>{' '}
                                        <span className="text-muted-foreground font-mono text-xs">{things.counts.last_closed.number}</span>
                                        <p className="text-muted-foreground text-xs">
                                            Closed {shortDate(things.counts.last_closed.closed_on)} · {things.counts.last_closed.lines} on the list
                                        </p>
                                    </div>
                                    {things.counts.gaps > 0 ? (
                                        <Link
                                            href="/assets/counts"
                                            className="text-[13px] font-medium text-amber-700 hover:underline dark:text-amber-400"
                                        >
                                            {things.counts.gaps} {things.counts.gaps === 1 ? 'gap' : 'gaps'} to settle
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground text-xs">No gaps open</span>
                                    )}
                                </div>
                            )}
                        </Panel>
                    )}

                    {accounts && (
                        <Panel
                            title="Company accounts"
                            aside={
                                <PermissionNote title="This card shows only to people who can view the accounts hub">
                                    Accounts hub viewers
                                </PermissionNote>
                            }
                        >
                            <div className="mt-3 flex flex-wrap gap-7">
                                <Stat label="On record" value={accounts.on_record} href="/assets/accounts" />
                                <Stat
                                    label="Renewing in 30 days"
                                    value={accounts.renewing_soon}
                                    tone={accounts.renewing_soon > 0 ? 'warning' : 'ok'}
                                    href="/assets/accounts?renewing=1"
                                />
                                <Stat
                                    label="Overdue"
                                    value={accounts.overdue}
                                    tone={accounts.overdue > 0 ? 'danger' : 'ok'}
                                    href="/assets/accounts?renewing=1"
                                />
                                {accounts.shared > 0 && (
                                    <Stat
                                        label="Shared by the group"
                                        value={accounts.shared}
                                        hint="Accounts another business of the group shares with this one. Readable here, changed only in the home business."
                                        href="/assets/accounts"
                                    />
                                )}
                            </div>
                            {accounts.soon.length > 0 && (
                                <ul className="mt-3 border-t">
                                    {accounts.soon.map((row) => (
                                        <li key={row.id} className="flex items-center gap-3 border-b py-2 text-[13px] last:border-b-0">
                                            <Link href={row.url} className="min-w-0 flex-1 truncate hover:underline">
                                                {row.name} <span className="text-muted-foreground text-xs">{row.kind_label}</span>
                                            </Link>
                                            <span
                                                className={cn(
                                                    'shrink-0 text-xs font-medium',
                                                    row.tone === 'overdue' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400',
                                                )}
                                            >
                                                {row.tone === 'overdue' ? 'overdue, ' : ''}
                                                {shortDate(row.renews_on)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {accounts.by_kind.length > 0 && (
                                <p className="text-muted-foreground mt-3 text-xs">
                                    {accounts.by_kind.map((kind, index) => (
                                        <span key={kind.value}>
                                            {index > 0 && ' · '}
                                            {kind.label} <span className="tabular-nums">{kind.count}</span>
                                        </span>
                                    ))}
                                </p>
                            )}
                        </Panel>
                    )}

                    {books && (
                        <Panel
                            title="Book value"
                            aside={
                                <PermissionNote title="This card shows only to readers of the financial register (Fixed assets)">
                                    Fixed assets readers
                                </PermissionNote>
                            }
                        >
                            <div className="mt-3 flex flex-wrap items-end justify-between gap-3">
                                <Stat label="Book value today, Tk" value={books.book_value} size="lg" href="/assets" />
                                <p className="text-muted-foreground text-right text-xs">
                                    Cost <span className="text-foreground tabular-nums">{books.cost}</span>
                                    <br />
                                    Written off <span className="text-foreground tabular-nums">{books.written_off}</span>
                                </p>
                            </div>
                            <Meter
                                className="mt-3 h-2"
                                label={`${books.percent_left} percent of cost still on the books`}
                                segments={[{ share: books.percent_left, className: 'bg-primary' }]}
                            />
                            <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                                <div>
                                    <p className="text-[13.5px] font-medium">
                                        {books.last_run ? `Depreciation, last run ${books.last_run}` : 'Depreciation not run yet'}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        <span className="tabular-nums">{books.monthly}</span> a month · {books.to_write_down}{' '}
                                        {books.to_write_down === 1 ? 'asset' : 'assets'} still to write down · {books.assets} on the register
                                    </p>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/assets/depreciation">Open depreciation</Link>
                                </Button>
                            </div>
                        </Panel>
                    )}
                </div>

                {!things && !accounts && !books && <p className="text-muted-foreground text-sm">Nothing here is yours to see.</p>}
            </div>
        </AppLayout>
    );
}

/** What we hold: the count, the status split as a bar, and the two shape counts. */
function Holdings({ things }: { things: Things }) {
    const total = things.statuses.reduce((sum, status) => sum + status.count, 0);

    return (
        <section className="bg-card flex flex-wrap items-center gap-6 rounded-xl border p-4">
            <Stat label="Things we keep" value={things.count} size="lg" href="/assets/items" />
            <p className="text-muted-foreground -ml-4 self-end pb-1 text-xs">
                <span className="tabular-nums">{things.pieces}</span> pieces
            </p>
            <div className="min-w-64 flex-1 basis-80">
                <Meter
                    label={things.statuses.map((status) => `${status.count} ${status.label.toLowerCase()}`).join(', ')}
                    segments={things.statuses.map((status) => ({
                        share: total ? (status.count / total) * 100 : 0,
                        className: STATUS_BAR[status.value] ?? 'bg-muted-foreground',
                    }))}
                />
                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1.5">
                    {things.statuses.map((status) => (
                        <Stat
                            key={status.value}
                            size="sm"
                            href={status.url}
                            tone={
                                status.value === 'missing' && status.count > 0
                                    ? 'danger'
                                    : status.value === 'under_repair' && status.count > 0
                                      ? 'warning'
                                      : 'ok'
                            }
                            label={
                                <>
                                    <span aria-hidden="true" className={cn('inline-block size-2 rounded-full', STATUS_BAR[status.value])} />
                                    {status.label}
                                </>
                            }
                            value={status.count}
                        />
                    ))}
                </div>
            </div>
            <div className="flex gap-6 border-l pl-6">
                <Stat
                    size="sm"
                    label="Damaged or poor"
                    value={things.damaged_or_poor.count}
                    tone={things.damaged_or_poor.count > 0 ? 'danger' : 'ok'}
                    href={things.damaged_or_poor.url}
                />
                <Stat
                    size="sm"
                    label="Idle"
                    value={things.idle.count}
                    href={things.idle.url}
                    hint="In the register but nobody holds it and it is not on its way anywhere. Worth lending out before buying another."
                />
            </div>
        </section>
    );
}

function WhereList({ rows, view }: { rows: WhereRow[]; view: 'people' | 'places' | 'categories' }) {
    const caption = { people: 'Things held by each person', places: 'Things at each office and warehouse', categories: 'Things in each category' }[
        view
    ];
    const max = Math.max(1, ...rows.map((row) => row.count));

    return (
        <>
            <p className="text-muted-foreground mt-2 text-xs">{caption}</p>
            {rows.length === 0 ? (
                <p className="text-muted-foreground mt-3 text-sm">Nothing yet.</p>
            ) : (
                <ul className="mt-3 grid gap-3">
                    {rows.map((row) => (
                        <li key={row.label}>
                            <Link href={row.url} className="block">
                                <div className="flex justify-between text-[13px]">
                                    <span className={cn(row.muted && 'text-muted-foreground')}>{row.label}</span>
                                    <span className="font-semibold tabular-nums">{row.count}</span>
                                </div>
                                <Meter
                                    className="mt-1 h-1.5"
                                    label={`${row.count}`}
                                    segments={[{ share: (row.count / max) * 100, className: row.muted ? 'bg-primary/40' : 'bg-primary' }]}
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
