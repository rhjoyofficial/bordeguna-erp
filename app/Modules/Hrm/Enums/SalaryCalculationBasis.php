<?php

namespace App\Modules\Hrm\Enums;

/**
 * How a component's amount is arrived at (SAL-04).
 *
 * SAL-04 ticked *both* "all fixed amount" and "varies by component", so the
 * basis belongs to the component rather than being one global rule.
 *
 * The three bases are deliberately ordered to stay non-circular. Basic is
 * always a fixed amount; an earning may be a percentage of Basic; only a
 * deduction may be a percentage of Gross, because Gross is not known until
 * every earning has been resolved. Allowing an earning to be a percentage of
 * gross would make gross depend on itself.
 */
enum SalaryCalculationBasis: string
{
    case Fixed = 'fixed';
    case PercentOfBasic = 'percent_of_basic';
    case PercentOfGross = 'percent_of_gross';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::PercentOfBasic => 'Percentage of Basic',
            self::PercentOfGross => 'Percentage of Gross',
        };
    }

    public function isPercentage(): bool
    {
        return $this !== self::Fixed;
    }

    /**
     * Whether this basis may be used by an earning. Gross is the sum of the
     * earnings, so an earning cannot be a share of it.
     */
    public function allowedForEarning(): bool
    {
        return $this !== self::PercentOfGross;
    }

    /**
     * How the stored figure reads on screen and in a payslip's formula line.
     */
    public function describe(float $value): string
    {
        return match ($this) {
            self::Fixed => number_format($value, 2),
            self::PercentOfBasic => rtrim(rtrim(number_format($value, 2), '0'), '.').'% of Basic',
            self::PercentOfGross => rtrim(rtrim(number_format($value, 2), '0'), '.').'% of Gross',
        };
    }
}
