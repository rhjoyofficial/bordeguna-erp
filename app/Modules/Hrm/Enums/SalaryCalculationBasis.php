<?php

namespace App\Modules\Hrm\Enums;

/**
 * How a component's amount is arrived at (SAL-04).
 *
 * SAL-04 ticked *both* "all fixed amount" and "varies by component", so the
 * basis belongs to the component rather than being one global rule.
 *
 * The first three bases are deliberately ordered to stay non-circular. Basic is
 * always a fixed amount; an earning may be a percentage of Basic; only a
 * deduction may be a percentage of Gross, because Gross is not known until
 * every earning has been resolved. Allowing an earning to be a percentage of
 * gross would make gross depend on itself.
 *
 * `PerPresentDay` (Correction C14) sits outside that ordering entirely, because
 * it is not a share of anything. It is a rate multiplied by a count of days the
 * employee reported for work, and that count does not exist until a payroll
 * period is named — so unlike the other three, **`SalaryResolver` cannot
 * resolve it to money.** It resolves to 0.00 there and is priced inside the
 * payroll engine once attendance is known. See `TA-DA-ARCHITECTURE-PLAN.md`.
 */
enum SalaryCalculationBasis: string
{
    case Fixed = 'fixed';
    case PercentOfBasic = 'percent_of_basic';
    case PercentOfGross = 'percent_of_gross';
    case PerPresentDay = 'per_present_day';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::PercentOfBasic => 'Percentage of Basic',
            self::PercentOfGross => 'Percentage of Gross',
            self::PerPresentDay => 'Rate per day reported for work',
        };
    }

    public function isPercentage(): bool
    {
        return $this === self::PercentOfBasic || $this === self::PercentOfGross;
    }

    /**
     * Whether the amount depends on a payroll period's attendance, and so
     * cannot be resolved from the salary structure alone.
     *
     * ⚠ A basis that answers true here is **excluded from Gross**. Gross is the
     * contractual monthly figure, and it is the base `dailyRate()` divides to
     * price absence and proration — so letting an attendance-priced allowance
     * into it would raise the cost of every absent day for every other
     * component. That is the defect this method exists to prevent.
     */
    public function isPricedByAttendance(): bool
    {
        return $this === self::PerPresentDay;
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
     * Whether this basis may be used by a *deduction*.
     *
     * A per-day allowance is compensation for reporting to work; there is no
     * coherent reading of "deduct 246 for every day you attended", so the basis
     * is earning-only rather than left available and misused.
     */
    public function allowedForDeduction(): bool
    {
        return ! $this->isPricedByAttendance();
    }

    /**
     * Whether a `BonusEvent` may use this basis (ADJ-05).
     *
     * ⚠ This enum is deliberately shared with `BonusEvent` rather than
     * duplicated, so every case added here reaches the bonus screen too. A
     * bonus is a one-off award resolved once at award time against the salary
     * then in force; "per day reported for work" is a standing entitlement and
     * has no meaning as a festival bonus. Excluded at the form, at validation
     * and in `BonusEvent` itself, so all three agree.
     */
    public function allowedForBonus(): bool
    {
        return ! $this->isPricedByAttendance();
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
            self::PerPresentDay => number_format($value, 2).' per day reported for work',
        };
    }
}
