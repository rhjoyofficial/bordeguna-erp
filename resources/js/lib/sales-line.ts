/**
 * A sales order line typed either way (Joy, 19 Oct 2026): the unit price, or
 * the line's total with the unit price worked out, as on the purchase order.
 *
 * ⚠ A sales line stores ONE figure, its unit price to four places, and the
 * server takes quantity x unit price to the paisa (Money::ofProduct, half away
 * from zero). So a typed total is turned into the four-place price whose line
 * comes back to exactly that total: 1,000 for 3 is 333.3333, and 3 x 333.3333
 * = 999.9999, which is 1,000.00 to the paisa. When no four-place price lands on
 * the total (a large quantity), the nearest one is used and `exact` says so,
 * so the form can show the total the order will really carry.
 */
export type SalesEntryMode = 'rate' | 'total';

const parse = (value: string): number | null => {
    if (value.trim() === '') {
        return null;
    }

    const parsed = Number(value.replace(/,/g, ''));

    return Number.isFinite(parsed) ? parsed : null;
};

/** quantity x unit price in paisa, rounded as the server rounds it. */
const linePaisa = (quantity: number, unitPrice: number): number => Math.round(quantity * unitPrice * 100 + 1e-7);

/** quantity x unit price as "1234.56", or '' while either box is not a number. */
export const salesLineTotal = (quantity: string, unitPrice: string): string => {
    const q = parse(quantity);
    const p = parse(unitPrice);

    return q === null || p === null ? '' : (linePaisa(q, p) / 100).toFixed(2);
};

/**
 * The four-place unit price for a typed line total, and whether quantity x that
 * price is exactly the total. Null while either box is not a usable number.
 */
export const salesUnitPriceFor = (quantity: string, lineTotal: string): { unitPrice: string; exact: boolean; total: string } | null => {
    const q = parse(quantity);
    const total = parse(lineTotal);

    if (q === null || total === null || q <= 0 || total < 0) {
        return null;
    }

    const wanted = Math.round(total * 100);
    const start = Math.round((total / q) * 10000);
    let best = start;

    // The exact price, when there is one, sits within a few ten-thousandths
    // of total / quantity; look either side and keep the closest.
    for (let step = 0; step <= 50; step++) {
        for (const candidate of step === 0 ? [start] : [start + step, start - step]) {
            if (candidate < 0) continue;

            if (linePaisa(q, candidate / 10000) === wanted) {
                return { unitPrice: (candidate / 10000).toFixed(4), exact: true, total: (wanted / 100).toFixed(2) };
            }

            if (Math.abs(linePaisa(q, candidate / 10000) - wanted) < Math.abs(linePaisa(q, best / 10000) - wanted)) {
                best = candidate;
            }
        }
    }

    return { unitPrice: (best / 10000).toFixed(4), exact: false, total: (linePaisa(q, best / 10000) / 100).toFixed(2) };
};

/** "842.5000" shown as "842.50", "333.3333" kept: never more places than it needs, never fewer than two. */
export const plainPrice = (value: string): string => {
    const parsed = parse(value);

    if (parsed === null) {
        return value;
    }

    const fixed = parsed.toFixed(4).replace(/0{1,2}$/, '');

    return fixed;
};
