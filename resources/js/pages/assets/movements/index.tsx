import { Chip, CompactTable, Pill } from '@/components/assets/assets-ui';
import { Pagination } from '@/components/data-table/pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';

interface Movement {
    id: number;
    number: string;
    kind: string;
    kind_label: string;
    status: string;
    status_label: string;
    from: string;
    to: string;
    moved_on: string;
    expected_back_on: string | null;
    overdue: boolean;
    lines_count: number;
    created_by: string | null;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Things', href: '/assets/items' },
    { title: 'Hand-overs', href: '/assets/movements' },
];

/** The chip's short word for each kind; the table keeps the full label. */
const KIND_SHORT: Record<string, string> = {
    issue: 'Handed out',
    return: 'Returned',
    reassign: 'Passed on',
    transfer: 'Moved',
    send_out: 'Sent out',
    receive_back: 'Received back',
};

function State({ movement }: { movement: Movement }) {
    if (movement.status === 'dispatched') return <Pill tone="warning">{movement.status_label}</Pill>;
    if (movement.overdue) return <Pill tone="danger">Overdue back</Pill>;

    return <Pill tone="muted">{movement.status_label}</Pill>;
}

/**
 * Every hand-over (slice A2), newest first. A transfer still on its way is
 * flagged: it is at neither place until somebody receives it.
 *
 * ⭐ Redesigned 15 Oct 2026 as the Hand-overs tab of Things: the kinds as
 * chips, "On the way only" with its count, one compact table. The six
 * actions keep their addresses.
 */
export default function AssetMovementsIndex({
    movements,
    filters,
    kinds,
    can_manage,
    in_transit,
}: {
    movements: Paginated<Movement>;
    filters: { kind?: string; open?: string };
    kinds: Option[];
    can_manage: boolean;
    in_transit: number;
}) {
    const apply = (next: { kind?: string; open?: string }) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== undefined && value !== ''));
        router.get('/assets/movements', query, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hand-overs" />
            <div className="flex flex-col gap-3 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        {in_transit > 0 ? (
                            <>
                                <span className="font-medium text-amber-700 dark:text-amber-400">{in_transit} on the way</span>, not yet received.
                            </>
                        ) : (
                            'Nothing on the way.'
                        )}
                    </p>
                    {can_manage && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=return">Return</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=reassign">Pass to someone else</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=transfer">Move to a place</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=send_out">Send out</Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/assets/movements/create?kind=receive_back">Receive back</Link>
                            </Button>
                            <Button size="sm" asChild>
                                <Link href="/assets/movements/create?kind=issue">Hand to someone</Link>
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Chip active={!filters.kind} onClick={() => apply({ kind: '' })}>
                        Every kind
                    </Chip>
                    {kinds.map((kind) => (
                        <Chip
                            key={kind.value}
                            active={filters.kind === kind.value}
                            onClick={() => apply({ kind: filters.kind === kind.value ? '' : kind.value })}
                        >
                            {KIND_SHORT[kind.value] ?? kind.label}
                        </Chip>
                    ))}
                    <Chip
                        className="ml-auto"
                        active={!!filters.open}
                        count={in_transit}
                        tone={in_transit > 0 ? 'warning' : undefined}
                        onClick={() => apply({ open: filters.open ? '' : '1' })}
                    >
                        On the way only
                    </Chip>
                </div>

                {movements.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No hand-overs yet.</p>
                ) : (
                    <div className="bg-card overflow-hidden rounded-xl border">
                        <CompactTable>
                            <thead className="bg-muted/40 text-muted-foreground">
                                <tr>
                                    <th>Number</th>
                                    <th>What happened</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th className="text-right">Things</th>
                                    <th>Date</th>
                                    <th>By</th>
                                    <th>State</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movements.data.map((movement) => (
                                    <tr key={movement.id}>
                                        <td className="font-mono text-xs whitespace-nowrap">
                                            <Link href={`/assets/movements/${movement.id}`} className="underline-offset-2 hover:underline">
                                                {movement.number}
                                            </Link>
                                        </td>
                                        <td>
                                            <Link
                                                href={`/assets/movements/${movement.id}`}
                                                className="font-medium underline-offset-2 hover:underline"
                                            >
                                                {movement.kind_label}
                                            </Link>
                                            {movement.expected_back_on && (
                                                <span className="text-muted-foreground block text-xs">Expected back {movement.expected_back_on}</span>
                                            )}
                                        </td>
                                        <td>{movement.from}</td>
                                        <td>{movement.to}</td>
                                        <td className="text-right tabular-nums">{movement.lines_count}</td>
                                        <td className="whitespace-nowrap tabular-nums">{movement.moved_on}</td>
                                        <td className="text-muted-foreground">{movement.created_by ?? '-'}</td>
                                        <td>
                                            <State movement={movement} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </CompactTable>
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t px-3.5 py-2">
                            <p className="text-muted-foreground text-xs">
                                Newest first. A hand-over is never edited: a mistake is put right with another one.
                            </p>
                            <Pagination meta={movements} />
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
