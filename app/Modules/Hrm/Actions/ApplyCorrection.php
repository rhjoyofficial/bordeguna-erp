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
                // Rule #18 (14 Oct 2026): in a cycle whose payroll is approved
                // the days stay as approved, and the cycle is priced once.
                $this->process->deferringFrozen(function () use ($employee, $correction) {
                    $this->process->forDay($employee, $correction->attendance_date, force: true);
                    // The later days in the cycle were counted with the old
                    // verdict (late occurrences, the lateness allowance), so
                    // they are re-run too.
                    $this->process->restOfCycleAfter($employee, $correction->attendance_date);
                });
            }

            return $correction->fresh();
        });
    }
}
