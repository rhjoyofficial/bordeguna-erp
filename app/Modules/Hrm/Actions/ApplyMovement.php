<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\Movement;
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
    public function __construct(private readonly ProcessAttendance $process) {}

    public function handle(Movement $movement): Movement
    {
        return DB::transaction(function () use ($movement) {
            if (! $movement->isApplied()) {
                $movement->update(['applied_at' => now()]);
            }

            $employee = $movement->employee;

            if ($employee !== null) {
                $this->process->forDay($employee, $movement->movement_date, force: true);
            }

            return $movement->fresh();
        });
    }
}
