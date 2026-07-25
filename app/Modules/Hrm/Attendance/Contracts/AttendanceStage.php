<?php

namespace App\Modules\Hrm\Attendance\Contracts;

use App\Modules\Hrm\Attendance\Data\AttendanceComputation;

/**
 * One step of the attendance pipeline.
 *
 * A stage is pure: same computation in, same computation out, no database, no
 * clock, no randomness. Everything a stage needs is already on the context.
 */
interface AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation;
}
