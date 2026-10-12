import { GapList, type Gap } from '@/components/assets/gap-list';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface Count {
    id: number;
    number: string;
    place: string;
    status: string;
    note: string | null;
    started_by: string | null;
    started_at: string | null;
    closed_by: string | null;
    closed_at: string | null;
}

interface Line {
    item_id: number;
    tag: string | null;
    name: string | null;
    is_group: boolean;
    with: string | null;
    current_condition: string;
    expected: boolean;
    expected_quantity: number;
    found: boolean | null;
    found_quantity: number | null;
    condition: string | null;
    marked_by: string | null;
}

interface Option {
    value: string;
    label: string;
}

function LineRow({ count, line, conditions, open }: { count: Count; line: Line; conditions: Option[]; open: boolean }) {
    const [pieces, setPieces] = useState(String(line.found_quantity ?? line.expected_quantity));
    const [condition, setCondition] = useState('');

    const mark = (found: boolean) =>
        router.post(
            `/assets/counts/${count.id}/mark`,
            { item_id: line.item_id, found, pieces: line.is_group ? Number(pieces) : null, condition: condition || null },
            { preserveScroll: true },
        );

    return (
        <li className="grid gap-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="min-w-0">
                    <Link href={`/assets/items/${line.item_id}`} className="font-mono text-xs underline underline-offset-2">
                        {line.tag}
                    </Link>{' '}
                    {line.name}
                    {line.with && <span className="text-muted-foreground"> - with {line.with}</span>}
                    {line.is_group && <span className="text-muted-foreground"> - {line.expected_quantity} expected</span>}
                    {!line.expected && <span className="text-muted-foreground"> - not expected here</span>}
                </span>
                {line.found === true && (
                    <Badge variant="secondary">
                        Found{line.is_group && ` ${line.found_quantity}`}
                        {line.condition && `, ${line.condition}`}
                    </Badge>
                )}
                {line.found === false && <Badge variant="destructive">Missing</Badge>}
                {line.found === null && <Badge variant="outline">To mark</Badge>}
            </div>
            {open && (
                <div className="flex flex-wrap items-center gap-2">
                    {line.is_group && (
                        <Input
                            type="number"
                            min={0}
                            value={pieces}
                            onChange={(e) => setPieces(e.target.value)}
                            className="w-20"
                            aria-label="Pieces found"
                        />
                    )}
                    <SearchableSelect
                        fit
                        value={condition}
                        onChange={setCondition}
                        options={[{ value: '', label: 'Condition as it was' }, ...conditions]}
                    />
                    <Button size="sm" onClick={() => mark(line.is_group ? Number(pieces) > 0 : true)}>
                        {line.is_group ? 'Counted' : 'Found'}
                    </Button>
                    {line.expected && (
                        <Button size="sm" variant="outline" onClick={() => mark(false)}>
                            Missing
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

function AddFound({ count }: { count: Count }) {
    const { data, setData, post, errors, processing, reset } = useForm({ tag: '', pieces: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/counts/${count.id}/found`, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-2">
            <div className="grid gap-1">
                <Input
                    value={data.tag}
                    onChange={(e) => setData('tag', e.target.value)}
                    aria-required
                    placeholder="Tag, e.g. BDG-IT-0007"
                    className="w-52 font-mono"
                />
                <InputError message={errors.tag ?? (errors as Record<string, string | undefined>).item} />
            </div>
            <Input value={data.pieces} onChange={(e) => setData('pieces', e.target.value)} placeholder="Pieces (groups)" className="w-32" />
            <Button size="sm" variant="outline" disabled={processing || !data.tag}>
                Add as found here
            </Button>
        </form>
    );
}

/**
 * One stock-take (slice A3): mark every thing, add what turned up, close, then
 * settle the gaps.
 */
export default function AssetCountShow({
    count,
    lines,
    gaps,
    conditions,
    can_manage,
}: {
    count: Count;
    lines: Line[];
    gaps: Gap[];
    conditions: Option[];
    can_manage: boolean;
}) {
    const open = count.status === 'open' && can_manage;
    const unmarked = lines.filter((line) => line.expected && line.found === null).length;
    const errors = usePage().props.errors as Record<string, string | undefined>;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Things we keep', href: '/assets/items' },
        { title: 'Stock-takes', href: '/assets/counts' },
        { title: count.number, href: `/assets/counts/${count.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={count.number} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {count.number} <span className="font-normal">{count.place}</span>
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Started {count.started_at}
                            {count.started_by && ` by ${count.started_by}`}
                            {count.closed_at && `, closed ${count.closed_at}`}
                            {count.closed_by && ` by ${count.closed_by}`}
                        </p>
                        <div className="mt-2">
                            {count.status === 'open' ? <Badge>{unmarked} still to mark</Badge> : <Badge variant="secondary">Closed</Badge>}
                        </div>
                    </div>
                    {open && (
                        <ConfirmDialog
                            trigger={<Button disabled={unmarked > 0}>Close the count</Button>}
                            title={`Close ${count.number}?`}
                            description="Whatever is marked missing becomes Missing in the register, conditions noted are written, and every gap is listed for somebody to settle."
                            confirmLabel="Close it"
                            onConfirm={() => router.post(`/assets/counts/${count.id}/close`, {}, { preserveScroll: true })}
                        />
                    )}
                </div>
                {errors.count && <p className="text-destructive text-sm">{errors.count}</p>}

                {open && (
                    <section className="grid gap-2 rounded-lg border p-3">
                        <p className="text-sm font-medium">Found something not on the list?</p>
                        <AddFound count={count} />
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">On the list ({lines.length})</h2>
                    {errors.pieces && <p className="text-destructive text-sm">{errors.pieces}</p>}
                    {lines.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing is expected at this place.</p>
                    ) : (
                        <ul className="grid gap-2">
                            {lines.map((line) => (
                                <LineRow key={line.item_id} count={count} line={line} conditions={conditions} open={open} />
                            ))}
                        </ul>
                    )}
                </section>

                {count.status === 'closed' && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">Gaps</h2>
                        <GapList gaps={gaps} canManage={can_manage} showCount={false} />
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
