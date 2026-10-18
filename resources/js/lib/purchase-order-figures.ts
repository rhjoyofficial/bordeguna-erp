/**
 * ⭐ The full purchase order page's arithmetic (purchase redesign PR1, 18 Oct
 * 2026), mirroring PurchaseOrderFigures on the server for the LIVE preview:
 * per line gross, its own discount, its share of the order discount, VAT on
 * what is left; then charges, rounding and the supplier's total; and the
 * landed cost per unit each line will carry once the charges reach it.
 *
 * ⚠ DISPLAY ONLY, in integer paisa. The server works every figure out again
 * and its answer is the one that is saved.
 */
import { linePaisaOf, type EntryMode } from '@/lib/purchase-line';

export type DiscountType = '' | 'percent' | 'amount';

export type FigureLine = {
    quantity: string;
    unit_cost: string;
    line_total: string;
    entry_mode: EntryMode;
    discount_type: DiscountType;
    discount_value: string;
    tax_rate: string;
    /** Purchase units per one typed unit (1 when typed in the purchase unit). */
    factor: number;
    /** Grams per purchase unit, for a weight split; null when unknown. */
    weight_grams: number | null;
};

export type FigureCharge = {
    amount: string;
    allocation_method: 'value' | 'quantity' | 'weight' | 'direct';
    line_index: number | null;
    into_cost: boolean;
    /** Owed to the order's own supplier (it adds to what they are owed). */
    to_this_supplier: boolean;
};

export type WorkedLine = {
    gross: number;
    discount: number;
    orderDiscount: number;
    net: number;
    vat: number;
    /** Capitalised charges this line carries. */
    charges: number;
    /** Into stock per typed unit: net (+ VAT while VAT is off) + charges, over the quantity. */
    landedPerUnit: number | null;
};

const num = (value: string): number => {
    const parsed = Number(String(value ?? '').replace(/,/g, ''));

    return Number.isFinite(parsed) ? parsed : 0;
};

export const paisa = (value: string): number => Math.round(num(value) * 100);

/** A discount in paisa: percent of `of` (half up) or an amount. */
export function discountOf(type: DiscountType, value: string, of: number): number {
    if (type === '' || String(value ?? '').trim() === '') {
        return 0;
    }

    return type === 'percent' ? Math.round((of * num(value)) / 100) : paisa(value);
}

/** `amount` shared in proportion to `weights`, exactly; the largest weight takes the remainder. */
export function spread(amount: number, weights: number[]): number[] {
    const total = weights.reduce((sum, w) => sum + w, 0);
    const shares = weights.map(() => 0);

    if (amount === 0 || total <= 0) {
        return shares;
    }

    weights.forEach((w, i) => {
        shares[i] = Math.floor((amount * w) / total);
    });

    const largest = weights.indexOf(Math.max(...weights));
    shares[largest] += amount - shares.reduce((sum, s) => sum + s, 0);

    return shares;
}

export function workOrder(
    lines: FigureLine[],
    order: { discount_type: DiscountType; discount_value: string; rounding: string; vat_in_cost: boolean },
    charges: FigureCharge[],
) {
    const gross = lines.map((line) => linePaisaOf(line) ?? 0);
    const own = lines.map((line, i) => Math.min(gross[i], discountOf(line.discount_type, line.discount_value, gross[i])));
    const bases = gross.map((g, i) => g - own[i]);
    const base = bases.reduce((sum, b) => sum + b, 0);
    const orderDiscount = Math.min(base, discountOf(order.discount_type, order.discount_value, base));
    const shares = spread(orderDiscount, bases);
    const net = bases.map((b, i) => b - shares[i]);
    const vat = net.map((n, i) => Math.round((n * num(lines[i].tax_rate)) / 100));

    // The capitalised charges, spread as the landed-cost engine will.
    const carried = lines.map(() => 0);
    const quantities = lines.map((line) => num(line.quantity) * (line.factor || 1));
    const weights = lines.map((line, i) => (line.weight_grams ? line.weight_grams * quantities[i] : 0));

    for (const charge of charges) {
        if (!charge.into_cost) {
            continue;
        }

        const amount = paisa(charge.amount);
        let split: number[];

        if (charge.allocation_method === 'direct') {
            split = lines.map((_, i) => (i === charge.line_index ? amount : 0));
        } else if (charge.allocation_method === 'quantity') {
            split = spread(
                amount,
                quantities.map((q) => Math.round(q * 10000)),
            );
        } else if (charge.allocation_method === 'weight') {
            split = spread(
                amount,
                weights.map((w) => Math.round(w)),
            );
        } else {
            split = spread(amount, gross);
        }

        split.forEach((s, i) => {
            carried[i] += s;
        });
    }

    const worked: WorkedLine[] = lines.map((line, i) => {
        const quantity = num(line.quantity);
        const intoStock = net[i] + (order.vat_in_cost ? vat[i] : 0) + carried[i];

        return {
            gross: gross[i],
            discount: own[i],
            orderDiscount: shares[i],
            net: net[i],
            vat: vat[i],
            charges: carried[i],
            landedPerUnit: quantity > 0 ? intoStock / quantity : null,
        };
    });

    const goods = gross.reduce((sum, g) => sum + g, 0);
    const discounts = own.reduce((sum, d) => sum + d, 0) + orderDiscount;
    const vatTotal = vat.reduce((sum, v) => sum + v, 0);
    const chargesTotal = charges.reduce((sum, c) => sum + paisa(c.amount), 0);
    const supplierCharges = charges.reduce((sum, c) => sum + (c.to_this_supplier ? paisa(c.amount) : 0), 0);
    const rounding = paisa(order.rounding);
    const grand = goods - discounts + vatTotal + supplierCharges + rounding;

    return {
        lines: worked,
        goods,
        discounts,
        orderDiscount,
        vat: vatTotal,
        charges: chargesTotal,
        supplierCharges,
        otherCharges: chargesTotal - supplierCharges,
        rounding,
        grand,
        /** Everything this purchase costs, whoever is paid. */
        totalCost: goods - discounts + vatTotal + chargesTotal + rounding,
    };
}

/** Rounding that brings `total` paisa to the nearest whole taka. */
export const roundingToTaka = (total: number): number => Math.round(total / 100) * 100 - total;
