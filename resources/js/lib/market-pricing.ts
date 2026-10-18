/**
 * Market pricing rules the screens share, kept pure so tests/js can RUN them
 * (tests/Unit/MarketPricingUiTest.php).
 *
 * ⭐ 18 Oct 2026 (Joy, DECISIONS-JOY-2026-10-18 D-E; practice P16): no markup
 * rules and no suggestion in Phase 1. A market product shows no MRP box, no
 * rule boxes and no group price table on the product form, only a note that
 * its price is set as today's price at each goods receipt or on Selling
 * prices. The data is unchanged: the columns stay and are ignored.
 */

export type PricingModeValue = 'fixed_mrp' | 'market';

export interface PricingSections {
    /** The printed MRP box. */
    mrp: boolean;
    /** The markup / minimum margin / rounding step boxes (never in Phase 1). */
    rules: boolean;
    /** The per-group price table. */
    groupPrices: boolean;
    /** The "Price is set as today's price..." note. */
    todaysPriceNote: boolean;
}

/** Which pricing parts of the product form show for a pricing mode. */
export function pricingSectionsFor(mode: PricingModeValue | string | null | undefined): PricingSections {
    const market = mode === 'market';

    return { mrp: !market, rules: false, groupPrices: !market, todaysPriceNote: market };
}

/**
 * Where a typed price stands against the batch cost: below it is a markdown
 * (warning, reason, permission), otherwise fine. The server decides again
 * (ProductPricing::refusalFor); this only says it before the round trip.
 */
export function priceStanding(price: string, cost: string | null | undefined): 'blank' | 'below_cost' | 'ok' {
    if (cost === null || cost === undefined || price.trim() === '') return 'blank';
    const typed = parseFloat(price);
    if (!Number.isFinite(typed) || typed <= 0) return 'blank';
    if (typed < parseFloat(cost)) return 'below_cost';
    return 'ok';
}
