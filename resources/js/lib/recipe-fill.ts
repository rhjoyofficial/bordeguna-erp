/**
 * ⚠ P9 (18 Oct 2026, practice run): on the new production run, picking the
 * output product BEFORE typing a quantity left the input lines empty, and
 * typing the quantity afterwards did not fill them; only "Re-apply the recipe"
 * did. The old test for "untouched" compared the table with a remembered copy,
 * which a re-render or a blank row could defeat.
 *
 * Now the rule is said once, and the form only asks it: while the operator has
 * not edited a line by hand, the recipe owns the table. With a quantity it
 * fills products AND amounts; without one it lists the products and waits.
 * After a hand edit, nothing is overwritten ("Re-apply the recipe" stays the
 * way back).
 */
export type RecipeStep = 'fill' | 'products-only' | 'leave';

export function recipeStep({ hasRecipe, quantity, touched }: { hasRecipe: boolean; quantity: string; touched: boolean }): RecipeStep {
    if (!hasRecipe || touched) {
        return 'leave';
    }

    const amount = Number(quantity);

    return quantity.trim() !== '' && Number.isFinite(amount) && amount > 0 ? 'fill' : 'products-only';
}

/**
 * ⭐ PR3 (18 Oct 2026, Tally's Manufacturing Journal both ways). The arithmetic
 * the run form fills with, kept here so it is said once. It mirrors
 * BomSuggestion and MaxMakeable on the server: four places, wastage included,
 * whole jars filled UP and whole packs rounded DOWN. A suggestion, never the
 * record: the server converts and recomputes the standard on save.
 */
export interface RecipeLineSpec {
    product_id: number;
    quantity_per_output: string;
    wastage_percent: string;
}

const round4 = (value: number): number => Math.round((value + Number.EPSILON) * 10000) / 10000;
// A hair of tolerance so 24.999999999 from float division still reads as 25.
const floor4 = (value: number): number => Math.floor(value * 10000 + 1e-6) / 10000;

/** How much of a component ONE unit of output needs, wastage included. */
export function perOutputUnit(line: RecipeLineSpec, recipeOutput: string): number {
    const batch = Number(recipeOutput) || 0;

    if (batch <= 0) {
        return 0;
    }

    return (Number(line.quantity_per_output) / batch) * (1 + (Number(line.wastage_percent) || 0) / 100);
}

/** The component quantity, in its stock unit, for `outputQuantity` units of output. */
export function suggestQuantity(line: RecipeLineSpec, recipeOutput: string, outputQuantity: number, wholeOnly: boolean): string {
    const per = perOutputUnit(line, recipeOutput);

    if (per <= 0 || !(outputQuantity > 0)) {
        return '';
    }

    const rounded = round4(per * outputQuantity);

    return String(wholeOnly ? Math.ceil(rounded) : rounded);
}

/**
 * The output one component's quantity (in its stock unit) supports: the
 * reverse fill. Rounded down, to a whole number when the output is counted.
 */
export function outputFromComponent(line: RecipeLineSpec, recipeOutput: string, componentStockQuantity: number, outputWholeOnly: boolean): string {
    const per = perOutputUnit(line, recipeOutput);

    if (per <= 0 || !(componentStockQuantity > 0)) {
        return '';
    }

    const output = floor4(componentStockQuantity / per);

    return String(outputWholeOnly ? Math.floor(output + 1e-9) : output);
}

/** A typed quantity into the stock unit, by the unit's preview factor. */
export function toStock(quantity: string, factor: string | undefined): number {
    return round4((Number(quantity) || 0) * (Number(factor ?? '1') || 1));
}

/** A stock quantity re-expressed in another unit, for a unit change on a line. */
export function fromStock(stockQuantity: number, factor: string | undefined): string {
    const f = Number(factor ?? '1') || 1;

    return stockQuantity > 0 ? String(round4(stockQuantity / f)) : '';
}

export interface CostLayer {
    quantity: string;
    unit_cost: string;
}

/**
 * What FIFO would charge for `quantity` from these layers, in paisa, rounded
 * once. Past the last layer the remainder is priced at the last layer's cost
 * and `short` says so; confirm would refuse that run anyway.
 */
export function fifoEstimate(layers: CostLayer[] | undefined, quantity: number): { paisa: number; short: boolean } {
    let remaining = quantity;
    let taka = 0;
    let lastCost = 0;

    for (const layer of layers ?? []) {
        if (remaining <= 0) {
            break;
        }

        const take = Math.min(remaining, Number(layer.quantity));
        lastCost = Number(layer.unit_cost);
        taka += take * lastCost;
        remaining = round4(remaining - take);
    }

    const short = remaining > 0;

    if (short) {
        taka += remaining * lastCost;
    }

    return { paisa: Math.round(taka * 100), short };
}
