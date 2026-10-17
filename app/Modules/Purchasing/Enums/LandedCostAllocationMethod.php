<?php

namespace App\Modules\Purchasing\Enums;

/**
 * How a capitalised line is spread over the receipt (ruling Q4: PER LINE,
 * never one method for the whole document).
 *
 *   direct  the whole line onto ONE receipt line (a packaging charge for one
 *           product);
 *   value   across every stocked line of the receipt in proportion to the
 *           value received (a shared charge over mixed goods).
 *
 * Weight, quantity and equal-share allocation are Phase 2 (ruling Q6).
 */
enum LandedCostAllocationMethod: string
{
    case Direct = 'direct';
    case Value = 'value';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'One receipt line',
            self::Value => 'By value across the receipt',
        };
    }
}
