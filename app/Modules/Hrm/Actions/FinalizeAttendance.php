<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Foundation\Support\SystemActor;
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
    public function handle(iterable $days, ?int $actorId = null): array
    {
        $actorId ??= Auth::id();

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
                'finalized_by' => $actorId,
            ]);

            $finalized++;
        }

        return ['finalized' => $finalized, 'blocked' => $blocked];
    }

    /**
     * The scheduler's path (Corrections C15a and C15c): close a day that has
     * left the reprocessing window, recording the system as the actor.
     *
     * ⚠ **It refuses a provisional day, which `handle()` does not.** A human
     * finalising is *exercising* the judgement POL-06 asks for, so clearing
     * `is_provisional` is the whole point. The system has no judgement to
     * exercise, so a day awaiting review must survive the window rather than be
     * closed by the passage of time. Without this guard the seven-day window
     * would quietly become an approval: an unreviewed Band 3 deduction would
     * finalise itself a week later and reach payroll unseen, which is precisely
     * what C15b exists to stop.
     *
     * @return array{finalized: int, blocked: int}
     */
    public function bySystem(iterable $days): array
    {
        $eligible = [];
        $blocked = 0;

        foreach ($days as $day) {
            if ($day->is_provisional) {
                $blocked++;

                continue;
            }

            $eligible[] = $day;
        }

        $result = $this->handle($eligible, SystemActor::id());

        return ['finalized' => $result['finalized'], 'blocked' => $blocked + $result['blocked']];
    }

    public function one(DailyAttendance $day): bool
    {
        return $this->handle([$day])['finalized'] === 1;
    }
}
