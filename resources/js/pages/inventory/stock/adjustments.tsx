import { ActionDialog } from '@/components/action-dialog';
import { Pagination } from '@/components/data-table/pagination';
import InputError from '@/components/input-error';
import { Chip, inventoryCrumbs, Toolbar } from '@/components/inventory/inventory-ui';
import { BlockedNote, StockReasonDialog, type StockAvailability } from '@/components/inventory/stock-reason-dialog';
import { SearchSelect } from '@/components/search-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFilters } from '@/hooks/use-filters';
import AppLayout from '@/layouts/app-layout';
import { dhakaToday, showDate } from '@/lib/dates';
import { useTranslation, type TranslationKey } from '@/lib/i18n';
import { decimalInput, formatMoney, formatQuantity } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Status = 'waiting' | 'withdrawn' | 'approved' | 'rejected' | 'reversed';

interface AdjustmentRow {
    id: number;
    number: string;
    effective_date: string | null;
    type: string;
    direction: 'increase' | 'decrease';
    product: string | null;
    product_id: number;
    unit: string | null;
    warehouse: string | null;
    batch: string | null;
    quantity: string;
    unit_cost: string | null;
    estimated_value: string;
    reason_id: number;
    reason: string | null;
    note: string | null;
    raised_by: string | null;
    status: Status;
    reversal: { on: string | null; by: string | null; reason: string | null; journal: string | null } | null;
    cost_change: boolean;
    actions: StockAvailability[];
}

type Reason = { id: number; name: string };
type AdjustmentReason = Reason & { type: string; only_reduces: boolean };

const breadcrumbs = inventoryCrumbs('Stock', 'Corrections');

const statusVariant: Record<Status, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    waiting: 'secondary',
    withdrawn: 'outline',
    approved: 'default',
    rejected: 'destructive',
    reversed: 'outline',
};

const statusKey: Record<Status, TranslationKey> = {
    waiting: 'corr.stock.adjust.status.waiting',
    withdrawn: 'corr.stock.adjust.status.withdrawn',
    approved: 'corr.stock.adjust.status.approved',
    rejected: 'corr.stock.adjust.status.rejected',
    reversed: 'corr.stock.adjust.status.reversed',
};

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): Edit a correction that is waiting,
 * or one the raiser withdrew. The date first (Joy, 19 Oct). Waiting is not a
 * draft, so a reason for the change is asked; a withdrawn one is a draft and
 * goes back for approval when saved.
 *
 * ⚠ No <form>: saving happens only by clicking the button (Joy, 19 Oct).
 */
