<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Puts an approved correction into effect.
 *
 * "Into effect" means nothing more than marking it applied and reprocessing the
 * day: the pipeline picks approved corrections up when it gathers punches, so
 * the attendance engine needs no knowledge of corrections at all. A missing-out
 * punch supplied here simply closes its pair on recomputation, and the POL-07
 * payroll hold clears as a consequence rather than by being overridden.
 *
 * Reprocessing is forced because a corrected day is precisely the case where a
 * previously finalised judgement should be revisited.
 */
class ApplyCorrection
{
    public function __construct(private readonly ProcessAttendance $process) {}

    public function handle(AttendanceCorrection $correction): AttendanceCorrection
    {
        return DB::transaction(function () use ($correction) {
            if (! $correction->isApplied()) {
                $correction->update([
                    'applied_at' => now(),
                    'applied_by' => Auth::id(),
                ]);
            }

            $employee = $correction->employee ?? Employee::find($correction->employee_id);

            if ($employee !== null) {
                $this->process->forDay($employee, $correction->attendance_date, force: true);
            }

            return $correction->fresh();
        });
    }
}
