<?php

namespace App\Modules\Hrm\Enums;

/**
 * Which side of the payslip a component sits on (SAL-01 / Correction C3).
 *
 * C3 settled that salary is component-based rather than a single gross figure:
 * Basic plus configurable Earnings roll up to Gross, and Deductions come off it
 * to reach Net. Basic is an Earning with a flag rather than a third type —
 * there is exactly one of it, and it is the base other components may be a
 * percentage of.
 */
enum SalaryComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }

    /**
     * Earnings add to gross; deductions subtract from it.
     */
    public function sign(): int
    {
        return $this === self::Earning ? 1 : -1;
    }
}
