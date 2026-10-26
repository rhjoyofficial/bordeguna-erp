<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved movement into effect (POL-09 / POL-10).
 *
 * Like corrections and leave, this changes nothing in the attendance engine —
 * it marks the movement applied and reprocesses the day. The engine already
 * knows what to do with an approved movement (Present) and approved holiday
 * work (counted rather than discarded); the pipeline simply now tells it.
 */
class ApplyMovement
{
    public function __construct(
        private readonly ProcessAttendance $process,
        private readonly FinalizeAttendance $finalize,
    ) {}

    public function handle(Movement $movement, User|int|null $approver = null): Movement
    {
        $approverId = $approver instanceof User ? (int) $approver->id : $approver;

        return DB::transaction(function () use ($movement, $approverId) {
            if (! $movement->isApplied()) {
                $movement->update(['applied_at' => now()]);
            }

            $employee = $movement->employee;

            if ($employee === null) {
                return $movement->fresh();
            }

            $date = Carbon::parse($movement->movement_date)->startOfDay();

            // ⭐ Early leave (27 Oct 2026). A day that has not happened yet is
            // left to the nightly run: processing it now would write a
            // half-punched (or absent) day for a date still in progress.
            if ($movement->type->isEarlyLeave() && $date->greaterThan(Carbon::today())) {
                return $movement->fresh();
            }

            $day = $this->process->forDay($employee, $date, force: true);

            // ⭐ Approved early leave = no deduction AND the day is finalised:
            // the approver has made the judgement POL-06 asks for. Only a
            // finished day, and only when nothing else on it still needs a
            // human (a missing punch, a band-3 late): those stay provisional.
            if ($movement->type->isEarlyLeave()
                && $day !== null
                && $date->lessThan(Carbon::today())
                && ! $day->is_provisional
                && ! $day->holds_payroll) {
                $this->finalize->handle([$day], $approverId);
            }

            return $movement->fresh();
        });
    }
}
