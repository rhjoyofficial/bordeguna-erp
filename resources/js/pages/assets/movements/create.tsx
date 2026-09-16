import InputError from '@/components/input-error';
import { SearchableSelect } from '@/components/searchable-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface Movable {
    id: number;
    tag: string;
    name: string;
    is_group: boolean;
    parent_id: number | null;
    where: string;
    with: string;
    condition: string;
    held: { key: string; employee_id: number | null; party_id: number | null; quantity: number }[] | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Things we keep', href: '/assets/items' },
    { title: 'Hand-overs', href: '/assets/movements' },
    { title: 'New hand-over', href: '/assets/movements/create' },
];

const textareaClass = 'border-input bg-background min-h-16 w-full rounded-md border px-3 py-2 text-sm';

/**
 * Record a hand-over (slice A2). The kind decides who the other side is:
 * an employee (issue), an outside party (send out), or a place (return,
 * receive back, transfer). The list offers only things the kind allows.
 *
 * ⚠ Ticking a thing that has parts ticks its parts too, and each can be
 * unticked: a monitor may stay while the desktop goes.
 */
export default function AssetMovementCreate({
    kind: initialKind,
    kinds,
    preselected,
    items,
    employees,
    parties,
    places,
    conditions,
    today,
}: {
    kind: string;
    kinds: Option[];
    preselected: number[];
    items: Movable[];
    employees: { id: number; label: string }[];
    parties: { id: number; label: string; kind: string }[];
    places: Option[];
    conditions: Option[];
    today: string;
}) {
    const [needle, setNeedle] = useState('');
    const { data, setData, post, errors, processing, transform } = useForm<{
        kind: string;
        lines: { item_id: number; quantity: string; condition: string }[];
        employee_id: string;
        party_id: string;
        from_employee_id: string;
        from_party_id: string;
        place: string;
        moved_on: string;
        note: string;
        photos: File[];
    }>({
        kind: initialKind,
        lines: preselected.filter((id) => items.some((item) => item.id === id)).map((id) => ({ item_id: id, quantity: '1', condition: '' })),
        employee_id: '',
        party_id: '',
        from_employee_id: '',
        from_party_id: '',
        place: places[0]?.value ?? '',
        moved_on: today,
        note: '',
        photos: [],
    });

    const kind = data.kind;
    const needsEmployee = kind === 'issue';
    const needsParty = kind === 'send_out';
    const needsPlace = kind === 'return' || kind === 'receive_back' || kind === 'transfer';

    const shown = useMemo(
        () =>
            items.filter(
                (item) => needle === '' || `${item.tag} ${item.name} ${item.with} ${item.where}`.toLowerCase().includes(needle.toLowerCase()),
            ),
        [items, needle],
    );

    const toggle = (item: Movable, on: boolean) => {
        setData((current) => {
            let lines = current.lines.filter((line) => line.item_id !== item.id);

            if (on) {
                lines = [...lines, { item_id: item.id, quantity: '1', condition: '' }];
                // Its parts come along, each untickable.
                for (const part of items.filter(
                    (candidate) => candidate.parent_id === item.id && !lines.some((line) => line.item_id === candidate.id),
                )) {
                    lines.push({ item_id: part.id, quantity: '1', condition: '' });
                }
            }

            return { ...current, lines };
        });
    };

    const setLine = (id: number, field: 'quantity' | 'condition', value: string) =>
        setData(
            'lines',
            data.lines.map((line) => (line.item_id === id ? { ...line, [field]: value } : line)),
        );

    const changeKind = (value: string) => router.get('/assets/movements/create', { kind: value }, { preserveState: false });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((current) => ({
            ...current,
            lines: current.lines.map((line) => ({ item_id: line.item_id, quantity: line.quantity || '1', condition: line.condition || null })),
        }));
        post('/assets/movements', { forceFormData: true });
    };

    const lineErrors = (errors as Record<string, string | undefined>).lines;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New hand-over" />
            <form onSubmit={submit} className="flex max-w-4xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-lg font-semibold">New hand-over</h1>
                    <p className="text-muted-foreground text-sm">
                        {kind === 'transfer'
                            ? 'A move to another place is done in two steps: recorded here, then received by somebody at the other place.'
                            : 'Recorded once, by the person who did the hand-over. It cannot be edited afterwards.'}
                    </p>
                </div>

                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label required>What is happening</Label>
                        <SearchableSelect value={kind} onChange={changeKind} options={kinds} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="moved_on" required>
                            On
                        </Label>
                        <Input id="moved_on" type="date" value={data.moved_on} onChange={(e) => setData('moved_on', e.target.value)} />
                        <InputError message={errors.moved_on} />
                    </div>

                    {needsEmployee && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label required>To whom</Label>
                            <SearchableSelect
                                value={data.employee_id}
                                onChange={(v) => setData('employee_id', v)}
                                options={employees.map((e) => ({ value: String(e.id), label: e.label }))}
                                placeholder="Choose an employee"
                            />
                            <InputError message={errors.employee_id} />
                        </div>
                    )}

                    {needsParty && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label required>To which outside party</Label>
                            <SearchableSelect
                                value={data.party_id}
                                onChange={(v) => setData('party_id', v)}
                                options={parties.map((p) => ({ value: String(p.id), label: `${p.label} - ${p.kind}` }))}
                                placeholder="Choose a party"
                            />
                            <p className="text-muted-foreground text-xs">
                                No party listed?{' '}
                                <Link href="/assets/parties" className="underline">
                                    Add one first.
                                </Link>
                            </p>
                            <InputError message={errors.party_id} />
                        </div>
                    )}

                    {needsPlace && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label required>{kind === 'transfer' ? 'To which place' : 'Back to which place'}</Label>
                            <SearchableSelect
                                value={data.place}
                                onChange={(v) => setData('place', v)}
                                options={places}
                                placeholder="Choose a place"
                            />
                            <InputError message={(errors as Record<string, string | undefined>).location ?? errors.place} />
                        </div>
                    )}

                    {kind === 'return' && data.lines.some((line) => items.find((i) => i.id === line.item_id)?.is_group) && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label required>Which employee is returning the counted pieces</Label>
                            <SearchableSelect
                                value={data.from_employee_id}
                                onChange={(v) => setData('from_employee_id', v)}
                                options={employees.map((e) => ({ value: String(e.id), label: e.label }))}
                                placeholder="Choose an employee"
                            />
                            <InputError message={errors.from_employee_id} />
                        </div>
                    )}

                    {kind === 'receive_back' && data.lines.some((line) => items.find((i) => i.id === line.item_id)?.is_group) && (
                        <div className="grid gap-2 sm:col-span-2">
                            <Label required>Which outside party is giving the counted pieces back</Label>
                            <SearchableSelect
                                value={data.from_party_id}
                                onChange={(v) => setData('from_party_id', v)}
                                options={parties.map((p) => ({ value: String(p.id), label: p.label }))}
                                placeholder="Choose a party"
                            />
                            <InputError message={errors.from_party_id} />
                        </div>
                    )}
                </section>

                <section className="grid gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Label required>Things ({data.lines.length} chosen)</Label>
                        <Input
                            value={needle}
                            onChange={(e) => setNeedle(e.target.value)}
                            placeholder="Tag, name, who has it"
                            className="w-full sm:w-64"
                        />
                    </div>
                    <InputError message={lineErrors} />
                    {shown.length === 0 ? (
                        <p className="text-muted-foreground rounded-lg border p-4 text-center text-sm">Nothing this hand-over could take.</p>
                    ) : (
                        <ul className="grid gap-1 rounded-lg border p-2 text-sm">
                            {shown.map((item) => {
                                const line = data.lines.find((candidate) => candidate.item_id === item.id);

                                return (
                                    <li key={item.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-2 py-1.5">
                                        <Checkbox id={`m-${item.id}`} checked={!!line} onCheckedChange={(on) => toggle(item, on === true)} />
                                        <label htmlFor={`m-${item.id}`} className="flex min-w-0 flex-1 flex-wrap gap-x-3">
                                            <span className="font-mono text-xs">{item.tag}</span>
                                            <span>{item.name}</span>
                                            <span className="text-muted-foreground">
                                                {item.where}
                                                {!item.is_group && ` - ${item.with}`}
                                            </span>
                                        </label>
                                        {line && item.is_group && (
                                            <Input
                                                type="number"
                                                min={1}
                                                value={line.quantity}
                                                onChange={(e) => setLine(item.id, 'quantity', e.target.value)}
                                                className="w-20"
                                                aria-label="How many"
                                            />
                                        )}
                                        {line && (
                                            <SearchableSelect
                                                fit
                                                value={line.condition}
                                                onChange={(v) => setLine(item.id, 'condition', v)}
                                                options={[{ value: '', label: 'Condition unchanged' }, ...conditions]}
                                            />
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="note">Note</Label>
                        <textarea id="note" className={textareaClass} value={data.note} onChange={(e) => setData('note', e.target.value)} />
                        <InputError message={errors.note} />
                    </div>
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="photos">Hand-over photos</Label>
                        <input
                            id="photos"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            className="w-full min-w-0 text-sm"
                            onChange={(e) => setData('photos', Array.from(e.target.files ?? []).slice(0, 6))}
                        />
                        <InputError message={errors.photos ?? (errors as Record<string, string | undefined>)['photos.0']} />
                    </div>
                </section>

                <div className="flex flex-wrap gap-2">
                    <Button disabled={processing || data.lines.length === 0}>{kind === 'transfer' ? 'Record as dispatched' : 'Record'}</Button>
                    <Button variant="outline" asChild>
                        <Link href="/assets/movements">Cancel</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
