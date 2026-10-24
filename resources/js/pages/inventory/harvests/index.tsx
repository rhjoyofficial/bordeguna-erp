import { ActionDialog } from '@/components/action-dialog';
import InputError from '@/components/input-error';
import { inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { BlockedNote, type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { decimalInput, formatMoney, formatQuantity } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * ⭐ Farm harvest (24 Oct 2026): what each Crops in Progress head holds, the
 * harvests that moved it into stock and the crop failures written off it.
 *
 * Joy's rules: add in a POPUP, date FIRST, DD/MM/YYYY through the shared date
 * box, no <form> (saving happens only by clicking the button, so Enter never
 * sends), no keyboard shortcuts, every box only as wide as what goes in it.
 * Reverse goes through the corrections engine's own dialog (ActionDialog).
 */

type Option = { id: number; label: string };
type CropHead = Option & { balance: string };
type ProductOption = { id: number; name: string; sku: string; unit_id: number; unit: string | null; tracks_expiry: boolean };
type Unit = { id: number; name: string; abbreviation: string };
type Reason = { id: number; name: string };
type Reversal = { on: string | null; by: string | null; reason: string | null; journal: string | null } | null;

type HarvestRow = {
    id: number;
    number: string;
    harvested_on: string | null;
    head: string | null;
    warehouse: string | null;
    amount: string;
    journal: string | null;
    note: string | null;
    by: string | null;
    lines: { product: string | null; main: boolean; quantity: string; unit: string | null; cost: string; unit_cost: string; batch: string | null }[];
    reversal: Reversal;
    actions: StockAvailability[];
};

type WriteOffStatus = 'waiting' | 'approved' | 'rejected' | 'reversed' | 'withdrawn';

type WriteOffRow = {
    id: number;
    number: string;
    written_off_on: string | null;
    head: string | null;
    loss_head: string | null;
    amount: string;
    reason: string;
    status: WriteOffStatus;
    journal: string | null;
    by: string | null;
    approved_by: string | null;
    can_decide: boolean;
    self_approval: boolean;
    reversal: Reversal;
    actions: StockAvailability[];
};

type PageProps = {
    harvests: HarvestRow[];
    writeOffs: WriteOffRow[];
    focus: number | null;
    heads: CropHead[];
    lossHeads: Option[];
    defaultLossHeadId: number | null;
    warehouses: { id: number; name: string }[];
    products: ProductOption[];
    units: Unit[];
    reasons: Reason[];
    types: { harvest: string; writeOff: string };
    headCode: string;
};

const statusKey: Record<WriteOffStatus, TranslationKey> = {
    waiting: 'farm.status.waiting',
    approved: 'farm.status.approved',
    rejected: 'farm.status.rejected',
    reversed: 'farm.status.reversed',
    withdrawn: 'corr.fu.withdrawn',
};

const statusVariant: Record<WriteOffStatus, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    waiting: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    reversed: 'outline',
    withdrawn: 'outline',
};

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const selectClass = 'border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm';

type Line = { product_id: string; quantity: string; unit_id: string; cost: string; batch_number: string; expiry_date: string };

const blankLine: Line = { product_id: '', quantity: '', unit_id: '', cost: '', batch_number: '', expiry_date: '' };

function UnitSelect({
    id,
    value,
    product,
    units,
    onChange,
}: {
    id: string;
    value: string;
    product?: ProductOption;
    units: Unit[];
    onChange: (value: string) => void;
}) {
    return (
        <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className={selectClass}>
            <option value="">{product?.unit ?? '-'}</option>
            {units
                .filter((unit) => unit.id !== product?.unit_id)
                .map((unit) => (
                    <option key={unit.id} value={unit.id}>
                        {unit.abbreviation}
                    </option>
                ))}
        </select>
    );
}

function HarvestDialog({ heads, warehouses, products, units }: Pick<PageProps, 'heads' | 'warehouses' | 'products' | 'units'>) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [form, setForm] = useState({
        harvested_on: dhakaToday(),
        crop_account_id: heads.length === 1 ? String(heads[0].id) : '',
        warehouse_id: warehouses[0] ? String(warehouses[0].id) : '',
        amount: '',
        note: '',
    });
    const [lines, setLines] = useState<Line[]>([{ ...blankLine }]);
    const set = (field: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [field]: value }));
    const setLine = (index: number, field: keyof Line, value: string) =>
        setLines((previous) => previous.map((line, i) => (i === index ? { ...line, [field]: value } : line)));
    const product = (id: string) => products.find((p) => String(p.id) === id);
    const head = heads.find((h) => String(h.id) === form.crop_account_id);

    const onOpenChange = (next: boolean) => {
        setOpen(next);

        if (next) {
            setKey(newKey());
            setErrors({});
        }
    };

    const save = () => {
        setBusy(true);
        router.post(
            '/inventory/harvests',
            {
                ...form,
                amount: form.amount.trim() === '' ? null : form.amount,
                idempotency_key: key,
                lines: lines.map((line, index) => ({
                    product_id: line.product_id === '' ? null : Number(line.product_id),
                    quantity: line.quantity,
                    unit_id: line.unit_id === '' ? null : Number(line.unit_id),
                    cost: index === 0 || line.cost === '' ? null : line.cost,
                    batch_number: index === 0 ? line.batch_number : null,
                    expiry_date: line.expiry_date === '' ? null : line.expiry_date,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setLines([{ ...blankLine }]);
                    setForm((previous) => ({ ...previous, amount: '', note: '' }));
                },
                onError: (bag) => setErrors(bag),
                onFinish: () => setBusy(false),
            },
        );
    };

    const main = lines[0];
    const mainProduct = product(main.product_id);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <Button>{t('farm.record')}</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('farm.record_title')}</DialogTitle>
                    <DialogDescription>{t('farm.record_explain')}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    <div className="grid gap-2">
                        <Label htmlFor="harvest-date" required>
                            {t('farm.date')}
                        </Label>
                        <Input
                            id="harvest-date"
                            type="date"
                            className="w-auto"
                            max={dhakaToday()}
                            value={form.harvested_on}
                            onChange={(e) => set('harvested_on', e.target.value)}
                        />
                        <InputError message={errors.harvested_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="harvest-head" required>
                            {t('farm.head')}
                        </Label>
                        <SearchSelect id="harvest-head" fit value={form.crop_account_id} onChange={(e) => set('crop_account_id', e.target.value)}>
                            <option value="">{t('farm.head_pick')}</option>
                            {heads.map((h) => (
                                <option key={h.id} value={h.id}>
                                    {`${h.label} (Tk ${formatMoney(h.balance)})`}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.crop_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="harvest-warehouse" required>
                            {t('farm.warehouse')}
                        </Label>
                        <select
                            id="harvest-warehouse"
                            value={form.warehouse_id}
                            onChange={(e) => set('warehouse_id', e.target.value)}
                            className={selectClass}
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.warehouse_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="harvest-product" required>
                            {t('farm.product')}
                        </Label>
                        <SearchSelect id="harvest-product" fit value={main.product_id} onChange={(e) => setLine(0, 'product_id', e.target.value)}>
                            <option value="">{t('farm.product_pick')}</option>
                            {products.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {`${p.name} (${p.sku})`}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors['lines.0.product_id']} />
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="harvest-qty" required>
                                {t('farm.quantity')}
                            </Label>
                            <Input
                                id="harvest-qty"
                                inputMode="decimal"
                                className="w-32"
                                value={main.quantity}
                                onChange={(e) => setLine(0, 'quantity', decimalInput(e.target.value))}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="harvest-unit">{t('farm.unit')}</Label>
                            <UnitSelect
                                id="harvest-unit"
                                value={main.unit_id}
                                product={mainProduct}
                                units={units}
                                onChange={(v) => setLine(0, 'unit_id', v)}
                            />
                        </div>
                    </div>
                    <InputError message={errors['lines.0.quantity']} />
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="harvest-batch">{t('farm.batch')}</Label>
                            <Input
                                id="harvest-batch"
                                className="w-48"
                                value={main.batch_number}
                                onChange={(e) => setLine(0, 'batch_number', e.target.value)}
                            />
                        </div>
                        {mainProduct?.tracks_expiry && (
                            <div className="grid gap-2">
                                <Label htmlFor="harvest-expiry" required>
                                    {t('farm.expiry')}
                                </Label>
                                <Input
                                    id="harvest-expiry"
                                    type="date"
                                    className="w-auto"
                                    value={main.expiry_date}
                                    onChange={(e) => setLine(0, 'expiry_date', e.target.value)}
                                />
                            </div>
                        )}
                    </div>
                    <p className="text-muted-foreground text-xs">{t('farm.batch_hint')}</p>
                    <InputError message={errors['lines.0.expiry_date']} />
                    <div className="grid gap-2">
                        <Label htmlFor="harvest-amount">{t('farm.amount')}</Label>
                        <Input
                            id="harvest-amount"
                            inputMode="decimal"
                            className="w-40"
                            placeholder={head ? formatMoney(head.balance) : ''}
                            value={form.amount}
                            onChange={(e) => set('amount', decimalInput(e.target.value))}
                        />
                        <p className="text-muted-foreground text-xs">{t('farm.amount_hint')}</p>
                        <InputError message={errors.amount} />
                    </div>

                    <div className="grid gap-2 rounded-md border p-3">
                        <p className="font-medium">{t('farm.byproducts')}</p>
                        <p className="text-muted-foreground text-xs">{t('farm.byproducts_hint')}</p>
                        {lines.slice(1).map((line, offset) => {
                            const index = offset + 1;
                            const byProduct = product(line.product_id);

                            return (
                                <div key={index} className="flex flex-wrap items-end gap-3 border-t pt-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`by-product-${index}`}>{t('farm.product')}</Label>
                                        <SearchSelect
                                            id={`by-product-${index}`}
                                            fit
                                            value={line.product_id}
                                            onChange={(e) => setLine(index, 'product_id', e.target.value)}
                                        >
                                            <option value="">{t('farm.product_pick')}</option>
                                            {products.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {`${p.name} (${p.sku})`}
                                                </option>
                                            ))}
                                        </SearchSelect>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`by-qty-${index}`}>{t('farm.quantity')}</Label>
                                        <Input
                                            id={`by-qty-${index}`}
                                            inputMode="decimal"
                                            className="w-28"
                                            value={line.quantity}
                                            onChange={(e) => setLine(index, 'quantity', decimalInput(e.target.value))}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`by-unit-${index}`}>{t('farm.unit')}</Label>
                                        <UnitSelect
                                            id={`by-unit-${index}`}
                                            value={line.unit_id}
                                            product={byProduct}
                                            units={units}
                                            onChange={(v) => setLine(index, 'unit_id', v)}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`by-cost-${index}`}>{t('farm.share')}</Label>
                                        <Input
                                            id={`by-cost-${index}`}
                                            inputMode="decimal"
                                            className="w-32"
                                            value={line.cost}
                                            onChange={(e) => setLine(index, 'cost', decimalInput(e.target.value))}
                                        />
                                    </div>
                                    {byProduct?.tracks_expiry && (
                                        <div className="grid gap-2">
                                            <Label htmlFor={`by-expiry-${index}`}>{t('farm.expiry')}</Label>
                                            <Input
                                                id={`by-expiry-${index}`}
                                                type="date"
                                                className="w-auto"
                                                value={line.expiry_date}
                                                onChange={(e) => setLine(index, 'expiry_date', e.target.value)}
                                            />
                                        </div>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setLines((previous) => previous.filter((_, i) => i !== index))}
                                    >
                                        {t('farm.byproduct_remove')}
                                    </Button>
                                    <div className="w-full">
                                        <InputError
                                            message={
                                                errors[`lines.${index}.product_id`] ??
                                                errors[`lines.${index}.quantity`] ??
                                                errors[`lines.${index}.cost`] ??
                                                errors[`lines.${index}.expiry_date`]
                                            }
                                        />
                                    </div>
                                </div>
                            );
                        })}
                        <div>
                            <Button type="button" variant="outline" size="sm" onClick={() => setLines((previous) => [...previous, { ...blankLine }])}>
                                {t('farm.byproduct_add')}
                            </Button>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="harvest-note">{t('farm.note')}</Label>
                        <Input id="harvest-note" className="w-full max-w-md" value={form.note} onChange={(e) => set('note', e.target.value)} />
                    </div>
                    <InputError message={errors.lines ?? errors.harvest} />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('farm.go_back')}
                    </Button>
                    <Button type="button" disabled={busy} onClick={save}>
                        {t('farm.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function WriteOffDialog({ heads, lossHeads, defaultLossHeadId }: Pick<PageProps, 'heads' | 'lossHeads' | 'defaultLossHeadId'>) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [key, setKey] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [form, setForm] = useState({
        written_off_on: dhakaToday(),
        crop_account_id: heads.length === 1 ? String(heads[0].id) : '',
        loss_account_id: defaultLossHeadId === null ? '' : String(defaultLossHeadId),
        amount: '',
        reason_text: '',
    });
    const set = (field: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [field]: value }));

    const onOpenChange = (next: boolean) => {
        setOpen(next);

        if (next) {
            setKey(newKey());
            setErrors({});
        }
    };

    const save = () => {
        setBusy(true);
        router.post(
            '/inventory/crop-write-offs',
            { ...form, idempotency_key: key },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setForm((previous) => ({ ...previous, amount: '', reason_text: '' }));
                },
                onError: (bag) => setErrors(bag),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogTrigger asChild>
                <Button variant="outline">{t('farm.write_off')}</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('farm.write_off_title')}</DialogTitle>
                    <DialogDescription>{t('farm.write_off_explain')}</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    <div className="grid gap-2">
                        <Label htmlFor="wo-date" required>
                            {t('farm.write_off_date')}
                        </Label>
                        <Input
                            id="wo-date"
                            type="date"
                            className="w-auto"
                            max={dhakaToday()}
                            value={form.written_off_on}
                            onChange={(e) => set('written_off_on', e.target.value)}
                        />
                        <InputError message={errors.written_off_on} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-head" required>
                            {t('farm.head')}
                        </Label>
                        <SearchSelect id="wo-head" fit value={form.crop_account_id} onChange={(e) => set('crop_account_id', e.target.value)}>
                            <option value="">{t('farm.head_pick')}</option>
                            {heads.map((h) => (
                                <option key={h.id} value={h.id}>
                                    {`${h.label} (Tk ${formatMoney(h.balance)})`}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.crop_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-loss" required>
                            {t('farm.loss_head')}
                        </Label>
                        <SearchSelect id="wo-loss" fit value={form.loss_account_id} onChange={(e) => set('loss_account_id', e.target.value)}>
                            <option value="">{t('farm.loss_head_pick')}</option>
                            {lossHeads.map((h) => (
                                <option key={h.id} value={h.id}>
                                    {h.label}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.loss_account_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-amount" required>
                            {t('farm.write_off_amount')}
                        </Label>
                        <Input
                            id="wo-amount"
                            inputMode="decimal"
                            className="w-40"
                            value={form.amount}
                            onChange={(e) => set('amount', decimalInput(e.target.value))}
                        />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="wo-why" required>
                            {t('farm.why')}
                        </Label>
                        <textarea
                            id="wo-why"
                            aria-required
                            value={form.reason_text}
                            onChange={(e) => set('reason_text', e.target.value)}
                            className="border-input bg-background/80 min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                        />
                        <InputError message={errors.reason_text ?? errors.write_off} />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('farm.go_back')}
                    </Button>
                    <Button type="button" disabled={busy || form.reason_text.trim().length < 5} onClick={save}>
                        {t('farm.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function DecideDialog({ row }: { row: WriteOffRow }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const decide = (verb: 'approve' | 'reject') => {
        setBusy(true);
        router.post(
            `/inventory/crop-write-offs/${row.id}/${verb}`,
            { review_note: note.trim() === '' ? null : note },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onError: (bag) => setErrors(bag),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">{t('farm.decide')}</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('farm.decide_title', { number: row.number })}</DialogTitle>
                    <DialogDescription>
                        Tk {formatMoney(row.amount)} · {row.head} · {row.reason}
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2 text-sm">
                    {row.self_approval && <p className="text-muted-foreground text-xs">{t('farm.self_note')}</p>}
                    <Label htmlFor={`decide-note-${row.id}`} required={row.self_approval}>
                        {t('farm.decide_note')}
                    </Label>
                    <textarea
                        id={`decide-note-${row.id}`}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        className="border-input bg-background/80 min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    <InputError
                        message={
                            errors.self_approval_reason ?? errors.amount ?? errors.written_off_on ?? errors.crop_account_id ?? errors.loss_account_id
                        }
                    />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" disabled={busy} onClick={() => decide('reject')}>
                        {t('farm.reject')}
                    </Button>
                    <Button type="button" disabled={busy || (row.self_approval && note.trim() === '')} onClick={() => decide('approve')}>
                        {t('farm.approve')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ReverseButton({
    availability,
    type,
    id,
    number,
    reasons,
}: {
    availability?: StockAvailability;
    type: string;
    id: number;
    number: string;
    reasons: Reason[];
}) {
    const { t } = useTranslation();

    if (!availability) {
        return null;
    }

    const base = `/records/${type}/${id}`;

    return (
        <div className="inline-flex flex-col items-end gap-1">
            {availability.allowed || availability.requestable ? (
                <ActionDialog
                    action="reverse"
                    recordLabel={number}
                    previewUrl={`${base}/reverse/preview`}
                    submitUrl={`${base}/reverse`}
                    reasons={reasons}
                    trigger={
                        <Button size="sm" variant="outline">
                            {t('farm.reverse')}
                        </Button>
                    }
                />
            ) : (
                <Button size="sm" variant="outline" disabled>
                    {t('farm.reverse')}
                </Button>
            )}
            <BlockedNote availability={!availability.allowed && !availability.requestable ? availability : undefined} />
        </div>
    );
}

export default function FarmHarvestIndex(props: PageProps) {
    const { harvests, writeOffs, focus, heads, reasons, types, headCode } = props;
    const { t } = useTranslation();
    const breadcrumbs = inventoryCrumbs('Production', 'Farm harvest');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('farm.title')} />
            <div className="flex flex-col gap-6 p-4">
                <Toolbar
                    note={t('farm.explain')}
                    actions={
                        <>
                            <HarvestDialog heads={heads} warehouses={props.warehouses} products={props.products} units={props.units} />
                            <WriteOffDialog heads={heads} lossHeads={props.lossHeads} defaultLossHeadId={props.defaultLossHeadId} />
                        </>
                    }
                />

                <section className="grid gap-2">
                    <h2 className="font-medium">{t('farm.heads')}</h2>
                    {heads.length === 0 ? (
                        <p className="text-muted-foreground text-sm">{t('farm.heads_none', { code: headCode })}</p>
                    ) : (
                        <ul className="flex flex-wrap gap-2 text-sm">
                            {heads.map((h) => (
                                <li key={h.id} className="rounded-md border px-3 py-2">
                                    <span className="font-medium">{h.label}</span>
                                    <span className="text-muted-foreground block tabular-nums">
                                        {t('farm.holds', { amount: formatMoney(h.balance) })}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">{t('farm.harvests')}</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">{t('farm.col_date')}</th>
                                    <th className="py-2 pr-3">{t('farm.col_head')}</th>
                                    <th className="py-2 pr-3">{t('farm.col_came_in')}</th>
                                    <th className="py-2 pr-3 text-right">{t('farm.col_amount')}</th>
                                    <th className="py-2 pr-3">{t('farm.col_status')}</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {harvests.map((row) => (
                                    <tr key={row.id} className="border-b align-top">
                                        <td className="py-2 pr-3">
                                            {row.harvested_on ? showDate(row.harvested_on) : ''}
                                            <span className="text-muted-foreground block text-xs">{row.number}</span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.head}
                                            <span className="text-muted-foreground block text-xs">{row.warehouse}</span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.lines.map((line, i) => (
                                                <span key={i} className={line.main ? 'block' : 'text-muted-foreground block text-xs'}>
                                                    {formatQuantity(line.quantity)} {line.unit} {line.product} ·{' '}
                                                    {t('farm.each', { cost: formatMoney(line.unit_cost) })}
                                                    {line.batch ? ` · ${line.batch}` : ''}
                                                </span>
                                            ))}
                                            {row.note && <span className="text-muted-foreground block text-xs">{row.note}</span>}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {formatMoney(row.amount)}
                                            {row.journal && <span className="text-muted-foreground block text-xs">{row.journal}</span>}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Badge variant={row.reversal ? 'outline' : 'default'}>
                                                {row.reversal ? t('farm.reversed') : t('farm.posted')}
                                            </Badge>
                                            {row.reversal && (
                                                <span className="text-muted-foreground mt-1 block max-w-xs text-xs">
                                                    {t('farm.reversed_on', {
                                                        date: row.reversal.on ? showDate(row.reversal.on) : '',
                                                        by: row.reversal.by ?? '',
                                                    })}{' '}
                                                    {row.reversal.reason}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <ReverseButton
                                                availability={row.actions.find((a) => a.action === 'reverse')}
                                                type={types.harvest}
                                                id={row.id}
                                                number={row.number}
                                                reasons={reasons}
                                            />
                                        </td>
                                    </tr>
                                ))}
                                {harvests.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground py-4 text-center">
                                            {t('farm.harvests_none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-2">
                    <h2 className="font-medium">{t('farm.write_offs')}</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left">
                                    <th className="py-2 pr-3">{t('farm.col_date')}</th>
                                    <th className="py-2 pr-3">{t('farm.col_head')}</th>
                                    <th className="py-2 pr-3">{t('farm.why')}</th>
                                    <th className="py-2 pr-3 text-right">{t('farm.col_amount')}</th>
                                    <th className="py-2 pr-3">{t('farm.col_status')}</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {writeOffs.map((row) => (
                                    <tr key={row.id} className={`border-b align-top ${focus === row.id ? 'bg-muted/50' : ''}`}>
                                        <td className="py-2 pr-3">
                                            {row.written_off_on ? showDate(row.written_off_on) : ''}
                                            <span className="text-muted-foreground block text-xs">{row.number}</span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.head}
                                            <span className="text-muted-foreground block text-xs">{row.loss_head}</span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.reason}
                                            <span className="text-muted-foreground block text-xs">{row.by}</span>
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {formatMoney(row.amount)}
                                            {row.journal && <span className="text-muted-foreground block text-xs">{row.journal}</span>}
                                        </td>
                                        <td className="py-2 pr-3">
                                            <Badge variant={statusVariant[row.status]}>{t(statusKey[row.status])}</Badge>
                                            {row.reversal && (
                                                <span className="text-muted-foreground mt-1 block max-w-xs text-xs">
                                                    {t('farm.reversed_on', {
                                                        date: row.reversal.on ? showDate(row.reversal.on) : '',
                                                        by: row.reversal.by ?? '',
                                                    })}{' '}
                                                    {row.reversal.reason}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            {row.can_decide ? (
                                                <DecideDialog row={row} />
                                            ) : (
                                                <ReverseButton
                                                    availability={row.actions.find((a) => a.action === 'reverse')}
                                                    type={types.writeOff}
                                                    id={row.id}
                                                    number={row.number}
                                                    reasons={reasons}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {writeOffs.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-muted-foreground py-4 text-center">
                                            {t('farm.write_offs_none')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
