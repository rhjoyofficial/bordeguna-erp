<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockMovement;

/**
 * What a stock movement is worth. THE definition, and deliberately the only one.
 *
 * ⚠⚠ WHY THIS CLASS EXISTS. "The value of a movement" was implemented twice,
 * independently, and the two answers disagreed:
 *
 *   - `PostInventoryAdjustmentJournal::valueOf()` knew that `value_delta` wins
 *     when it is set, because a revaluation carries its money there.
 *   - `SubledgerReconciliation::reconcileStockOnHand()` did not, and valued
 *     every movement as `quantity x unit_cost`.
 *
 * A revaluation is written with `quantity = 0` (deliberately, so it stays
 * invisible to the QUANTITY chain), so the second expression valued it at
 * nothing while the first posted it to the ledger in full. The ledger moved and
 * the figure it is reconciled against did not, which is a permanent, unclearable
 * break equal to the revaluation - plus a second, spurious warning that the
 * batch cache disagrees with the movements, because the batch's `unit_cost` IS
 * updated by a revaluation.
 *
 * Both suites were green throughout. `StockRevaluationTest` revalues and never
 * reconciles; `SubledgerReconciliationTest` reconciles and never revalues. Each
 * covered its own half of a seam that only breaks in the middle.
 *
 * ⚠⚠ AND THE SIGN. Fixing the first defect uncovered a worse one. A revaluation
 * stores `value_delta` as a POSITIVE MAGNITUDE - `CreateInventoryAdjustment::
 * estimateRevaluation()` returns `abs($delta)` - and carries its direction on
 * the movement, exactly as every other movement does. But `valueOf()` returned
 * `value_delta` WITHOUT applying that direction. So a write-DOWN posted a
 * positive amount, and a positive amount is a DEBIT: stock written down to net
 * realisable value INCREASED the inventory account and CREDITED the write-off
 * expense. Precisely backwards, for twice the amount.
 *
 * The rule, in one place, applied by everyone:
 *
 *   value  = |value_delta| when set, else quantity x unit_cost
 *   sign   = ALWAYS from the movement's own direction: `in` raises inventory,
 *            `out` lowers it
 *
 * ⚠ `value_delta` wins over the product when both are present. That is not new
 * behaviour - it is the posting engine's existing rule, moved here so that it
 * is stated once instead of remembered twice.
 *
 * ⚠ `absolute()` before the sign is applied is defensive, not decorative. The
 * magnitude is written by `abs()` today; if a future caller ever stores a signed
 * delta, a doubly-negated write-down would post as an increase and balance
 * perfectly while being wrong - the exact failure mode this class was created to
 * end.
 *
 * See INVENTORY-VALUATION-ARCHITECTURE.md, C-IVL-1.
 */
final class StockValue
{
    /**
     * Signed the way stock moved: positive raises inventory, negative lowers it.
     *
     * ⚠ Integer-scaled through `Money`, rounded once. A quantity of 0.1 at a
     * cost of 0.29 is where a float would show first, and this project has paid
     * for decimal handling four separate times.
     */
    public static function of(StockMovement $movement): Money
    {
        $amount = $movement->value_delta !== null
            ? Money::ofTaka((string) $movement->value_delta)->absolute()
            : Money::ofProduct((string) $movement->quantity, (string) $movement->unit_cost);

        return $movement->direction === MovementDirection::In
            ? $amount
            : $amount->negated();
    }

    /**
     * The net value of a set of movements.
     *
     * ⚠ Every movement is valued and rounded individually before being summed,
     * never aggregated in SQL. `SubledgerReconciliation` and `Ageing` both do it
     * this way for the same reason (C-REC-6): rounding order differs between a
     * per-row and a whole-column sum, and a control figure that manufactures
     * paisa of its own teaches people to ignore the one that matters.
     *
     * @param  iterable<int, StockMovement>  $movements
     */
    public static function sum(iterable $movements): Money
    {
        $values = [];

        foreach ($movements as $movement) {
            $values[] = self::of($movement);
        }

        return Money::sum($values);
    }

    /**
     * The columns `of()` needs. Named so a caller selecting a partial column
     * list cannot forget `value_delta` - forgetting it is silent, and produces
     * exactly the defect this class was written to fix.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['direction', 'quantity', 'unit_cost', 'value_delta'];
    }
}
