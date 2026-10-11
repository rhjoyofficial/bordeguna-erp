import { ConfirmDialog } from '@/components/confirm-dialog';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

// Courier hub slice 5: every version of what each courier charges. A Manager
// prepares a draft (typed, from a CSV, or proposed by calibration from what the
// statements really charged); the Owner approves it with the date it takes
// effect. An approved card never changes: estimates already made keep the
// version they used.

type Status = 'draft' | 'approved' | 'retired';

interface CardSummary {
    id: number;
    version: string;
    status: Status;
    effective_from: string | null;
    is_list_price: boolean;
    source: 'published' | 'calibration' | 'manual' | 'import';
    based_on: string | null;
    lines: number;
    approved_by: string | null;
    approved_at: string | null;
    created_by: string | null;
    in_force: boolean;
}

interface Line {
    id?: number;
    from_band: string;
    to_band: string;
    service: string;
    weight_from_g: number | string;
    weight_to_g: number | string | null;
    price: string;
    extra_per_kg_after_g: number | string | null;
    extra_per_kg_price: string | null;
    return_fee_percent: string | null;
    expected_discount: string | null;
    sample_count: number | null;
    previous_price: string | null;
}

interface CardDetail {
    id: number;
    version: string;
    status: Status;
    effective_from: string | null;
    is_list_price: boolean;
    source: CardSummary['source'];
    notes: string | null;
    in_force: boolean;
    lines: Line[];
}

interface PageProps {
    providers: { code: string; name: string }[];
    provider: string;
    cards: CardSummary[];
    card: CardDetail | null;
    bands: { code: string; name: string }[];
    services: string[];
    min_samples: number;
    today: string;
    can_approve: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Courier rate cards', href: '/settings/couriers/rate-cards' }];

const statusTone: Record<Status, string> = {
    draft: 'border-amber-300 text-amber-800 dark:border-amber-700 dark:text-amber-300',
    approved: 'border-emerald-300 text-emerald-800 dark:border-emerald-700 dark:text-emerald-300',
    retired: 'text-muted-foreground',
};

const sourceLabel: Record<CardSummary['source'], string> = {
    published: "Courier's published prices",
    calibration: 'Proposed from statements',
    manual: 'Typed',
    import: 'From a CSV',
};

const blank = (v: unknown) => v === null || v === undefined || v === '';
const effective = (line: Line) => (blank(line.price) ? null : Number(line.price) - (blank(line.expected_discount) ? 0 : Number(line.expected_discount)));
const cellClass = 'h-8 px-2 text-xs';

function selectClass() {
    return 'border-input bg-background h-8 rounded-md border px-1 text-xs';
}

export default function CourierRateCards({ providers, provider, cards, card, bands, services, min_samples, today, can_approve }: PageProps) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const bandName = (code: string) => bands.find((b) => b.code === code)?.name ?? code;
    const isDraft = card?.status === 'draft';
    const calibrated = card?.source === 'calibration';

    const form = useForm<{ version: string; notes: string; is_list_price: boolean; lines: Line[] }>({
        version: card?.version ?? '',
        notes: card?.notes ?? '',
        is_list_price: card?.is_list_price ?? false,
        lines: card?.lines ?? [],
    });

    useEffect(() => {
        form.setData({ version: card?.version ?? '', notes: card?.notes ?? '', is_list_price: card?.is_list_price ?? false, lines: card?.lines ?? [] });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [card?.id, card?.lines.length, card?.version]);

    const [effectiveFrom, setEffectiveFrom] = useState(today);
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState<string | null>(null);

    const setLine = (index: number, key: keyof Line, value: string) =>
        form.setData(
            'lines',
            form.data.lines.map((line, i) => (i === index ? { ...line, [key]: value } : line)),
        );

    const addLine = () =>
        form.setData('lines', [
            ...form.data.lines,
            {
                from_band: bands[0]?.code ?? '',
                to_band: bands[0]?.code ?? '',
                service: 'normal',
                weight_from_g: 0,
                weight_to_g: 500,
                price: '',
                extra_per_kg_after_g: null,
                extra_per_kg_price: null,
                return_fee_percent: null,
                expected_discount: null,
                sample_count: null,
                previous_price: null,
            },
        ]);

    const removeLine = (index: number) =>
        form.setData(
            'lines',
            form.data.lines.filter((_, i) => i !== index),
        );

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        if (!card) return;
        form.put(route('couriers.ratecards.update', card.id), { preserveScroll: true });
    };

