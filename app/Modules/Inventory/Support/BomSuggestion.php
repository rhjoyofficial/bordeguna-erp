<?php

namespace App\Modules\Inventory\Support;

use InvalidArgumentException;

/**
 * How much of a component a recipe suggests, for a given quantity of output.
 *
 * ⚠⚠ C-BOM-3. A SUGGESTION, NEVER A DECISION. Everything this class returns is
 * pre-filled into an editable box on the production run screen. The run records
 * what the operator says was actually consumed; this only saves typing.
 *
 * ⚠⚠ C-BOM-4. NO UNIT CONVERSION, at any point. The quantity that comes out is
 * in the component's own stock unit, because that is the unit the recipe was
 * written in. Mustard oil is why: 1 kg = 1.1 L and 1 L = 0.9 kg are not
 * reciprocals, so any class that "helpfully" converted would silently choose
 * one of two answers 21% apart.
 *
 * ⚠ STRING ARITHMETIC through bcmath, not float. A quantity here becomes a
 * `standard_quantity` that a variance is measured against, and this project has
 * paid for float arithmetic on decimal columns before.
 */
final class BomSuggestion
{
    /** The scale quantity columns hold: decimal(15,4). */
    private const SCALE = 4;

    /** Enough headroom that the rounding at the end is the only rounding. */
    private const WORKING_SCALE = 12;

    /**
     * `quantityPerOutput` of a component, for `outputQuantity` units of output,
     * where the recipe is written per `recipeOutputQuantity` units - plus a
     * wastage allowance.
     *
     * 0.4 kg per 1 pcs, for 250 pcs, at 2% wastage → 102.0000 kg.
     */
    public static function forOutput(
        string $quantityPerOutput,
        string $outputQuantity,
        string $recipeOutputQuantity,
        string $wastagePercent = '0',
        bool $wholeUnits = false,
    ): string {
        if (bccomp($recipeOutputQuantity, '0', self::WORKING_SCALE) <= 0) {
            // ⚠ A recipe written "per zero units of output" has no meaning and
            // would be a division by zero. Refused where it is entered, and
            // refused again here rather than trusted.
            throw new InvalidArgumentException('A recipe must state a positive output quantity.');
        }

        $perUnit = bcdiv($quantityPerOutput, $recipeOutputQuantity, self::WORKING_SCALE);
        $scaled = bcmul($perUnit, $outputQuantity, self::WORKING_SCALE);

        // C-BOM-5. The allowance inflates the suggestion and goes no further.
        $withWastage = bcdiv(
            bcmul($scaled, bcadd('100', $wastagePercent, self::WORKING_SCALE), self::WORKING_SCALE),
            '100',
            self::WORKING_SCALE
        );

        $rounded = self::round($withWastage);

        // ⭐ PR3 (18 Oct 2026): a component counted in whole units (jar, pcs,
        // bottle) is suggested UP to the next whole one. 10.2 jars is not a
        // quantity anybody can take off the shelf, and the run refused it as
        // "not whole" after the recipe had suggested it.
        return $wholeUnits ? self::ceilWhole($rounded) : $rounded;
    }

    /**
     * How much of a component ONE unit of output needs, wastage included, at
     * the working scale. MaxMakeable divides the shelf by this.
     */
    public static function perOutputUnit(string $quantityPerOutput, string $recipeOutputQuantity, string $wastagePercent = '0'): string
    {
        if (bccomp($recipeOutputQuantity, '0', self::WORKING_SCALE) <= 0) {
            throw new InvalidArgumentException('A recipe must state a positive output quantity.');
        }

        return bcdiv(
            bcmul(bcdiv($quantityPerOutput, $recipeOutputQuantity, self::WORKING_SCALE), bcadd('100', $wastagePercent, self::WORKING_SCALE), self::WORKING_SCALE),
            '100',
            self::WORKING_SCALE
        );
    }

    /** `10.2000` → `11.0000`, `10.0000` stays. Non-negative quantities only. */
    public static function ceilWhole(string $value): string
    {
        $whole = bcadd($value, '0', 0);

        return bccomp($value, $whole, self::SCALE) > 0
            ? bcadd($whole, '1', self::SCALE)
            : bcadd($whole, '0', self::SCALE);
    }

    /**
     * Half away from zero at four places, because bcmath truncates and a
     * truncated allowance is a smaller allowance than the one that was typed.
     */
    private static function round(string $value): string
    {
        $negative = bccomp($value, '0', self::WORKING_SCALE) < 0;
        $absolute = $negative ? bcmul($value, '-1', self::WORKING_SCALE) : $value;

        $bumped = bcadd($absolute, '0.00005', self::WORKING_SCALE);
        $rounded = bcadd($bumped, '0', self::SCALE);

        return $negative && bccomp($rounded, '0', self::SCALE) !== 0 ? '-'.$rounded : $rounded;
    }
}
