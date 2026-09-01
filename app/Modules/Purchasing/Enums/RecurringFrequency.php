<?php

namespace App\Modules\Purchasing\Enums;

use Carbon\CarbonInterface;

/**
 * How often a standing cost comes round.
 *
 * ⚠ Deliberately short. Weekly and fortnightly are not here because no cost this
 * business carries behaves that way, and an option nobody picks is an option
 * somebody eventually picks by accident.
 */
enum RecurringFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Every month',
            self::Quarterly => 'Every 3 months',
            self::HalfYearly => 'Every 6 months',
            self::Yearly => 'Every year',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Yearly => 12,
        };
    }

    /**
     * How many of these fall in a year, for the annual-commitment figure a
     * director actually asks for.
     */
    public function perYear(): float
    {
        return 12 / $this->months();
    }

    /**
     * ⚠⚠ CLAMPED TO THE MONTH'S LAST DAY. Nikola Host is due on the last day of
     * the month and is stored as 31; February must produce the 28th or 29th, not
     * roll into March. `Carbon::addMonths` on a 31st does roll, which is exactly
     * the bug this avoids by building the date from the month's own length.
     */
    public function dateIn(CarbonInterface $month, int $dayOfMonth): CarbonInterface
    {
        $start = $month->copy()->startOfMonth();

        return $start->copy()->setDay(min($dayOfMonth, $start->daysInMonth))->startOfDay();
    }
}
