/**
 * A purchase line typed either way (Phase 1 part A, 17 Oct 2026).
 *
 * ⚠ DISPLAY ARITHMETIC ONLY. The box typed last (`entry_mode`) is the truth
 * and the server (PurchaseLineEntry) derives the other figure again from it
 * with integer arithmetic. What this file works out is what the person sees
 * while typing, so the two boxes agree on screen; nothing here is posted.
 *
 * ⚠ The derived rate is shown to six places and a total is never rebuilt from
 * it: 3,000 units for 100,000 shows 33.333333, and 3,000 x 33.333333 is
 * 99,999.999, which is the whole reason the total is what gets stored.
 */
export type EntryMode = 'rate' | 'total';

const number = (value: string): number | null => {
    if (value.trim() === '') {
        return null;
    }

    const parsed = Number(value.replace(/,/g, ''));

    return Number.isFinite(parsed) ? parsed : null;
};

/** quantity x unit price as "1234.56", or '' while either box is not a number. */
export const deriveLineTotal = (quantity: string, unitPrice: string): string => {
    const q = number(quantity);
    const p = number(unitPrice);

    return q === null || p === null ? '' : (Math.round(q * p * 100) / 100).toFixed(2);
};

/** total / quantity as "33.333333", or '' while either box is not a number or the quantity is 0. */
export const deriveUnitPrice = (quantity: string, lineTotal: string): string => {
    const q = number(quantity);
    const total = number(lineTotal);

    return q === null || total === null || q === 0 ? '' : (total / q).toFixed(6);
};

/** The line's money in paisa from its TYPED figure, or null while that is not a number. */
export const linePaisaOf = (line: { quantity: string; unit_cost: string; line_total: string; entry_mode: EntryMode }): number | null => {
    if (line.entry_mode === 'total') {
        const total = number(line.line_total);

        return total === null ? null : Math.round(total * 100);
    }

    const q = number(line.quantity);
    const p = number(line.unit_cost);

    return q === null || p === null ? null : Math.round(q * p * 100);
};

/**
 * What a quantity of an ORDER line is worth, mirroring PurchaseOrderLine::valueOf
 * for the variance preview on the bill form: the full quantity is the stored
 * total exactly; a part of a total-typed line is its paisa share; a part of a
 * rate-typed line is quantity x rate. In paisa.
 */
export const orderValuePaisa = (
    line: { quantity_ordered: string; unit_cost: string; line_total: string; entry_mode: EntryMode },
    quantity: string,
): number | null => {
    const q = number(quantity);
    const ordered = number(line.quantity_ordered);
    const total = number(line.line_total);

    if (q === null || ordered === null || total === null) {
        return null;
    }

    if (q === ordered) {
        return Math.round(total * 100);
    }

    if (line.entry_mode === 'total') {
        return ordered === 0 ? 0 : Math.round((total * 100 * q) / ordered);
    }

    const rate = number(line.unit_cost);

    return rate === null ? null : Math.round(q * rate * 100);
};
