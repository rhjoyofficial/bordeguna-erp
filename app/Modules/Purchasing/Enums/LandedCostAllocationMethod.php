<?php

namespace App\Modules\Purchasing\Enums;

/**
 * How a capitalised line is spread over the receipt (ruling Q4: PER LINE,
 * never one method for the whole document).
 *
 *   direct    the whole line onto ONE receipt line (a packaging charge for one
 *             product);
 *   value     across every stocked line in proportion to the value received
 *             (a shared charge over mixed goods);
 *   quantity  in proportion to the units received, in stock units (unloading
 *             priced per sack or per carton); refused when the lines are in
 *             units that do not add up (g and pcs);
 *   weight    in proportion to the weight received, products.weight_grams x
 *             quantity (a truck priced by the load); refused when a product
 *             has no weight.
 *
 * ⭐ 18 Oct 2026, decision D-A: "quantity, weight, value (and direct to one
 * line); not one basis for everything". Equal shares stay out on purpose.
 */
enum LandedCostAllocationMethod: string
{
    case Direct = 'direct';
    case Value = 'value';
    case Quantity = 'quantity';
    case Weight = 'weight';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'One receipt line',
            self::Value => 'By value across the receipt',
            self::Quantity => 'By quantity (stock units)',
            self::Weight => 'By weight',
        };
    }

    /** Spread over the whole receipt rather than onto one named line. */
    public function spreads(): bool
    {
        return $this !== self::Direct;
    }
}
