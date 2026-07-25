<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\PunchPair;
use App\Modules\Hrm\Enums\BreakPolicy;

/**
 * Stage 5 — how long was actually worked, and how much of it is payable
 * (POL-08).
 *
 * POL-08 ticked only "varies by shift", so the break treatment comes from the
 * shift rather than being fixed here. Summing the spans already excludes any
 * gap between an out and the next in, which is why the "actual punches"
 * treatment subtracts nothing further — the break is gone by construction.
 */
class CalculateWorkedTime implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $worked = array_sum(array_map(fn (PunchPair $pair) => $pair->minutes(), $computation->pairs));

        if ($worked === 0) {
            return $computation->with(['workedMinutes' => 0, 'breakMinutes' => 0, 'payableMinutes' => 0]);
        }

        $shift = $computation->context->shift;
        $policy = $shift?->breakPolicy ?? BreakPolicy::Paid;

        $break = match ($policy) {
            BreakPolicy::Paid => 0,
            BreakPolicy::FixedDeduction => $shift?->breakMinutes ?? 0,
            // Already excluded: only time inside a span was counted.
            BreakPolicy::ActualPunches => 0,
        };

        $payable = max(0, $worked - $break);

        $computation = $computation
            ->with([
                'workedMinutes' => $worked,
                'breakMinutes' => $break,
                'payableMinutes' => $payable,
            ])
            ->note(sprintf('Worked %s across the day%s.', self::hours($worked), $break > 0 ? sprintf(', less a %d minute break', $break) : ''))
            ->note($policy->describe());

        // A shift can set a floor below which a day is not payable at all.
        if ($shift?->minimumPayableHours !== null && $payable < $shift->minimumPayableHours * 60) {
            $computation = $computation
                ->flag('below_minimum_payable_hours')
                ->note(sprintf(
                    'Below the shift minimum of %s payable hours.',
                    rtrim(rtrim(number_format($shift->minimumPayableHours, 2), '0'), '.'),
                ));
        }

        return $computation;
    }

    private static function hours(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
