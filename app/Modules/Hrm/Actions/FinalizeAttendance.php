<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Support\Facades\Auth;

/**
 * The human half of POL-06: the system proposes a status, a person makes it
 * payroll-eligible.
 *
 * A day that holds payroll (POL-07's missing punch) cannot be waved through
 * here — that needs an approved correction, which is a Phase-2c record, not a
 * finalise click.
 */
class FinalizeAttendance
{
    /**
     * @return array{finalized: int, blocked: int}
     */
    public function handle(iterable $days): array
    {
        $finalized = 0;
        $blocked = 0;

        foreach ($days as $day) {
            if ($day->holds_payroll) {
                $blocked++;

                continue;
            }

            $day->update([
                'is_provisional' => false,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ]);

            $finalized++;
        }

        return ['finalized' => $finalized, 'blocked' => $blocked];
    }

    public function one(DailyAttendance $day): bool
    {
        return $this->handle([$day])['finalized'] === 1;
    }
}
