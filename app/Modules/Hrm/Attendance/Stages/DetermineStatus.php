<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Enums\AttendanceStatus;

/**
 * Stage 6 — the baseline status of the day (POL-06/07/09/10).
 *
 * Order matters and is deliberate: a non-working day is decided before any
 * punch is considered (POL-09 — holiday work does not count without approval),
 * approved leave and movement outrank an empty punch list (POL-10), an
 * incomplete pair is a blocking exception rather than an assumption (POL-07),
 * and only what is left can be Absent — which POL-06 requires be provisional.
 *
 * Lateness is NOT decided here. This stage answers "was the person there";
 * stage 7 answers "were they on time".
 */
class DetermineStatus implements AttendanceStage
{
    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $context = $computation->context;

        if ($context->isNonWorkingDay()) {
            return $this->nonWorkingDay($computation);
        }

        if ($context->hasApprovedLeave) {
            return $computation
                ->with(['status' => AttendanceStatus::OnLeave])
                ->note('Approved leave for this day.');
        }

        if ($context->hasApprovedMovement) {
            return $computation
                ->with(['status' => AttendanceStatus::FieldDuty])
                ->note('Approved official movement counts as present (POL-10).');
        }

        if (! $context->hasPunches()) {
            return $computation
                ->with(['status' => AttendanceStatus::Absent])
                ->flag('needs_review')
                ->note('No punches and no approved leave or movement — proposed as Absent, pending HR review.');
        }

        if ($computation->hasOpenPair || $computation->lastOut === null) {
            return $computation
                ->with(['status' => AttendanceStatus::MissingPunch])
                ->flag('payroll_hold')
                ->flag('needs_review')
                ->note('A punch pair never closed. Payroll is held for this day until a correction is approved (POL-07).');
        }

        return $computation->with(['status' => AttendanceStatus::Present]);
    }

    /**
     * POL-09: work on a holiday or weekly off never counts unless it was
     * approved. The punches are still recorded and shown — they are simply not
     * credited, and the day is flagged so someone can approve it if it was real.
     */
    private function nonWorkingDay(AttendanceComputation $computation): AttendanceComputation
    {
        $context = $computation->context;

        $status = $context->isHoliday ? AttendanceStatus::Holiday : AttendanceStatus::WeeklyOff;
        $label = $context->isHoliday ? ($context->holidayName ?? 'Holiday') : 'Weekly off';

        $computation = $computation->with(['status' => $status])->note($label.'.');

        if ($computation->workedMinutes === 0) {
            return $computation;
        }

        if ($context->hasApprovedHolidayWork || $context->policy->countHolidayWorkWithoutApproval) {
            return $computation->note('Work on this non-working day is approved and counted.');
        }

        return $computation
            ->with(['payableMinutes' => 0])
            ->flag('unapproved_holiday_work')
            ->flag('needs_review')
            ->note('Worked on a non-working day without approval, so it is not counted (POL-09).');
    }
}
