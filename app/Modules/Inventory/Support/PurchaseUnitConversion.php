<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;
use InvalidArgumentException;

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

        // ⚠ A 1e4-scaled quantity times a 1e8-scaled factor overflows a 64-bit
        // int above roughly 9.2 million units, at which point PHP turns it into
        // a float and `intdiv` raises a TypeError - a 500, not a refusal. The
        // column is decimal(15,4) and permits eleven integer digits, so the
        // schema allows quantities this class must still handle.
        if ($quantity !== 0 && abs($quantity) > intdiv(PHP_INT_MAX, max(abs($scaledFactor), 1))) {
            throw new InvalidArgumentException(
                "Purchase quantity {$purchaseQuantity} is too large to convert precisely."
            );
        }

        $product = $quantity * $scaledFactor;   // scaled by QUANTITY_SCALE * FACTOR_SCALE

        return self::unscale(self::divideRounded($product, self::FACTOR_SCALE), self::QUANTITY_SCALE, 4);
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

        return self::unscale(self::divideRounded($quantity * self::FACTOR_SCALE, $scaledFactor), self::QUANTITY_SCALE, 4);
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
     * place per unit - at most 0.00005 * quantity taka.
     *
     * ⚠⚠ THAT RESIDUE IS HANDLED IN `PostPurchaseJournals::forReceipt`, NOT
     * HERE, and not by `LedgerRounding` alone. LedgerRounding closes a gap
     * inside ONE journal, and a receipt journal whose debit and credit are both
     * this number balances perfectly while leaving the difference stranded in
     * GRNI. The receipt therefore debits stock with what the movement is worth
     * and credits GRNI with what the supplier will invoice - two independently
     * computed sides - and the difference between them is what LedgerRounding
     * then names. See ConvertedReceiptClearsGrniTest. C-UOM-7.
     */
    public static function toStockUnitCost(Money $total, string $stockQuantity): string
    {
        $quantity = self::scale($stockQuantity, self::QUANTITY_SCALE);

        if ($quantity === 0) {
            return '0.0000';
        }

        // minor units are paisa (1e2); we want taka at 1e4, so scale by 1e6.
        $numerator = $total->minor * 1000000;

        return self::unscale(self::divideRounded($numerator, $quantity), self::QUANTITY_SCALE, 4);
    }

    /**
     * A purchase rate as a batch can hold it: four places, half away from
     * zero. Order and bill lines carry six places since 17 Oct 2026; batches
     * and movements still carry four, and the rounding happens here, once, the
     * same way on every database driver (MySQL would round silently on insert,
     * SQLite would store the six).
     */
    public static function toFourPlaces(string $unitCost): string
    {
        return self::unscale(self::scale($unitCost, self::QUANTITY_SCALE), self::QUANTITY_SCALE, 4);
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

    /**
     * ⚠ The number of decimals is passed in, not derived with `log10($scale)`.
     * Deriving it puts a floating-point call in the middle of a money path for
     * no reason: `(int) log10(...)` returning 3 instead of 4 on some build would
     * silently truncate every quantity this class produces.
     */
    private static function unscale(int $scaled, int $scale, int $decimals): string
    {
        return number_format($scaled / $scale, $decimals, '.', '');
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
