<?php

namespace App\Modules\Purchasing\Enums;

/**
 * Where a landed-cost line's money ends up: in the value of the stock it was
 * spent on (capitalised through the batch revaluation primitive), or on an
 * expense head exactly as an expense voucher would post it.
 */
enum LandedCostTreatment: string
{
    case Capitalize = 'capitalize';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Capitalize => 'Add to stock value',
            self::Expense => 'Expense',
        };
    }
}
