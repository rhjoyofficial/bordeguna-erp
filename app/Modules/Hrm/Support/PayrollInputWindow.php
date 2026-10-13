<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\PayrollPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Whether a payroll month still takes new inputs: overtime, bonuses,
 * adjustments and generated arrears.
 *
 * ⚠⚠ ONE ANSWER, ASKED TWICE. An input is only ever priced by a calculation,
 * and a calculation only runs while the month's latest run is absent, Draft or
 * Calculated, or was voided and will be replaced. Once a run is Reviewed or
 * Approved nothing recalculates it, so an input accepted then sat approved and
 * was never paid. The check runs when the input is raised AND when it is
 * finally approved, because a month can close in between.
 */
class PayrollInputWindow
{
    public static function accepts(?PayrollPeriod $period): bool
    {
        $status = $period?->latestRun()?->status;

        return $status === null || $status->isRerunnable() || $status->isVoided();
    }

    /**
     * The payroll month a dated input (overtime) falls in, if it exists yet.
     */
    public static function periodContaining(Carbon|string $date): ?PayrollPeriod
    {
        $day = Carbon::parse($date)->toDateString();

        return PayrollPeriod::query()
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public static function assertAccepts(?PayrollPeriod $period, string $field = 'payroll_period_id'): void
    {
        if (self::accepts($period)) {
            return;
        }

        $status = $period->latestRun()->status;

        throw ValidationException::withMessages([
            $field => sprintf(
                '%s payroll is already %s, so it no longer takes new inputs. %s',
                $period->label(),
                $status->label(),
                $status->isReopenable()
                    ? 'Reopen it first, or put this on the next open month.'
                    : 'Put this on the next open month (WFL-06).',
            ),
        ]);
    }
}
