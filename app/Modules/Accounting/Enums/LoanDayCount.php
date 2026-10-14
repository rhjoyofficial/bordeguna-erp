<?php

namespace App\Modules\Accounting\Enums;

/**
 * How a day's interest is counted: the actual days elapsed over a 365-day
 * year (the default, and what Bangladesh banks quote) or over 360.
 */
enum LoanDayCount: string
{
    case Actual365 = 'actual_365';
    case Actual360 = 'actual_360';

    public function label(): string
    {
        return match ($this) {
            self::Actual365 => 'Actual/365',
            self::Actual360 => 'Actual/360',
        };
    }

    public function basis(): int
    {
        return $this === self::Actual360 ? 360 : 365;
    }
}
