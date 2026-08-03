<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Carbon;

/**
 * Which fiscal year a date falls in, for a business that may not start its year
 * in January.
 *
 * C-GL-4: Bor De Guna runs 1 July to 30 June, which is the common company year
 * in Bangladesh. `businesses.fiscal_year_start_month` already existed and is set
 * to 7 for all four businesses.
 *
 * ⚠ The year is identified by the year it STARTS in. A date of 2026-08-15 with a
 * July start belongs to fiscal year 2026, and 2026-05-15 belongs to 2025. Naming
 * it by the ending year instead is the kind of off-by-one that is invisible for
 * eleven months and then renumbers everything.
 */
final class FiscalYear
{
    private function __construct(
        public readonly int $startingYear,
        public readonly int $startMonth,
    ) {}

    public static function containing(Carbon $date, Business $business): self
    {
        $startMonth = (int) ($business->fiscal_year_start_month ?: 1);

        $startingYear = $date->month >= $startMonth
            ? $date->year
            : $date->year - 1;

        return new self($startingYear, $startMonth);
    }

    /**
     * "2026-27" for a July start, "2026" for a calendar year.
     *
     * ⚠ The calendar case is not decoration: "2026-26" would be nonsense on
     * every printed voucher, and a business that switches to a calendar year
     * later should not produce it.
     */
    public function label(): string
    {
        if ($this->startMonth === 1) {
            return (string) $this->startingYear;
        }

        return $this->startingYear.'-'.substr((string) ($this->startingYear + 1), -2);
    }
}