    const act = (key: string, name: string, payload: Record<string, unknown> = {}, params?: Record<string, unknown>) => {
        setBusy(key);
        router.post(route(name, params), payload as Record<string, string>, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const upload = () => {
        if (!card || !file) return;
        setBusy('import');
        router.post(route('couriers.ratecards.import', card.id), { file }, { forceFormData: true, preserveScroll: true, onFinish: () => setBusy(null) });
    };

    const lineErrors = Object.entries(errors).filter(([key]) => key.startsWith('lines'));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Courier rate cards" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall
                            title="Courier rate cards"
                            description="What each courier charges us, by its own bands, service and weight. The approved card in force prices every estimate when the courier gives no live quote. A card never changes once approved: a new price is a new version with its own date."
                        />
                        <div className="flex flex-wrap gap-3 text-sm">
                            <Link href={route('sales.courier-fees.index')} className="underline underline-offset-4">
                                Courier fees report
                            </Link>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {providers.map((p) => (
                            <Button
                                key={p.code}
                                size="sm"
                                variant={p.code === provider ? 'default' : 'outline'}
                                onClick={() => router.get(route('couriers.ratecards.index'), { provider: p.code })}
                            >
                                {p.name}
                            </Button>
                        ))}
                        <span className="grow" />
                        <Button size="sm" variant="outline" disabled={busy !== null} onClick={() => act('propose', 'couriers.ratecards.propose', { provider })}>
                            {busy === 'propose' ? 'Proposing...' : 'Propose from statements'}
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() => act('new', 'couriers.ratecards.store', { provider, based_on_id: card?.id ?? '' })}
                        >
                            {card ? `New draft from ${card.version}` : 'New empty draft'}
                        </Button>
                    </div>
                    <InputError message={errors.provider ?? errors.based_on_id ?? errors.card} />

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-2 pr-3">Version</th>
                                    <th className="py-2 pr-3">Status</th>
                                    <th className="py-2 pr-3">Takes effect</th>
                                    <th className="py-2 pr-3">Source</th>
                                    <th className="py-2 pr-3 text-right">Prices</th>
                                    <th className="py-2 pr-3">Approved</th>
                                </tr>
                            </thead>
                            <tbody>
                                {cards.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground py-4">
                                            No cards for this courier yet.
                                        </td>
                                    </tr>
                                )}
                                {cards.map((c) => (
                                    <tr key={c.id} className={cn('border-t', c.id === card?.id && 'bg-muted/50')}>
                                        <td className="py-1.5 pr-3">
                                            <Link
                                                href={route('couriers.ratecards.index', { provider, card: c.id })}
                                                preserveScroll
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {c.version}
                                            </Link>
                                            {c.in_force && <Badge className="ml-2">In force</Badge>}
                                            {c.is_list_price && <span className="text-muted-foreground ml-2 text-xs">list price</span>}
                                        </td>
                                        <td className="py-1.5 pr-3">
                                            <Badge variant="outline" className={statusTone[c.status]}>
                                                {c.status}
                                            </Badge>
                                        </td>
                                        <td className="py-1.5 pr-3 tabular-nums">{c.effective_from ?? '-'}</td>
                                        <td className="py-1.5 pr-3 text-xs">
                                            {sourceLabel[c.source]}
                                            {c.based_on && ` (from ${c.based_on})`}
                                        </td>
                                        <td className="py-1.5 pr-3 text-right tabular-nums">{c.lines}</td>
                                        <td className="py-1.5 pr-3 text-xs">{c.approved_at ? `${c.approved_by ?? 'seeded'}, ${c.approved_at}` : '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {card && (
                        <form onSubmit={save} className="border-sidebar-border/70 dark:border-sidebar-border space-y-4 rounded-md border p-4">
                            <div className="flex flex-wrap items-end gap-3">
                                <div className="grid gap-1">
                                    <Label htmlFor="card-version">Version</Label>
                                    <Input
                                        id="card-version"
                                        className="w-48"
                                        maxLength={40}
                                        disabled={!isDraft}
                                        value={form.data.version}
                                        onChange={(e) => form.setData('version', e.target.value)}
                                    />
                                    <InputError message={errors.version} />
                                </div>
                                <label className="flex items-center gap-2 pb-2 text-sm">
                                    <input
                                        type="checkbox"
                                        disabled={!isDraft}
                                        checked={form.data.is_list_price}
                                        onChange={(e) => form.setData('is_list_price', e.target.checked)}
                                    />
                                    List price (the courier usually charges less)
                                </label>
                                <span className="grow" />
                                <a href={route('couriers.ratecards.export', card.id)} className="pb-2 text-sm underline underline-offset-4">
                                    Export CSV
                                </a>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="card-notes">Notes</Label>
                                <textarea
                                    id="card-notes"
                                    rows={2}
                                    disabled={!isDraft}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                />
                            </div>

                            {calibrated && (
                                <p className="text-muted-foreground text-xs">
                                    Proposed from what the statements charged. "Before" is the price in force, "After" this draft's price, "Parcels" the
                                    clean parcels behind it. A line moves only with at least {min_samples} parcels; that is the least to consider, not
                                    proof. Check returns, re-weighing and outliers on the Courier fees report before approving.
                                </p>
                            )}

                            <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                    <thead className="text-muted-foreground text-left">
                                        <tr>
                                            <th className="py-2 pr-2">From</th>
                                            <th className="py-2 pr-2">To</th>
                                            <th className="py-2 pr-2">Service</th>
                                            <th className="py-2 pr-2 text-right">Above (g)</th>
                                            <th className="py-2 pr-2 text-right">Up to (g)</th>
                                            <th className="py-2 pr-2 text-right">Price</th>
                                            <th className="py-2 pr-2 text-right">Per kg after (g)</th>
                                            <th className="py-2 pr-2 text-right">Per kg</th>
                                            <th className="py-2 pr-2 text-right">Return %</th>
                                            <th className="py-2 pr-2 text-right">Expected discount</th>
                                            {calibrated && (
                                                <>
                                                    <th className="py-2 pr-2 text-right">Before</th>
                                                    <th className="py-2 pr-2 text-right">After</th>
                                                    <th className="py-2 pr-2 text-right">Parcels</th>
                                                </>
                                            )}
                                            {isDraft && <th className="py-2" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {form.data.lines.map((line, i) => {
                                            const after = effective(line);
                                            const moved =
                                                calibrated && line.previous_price !== null && after !== null && Math.abs(after - Number(line.previous_price)) > 0.004;

                                            return (
                                                <tr key={line.id ?? `new-${i}`} className={cn('border-t', moved && 'bg-sky-50 dark:bg-sky-950/30')}>
                                                    {isDraft ? (
                                                        <>
                                                            <td className="py-1 pr-2">
                                                                <select
                                                                    aria-label="From band"
                                                                    className={selectClass()}
                                                                    value={line.from_band}
                                                                    onChange={(e) => setLine(i, 'from_band', e.target.value)}
                                                                >
                                                                    {bands.map((b) => (
                                                                        <option key={b.code} value={b.code}>
                                                                            {b.name}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </td>
                                                            <td className="py-1 pr-2">
                                                                <select
                                                                    aria-label="To band"
                                                                    className={selectClass()}
                                                                    value={line.to_band}
                                                                    onChange={(e) => setLine(i, 'to_band', e.target.value)}
                                                                >
                                                                    {bands.map((b) => (
                                                                        <option key={b.code} value={b.code}>
                                                                            {b.name}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </td>
                                                            <td className="py-1 pr-2">
                                                                <select
                                                                    aria-label="Service"
                                                                    className={selectClass()}
                                                                    value={line.service}
                                                                    onChange={(e) => setLine(i, 'service', e.target.value)}
                                                                >
                                                                    {services.map((s) => (
                                                                        <option key={s} value={s}>
                                                                            {s.replace('_', ' ')}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </td>
                                                            {(
                                                                [
                                                                    'weight_from_g',
                                                                    'weight_to_g',
                                                                    'price',
                                                                    'extra_per_kg_after_g',
                                                                    'extra_per_kg_price',
                                                                    'return_fee_percent',
                                                                    'expected_discount',
                                                                ] as const
                                                            ).map((key) => (
                                                                <td key={key} className="py-1 pr-2">
                                                                    <Input
                                                                        aria-label={key.replaceAll('_', ' ')}
                                                                        inputMode="decimal"
                                                                        className={cn(cellClass, 'w-20 text-right')}
                                                                        value={line[key] === null || line[key] === undefined ? '' : String(line[key])}
                                                                        onChange={(e) => setLine(i, key, e.target.value)}
                                                                    />
                                                                </td>
                                                            ))}
                                                        </>
                                                    ) : (
                                                        <>
                                                            <td className="py-1.5 pr-2">{bandName(line.from_band)}</td>
                                                            <td className="py-1.5 pr-2">{bandName(line.to_band)}</td>
                                                            <td className="py-1.5 pr-2">{line.service.replace('_', ' ')}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.weight_from_g}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.weight_to_g ?? 'no top'}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.price}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.extra_per_kg_after_g ?? '-'}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.extra_per_kg_price ?? '-'}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.return_fee_percent ?? '-'}</td>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.expected_discount ?? 'not known'}</td>
                                                        </>
                                                    )}
                                                    {calibrated && (
                                                        <>
                                                            <td className="py-1.5 pr-2 text-right tabular-nums">{line.previous_price ?? '-'}</td>
                                                            <td className={cn('py-1.5 pr-2 text-right tabular-nums', moved && 'font-medium')}>
                                                                {after === null ? '-' : after.toFixed(2)}
                                                            </td>
                                                            <td
                                                                className={cn(
                                                                    'py-1.5 pr-2 text-right tabular-nums',
                                                                    (line.sample_count ?? 0) < min_samples && 'text-muted-foreground',
                                                                )}
                                                            >
                                                                {line.sample_count ?? 0}
                                                            </td>
                                                        </>
                                                    )}
                                                    {isDraft && (
                                                        <td className="py-1">
                                                            <Button type="button" size="sm" variant="ghost" className="h-7 px-2" onClick={() => removeLine(i)}>
                                                                Remove
                                                            </Button>
                                                        </td>
                                                    )}
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            {lineErrors.map(([key, message]) => (
                                <InputError key={key} message={message} />
                            ))}

                            {isDraft ? (
                                <div className="flex flex-wrap items-end gap-3">
                                    <Button type="button" size="sm" variant="outline" onClick={addLine}>
                                        Add a price
                                    </Button>
                                    <Button size="sm" disabled={form.processing}>
                                        {form.processing ? 'Saving...' : 'Save draft'}
                                    </Button>
                                    <div className="grid gap-1">
                                        <Label htmlFor="card-file">Replace prices from a CSV</Label>
                                        <Input
                                            id="card-file"
                                            type="file"
                                            accept=".csv,text/csv"
                                            className="w-64"
                                            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                                        />
                                        <InputError message={errors.file} />
                                    </div>
                                    <Button type="button" size="sm" variant="outline" disabled={!file || busy !== null} onClick={upload}>
                                        {busy === 'import' ? 'Reading...' : 'Read CSV'}
                                    </Button>
                                    <span className="grow" />
                                    <ConfirmDialog
                                        trigger={
                                            <Button type="button" size="sm" variant="ghost" className="text-red-600">
                                                Discard draft
                                            </Button>
                                        }
                                        title={`Discard ${card.version}?`}
                                        description="The draft and its prices are removed. Approved cards are not touched."
                                        confirmLabel="Discard"
                                        destructive
                                        onConfirm={() => router.delete(route('couriers.ratecards.destroy', card.id))}
                                    />
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-xs">
                                    {card.status === 'approved'
                                        ? `Approved, in use from ${card.effective_from}. To change a price, make a new draft from it.`
                                        : 'Retired. Estimates made with it keep it.'}
                                </p>
                            )}

                            {can_approve && isDraft && (
                                <div className="flex flex-wrap items-end gap-3 border-t pt-4">
                                    <div className="grid gap-1">
                                        <Label htmlFor="card-effective">Takes effect on</Label>
                                        <Input
                                            id="card-effective"
                                            type="date"
                                            min={today}
                                            className="w-44"
                                            value={effectiveFrom}
                                            onChange={(e) => setEffectiveFrom(e.target.value)}
                                        />
                                        <InputError message={errors.effective_from} />
                                    </div>
                                    <ConfirmDialog
                                        trigger={
                                            <Button type="button" size="sm" disabled={busy !== null || form.isDirty}>
                                                Approve
                                            </Button>
                                        }
                                        title={`Approve ${card.version}?`}
                                        description={`From ${effectiveFrom} every estimate without a live quote uses these prices. Estimates already made keep their own version. It cannot be edited afterwards.`}
                                        confirmLabel="Approve"
                                        onConfirm={() => act('approve', 'couriers.ratecards.approve', { effective_from: effectiveFrom }, { rateCard: card.id })}
                                    />
                                    {form.isDirty && <span className="text-muted-foreground pb-2 text-xs">Save the draft first.</span>}
                                </div>
                            )}

                            {can_approve && card.status === 'approved' && (
                                <div className="border-t pt-4">
                                    <ConfirmDialog
                                        trigger={
                                            <Button type="button" size="sm" variant="outline">
                                                Retire
                                            </Button>
                                        }
                                        title={`Retire ${card.version}?`}
                                        description="It stops pricing new estimates. An older approved card, if any, takes over; estimates already made keep this version."
                                        confirmLabel="Retire"
                                        destructive
                                        onConfirm={() => act('retire', 'couriers.ratecards.retire', {}, { rateCard: card.id })}
                                    />
                                </div>
                            )}
                        </form>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