function EditAdjustmentDialog({
    row,
    availability,
    adjustmentReasons,
    correctionReasons,
}: {
    row: AdjustmentRow;
    availability: StockAvailability;
    adjustmentReasons: AdjustmentReason[];
    correctionReasons: Reason[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({
        effective_date: row.effective_date ?? dhakaToday(),
        quantity: formatQuantity(row.quantity),
        unit_cost: row.unit_cost ?? '',
        what_happened: String(row.reason_id),
        note: row.note ?? '',
        reason_id: '',
        reason_text: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const set = (field: keyof typeof form, value: string) => setForm((previous) => ({ ...previous, [field]: value }));
    const needsReason = availability.requires_reason;
    const usable = adjustmentReasons.filter((r) => (row.direction === 'increase' ? !r.only_reduces : true));
    const ready = !needsReason || form.reason_text.trim().length >= 5;

    const save = () => {
        setBusy(true);
        router.put(
            route('inventory.stock.adjustments.update', row.id),
            {
                ...form,
                unit_cost: row.direction === 'increase' ? form.unit_cost : null,
                reason_id: form.reason_id === '' ? null : Number(form.reason_id),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setOpen(false);
                    setErrors({});
                },
                onError: (bag) => setErrors(bag),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" disabled={!availability.allowed}>
                    {t('corr.stock.edit')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('corr.stock.adjust.edit_title', { number: row.number })}</DialogTitle>
                    <DialogDescription>
                        {row.status === 'withdrawn' ? t('corr.stock.adjust.edit_withdrawn') : t('corr.stock.adjust.edit_waiting')}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 text-sm">
                    <div className="grid gap-2">
                        <Label htmlFor={`adj-date-${row.id}`} required>
                            {t('corr.stock.adjust.when')}
                        </Label>
                        <Input
                            id={`adj-date-${row.id}`}
                            type="date"
                            className="w-auto"
                            max={dhakaToday()}
                            value={form.effective_date}
                            onChange={(e) => set('effective_date', e.target.value)}
                        />
                        <InputError message={errors.effective_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`adj-what-${row.id}`} required>
                            {t('corr.stock.adjust.what_happened')}
                        </Label>
                        <SearchSelect id={`adj-what-${row.id}`} value={form.what_happened} onChange={(e) => set('what_happened', e.target.value)}>
                            {usable.map((reason) => (
                                <option key={reason.id} value={reason.id}>
                                    {reason.name}
                                </option>
                            ))}
                        </SearchSelect>
                        <InputError message={errors.what_happened} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`adj-qty-${row.id}`} required>
                            {t('corr.stock.adjust.how_many', { unit: row.unit ?? '' })}
                        </Label>
                        <Input
                            id={`adj-qty-${row.id}`}
                            inputMode="decimal"
                            className="w-32"
                            value={form.quantity}
                            onChange={(e) => set('quantity', decimalInput(e.target.value))}
                        />
                        <InputError message={errors.quantity} />
                    </div>
                    {row.direction === 'increase' && (
                        <div className="grid gap-2">
                            <Label htmlFor={`adj-cost-${row.id}`} required>
                                {t('corr.stock.adjust.cost_each')}
                            </Label>
                            <Input
                                id={`adj-cost-${row.id}`}
                                inputMode="decimal"
                                className="w-36"
                                value={form.unit_cost}
                                onChange={(e) => set('unit_cost', decimalInput(e.target.value))}
                            />
                            <InputError message={errors.unit_cost} />
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor={`adj-note-${row.id}`}>{t('corr.stock.note')}</Label>
                        <Input id={`adj-note-${row.id}`} value={form.note} onChange={(e) => set('note', e.target.value)} />
                    </div>

                    {needsReason && (
                        <div className="grid gap-2 rounded-md border p-3">
                            <Label htmlFor={`adj-why-${row.id}`}>{t('corr.stock.reason')}</Label>
                            <select
                                id={`adj-why-${row.id}`}
                                value={form.reason_id}
                                onChange={(e) => set('reason_id', e.target.value)}
                                className="border-input bg-background h-9 w-fit max-w-full rounded-md border px-2 text-sm"
                            >
                                <option value="">{t('corr.stock.reason_pick')}</option>
                                {correctionReasons.map((reason) => (
                                    <option key={reason.id} value={reason.id}>
                                        {reason.name}
                                    </option>
                                ))}
                            </select>
                            <Label htmlFor={`adj-why-text-${row.id}`} required>
                                {t('corr.stock.adjust.why_change')}
                            </Label>
                            <textarea
                                id={`adj-why-text-${row.id}`}
                                aria-required
                                value={form.reason_text}
                                onChange={(e) => set('reason_text', e.target.value)}
                                className="border-input bg-background/80 min-h-16 w-full rounded-md border px-3 py-2 text-sm"
                            />
                            <InputError message={errors.reason_text} />
                        </div>
                    )}

                    <InputError message={errors.stock} />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        {t('corr.stock.go_back')}
                    </Button>
                    <Button type="button" disabled={busy || !ready} onClick={save}>
                        {row.status === 'withdrawn' ? t('corr.stock.adjust.save_send') : t('corr.stock.adjust.save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** ⭐ Initial plan B6 (24 Oct 2026): the losses-by-reason report (StockAdjustmentController::lossesByReason). */
interface LossReport {
    from: string;
    to: string;
    rows: { type: string; label: string; head: string | null; count: number; amount: string }[];
    total: string;
}

function LossesByReason({
    losses,
    from,
    to,
    onFrom,
    onTo,
}: {
    losses: LossReport;
    from: string;
    to: string;
    onFrom: (v: string) => void;
    onTo: (v: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <section className="flex flex-col gap-2 rounded-md border p-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex flex-col gap-1">
                    <Label htmlFor="loss-from">{t('ips.loss.from')}</Label>
                    <Input id="loss-from" type="date" className="w-auto" value={from || losses.from} onChange={(e) => onFrom(e.target.value)} />
                </div>
                <div className="flex flex-col gap-1">
                    <Label htmlFor="loss-to">{t('ips.loss.to')}</Label>
                    <Input id="loss-to" type="date" className="w-auto" value={to || losses.to} onChange={(e) => onTo(e.target.value)} />
                </div>
                <div className="flex flex-col">
                    <h2 className="font-semibold">{t('ips.loss.report_title')}</h2>
                    <p className="text-muted-foreground text-xs">{t('ips.loss.report_hint')}</p>
                </div>
            </div>
            {losses.rows.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('ips.loss.none')}</p>
            ) : (
                <table className="w-auto text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left">
                            <th className="py-1 pr-6">{t('ips.loss.col_reason')}</th>
                            <th className="py-1 pr-6">{t('ips.loss.col_head')}</th>
                            <th className="py-1 pr-6 text-right">{t('ips.loss.col_count')}</th>
                            <th className="py-1 text-right">{t('ips.loss.col_amount')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {losses.rows.map((row) => (
                            <tr key={row.type} className="border-b last:border-0">
                                <td className="py-1 pr-6">{row.label}</td>
                                <td className="text-muted-foreground py-1 pr-6">{row.head ?? '-'}</td>
                                <td className="py-1 pr-6 text-right tabular-nums">{row.count}</td>
                                <td className="py-1 text-right tabular-nums">{formatMoney(row.amount)}</td>
                            </tr>
                        ))}
                        <tr className="font-semibold">
                            <td className="py-1 pr-6">{t('ips.loss.total')}</td>
                            <td />
                            <td />
                            <td className="py-1 text-right tabular-nums">{formatMoney(losses.total)}</td>
                        </tr>
                    </tbody>
                </table>
            )}
        </section>
    );
}

export default function StockAdjustmentsIndex({
    adjustments,
    filters: initialFilters,
    type,
    adjustmentReasons,
    reasons,
    losses,
}: {
    adjustments: Paginated<AdjustmentRow>;
    losses: LossReport;
    filters: { status: string; focus: number | null; loss_from?: string; loss_to?: string };
    type: string;
    adjustmentReasons: AdjustmentReason[];
    reasons: { correction: Reason[]; withdrawal: Reason[]; reversal: Reason[] };
}) {
    const { t } = useTranslation();
    const { filters, setFilter, reset, hasActiveFilters } = useFilters('/inventory/stock/adjustments', {
        status: initialFilters.status,
        focus: initialFilters.focus === null ? '' : String(initialFilters.focus),
        loss_from: initialFilters.loss_from ?? '',
        loss_to: initialFilters.loss_to ?? '',
    });

    const chips: { value: string; key: TranslationKey }[] = [
        { value: '', key: 'corr.stock.adjust.all' },
        { value: 'waiting', key: 'corr.stock.adjust.status.waiting' },
        { value: 'withdrawn', key: 'corr.stock.adjust.status.withdrawn' },
        { value: 'approved', key: 'corr.stock.adjust.status.approved' },
        { value: 'reversed', key: 'corr.stock.adjust.status.reversed' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('corr.stock.adjust.title')} />
            <div className="flex flex-col gap-4 p-4">
                <Toolbar
                    note={t('corr.stock.adjust.explain')}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/inventory/stock">{t('corr.stock.adjust.back_to_stock')}</Link>
                        </Button>
                    }
                />

                <LossesByReason
                    losses={losses}
                    from={filters.loss_from}
                    to={filters.loss_to}
                    onFrom={(v) => setFilter('loss_from', v)}
                    onTo={(v) => setFilter('loss_to', v)}
                />

                <div className="flex flex-wrap items-center gap-2">
                    {chips.map((chip) => (
                        <Chip key={chip.value} active={filters.status === chip.value} onClick={() => setFilter('status', chip.value)}>
                            {t(chip.key)}
                        </Chip>
                    ))}
                    {hasActiveFilters && (
                        <button type="button" className="text-muted-foreground text-sm underline" onClick={reset}>
                            {t('corr.stock.adjust.clear')}
                        </button>
                    )}
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[60rem] text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left">
                                <th className="py-2 pr-3">{t('corr.stock.adjust.col_date')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.adjust.col_product')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.adjust.col_what')}</th>
                                <th className="py-2 pr-3 text-right">{t('corr.stock.adjust.col_qty')}</th>
                                <th className="py-2 pr-3 text-right">{t('corr.stock.adjust.col_value')}</th>
                                <th className="py-2 pr-3">{t('corr.stock.adjust.col_status')}</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {adjustments.data.map((row) => {
                                const edit = row.actions.find((a) => a.action === 'edit');
                                const withdraw = row.actions.find((a) => a.action === 'withdraw');
                                const reverse = row.actions.find((a) => a.action === 'reverse');
                                const base = `/records/${type}/${row.id}`;

                                return (
                                    <tr key={row.id} className="border-b align-top">
                                        <td className="py-2 pr-3">
                                            {row.effective_date ? showDate(row.effective_date) : ''}
                                            <span className="text-muted-foreground block text-xs">{row.number}</span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.product}
                                            <span className="text-muted-foreground block text-xs">
                                                {row.warehouse}
                                                {row.batch ? `, ${t('corr.stock.batch')} ${row.batch}` : ''}
                                            </span>
                                        </td>
                                        <td className="py-2 pr-3">
                                            {row.reason ?? row.type}
                                            {row.note && <span className="text-muted-foreground block text-xs">{row.note}</span>}
                                            <span className="text-muted-foreground block text-xs">{row.raised_by}</span>
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">
                                            {row.cost_change
                                                ? ''
                                                : `${row.direction === 'decrease' ? '-' : '+'}${formatQuantity(row.quantity)} ${row.unit ?? ''}`}
                                        </td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatMoney(row.estimated_value)}</td>
                                        <td className="py-2 pr-3">
                                            <Badge variant={statusVariant[row.status]}>{t(statusKey[row.status])}</Badge>
                                            {row.reversal && (
                                                <span className="text-muted-foreground mt-1 block max-w-xs text-xs">
                                                    {t('corr.stock.adjust.reversed_on', {
                                                        date: row.reversal.on ? showDate(row.reversal.on) : '',
                                                        by: row.reversal.by ?? '',
                                                    })}{' '}
                                                    {row.reversal.reason}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            <div className="inline-flex flex-wrap justify-end gap-2">
                                                {edit && (
                                                    <EditAdjustmentDialog
                                                        row={row}
                                                        availability={edit}
                                                        adjustmentReasons={adjustmentReasons}
                                                        correctionReasons={reasons.correction}
                                                    />
                                                )}
                                                {withdraw && (
                                                    <StockReasonDialog
                                                        trigger={
                                                            <Button size="sm" variant="outline" disabled={!withdraw.allowed}>
                                                                {t('corr.stock.withdraw')}
                                                            </Button>
                                                        }
                                                        title={t('corr.stock.adjust.withdraw_title', { number: row.number })}
                                                        description={t('corr.stock.adjust.withdraw_explain')}
                                                        confirmLabel={t('corr.stock.withdraw')}
                                                        reasons={reasons.withdrawal}
                                                        url={route('inventory.stock.adjustments.withdraw', row.id)}
                                                    />
                                                )}
                                                {reverse &&
                                                    (reverse.allowed || reverse.requestable ? (
                                                        <ActionDialog
                                                            action="reverse"
                                                            recordLabel={row.number}
                                                            previewUrl={`${base}/reverse/preview`}
                                                            submitUrl={`${base}/reverse`}
                                                            reasons={reasons.reversal}
                                                            trigger={
                                                                <Button size="sm" variant="outline">
                                                                    {t('corr.stock.reverse')}
                                                                </Button>
                                                            }
                                                        />
                                                    ) : (
                                                        <Button size="sm" variant="outline" disabled>
                                                            {t('corr.stock.reverse')}
                                                        </Button>
                                                    ))}
                                            </div>
                                            <BlockedNote availability={[edit, withdraw, reverse].find((a) => a && !a.allowed && !a.requestable)} />
                                        </td>
                                    </tr>
                                );
                            })}
                            {adjustments.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground py-4 text-center">
                                        {t('corr.stock.adjust.none')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={adjustments} />
            </div>
        </AppLayout>
    );
}
