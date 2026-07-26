<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Payroll\PayrollConfig;

/**
 * How much compensatory leave a holiday or off-day stint earns (ADJ-04 via
 * Correction C8b).
 *
 * Pure, so the rule can be tested without a database and read without tracing
 * it through an action.
 *
 * ADJ-04 ticked "compensatory leave" and left 1.5× and 2× **blank**. That is the
 * whole basis of the ratio here: having declined every multiplier, the credit is
 * time-for-time and cannot exceed one day for one day worked. Anything more
 * would be a multiplier by another name.
 *
 * Two bounds shape it, and both come from ticked rules rather than taste:
 *
 *  - **Nothing below ADJ-03's minimum eligible minutes.** That is ADJ-03's one
 *    genuinely eligibility-shaped limit, as opposed to its payment ceilings.
 *  - **Half-day granularity, minimum half a day.** Leave is taken in half and
 *    full days (LeaveDayPortion), so a balance of 0.19 days is a number nobody
 *    can spend. Rounding to the nearest half keeps the credit usable.
 *
 * ADJ-03's *daily four-hour ceiling is deliberately not applied here.* That
 * ceiling limits what overtime may be **paid**; ADJ-04 removes holiday work from
 * payment entirely. Applying a payment cap to a leave credit would mean a full
 * holiday worked earned half a day off, which conflates two separate answers.
 *
 * ⚠ The half-day rounding is the one part of this the questionnaire does not
 * state outright. It is recorded as a client-confirmation item rather than
 * presented as settled.
 */
final class CompensatoryCredit
{
    /** A day worked can never be worth more than a day off — ADJ-04 declined every multiplier. */
    private const MAX_DAYS_PER_DATE = 1.0;

    /** Leave is taken in half days, so a credit must be spendable in them. */
    private const GRANULARITY = 0.5;

    public static function daysFor(float $hours, PayrollConfig $config): float
    {
        if ($hours <= 0 || $hours * 60 < $config->overtimeMinimumMinutes) {
            return 0.0;
        }

        $standard = max(0.01, $config->standardWorkingHours);
        $proportion = $hours / $standard;

        $rounded = round($proportion / self::GRANULARITY) * self::GRANULARITY;

        return min(self::MAX_DAYS_PER_DATE, max(self::GRANULARITY, round($rounded, 2)));
    }

    /**
     * The provenance line stored with the credit, so a balance can explain
     * itself years later without re-deriving anything.
     */
    public static function explain(float $hours, float $days, PayrollConfig $config): string
    {
        return sprintf(
            '%s hour%s of holiday work ÷ %s standard hours → %s day%s compensatory leave (ADJ-04).',
            self::trim($hours),
            $hours == 1.0 ? '' : 's',
            self::trim($config->standardWorkingHours),
            self::trim($days),
            $days == 1.0 ? '' : 's',
        );
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
