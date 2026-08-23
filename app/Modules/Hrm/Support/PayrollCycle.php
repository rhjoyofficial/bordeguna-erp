<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Support\Carbon;

/**
 * THE one place that knows when a payroll month starts and ends.
 *
 * ⚠⚠ Joy's final decision, 23 Aug 2026: Bor De Guna's employee month runs the
 * 24th to the 23rd, set per business on Settings → Business, and it governs
 * payroll, attendance summaries and payroll-related HR reports - NOTHING in
 * accounting. A supplier bill dated the 25th belongs to the month on its face,
 * not to "next payroll month", and any accounting code importing this class
 * should fail review on sight.
 *
 * ⚠ The labelled month is where the period ENDS - "August" with start day 24
 * covers 24 July to 23 August. That convention is C7b's and predates this
 * class; payroll periods, payslips and reports already carry those labels, so
 * inventing a different mapping here would relabel history.
 *
 * ⚠ A start day of 1 means the plain calendar month - "August" is 1 to 31
 * August - rather than the degenerate "day zero" reading of the general rule.
 * Validation caps the day at 28 so every month of every year has it.
 */
class PayrollCycle
{
    public const MIN_DAY = 1;

    public const MAX_DAY = 28;

    /**
     * The configured start day for the current business.
     *
     * ⚠ Read fresh each time, deliberately uncached: a per-process memo is the
     * exact process-lifetime trap this project has been bitten by (the
     * scheduler processes four businesses in one PHP process), and this is one
     * indexed primary-key lookup.
     */
    public static function startDay(): int
    {
        $businessId = app(BusinessContext::class)->id();

        if ($businessId === null) {
            return 24;
        }

        $day = Business::query()
            ->withoutGlobalScopes()
            ->whereKey($businessId)
            ->value('payroll_cycle_start_day');

        return self::clamp((int) ($day ?? 24));
    }

    /**
     * The bounds of the payroll month carrying this label.
     *
     * @return array{0: Carbon, 1: Carbon} [start, end], both at start of day
     */
    public static function forMonth(int $year, int $month, ?int $startDay = null): array
    {
        $day = self::clamp($startDay ?? self::startDay());

        if ($day === 1) {
            $start = Carbon::create($year, $month, 1)->startOfDay();

            return [$start, $start->copy()->endOfMonth()->startOfDay()];
        }

        $end = Carbon::create($year, $month, $day - 1)->startOfDay();
        $start = $end->copy()->subMonthNoOverflow()->day($day)->startOfDay();

        return [$start, $end];
    }

    /**
     * The cycle a given date falls inside, with the label it carries.
     *
     * With start day 24: 23 August sits in "August" (24 Jul - 23 Aug), and
     * 24 August sits in "September" (24 Aug - 23 Sep).
     *
     * @return array{start: Carbon, end: Carbon, year: int, month: int}
     */
    public static function containing(Carbon $date, ?int $startDay = null): array
    {
        $day = self::clamp($startDay ?? self::startDay());
        $date = $date->copy()->startOfDay();

        // The labelled month: this one if the date sits before the next cycle
        // begins, the next one otherwise. Start day 1 collapses to "this month".
        $label = ($day > 1 && $date->day >= $day)
            ? $date->copy()->addMonthNoOverflow()
            : $date->copy();

        [$start, $end] = self::forMonth((int) $label->year, (int) $label->month, $day);

        return ['start' => $start, 'end' => $end, 'year' => (int) $label->year, 'month' => (int) $label->month];
    }

    private static function clamp(int $day): int
    {
        return max(self::MIN_DAY, min(self::MAX_DAY, $day));
    }
}
