<?php

namespace App\Modules\Hrm\Enums;

/**
 * What a late arrival costs, once the occurrence tiers in POL-03 are applied.
 *
 * Deliberately separate from AttendanceStatus (POL-02): the same "Late" status
 * carries no consequence for the first three occurrences in a month, a warning
 * on the fourth and a deduction from the fifth. Conflating the two is exactly
 * what makes a policy like this impossible to express.
 */
enum AttendanceConsequence: string
{
    case None = 'none';
    case Warning = 'warning';
    case Deduction = 'deduction';
    case WarningAndDeduction = 'warning_and_deduction';
    case Disciplinary = 'disciplinary';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Excused',
            self::Warning => 'Warning',
            self::Deduction => 'Salary deduction',
            self::WarningAndDeduction => 'Warning + salary deduction',
            self::Disciplinary => 'Disciplinary action',
        };
    }

    /**
     * Whether Phase-3 payroll should apply a pro-rata deduction for this day.
     * The *amount* is payroll's business — this only says that it applies.
     */
    public function deducts(): bool
    {
        return $this === self::Deduction || $this === self::WarningAndDeduction;
    }

    public function warns(): bool
    {
        return in_array($this, [self::Warning, self::WarningAndDeduction, self::Disciplinary], true);
    }
}
