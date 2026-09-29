import { Pagination } from '@/components/data-table/pagination';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
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
    lines_count: number;
    created_by: string | null;
}

interface Option {
    value: string;
    label: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Hand-overs', href: '/assets/movements' },
];

/**
 * Every hand-over (slice A2), newest first. A transfer still on its way is
 * flagged: it is at neither place until somebody receives it.
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
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Hand-overs</h1>
                        <p className="text-muted-foreground max-w-3xl text-sm">
                            {in_transit > 0 && `${in_transit} on the way, not yet received.`}
                        </p>
                    </div>
                    {can_manage && (
                        <div className="flex flex-wrap gap-2">
                            <Button asChild>
                                <Link href="/assets/movements/create?kind=issue">Hand to someone</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/assets/movements/create?kind=return">Record a return</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/assets/movements/create?kind=transfer">Move to another place</Link>
                            </Button>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <SearchableSelect
                        fit
                        value={filters.kind ?? ''}
                        onChange={(value) => apply({ kind: value })}
                        options={[{ value: '', label: 'Every kind' }, ...kinds]}
                    />
                    <Button variant={filters.open ? 'default' : 'outline'} size="sm" onClick={() => apply({ open: filters.open ? '' : '1' })}>
                        On the way only
                    </Button>
                </div>

                {movements.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-center text-sm">No hand-overs yet.</p>
                ) : (
                    <ul className="grid gap-2">
                        {movements.data.map((movement) => (
                            <li key={movement.id}>
                                <Link href={`/assets/movements/${movement.id}`} className="block rounded-lg border p-3 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span>
                                            <span className="font-mono text-xs">{movement.number}</span>{' '}
                                            <span className="font-medium">{movement.kind_label}</span>
                                        </span>
                                        {movement.status === 'dispatched' ? (
                                            <Badge variant="destructive">{movement.status_label}</Badge>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">{movement.moved_on}</span>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground mt-1">
                                        {movement.from} to {movement.to} - {movement.lines_count} thing{movement.lines_count === 1 ? '' : 's'}
                                        {movement.created_by && ` - by ${movement.created_by}`}
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination meta={movements} />
            </div>
        </AppLayout>
    );
}
