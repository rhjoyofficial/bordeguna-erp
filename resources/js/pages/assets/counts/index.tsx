import { GapList, type Gap } from '@/components/assets/gap-list';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface CountRow {
    id: number;
    number: string;
    place: string;
    status: string;
    lines_count: number;
    unmarked_count: number;
    discrepancies_count: number;
    started_at: string | null;
    started_by: string | null;
    closed_at: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Assets', href: '/assets/overview' },
    { title: 'Things', href: '/assets/items' },
    { title: 'Stock-takes', href: '/assets/counts' },
];

/**
 * Stock-takes of the register (slice A3): start one for a place, see which are
 * open, and settle what earlier ones found.
 */
export default function AssetCounts({
    counts,
    gaps,
    places,
    can_manage,
}: {
    counts: CountRow[];
    gaps: Gap[];
    places: { value: string; label: string }[];
    can_manage: boolean;
}) {
    const { data, setData, post, errors, processing } = useForm({ place: places[0]?.value ?? '', note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/assets/counts');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stock-takes" />
            <div className="flex flex-col gap-4 p-4">
                {/* The tab row above names the screen (15 Oct 2026). */}
                {can_manage && (
                    <form onSubmit={submit} className="bg-card flex flex-wrap items-end gap-3 rounded-xl border p-3">
                        <div className="grid gap-1">
                            <Label required>Place to count</Label>
                            <SearchableSelect fit value={data.place} onChange={(v) => setData('place', v)} options={places} />
                            <InputError message={(errors as Record<string, string | undefined>).place} />
                        </div>
                        <Button size="sm" disabled={processing || !data.place}>
                            Start a count
                        </Button>
                    </form>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">Gaps to settle ({gaps.filter((gap) => gap.open).length})</h2>
                    <GapList gaps={gaps.filter((gap) => gap.open)} canManage={can_manage} />
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">Counts</h2>
                    {counts.length === 0 ? (
                        <p className="text-muted-foreground text-sm">None yet.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {counts.map((count) => (
                                <li key={count.id}>
                                    <Link href={`/assets/counts/${count.id}`} className="block rounded-lg border p-3 text-sm">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span>
                                                <span className="font-mono text-xs">{count.number}</span>{' '}
                                                <span className="font-medium">{count.place}</span>
                                            </span>
                                            {count.status === 'open' ? (
                                                <Badge>Open</Badge>
                                            ) : (
                                                <Badge variant="secondary">Closed {count.closed_at}</Badge>
                                            )}
                                        </div>
                                        <p className="text-muted-foreground mt-1">
                                            {count.lines_count} on the list
                                            {count.status === 'open' && `, ${count.unmarked_count} still to mark`}
                                            {count.status === 'closed' && `, ${count.discrepancies_count} gap(s)`} - started {count.started_at}
                                            {count.started_by && ` by ${count.started_by}`}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
