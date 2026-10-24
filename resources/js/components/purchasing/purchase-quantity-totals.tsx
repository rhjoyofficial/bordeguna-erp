import { useTranslation } from '@/lib/i18n';

export type QuantityRow = {
    /** As typed, in the line's own unit. */
    quantity: string;
    /** The line's unit, for grouping ("kg", "pc"). */
    unit: string | null;
    /** How many purchase units one of the line's unit is (1 when typed in the purchase unit). */
    factor?: number;
    /** Grams per purchase unit, from the product; null when nobody has weighed it. */
    weight_grams: number | null;
};

const num = (value: string) => Number(value) || 0;
const show = (value: number) => (Math.round(value * 1000) / 1000).toLocaleString('en-US', { maximumFractionDigits: 3 });

/**
 * ⭐ Initial plan B1 (R51, 25 Oct 2026): "Total net weight / total quantity"
 * under the item grid. Quantities are added per unit (10 kg and 5 pc are not
 * 15 of anything); the weight adds every line whose product has a weight, and
 * says how many lines have none, so a short total is never mistaken for a
 * full one. Display only: nothing here is saved.
 */
export function PurchaseQuantityTotals({ rows }: { rows: QuantityRow[] }) {
    const { t } = useTranslation();
    const filled = rows.filter((row) => num(row.quantity) > 0);

    if (filled.length === 0) {
        return null;
    }

    const byUnit = new Map<string, number>();
    let grams = 0;
    let unweighed = 0;

    for (const row of filled) {
        const unit = row.unit ?? '';
        byUnit.set(unit, (byUnit.get(unit) ?? 0) + num(row.quantity));

        if (row.weight_grams) {
            grams += row.weight_grams * num(row.quantity) * (row.factor || 1);
        } else {
            unweighed += 1;
        }
    }

    const quantity = Array.from(byUnit.entries())
        .map(([unit, total]) => `${show(total)}${unit ? ` ${unit}` : ''}`)
        .join(', ');

    return (
        <p className="text-sm" data-testid="purchase-quantity-totals">
            <span className="text-muted-foreground">{t('ipp.totals.quantity')}</span> <span className="font-medium tabular-nums">{quantity}</span>
            <span className="text-muted-foreground ml-4">{t('ipp.totals.weight')}</span>{' '}
            <span className="font-medium tabular-nums">{grams > 0 ? `${show(grams / 1000)} kg` : '-'}</span>
            {unweighed > 0 && <span className="text-muted-foreground ml-2 text-xs">{t('ipp.totals.unweighed', { count: unweighed })}</span>}
        </p>
    );
}
