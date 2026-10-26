<?php

namespace App\Modules\Hrm\Attendance\Stages;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\BreakPolicy;

/**
 * Stage 7 — lateness, early departure and the half-day rules (POL-02/04/05).
 *
 * Only runs on a day that resolved to Present: there is no such thing as a late
 * absence, and a missing punch is a blocked day rather than a punctual one.
 *
 * Note that being late does not consume any "free" grace here. POL-03's real
 * policy counts arrivals from 09:00:01 — including those inside the 15-minute
 * grace — and merely excuses the first few. So lateness is measured from the
 * shift start, and forgiveness is entirely the occurrence ladder's job in
 * stage 8. Treating grace as "not late" would silently discard the counter the
 * policy is built on.
 */
class ApplyGraceAndThresholds implements AttendanceStage
{
    /** The flag a day carries when an approved early leave excused its end. */
    public const APPROVED_EARLY_LEAVE = 'early_leave_approved';

    public function handle(AttendanceComputation $computation): AttendanceComputation
    {
        $context = $computation->context;

        // ⚠ Correction C17: Field duty is admitted here as well as Present, but
        // only where it came from the SCHEDULE. A Retail Sales Officer's day
        // resolves to FieldDuty because no clock-out is expected of them, and
        // the circular still holds them to an 08:15 arrival, so a stage that
        // only ever looked at Present could never judge it.
        //
        // ⚠⚠ An approved movement (POL-10) also lands on FieldDuty, and that day
        // has no punches at all. Admitting it here half-days every approved
        // field visit on zero recorded hours — caught by the engine test, not by
        // reading the code.
        $judgeable = $computation->status === AttendanceStatus::Present
            || ($computation->status === AttendanceStatus::FieldDuty && $context->countsFirstPunchOnly());

        if (! $judgeable) {
            return $computation;
        }

        $shift = $context->shift;

        if (! $context->judgesPunctuality() || $shift === null) {
            return $computation->note('Schedule is not judged on punctuality, so only hours worked matter.');
        }

        $computation = $this->applyLateness($computation, $shift);

        // Nothing after arrival was captured for a first-punch-only schedule, so
        // leaving early and working a short day are questions with no evidence
        // either way. Asking them anyway would half-day every honest field day.
        if (! $context->judgesHoursWorked()) {
            return $computation->note('Only the first punch of the day counts on this schedule, so hours worked are not judged.');
        }

        $computation = $this->applyEarlyDeparture($computation, $shift);

        return $this->applyHalfDay($computation);
    }

    private function applyLateness(AttendanceComputation $computation, $shift): AttendanceComputation
    {
        $firstIn = $computation->firstIn;

        if ($firstIn === null || $firstIn->lte($shift->start)) {
            return $computation;
        }

        $lateMinutes = (int) ceil($shift->start->diffInSeconds($firstIn) / 60);

        return $computation
            ->with(['lateMinutes' => $lateMinutes, 'status' => AttendanceStatus::Late])
            ->note(sprintf(
                'Arrived %s, %d minute%s after the %s start of %s.',
                $firstIn->format('H:i:s'),
                $lateMinutes,
                $lateMinutes === 1 ? '' : 's',
                $shift->name,
                $shift->start->format('H:i'),
            ));
    }

