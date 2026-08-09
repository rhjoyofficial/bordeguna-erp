<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;

/**
 * The arithmetic that turns an invoiced purchase quantity into stocked
 * quantity, and the invoice total into a cost per stock unit.
 *
 * ⚠⚠ INTEGER-SCALED, NEVER FLOAT, for the same reason FIFO and `Money` are.
 * `quantity * factor` here feeds every cost layer this product will ever have;
 * a floating-point tail becomes a cost of goods that is a paisa out on every
 * sale and cannot be traced back.
 *
 * Design note: UNIT-CONVERSION-ARCHITECTURE.md, C-UOM-7.
 */
final class PurchaseUnitConversion
{
    /** Quantities are decimal(15,4). */
    private const QUANTITY_SCALE = 10000;

    /** The factor is decimal(15,8): it multiplies every kilogram ever bought. */
    private const FACTOR_SCALE = 100000000;

    /**
     * Purchase quantity times the factor, rounded to the four places a quantity
     * column holds.
     *
     * 100.0000 kg at 1.09051254 litres per kilogram is 109.051254 litres, which
     * the column can only hold as 109.0513.
     */
    public static function toStockQuantity(string $purchaseQuantity, string $factor): string
    {
        $quantity = self::scale($purchaseQuantity, self::QUANTITY_SCALE);
        $scaledFactor = self::scale($factor, self::FACTOR_SCALE);

        $product = $quantity * $scaledFactor;   // scaled by QUANTITY_SCALE * FACTOR_SCALE

        return self::unscale(self::divideRounded($product, self::FACTOR_SCALE), self::QUANTITY_SCALE);
    }

    /**
     * The other direction: how many purchase units a given stock quantity came
     * from. Used only when a purchase return is satisfied partly by one batch
     * and has to carry the rest to the next.
     *
     * ⚠ Deliberately NOT used to convert a whole return. Dividing back through
     * the factor loses a ten-thousandth each time, and doing it once per batch
     * instead of once per line keeps that loss where it can be seen.
     */
    public static function toPurchaseQuantity(string $stockQuantity, string $factor): string
    {
        $quantity = self::scale($stockQuantity, self::QUANTITY_SCALE);
        $scaledFactor = self::scale($factor, self::FACTOR_SCALE);

        if ($scaledFactor === 0) {
            return '0.0000';
        }

        return self::unscale(self::divideRounded($quantity * self::FACTOR_SCALE, $scaledFactor), self::QUANTITY_SCALE);
    }

    /**
     * The cost of ONE stock unit, derived from the invoice total and the ROUNDED
     * stock quantity.
     *
     * ⚠⚠ DERIVED FROM THE TOTAL, NOT CONVERTED SEPARATELY. Converting the unit
     * cost on its own - dividing cost-per-kilogram by the factor - leaves the
     * stock value a few paisa away from the supplier invoice on every receipt,
     * and those paisa land in the ledger where nobody can explain them. Dividing
     * the total by the quantity that was actually stored keeps the two made of
     * the same numbers.
     *
     * ⚠ It still cannot be exact. `unit_cost` is decimal(15,4), so
     * `quantity * unit_cost` reproduces the total only to within half of the last
     * place per unit - at most 0.00005 * quantity taka. That residue is real,
     * bounded, and routed to the rounding-difference account by
     * `LedgerRounding`, never absorbed. C-UOM-7.
     */
    public static function toStockUnitCost(Money $total, string $stockQuantity): string
    {
        $quantity = self::scale($stockQuantity, self::QUANTITY_SCALE);

        if ($quantity === 0) {
            return '0.0000';
        }

        // minor units are paisa (1e2); we want taka at 1e4, so scale by 1e6.
        $numerator = $total->minor * 1000000;

        return self::unscale(self::divideRounded($numerator, $quantity), self::QUANTITY_SCALE);
    }

    /**
     * ⚠ The guard against an inverted factor, which is the single most dangerous
     * error available here: storing 0.917 where 1.0905 belongs is not a rounding
     * error, it is 21% wrong, and every ledger it touches still balances.
     *
     * The two directions a human supplies must be reciprocals. Anything further
     * apart than a rounding difference means one of them is the wrong way round.
     */
    public static function reciprocalsAgree(string $stockPerPurchase, string $purchasePerStock, float $tolerance = 0.0001): bool
    {
        $product = ((float) $stockPerPurchase) * ((float) $purchasePerStock);

        return abs($product - 1.0) <= $tolerance;
    }

    private static function scale(string $value, int $scale): int
    {
        return (int) round(((float) $value) * $scale);
    }

    private static function unscale(int $scaled, int $scale): string
    {
        return number_format($scaled / $scale, (int) log10($scale), '.', '');
    }

    /** Round half away from zero, the same rule `Money` uses. */
    private static function divideRounded(int $numerator, int $divisor): int
    {
        $negative = ($numerator < 0) !== ($divisor < 0);
        $numerator = abs($numerator);
        $divisor = abs($divisor);

        $result = intdiv($numerator, $divisor);

        if (($numerator % $divisor) * 2 >= $divisor) {
            $result++;
        }

        return $negative ? -$result : $result;
    }
}
