<?php

namespace App\Modules\Hrm\Support;

use Illuminate\Support\Carbon;

/**
 * How many days of a leave type an employee is entitled to in a leave year
 * (LEV-02, as revised by Joy on 2026-07-29).
 *
 * **The rule:** someone employed for the whole leave year gets the full annual
 * quota. Someone who joined during it gets the share of the year they will
 * actually be employed for, rounded **down** to the nearest half day.
 *
 * ⚠⚠ **THIS REVISES LEV-02.** The questionnaire's approved wording was "a full
 * grant, not monthly accrual and **not prorated by joining date**", and
 * `GrantAnnualLeaveQuota` implemented exactly that. Joy changed it deliberately
 * on 2026-07-29, first for the go-live migration and then — explicitly — for
 * every year going forward, so that a joiner is treated the same way whether
 * they arrived during the migration year or in 2029.
 *
 * ⚠⚠ **THIS CLASS EXISTS SO THE RULE LIVES IN ONE PLACE.** The opening-balance
 * migration and the annual grant both need it, and if each carried its own copy
 * they would eventually disagree about the same person's entitlement — with no
 * error, just two different numbers. Earned/Annual is encashable, so that
 * disagreement is cash.
 *
 * ⚠ **Rounds DOWN, not to nearest.** An over-granted day on an encashable type
 * is a real liability payable on separation, so the conservative direction is
 * the one that does not quietly create one. Half days because that is the
 * smallest unit the leave policy actually uses — a balance of 8.37 is not
 * something anybody can take.
 */
class LeaveEntitlement
{
    /**
     * @param  float  $annualQuota  the type's full-year entitlement
     * @param  string|null  $joiningDate  null when unknown — treated as employed all year
     */
    public static function daysFor(float $annualQuota, ?string $joiningDate, int $year): float
    {
        if ($annualQuota <= 0) {
            return 0.0;
        }

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();
        $daysInYear = $yearStart->diffInDays($yearEnd) + 1;

        $joined = $joiningDate === null ? null : Carbon::parse($joiningDate)->startOfDay();

        // Employed before the year began — or joining date unknown — is the
        // full entitlement. ⚠ Unknown deliberately resolves to the FULL grant
        // rather than zero: withholding leave because a date is missing from a
        // record punishes the employee for an administrative gap.
        if ($joined === null || $joined->lte($yearStart)) {
            return $annualQuota;
        }

        // Joined after the year ended — nothing for this year.
        if ($joined->gt($yearEnd)) {
            return 0.0;
        }

        $employedDays = $joined->diffInDays($yearEnd) + 1;

        return self::roundDownToHalf($annualQuota * $employedDays / $daysInYear);
    }

    /**
     * Whether this employee's entitlement is a partial one, for the note that
     * accompanies the ledger entry. A figure that cannot explain itself is the
     * thing this platform exists to avoid.
     */
    public static function isProrated(?string $joiningDate, int $year): bool
    {
        if ($joiningDate === null) {
            return false;
        }

        $joined = Carbon::parse($joiningDate)->startOfDay();

        return $joined->gt(Carbon::create($year, 1, 1)->startOfDay())
            && $joined->lte(Carbon::create($year, 12, 31)->startOfDay());
    }

    /**
     * Days of the leave year this employee is employed for, for provenance.
     */
    public static function employedDays(?string $joiningDate, int $year): int
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        if ($joiningDate === null) {
            return $yearStart->diffInDays($yearEnd) + 1;
        }

        $joined = Carbon::parse($joiningDate)->startOfDay();

        if ($joined->lte($yearStart)) {
            return $yearStart->diffInDays($yearEnd) + 1;
        }

        return $joined->gt($yearEnd) ? 0 : $joined->diffInDays($yearEnd) + 1;
    }

    public static function daysInYear(int $year): int
    {
        return Carbon::create($year, 1, 1)->startOfDay()
            ->diffInDays(Carbon::create($year, 12, 31)->startOfDay()) + 1;
    }

    private static function roundDownToHalf(float $days): float
    {
        return floor($days * 2) / 2;
    }
}