    private function applyEarlyDeparture(AttendanceComputation $computation, $shift): AttendanceComputation
    {
        $lastOut = $computation->lastOut;

        if ($lastOut === null || $lastOut->gte($shift->end)) {
            return $computation;
        }

        $earlyMinutes = (int) ceil($lastOut->diffInSeconds($shift->end) / 60);
        $grace = $computation->context->policy->earlyLeaveGraceMinutes;

        $computation = $computation->with(['earlyLeaveMinutes' => $earlyMinutes]);

        if ($earlyMinutes <= $grace) {
            return $computation->note(sprintf(
                'Left %d minute%s early, within the %d minute grace.',
                $earlyMinutes,
                $earlyMinutes === 1 ? '' : 's',
                $grace,
            ));
        }

        // ⭐ POL-04, 27 Oct 2026: an approved early departure carries no
        // deduction. The approval covers leaving at its time or later (inside
        // the same grace); leaving well before it is an ordinary early
        // departure, judged below as if nothing had been approved.
        $approvedAt = $computation->context->approvedEarlyLeaveAt;

        if ($approvedAt !== null && $lastOut->gte($approvedAt->copy()->subMinutes($grace))) {
            return $computation
                ->flag(self::APPROVED_EARLY_LEAVE)
                ->note(sprintf(
                    'Left %s under an approved early leave from %s, so the early departure carries no deduction (POL-04).',
                    $lastOut->format('H:i:s'),
                    $approvedAt->format('H:i'),
                ));
        }

        if ($approvedAt !== null) {
            $computation = $computation->note(sprintf(
                'Early leave was approved from %s, but the last punch is earlier, so the departure is judged as unapproved.',
                $approvedAt->format('H:i'),
            ));
        }

        // Without an approval the day goes to a human, and the half-day rule
        // below still applies (EarlyLeaveRules::UNAPPROVED_HALF_DAY_RULE).
        return $computation
            ->flag('early_departure')
            ->flag('needs_review')
            ->note(sprintf(
                'Left %s, %d minutes before the shift end of %s — beyond the %d minute grace (POL-04).',
                $lastOut->format('H:i:s'),
                $earlyMinutes,
                $shift->end->format('H:i'),
                $grace,
            ));
    }

    /**
     * POL-05 answered "Configurable", so the half-day boundary is policy data:
     * either too few hours worked, or an arrival past a configured cutoff.
     */
    private function applyHalfDay(AttendanceComputation $computation): AttendanceComputation
    {
        $policy = $computation->context->policy;
        $hours = $computation->payableMinutes / 60;

        // ⭐ 27 Oct 2026: the shorter day was approved, so the hours lines do
        // not turn it into a half day or a short day. A late ARRIVAL past the
        // cutoff is a different fact and is still judged below.
        $approvedShortDay = in_array(self::APPROVED_EARLY_LEAVE, $computation->flags, true);

        if (! $approvedShortDay && $hours <= $policy->halfDayMaximumHours) {
            return $computation
                ->with(['status' => AttendanceStatus::HalfDay])
                ->note(sprintf(
                    'Payable %s is at or below the half-day maximum of %s hours.',
                    number_format($hours, 2),
                    number_format($policy->halfDayMaximumHours, 2),
                ));
        }

        if ($policy->halfDayArrivalAfterMinutes !== null
            && $computation->lateMinutes > $policy->halfDayArrivalAfterMinutes) {
            return $computation
                ->with(['status' => AttendanceStatus::HalfDay])
                ->note(sprintf(
                    'Arrived more than %d minutes late, which the policy treats as a half day.',
                    $policy->halfDayArrivalAfterMinutes,
                ));
        }

        if ($approvedShortDay) {
            return $computation;
        }

        $minimum = $this->fullDayMinimumHours($computation);

        if ($hours < $minimum) {
            return $computation
                ->flag('short_day')
                ->note(sprintf(
                    'Payable %s is under the full-day minimum of %s hours.',
                    number_format($hours, 2),
                    number_format($minimum, 2),
                ));
        }

        return $computation;
    }

    /**
     * ⚠ Correction C23. The Office shift is 540 scheduled minutes; deduct a 60
     * minute meal and a perfect day lands on exactly 480 = exactly the flat
     * 8-hour minimum, passing by zero margin — a 61-minute lunch would flag
     * `short_day` on essentially every day and drown the flag in noise within
     * a week. Under the meal-window mode the minimum is therefore DERIVED from
     * the shift (scheduled span − standard meal − a small tolerance, decision
     * D4), so it survives the next time somebody edits the shift hours, which
     * a hand-set number would not.
     */
    private function fullDayMinimumHours(AttendanceComputation $computation): float
    {
        $shift = $computation->context->shift;

        if ($shift?->breakPolicy === BreakPolicy::MealWindow
            && $shift->mealStandardMinutes !== null) {
            return max(0, $shift->scheduledMinutes() - $shift->mealStandardMinutes - $shift->mealShortDayToleranceMinutes) / 60;
        }

        return $computation->context->policy->fullDayMinimumHours;
    }
}
