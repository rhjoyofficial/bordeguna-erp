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
