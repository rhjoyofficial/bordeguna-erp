import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBatchOptions, type BatchOption } from '@/hooks/use-batch-options';
import { formatDate } from '@/lib/dates';
import { useTranslation } from '@/lib/i18n';
import { decimalInput, formatMoney, formatQuantity } from '@/lib/utils';
import { useEffect } from 'react';

/** One batch picked for a production input line, in the product's stock unit. */
// A type, not an interface: Inertia's form data needs it index-compatible.
export type BatchPick = {
    stock_batch_id: string;
    quantity: string;
};

const selectClass = 'h-9 min-w-0 rounded-md border border-input bg-background px-2 text-sm';

/** Expired (on the run's day) or on hold: listed so the person sees it, never pickable. */
const refused = (batch: BatchOption, runDate: string) => batch.on_hold || (batch.expiry_date !== null && batch.expiry_date < runDate);

/**
 * ⭐ Initial plan B8 (Joy, 24 Oct 2026; Q8 = B, R31): the batch picker under
 * every input line of a production run. No proposal: the person picks every
 * batch, from the run's warehouse only (other warehouses never appear: transfer
 * the stock first). Each option says the supplier, the batch's own cost, its
 * expiry and how much is left; expired and on-hold batches are shown but cannot
 * be picked. A line may be split across several batches; together they must
 * equal what the line uses, in the stock unit. The server checks it all again.
 */
export function ProductionBatchPicker({
    productId,
    warehouseId,
    runDate,
    unit,
    needed,
    picks,
    onChange,
    onCost,
    error,
}: {
    productId: string;
    warehouseId: string;
    runDate: string;
    unit: string;
    /** What the line uses, in the stock unit. */
    needed: number;
    picks: BatchPick[];
    onChange: (picks: BatchPick[]) => void;
    /** The exact cost of the picks in paisa, or null while they do not cover the line. */
    onCost: (paisa: number | null) => void;
    error?: string;
}) {
    const { t } = useTranslation();
    const { batches, loading } = useBatchOptions(productId, warehouseId, productId !== '' && warehouseId !== '');
    const byId = new Map((batches ?? []).map((b) => [String(b.id), b]));
    const picked = picks.reduce((sum, pick) => sum + (Number(pick.quantity) || 0), 0);
    const matches = needed > 0 && Math.abs(picked - needed) < 0.00005;

    const costPaisa = picks.reduce((sum, pick) => {
        const batch = byId.get(pick.stock_batch_id);

        return batch ? sum + Math.round((Number(pick.quantity) || 0) * Number(batch.unit_cost) * 100) : sum;
    }, 0);

    useEffect(() => {
        onCost(matches && picks.every((p) => byId.has(p.stock_batch_id)) ? costPaisa : null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [matches, costPaisa, JSON.stringify(picks), batches]);

    if (!productId) {
        return null;
    }

    const setPick = (index: number, patch: Partial<BatchPick>) => onChange(picks.map((pick, i) => (i === index ? { ...pick, ...patch } : pick)));

    const choose = (index: number, batchId: string) => {
        const batch = byId.get(batchId);
        const rest = needed - picks.reduce((sum, pick, i) => (i === index ? sum : sum + (Number(pick.quantity) || 0)), 0);
        // The quantity box fills with what the line still needs, up to what the batch holds; the person may change it.
        const fill =
            picks[index].quantity === '' && batch && rest > 0 ? String(Math.min(rest, Number(batch.current_quantity))) : picks[index].quantity;
        setPick(index, { stock_batch_id: batchId, quantity: fill });
    };

    const label = (batch: BatchOption) =>
        [
            batch.batch_number,
            batch.supplier ?? null,
            t('ipc.batch.each', { cost: `৳${formatMoney(batch.unit_cost)}` }),
            batch.expiry_date ? t('ipc.batch.expires', { date: formatDate(batch.expiry_date) }) : null,
            t('ipc.batch.left', { quantity: formatQuantity(batch.current_quantity), unit }),
            batch.expiry_date !== null && batch.expiry_date < runDate ? t('ipc.batch.expired') : null,
            batch.on_hold ? t('ipc.batch.on_hold') : null,
        ]
            .filter(Boolean)
            .join(' . ');

    return (
        <div className="flex flex-col gap-2">
            <p className="text-muted-foreground text-xs">{t('ips.run.batches_hint')}</p>
            {loading && batches === null && <p className="text-muted-foreground text-sm">{t('ipc.batch.loading')}</p>}
            {batches !== null && batches.length === 0 && <p className="text-destructive text-sm">{t('ips.run.no_batches')}</p>}
            {picks.map((pick, index) => (
                <div key={index} className="flex flex-wrap items-center gap-2">
                    <select
                        className={selectClass}
                        aria-label={t('ips.run.batch')}
                        value={pick.stock_batch_id}
                        onChange={(e) => choose(index, e.target.value)}
                    >
                        <option value="">{t('ips.run.choose_batch')}</option>
                        {(batches ?? []).map((batch) => (
                            <option key={batch.id} value={batch.id} disabled={refused(batch, runDate)}>
                                {label(batch)}
                            </option>
                        ))}
                    </select>
                    <Input
                        className="w-28"
                        inputMode="decimal"
                        aria-label={t('ips.run.quantity')}
                        value={pick.quantity}
                        onChange={(e) => setPick(index, { quantity: decimalInput(e.target.value) })}
                    />
                    <span className="text-muted-foreground text-sm">{unit}</span>
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange(picks.filter((_, i) => i !== index))}>
                        {t('ips.run.remove_batch')}
                    </Button>
                </div>
            ))}
            <div className="flex flex-wrap items-center gap-3">
                <Button type="button" variant="outline" size="sm" onClick={() => onChange([...picks, { stock_batch_id: '', quantity: '' }])}>
                    {picks.length === 0 ? t('ips.run.pick_batch') : t('ips.run.add_batch')}
                </Button>
                {needed > 0 && (
                    <span
                        className={matches ? 'text-muted-foreground text-xs tabular-nums' : 'text-xs text-amber-700 tabular-nums dark:text-amber-400'}
                    >
                        {t('ips.run.picked', { picked: formatQuantity(picked), needed: formatQuantity(needed), unit })}
                    </span>
                )}
            </div>
            <InputError message={error} />
        </div>
    );
}
