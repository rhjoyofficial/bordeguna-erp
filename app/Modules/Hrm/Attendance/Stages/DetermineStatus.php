<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\ScheduleType;

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
            // An Exempt schedule assumes presence rather than evidencing it
            // (works from home, or the owner whose day is meetings). Absent
            // and its review flag are exactly what the type exists to switch
            // off - Absent is provisional, and provisional is payroll's gate.
            if ($context->assumesPresence()) {
                return $computation
                    ->with(['status' => AttendanceStatus::Present])
                    ->note('Exempt schedule: presence is assumed on a working day, no punches required.');
            }

            return $computation
                ->with(['status' => AttendanceStatus::Absent])
                ->flag('needs_review')
                ->note('No punches and no approved leave or movement — proposed as Absent, pending HR review.');
        }

        if ($computation->hasOpenPair || $computation->lastOut === null) {
            // ⚠⚠ Correction C13. POL-07's blocking Missing Punch presumes the
            // employee comes back to the device — true for office staff, and
            // false by design for field staff, who give fingerprint in the
            // morning and finish from wherever the day took them.
            //
            // Left blanket, this rule blocks their payroll EVERY month and
            // withholds the TA & DA they earn per day present, on days they
            // genuinely worked. So where the schedule says no return is
            // expected, one morning punch is read as "they came to work"
            // rather than "something went wrong".
            //
            // ⚠ The status must remain a WORKED one, not merely un-blocked:
            // `PayrollContextBuilder` counts Present, Late and FieldDuty toward
            // `presentDays`, and that is the figure both proration and the
            // per-present-day allowance are priced from.
            if (! $context->requiresClosedPunchPair()) {
                return $computation
                    ->with([
                        'status' => $context->scheduleType === ScheduleType::FieldDuty
                            ? AttendanceStatus::FieldDuty
                            : AttendanceStatus::Present,
                    ])
                    ->note('Single punch accepted: this schedule expects no return to the device (C13).');
            }

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
            // ⚠⚠ A PUNCH WITH NOTHING TO PAIR IT TO IS STILL SOMEBODY AT WORK.
            // This branch used to return here unconditionally, so a person who
            // clocked in on a holiday and never clocked out produced a day that
            // was indistinguishable from a day nobody touched: no credit, no
            // flag, no review, nothing for anyone to notice. On 26 August 2026
            // (Eid-e-Milad-un-Nabi) two people did exactly that and the system
            // said nothing about either of them.
            //
            // ⚠ It is deliberately NOT credited and NOT approved here: worked
            // minutes are genuinely unknown, and inventing them would put made
            // up numbers into payroll. It is raised for a human to settle.
            if ($computation->firstIn !== null && ! $context->assumesPresence()) {
                return $computation
                    ->flag('unapproved_holiday_work')
                    ->flag('needs_review')
                    ->note('Clocked in on a non-working day but never clocked out, so the hours are unknown and nothing is counted. Approve holiday work, or correct the punches, to settle it (POL-09).');
            }

            // ⭐⭐ APPROVED TO WORK, AND DID NOT COME (Joy, 9 September 2026).
            // The mirror of counting unapproved off-day work: a non-working day
            // somebody was approved to work and then did not is an absence, not
            // an ordinary day off that nobody looks at again.
            //
            // ⚠ FLAGGED, NEVER SILENTLY DEDUCTED. "Salary may be deducted" is a
            // decision a person makes; this stage's job is only to stop the day
            // passing unnoticed.
            //
            // ⚠ DELIBERATELY NARROW - it fires only when holiday work was
            // actually approved. A plain holiday with no punches must stay
            // untouched, because making one provisional blocks payroll for
            // everybody, and there is a test standing on exactly that.
            if ($context->hasApprovedHolidayWork && ! $context->assumesPresence()) {
                return $computation
                    ->with(['status' => AttendanceStatus::Absent])
                    ->flag('approved_holiday_work_missed')
                    ->flag('needs_review')
                    ->note('Holiday work was approved for this day and nobody clocked in, so it is recorded as an absence for a human to settle (POL-09).');
            }

            return $computation;
        }

        if ($context->hasApprovedHolidayWork || $context->policy->countHolidayWorkWithoutApproval) {
            return $computation->note('Work on this non-working day is approved and counted.');
        }

        // An Exempt schedule's weekend punch is recorded but neither paid nor
        // flagged. The flag matters more than it looks: needs_review makes the
        // day provisional, and ONE provisional day blocks the whole payroll
        // run - a trusted person's Friday visit must not hold nine salaries.
        if ($context->assumesPresence()) {
            return $computation
                ->with(['payableMinutes' => 0])
                ->note('Worked on a non-working day; an exempt schedule records the hours without payment or review (POL-09).');
        }

        return $computation
            ->with(['payableMinutes' => 0])
            ->flag('unapproved_holiday_work')
            ->flag('needs_review')
            ->note('Worked on a non-working day without approval, so it is not counted (POL-09).');
    }
}
