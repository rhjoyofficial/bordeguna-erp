<?php

namespace App\Modules\Accounting\Enums;

/**
 * How overdue a document is, as at the date the ageing is run.
 *
 * ⚠ 30/60/90 is the common Bangladeshi practice and what the accountant will
 * expect to read (C-GLA-C). The widths live here alone, so changing them if he
 * wants 7/14/30 is one edit rather than a hunt through a report.
 */
enum AgeingBucket: string
{
    case Current = 'current';
    case Days1To30 = 'days_1_30';
    case Days31To60 = 'days_31_60';
    case Days61To90 = 'days_61_90';
    case Days90Plus = 'days_90_plus';

    /**
     * ⚠ Day 0 is CURRENT, not overdue. A bill due today has not been paid late,
     * and an ageing that files it under "1-30 days" tells a supplier they are
     * owed money late on the morning it fell due. The boundaries here are the
     * whole risk in this report, which is why the tests assert days 0, 1, 30,
     * 31, 60, 61, 90 and 91 individually.
     */
    public static function forDaysOverdue(int $days): self
    {
        return match (true) {
            $days <= 0 => self::Current,
            $days <= 30 => self::Days1To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            default => self::Days90Plus,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Days1To30 => '1-30 days',
            self::Days31To60 => '31-60 days',
            self::Days61To90 => '61-90 days',
            self::Days90Plus => 'Over 90 days',
        };
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Current, self::Days1To30, self::Days31To60, self::Days61To90, self::Days90Plus];
    }
}
