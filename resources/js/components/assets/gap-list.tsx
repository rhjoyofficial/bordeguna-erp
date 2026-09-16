import { SearchableSelect } from '@/components/searchable-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export interface Gap {
    id: number;
    count_id: number;
    count_number: string | null;
    item_id: number;
    tag: string | null;
    name: string | null;
    kind: string;
    kind_label: string;
    quantity: number;
    open: boolean;
    responsible: string | null;
    resolution: string | null;
    resolved_by: string | null;
    note: string | null;
    choices: { value: string; label: string; meaning: string }[];
}

function Settle({ gap }: { gap: Gap }) {
    const { data, setData, post, processing, errors } = useForm({ resolution: gap.choices[0]?.value ?? '', note: '' });
    const chosen = gap.choices.find((choice) => choice.value === data.resolution);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/assets/discrepancies/${gap.id}/resolve`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="mt-2 grid gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <SearchableSelect fit value={data.resolution} onChange={(v) => setData('resolution', v)} options={gap.choices} />
                <Input value={data.note} onChange={(e) => setData('note', e.target.value)} placeholder="What happened" className="w-full sm:w-64" />
                <Button size="sm" disabled={processing || !data.resolution}>
                    Settle
                </Button>
            </div>
            {chosen && <p className="text-muted-foreground text-xs">{chosen.meaning}</p>}
            {errors.resolution && <p className="text-destructive text-xs">{errors.resolution}</p>}
        </form>
    );
}

/**
 * Gaps a stock-take found (slice A3), each with the choices this reader may
 * make. Confirming a loss is offered only to whoever may write things off.
 */
export function GapList({ gaps, canManage, showCount = true }: { gaps: Gap[]; canManage: boolean; showCount?: boolean }) {
    if (gaps.length === 0) {
        return <p className="text-muted-foreground text-sm">No gaps.</p>;
    }

    return (
        <ul className="grid gap-2">
            {gaps.map((gap) => (
                <li key={gap.id} className="rounded-lg border p-3 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="min-w-0">
                            <Link href={`/assets/items/${gap.item_id}`} className="font-mono text-xs underline underline-offset-2">
                                {gap.tag}
                            </Link>{' '}
                            {gap.name} - {gap.kind_label}
                            {gap.quantity > 1 && ` (${gap.quantity})`}
                            {showCount && gap.count_number && (
                                <>
                                    {' '}
                                    in{' '}
                                    <Link href={`/assets/counts/${gap.count_id}`} className="underline underline-offset-2">
                                        {gap.count_number}
                                    </Link>
                                </>
                            )}
                        </span>
                        {gap.open ? <Badge variant="destructive">Open</Badge> : <Badge variant="secondary">{gap.resolution}</Badge>}
                    </div>
                    <p className="text-muted-foreground text-xs">
                        {gap.open ? `Responsible: ${gap.responsible ?? '-'}` : `Settled by ${gap.resolved_by ?? '-'}`}
                        {gap.note && ` - ${gap.note}`}
                    </p>
                    {gap.open && canManage && gap.choices.length > 0 && <Settle gap={gap} />}
                </li>
            ))}
        </ul>
    );
}
